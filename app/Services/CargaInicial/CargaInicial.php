<?php

namespace App\Services\CargaInicial;

use App\Enums\EstadoInhumado;
use App\Enums\EstadoTitulo;
use App\Enums\EventoBitacora;
use App\Enums\OrigenTitulo;
use App\Enums\RolTitularidad;
use App\Enums\Sexo;
use App\Enums\TipoEspacio;
use App\Enums\TipoMovimiento;
use App\Models\Bitacora;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Lote;
use App\Models\Movimiento;
use App\Models\Panteon;
use App\Models\Persona;
use App\Models\Regimen;
use App\Models\Seccion;
use App\Models\Titulo;
use App\Support\Auditoria;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Carga del registro anterior (libros de papel o Excel) a partir de las plantillas.
 *
 * - revisar(): valida todos los renglones y dice, por renglón, qué está mal y qué falta.
 * - cargar(): sólo si la revisión no tiene errores; todo o nada, en una transacción.
 * - Se puede repetir con el mismo archivo: lo que ya existe se deja igual (no se duplica).
 * - En la bitácora queda un renglón de resumen por archivo, no uno por registro.
 */
final class CargaInicial
{
    public function revisar(TipoCarga $tipo, array $filas): ResultadoCarga
    {
        return $this->procesar($tipo, $filas, guardar: false);
    }

    public function cargar(TipoCarga $tipo, array $filas, string $archivo): ResultadoCarga
    {
        $revision = $this->revisar($tipo, $filas);
        if (! $revision->sinErrores()) {
            return $revision;
        }

        $resultado = DB::transaction(fn () => Auditoria::sinAnotar(fn () => $this->procesar($tipo, $filas, guardar: true)));
        Bitacora::anotar(EventoBitacora::Creado, 'Carga inicial: '.Str::lower(Str::after($tipo->getLabel(), '. ')), null, [
            'archivo' => ['despues' => $archivo],
            'renglones' => ['despues' => $resultado->renglones],
            'nuevos' => ['despues' => $resultado->nuevos],
            'ya_existian' => ['despues' => $resultado->existentes],
        ]);

        return $resultado;
    }

    private function procesar(TipoCarga $tipo, array $filas, bool $guardar): ResultadoCarga
    {
        $r = new ResultadoCarga;
        $r->renglones = count($filas);
        $vistos = [];

        foreach ($filas as $n => $f) {
            try {
                match ($tipo) {
                    TipoCarga::Espacios => $this->espacio($n, $f, $r, $guardar, $vistos),
                    TipoCarga::Titulos => $this->titulo($n, $f, $r, $guardar, $vistos),
                    TipoCarga::Sepultados => $this->sepultado($n, $f, $r, $guardar, $vistos),
                };
            } catch (Throwable $e) {
                if ($guardar) {
                    throw $e; // en la carga, cualquier falla deshace todo
                }
                $r->error($n, 'No se pudo revisar este renglón: '.$e->getMessage());
            }
        }

        return $r;
    }

    // ── Espacios ─────────────────────────────────────────────────────────────

