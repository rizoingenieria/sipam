<?php

namespace App\Services\Diagnostico;

use App\Enums\EstadoInhumado;
use App\Enums\EstadoTramite;
use App\Enums\Permiso;
use App\Enums\TipoTramite;
use App\Models\Cargo;
use App\Models\ConceptoCobro;
use App\Models\EntePublico;
use App\Models\Inhumado;
use App\Models\Panteon;
use App\Models\Parametro;
use App\Models\Seccion;
use App\Models\Tramite;
use App\Models\User;
use App\Services\Accesos\AdministracionAccesos;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Revisa lo que falta configurar para operar, y lo dice en lenguaje llano con el paso que lo resuelve.
 * Lo usan el comando panteones:diagnostico y el tablero de inicio de la interfaz.
 *
 * Sólo lee: no corrige nada por su cuenta.
 */
final class DiagnosticoConfiguracion
{
    /** @return Collection<int, Pendiente> Bloqueantes primero. */
    public function revisar(): Collection
    {
        $pendientes = collect([
            ...$this->servidor(),
            ...$this->accesos(),
            ...$this->inventario(),
            ...$this->parametros(),
            ...$this->tabulador(),
            ...$this->firmantes(),
            ...$this->membrete(),
            ...$this->datosAntiguos(),
            ...$this->tareasProgramadas(),
        ]);

        return $pendientes->sortBy(fn (Pendiente $p) => $p->esBloqueante() ? 0 : 1)->values();
    }

    public function listoParaOperar(): bool
    {
        return $this->revisar()->doesntContain(fn (Pendiente $p) => $p->esBloqueante());
    }

    /** @return list<Pendiente> */
    private function servidor(): array
    {
        $p = [];
        if (blank(config('app.key'))) {
            $p[] = Pendiente::bloqueante('Servidor', 'Falta la llave de cifrado del sistema (APP_KEY); sin ella no se pueden guardar datos protegidos.',
                'Pide a soporte técnico que la genere y que la respalde junto con la base de datos.');
        }
        if (! extension_loaded('intl')) {
            $p[] = Pendiente::advertencia('Servidor', 'Falta la extensión «intl» de PHP; fechas y cantidades podrían verse con formato extranjero.',
                'Pide a soporte técnico que la instale.');
        }
        if (config('app.fallback_locale') !== 'es') {
            $p[] = Pendiente::advertencia('Servidor', 'Algunos mensajes del sistema pueden salir en inglés.',
                'Pide a soporte técnico que ponga APP_FALLBACK_LOCALE=es en la configuración.');
        }
        if (DB::getDriverName() === 'mysql') {
            $collation = DB::selectOne('SELECT @@collation_database AS c')->c ?? '';
            if ($collation !== 'utf8mb4_0900_ai_ci') {
                $p[] = Pendiente::advertencia('Servidor', 'Las búsquedas podrían distinguir acentos y mayúsculas («Muñoz» y «Munoz» no coincidirían).',
                    'Pide a soporte técnico que use la intercalación utf8mb4_0900_ai_ci en la base de datos.');
            }
        }

        return $p;
    }

    /** @return list<Pendiente> */
    private function accesos(): array
    {
        if (! AdministracionAccesos::quedaAdministrador()) {
            return [Pendiente::bloqueante('Usuarios', 'Nadie puede administrar usuarios y roles.',
                'Pide a soporte técnico que ejecute: php artisan panteones:crear-administrador')];
        }

        $p = [];
        if (! $this->alguienPuede(Permiso::TramitesCapturar)) {
            $p[] = Pendiente::advertencia('Usuarios', 'Nadie puede capturar trámites.', 'Asigna el rol «Ventanilla» a quien atiende al público.');
        }
        foreach (TipoTramite::cases() as $tipo) {
            if (! $this->alguienPuede(Permiso::autorizar($tipo))) {
                $p[] = Pendiente::advertencia('Usuarios', "Nadie puede autorizar: {$tipo->getLabel()}.",
                    'Asigna a alguien un rol con el permiso «'.Permiso::autorizar($tipo)->getLabel().'».');
            }
        }

        return $p;
    }

    /** @return list<Pendiente> */
    private function inventario(): array
    {
        if (Panteon::doesntExist()) {
            return [Pendiente::bloqueante('Inventario', 'No hay ningún panteón registrado.',
                'Registra el panteón con sus secciones, lotes y espacios en Inventario › Panteones.')];
        }

        return Seccion::doesntExist()
            ? [Pendiente::bloqueante('Inventario', 'Los panteones no tienen secciones.', 'Registra las secciones de cada panteón; la cuota del título depende de ellas.')]
            : [];
    }

    /** @return list<Pendiente> */
    private function parametros(): array
    {
        $faltan = collect(Parametro::clavesRequeridas())
            ->reject(fn ($nombre, $clave) => Parametro::where('clave', $clave)->vigenteEn()->exists());

        return $faltan->isEmpty() ? [] : [Pendiente::bloqueante('Parámetros',
            'Faltan parámetros del reglamento: '.$faltan->values()->implode(', ').'.',
            'Pide a soporte técnico que ejecute: php artisan db:seed --class=CatalogosSeeder')];
    }