    private function espacio(int $n, array $f, ResultadoCarga $r, bool $guardar, array &$vistos): void
    {
        $panteon = $this->panteon($n, $f, $r);
        $tipo = $this->opcion($n, $f['tipo'] ?? null, 'Tipo', array_column(array_map(fn ($t) => ['k' => Str::lower($t->getLabel()), 'v' => $t], TipoEspacio::cases()), 'v', 'k'), $r) ?? TipoEspacio::Fosa;
        $medidas = [];
        foreach (['largo' => 'largo_m', 'ancho' => 'ancho_m', 'profundidad' => 'profundidad_m'] as $col => $campo) {
            $medidas[$campo] = $this->numero($n, $f[$col] ?? null, ucfirst($col), $r, 0, 99);
        }
        $disponible = $this->siNo($n, $f['disponible'] ?? null, 'Disponible', $r, true);
        $comun = $this->siNo($n, $f['fosa_comun'] ?? null, 'Fosa común', $r, false);
        $historico = $this->siNo($n, $f['monumento_historico'] ?? null, 'Monumento histórico', $r, false);
        foreach (['seccion' => 'Sección', 'lote' => 'Lote', 'espacio' => 'Espacio'] as $col => $nombre) {
            if (blank($f[$col] ?? null)) {
                $r->error($n, "Falta «{$nombre}».");
            }
        }
        if ($panteon === null || isset($r->errores[$n])) {
            return;
        }

        $llave = Str::lower(implode('|', [$panteon->id, $f['seccion'], $f['lote'], $f['espacio']]));
        if (isset($vistos[$llave])) {
            $r->error($n, "Este espacio ya viene en el renglón {$vistos[$llave]}.");

            return;
        }
        $vistos[$llave] = $n;

        $existente = $this->buscarEspacio($panteon, (string) $f['seccion'], (string) $f['lote'], (string) $f['espacio']);
        if ($existente) {
            $r->existentes++;

            return;
        }
        $r->nuevos++;
        if (! $guardar) {
            return;
        }
        $seccion = Seccion::firstOrCreate(['panteon_id' => $panteon->id, 'clave' => (string) $f['seccion']]);
        $lote = Lote::firstOrCreate(['seccion_id' => $seccion->id, 'numero' => (string) $f['lote']]);
        Espacio::create([
            'lote_id' => $lote->id, 'tipo' => $tipo, 'numero' => (string) $f['espacio'], ...$medidas,
            'habilitado' => $disponible, 'es_fosa_comun' => $comun, 'es_monumento_historico' => $historico,
            'observaciones' => $f['observaciones'] ?? null,
        ]);
    }

    // ── Títulos ──────────────────────────────────────────────────────────────

    private function titulo(int $n, array $f, ResultadoCarga $r, bool $guardar, array &$vistos): void
    {
        $espacio = $this->espacioExistente($n, $f, $r);
        $folio = trim((string) ($f['folio'] ?? ''));
        if ($folio === '') {
            $r->error($n, 'Falta el folio del título.');
        }
        $emision = $this->fecha($n, $f['fecha_emision'] ?? null, 'Fecha de emisión', $r, obligatoria: true);
        $regimenClave = Str::lower(Str::ascii(trim((string) ($f['tipo_titulo'] ?? '')))) ?: 'perpetuidad';
        $regimen = Regimen::where('clave', $regimenClave)->vigenteEn($emision && $emision->lte(now()) ? $emision : null)->first()
            ?? Regimen::where('clave', $regimenClave)->abiertas()->first();
        if ($regimen === null) {
            $r->error($n, "«{$f['tipo_titulo']}» no es un tipo de título. Usa perpetuidad o temporalidad.");
        }
        if (blank($f['titular_nombre'] ?? null)) {
            $r->error($n, 'Falta el nombre del titular.');
        }
        $curp = Str::upper(trim((string) ($f['titular_curp'] ?? '')));
        if ($curp !== '' && ! preg_match('/^[A-Z0-9]{18}$/', $curp)) {
            $r->error($n, "La CURP «{$curp}» no tiene 18 letras y números.");
        }
        if (isset($r->errores[$n]) || $espacio === null) {
            return;
        }

        if (isset($vistos['folio:'.$folio])) {
            $r->error($n, "El folio {$folio} ya viene en el renglón {$vistos['folio:'.$folio]}.");

            return;
        }
        if (isset($vistos['espacio:'.$espacio->id])) {
            $r->error($n, "Este espacio ya tiene un título en el renglón {$vistos['espacio:'.$espacio->id]}. Un espacio sólo tiene un título vigente.");

            return;
        }
        $vistos['folio:'.$folio] = $vistos['espacio:'.$espacio->id] = $n;

        $existente = Titulo::where('folio', $folio)->first();
        if ($existente) {
            if ((int) $existente->espacio_id !== (int) $espacio->id) {
                $r->error($n, "El folio {$folio} ya está registrado en otro espacio ({$existente->espacio->clave_completa}).");
            } else {
                $r->existentes++;
            }

            return;
        }
        $vigente = $espacio->tituloVigente()->first();
        if ($vigente) {
            $r->error($n, "El espacio ya tiene el título vigente {$vigente->folio}.");

            return;
        }
        $vence = $emision->copy()->addYearsNoOverflow($regimen->duracion_anios);
        if ($vence->lt(now())) {
            $r->aviso($n, 'Por su tipo y fecha, este título ya venció el '.$vence->format('d/m/Y').'. Se carga como vencido.');
        }
        $r->nuevos++;
        if (! $guardar) {
            return;
        }

        $titulo = Titulo::create([
            'espacio_id' => $espacio->id, 'regimen_id' => $regimen->id, 'folio' => $folio,
            'expediente' => ($f['expediente'] ?? null) ?: 'Registro anterior', 'origen' => OrigenTitulo::Legado,
            'estado' => $vence->lt(now()) ? EstadoTitulo::Vencido : EstadoTitulo::Vigente,
            'fecha_emision' => $emision, 'observaciones' => $f['observaciones'] ?? null,
        ]);
        $titular = $curp !== '' ? Persona::firstOrCreate(['curp' => $curp], $this->nombre($f)) : Persona::create($this->nombre($f));
        $titulo->personas()->attach($titular->id, ['rol' => RolTitularidad::Titular->value, 'orden' => 1]);
        $orden = 2;
        foreach ($this->lista($f['copropietarios'] ?? null) as $nombre) {
            $titulo->personas()->attach(Persona::create(['nombre' => $nombre])->id, ['rol' => RolTitularidad::Copropietario->value, 'orden' => $orden++]);
        }
        foreach ($this->lista($f['beneficiarios'] ?? null) as $nombre) {
            $titulo->personas()->attach(Persona::create(['nombre' => $nombre])->id, ['rol' => RolTitularidad::Beneficiario->value, 'orden' => $orden++]);
        }
    }

    // ── Sepultados ───────────────────────────────────────────────────────────

    private function sepultado(int $n, array $f, ResultadoCarga $r, bool $guardar, array &$vistos): void
    {
        $espacio = $this->espacioExistente($n, $f, $r);
        if (blank($f['nombre'] ?? null)) {
            $r->error($n, 'Falta el nombre. Si no se identificó, escribe «Desconocido».');
        }
        // «M» es mujer en la CURP y masculino en muchos libros: no se adivina.
        if (Str::lower(trim((string) ($f['sexo'] ?? ''))) === 'm') {
            $r->error($n, '«Sexo»: «M» puede significar mujer o masculino. Escribe «mujer» u «hombre».');
            $sexo = Sexo::NoEspecificado;
        } else {
            $sexo = $this->opcion($n, $f['sexo'] ?? null, 'Sexo', ['mujer' => Sexo::Mujer, 'hombre' => Sexo::Hombre, 'no especificado' => Sexo::NoEspecificado,
                'femenino' => Sexo::Mujer, 'masculino' => Sexo::Hombre, 'f' => Sexo::Mujer, 'h' => Sexo::Hombre], $r) ?? Sexo::NoEspecificado;
        }
        $inhumacion = $this->fecha($n, $f['fecha_inhumacion'] ?? null, 'Fecha de inhumación', $r);
        $defuncion = $this->fecha($n, $f['fecha_defuncion'] ?? null, 'Fecha de defunción', $r);
        $nacimiento = $this->fecha($n, $f['fecha_nacimiento'] ?? null, 'Fecha de nacimiento', $r);
        $edad = $this->numero($n, $f['edad_al_fallecer'] ?? null, 'Edad al fallecer', $r, 0, 130);
        $situacion = $this->opcion($n, $f['situacion'] ?? null, 'Situación', ['sepultado' => EstadoInhumado::Inhumado, 'inhumado' => EstadoInhumado::Inhumado,
            'restos en sitio' => EstadoInhumado::RestosEnSitio], $r) ?? EstadoInhumado::Inhumado;
        if ($defuncion && $inhumacion && $inhumacion->lt($defuncion)) {
            $r->error($n, 'La fecha de inhumación es anterior a la de defunción.');
        }
        if (isset($r->errores[$n]) || $espacio === null) {
            return;
        }
        if ($inhumacion === null) {
            $r->aviso($n, 'Sin fecha de inhumación no se podrá calcular cuándo se puede exhumar ni reutilizar la fosa.');
        }
        if ($edad === null && ! ($nacimiento && $defuncion)) {
            $r->aviso($n, 'Sin edad al fallecer, el plazo para exhumar no se podrá calcular; complétala después en su ficha.');
        }

        $nombreCompleto = Str::lower(trim(implode(' ', array_filter([$f['nombre'] ?? null, $f['primer_apellido'] ?? null, $f['segundo_apellido'] ?? null]))));
        $llave = $espacio->id.'|'.$nombreCompleto.'|'.($inhumacion?->toDateString() ?? '');
        if (isset($vistos[$llave])) {
            $r->error($n, "Esta persona ya viene en el renglón {$vistos[$llave]} con el mismo espacio y fecha.");

            return;
        }
        $vistos[$llave] = $n;

        $existe = Inhumado::where('espacio_id', $espacio->id)->whereRaw('LOWER(nombre_completo) = ?', [$nombreCompleto])
            ->when($inhumacion, fn ($q) => $q->whereDate('fecha_inhumacion', $inhumacion), fn ($q) => $q->whereNull('fecha_inhumacion'))->exists();
        if ($existe) {
            $r->existentes++;

            return;
        }
        $r->nuevos++;
        if (! $guardar) {
            return;
        }

        $desconocido = Str::lower(Str::ascii((string) $f['nombre'])) === 'desconocido';
        $inhumado = Inhumado::create([
            'espacio_id' => $espacio->id, ...$this->nombre($f), 'es_desconocido' => $desconocido, 'sexo' => $sexo,
            'fecha_nacimiento' => $nacimiento, 'fecha_defuncion' => $defuncion, 'edad_al_fallecer' => $edad,
            'numero_acta_defuncion' => $f['acta'] ?? null, 'lugar_fallecimiento' => $f['lugar'] ?? null,
            'causa_fallecimiento' => ($f['causa'] ?? null) ?: null, 'fecha_inhumacion' => $inhumacion,
            'estado' => $situacion, 'observaciones' => trim('Carga inicial del registro anterior. '.($f['observaciones'] ?? '')),
        ]);
        // Con fecha, queda en el libro de registro como inhumación del registro anterior.
        if ($inhumacion) {
            Movimiento::create([
                'inhumado_id' => $inhumado->id, 'tipo' => TipoMovimiento::Inhumacion, 'fecha_hora' => $inhumacion,
                'espacio_destino_id' => $espacio->id, 'registrado_por' => auth()->id(), 'observaciones' => 'Carga inicial del registro anterior.',
            ]);
        }
    }