    /**
     * Cuotas que faltan en el tabulador, una por renglón, para mostrarlas como lista
     * en la pantalla del tabulador.
     *
     * @return list<string>
     */
    public function cuotasFaltantes(): array
    {
        $secciones = Seccion::with('panteon')->orderBy('panteon_id')->orderBy('clave')->get();
        $faltan = [];

        foreach (ConceptoCobro::where('activo', true)->orderBy('orden')->get() as $concepto) {
            if (! $concepto->por_seccion) {
                if ($concepto->cuotaPara() === null) {
                    $faltan[] = $concepto->nombre;
                }

                continue;
            }
            foreach ($secciones as $s) {
                if ($concepto->cuotaPara($s) === null) {
                    $faltan[] = "{$concepto->nombre} · {$s->panteon->nombre}, sección {$s->clave}";
                }
            }
        }

        return $faltan;
    }

    /** @return list<Pendiente> */
    private function tabulador(): array
    {
        $secciones = Seccion::with('panteon')->get();
        $p = [];

        $generalesSinCuota = [];

        foreach (ConceptoCobro::where('activo', true)->orderBy('orden')->get() as $concepto) {
            if (! $concepto->por_seccion) {
                if ($concepto->cuotaPara() === null) {
                    $generalesSinCuota[] = "«{$concepto->nombre}»";
                }

                continue;
            }
            $sinCuota = $secciones->filter(fn (Seccion $s) => $concepto->cuotaPara($s) === null);
            if ($sinCuota->isNotEmpty()) {
                $p[] = Pendiente::advertencia('Tabulador',
                    "Falta la cuota de «{$concepto->nombre}» en ".Formato::cantidad($sinCuota->count(), 'sección', 'secciones').': '
                    .$sinCuota->map(fn (Seccion $s) => "{$s->panteon->nombre}, sección {$s->clave}")->implode('; ').'.',
                    'Tesorería debe capturar la cuota de cada sección con el acuerdo de Cabildo que la aprueba.');
            }
        }

        // Un solo aviso para todas las cuotas generales: una lista de avisos casi iguales no se lee.
        if ($generalesSinCuota !== []) {
            $n = count($generalesSinCuota);
            $p[] = Pendiente::advertencia('Tabulador',
                ($n === 1 ? 'Falta 1 cuota del tabulador: ' : "Faltan {$n} cuotas del tabulador: ").implode(', ', $generalesSinCuota)
                .'. Sin ellas no se pueden emitir esas órdenes de pago.',
                'Tesorería debe capturarlas con el acuerdo de Cabildo que las aprueba.');
        }

        return $p;
    }

    /** @return list<Pendiente> Un solo aviso con todos los cargos sin firmante. */
    private function firmantes(): array
    {
        $vacantes = Cargo::where('firma_documentos', true)->orderBy('orden')->get()
            ->filter(fn (Cargo $c) => $c->firmanteEn() === null)
            ->map(fn (Cargo $c) => "«{$c->nombre}»");

        return $vacantes->isEmpty() ? [] : [Pendiente::advertencia('Firmantes',
            'Falta registrar quién firma como '.$vacantes->implode(', ').'. Los documentos saldrán sin esos nombres.',
            'Registra a la persona que ocupa cada cargo y la fecha en que empezó.')];
    }

    /** @return list<Pendiente> */
    private function membrete(): array
    {
        $ente = EntePublico::actual();
        if ($ente === null) {
            return [Pendiente::bloqueante('Membrete', 'Faltan los datos del ayuntamiento.', 'Pide a soporte técnico que ejecute: php artisan db:seed --class=CatalogosSeeder')];
        }
        $faltan = array_keys(array_filter([
            'domicilio' => blank($ente->domicilio),
            'teléfono' => blank($ente->telefono),
            'escudo' => blank($ente->escudo_path),
        ]));

        return $faltan === [] ? [] : [Pendiente::advertencia('Membrete',
            'Al membrete de los documentos le falta: '.implode(', ', $faltan).'.',
            'Complétalo en Documentos oficiales › Datos del ayuntamiento.')];
    }

    /** @return list<Pendiente> */
    private function datosAntiguos(): array
    {
        $sinEdad = Inhumado::where('estado', EstadoInhumado::Inhumado->value)
            ->whereNull('edad_al_fallecer')->whereNull('fecha_nacimiento')->count();

        return $sinEdad === 0 ? [] : [Pendiente::advertencia('Registro de finados',
            Formato::cantidad($sinEdad, 'finado no tiene', 'finados no tienen').' edad al fallecer; no se podrán exhumar hasta capturarla.',
            'Captúrala en el registro de cada finado a partir de su acta de defunción. Puedes hacerlo cuando alguien pida la exhumación.')];
    }

    /** Si hay solicitudes que debieron caducar y siguen abiertas, el programador de tareas no está corriendo. */
    private function tareasProgramadas(): array
    {
        $atrasadas = Tramite::whereNotNull('caduca_en')->where('caduca_en', '<', Carbon::today()->subDay()->toDateString())
            ->whereNotIn('estado', [EstadoTramite::Concluido->value, EstadoTramite::Rechazado->value, EstadoTramite::Caducado->value])
            ->count();

        return $atrasadas === 0 ? [] : [Pendiente::advertencia('Servidor',
            ($atrasadas === 1 ? '1 solicitud debió caducar y sigue abierta' : "{$atrasadas} solicitudes debieron caducar y siguen abiertas").': las tareas automáticas no están corriendo.',
            'Pide a soporte técnico que active el programador de tareas de Laravel (schedule:run cada minuto).')];
    }

    private function alguienPuede(Permiso $permiso): bool
    {
        return User::where('activo', true)
            ->whereHas('roles', fn ($q) => $q->where('roles.activo', true)->whereJsonContains('roles.permisos', $permiso->value))
            ->exists();
    }
}