    // ── Ayudas de validación ─────────────────────────────────────────────────

    private function panteon(int $n, array $f, ResultadoCarga $r): ?Panteon
    {
        $clave = trim((string) ($f['panteon'] ?? ''));
        if ($clave === '') {
            $r->error($n, 'Falta «Panteón».');

            return null;
        }
        $p = Panteon::where('clave', $clave)->orWhere('nombre', $clave)->first();
        if ($p === null) {
            $r->error($n, "No hay un panteón con clave «{$clave}». Dalo de alta en Inventario › Panteones o corrige la clave.");
        }

        return $p;
    }

    private function buscarEspacio(Panteon $p, string $seccion, string $lote, string $espacio): ?Espacio
    {
        return Espacio::where('numero', $espacio)
            ->whereHas('lote', fn ($q) => $q->where('numero', $lote)->whereHas('seccion', fn ($s) => $s->where('panteon_id', $p->id)->where('clave', $seccion)))
            ->first();
    }

    private function espacioExistente(int $n, array $f, ResultadoCarga $r): ?Espacio
    {
        $p = $this->panteon($n, $f, $r);
        if ($p === null) {
            return null;
        }
        foreach (['seccion' => 'Sección', 'lote' => 'Lote', 'espacio' => 'Espacio'] as $col => $nombre) {
            if (blank($f[$col] ?? null)) {
                $r->error($n, "Falta «{$nombre}».");

                return null;
            }
        }
        $e = $this->buscarEspacio($p, (string) $f['seccion'], (string) $f['lote'], (string) $f['espacio']);
        if ($e === null) {
            $r->error($n, "No existe el espacio {$p->clave}-{$f['seccion']}-{$f['lote']}-{$f['espacio']}. Cárgalo primero en «Espacios».");
        }

        return $e;
    }

    private function fecha(int $n, mixed $v, string $nombre, ResultadoCarga $r, bool $obligatoria = false): ?CarbonInterface
    {
        if (blank($v)) {
            if ($obligatoria) {
                $r->error($n, "Falta «{$nombre}».");
            }

            return null;
        }
        $fecha = match (true) {
            $v instanceof DateTimeInterface => Carbon::instance($v),
            is_numeric($v) && (float) $v > 1000 => Carbon::create(1899, 12, 30)->addDays((int) $v), // número de serie de Excel
            default => $this->textoAFecha((string) $v),
        };
        if ($fecha === null) {
            $r->error($n, "«{$nombre}» no es una fecha válida ({$v}). Escríbela como día/mes/año, por ejemplo 04/02/2015.");

            return null;
        }
        if ($fecha->gt(now()) || $fecha->year < 1800) {
            $r->error($n, "«{$nombre}» ({$fecha->format('d/m/Y')}) no puede ser una fecha futura ni anterior a 1800.");

            return null;
        }

        return $fecha->startOfDay();
    }

    private function textoAFecha(string $t): ?CarbonInterface
    {
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd.m.Y'] as $formato) {
            try {
                $f = Carbon::createFromFormat('!'.$formato, trim($t));
                if ($f && $f->format($formato) === trim($t)) {
                    return $f;
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function numero(int $n, mixed $v, string $nombre, ResultadoCarga $r, float $min, float $max): int|float|null
    {
        if (blank($v)) {
            return null;
        }
        $t = str_replace(',', '.', (string) $v);
        if (! is_numeric($t) || (float) $t < $min || (float) $t > $max) {
            $r->error($n, "«{$nombre}» debe ser un número entre {$min} y {$max} ({$v}).");

            return null;
        }

        return str_contains($t, '.') ? (float) $t : (int) $t;
    }

    private function siNo(int $n, mixed $v, string $nombre, ResultadoCarga $r, bool $omision): bool
    {
        if (blank($v)) {
            return $omision;
        }
        $t = Str::lower(Str::ascii(trim((string) $v)));

        return match (true) {
            in_array($t, ['si', 's', 'x', '1', 'true', 'verdadero'], true) => true,
            in_array($t, ['no', 'n', '0', 'false', 'falso'], true) => false,
            default => tap($omision, fn () => $r->error($n, "«{$nombre}» debe ser «sí» o «no» ({$v}).")),
        };
    }

    /** @param  array<string, mixed>  $opciones */
    private function opcion(int $n, mixed $v, string $nombre, array $opciones, ResultadoCarga $r): mixed
    {
        if (blank($v)) {
            return null;
        }
        $t = Str::lower(trim((string) $v));
        $sinAcentos = Str::lower(Str::ascii($t));
        foreach ($opciones as $k => $valor) {
            if ($t === $k || $sinAcentos === Str::lower(Str::ascii($k))) {
                return $valor;
            }
        }
        $r->error($n, "«{$nombre}»: «{$v}» no es una opción válida. Usa: ".implode(', ', array_slice(array_keys($opciones), 0, 4)).'.');

        return null;
    }

    private function nombre(array $f): array
    {
        $prefijo = array_key_exists('titular_nombre', $f) ? 'titular_' : '';

        return [
            'nombre' => (string) $f[$prefijo.'nombre'],
            'primer_apellido' => ($f[$prefijo.'primer_apellido'] ?? null) ?: null,
            'segundo_apellido' => ($f[$prefijo.'segundo_apellido'] ?? null) ?: null,
        ];
    }

    /** @return list<string> */
    private function lista(mixed $v): array
    {
        return collect(explode(';', (string) $v))->map(fn ($x) => trim($x))->filter()->values()->all();
    }
}
