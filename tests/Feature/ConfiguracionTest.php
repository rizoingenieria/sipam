<?php

namespace Tests\Feature;

use App\Enums\EstadoTramite;
use App\Enums\Permiso;
use App\Enums\TipoEspacio;
use App\Enums\TipoTramite;
use App\Models\Cargo;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\Espacio;
use App\Models\Firmante;
use App\Models\Inhumado;
use App\Models\Lote;
use App\Models\Panteon;
use App\Models\Parametro;
use App\Models\Persona;
use App\Models\ReglaPlazo;
use App\Models\Rol;
use App\Models\Seccion;
use App\Models\User;
use App\Services\Accesos\AdministracionAccesos;
use App\Services\Diagnostico\DiagnosticoConfiguracion;
use App\Services\Diagnostico\Pendiente;
use App\Services\Tramites\GestorTramites;
use App\Services\Tramites\ReglasDatos;
use App\Support\AccionBloqueada;
use App\Support\Formato;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Configuración desde la interfaz: parámetros, correcciones en vigor, roles y permisos,
 * diagnóstico de arranque e idioma. Requiere DB_CONNECTION=mysql y una base de prueba propia.
 */
class ConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('El esquema usa columnas generadas y CHECK de MySQL; configure DB_CONNECTION=mysql.');
        }

        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->seed(CatalogosSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(string ...$roles): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Rol::whereIn('clave', $roles)->pluck('id'));

        return $u;
    }

    // ── Parámetros ────────────────────────────────────────────────────────────

    public function test_parametros_sembrados_y_lectura_tipada(): void
    {
        $this->assertSame(15, Parametro::count());
        $this->assertSame(2, Parametro::entero('testigos_requeridos'));
        $this->assertSame(0.9, Parametro::decimal('monumento_altura_maxima_m'));
        $this->assertSame('INH', Parametro::texto('serie_folio_inhumacion'));
        $this->assertSame(array_keys(Parametro::clavesRequeridas()), Parametro::orderBy('id')->pluck('clave')->all());
    }

    public function test_un_parametro_rechaza_valores_que_no_sirven_con_mensaje_llano(): void
    {
        $casos = [
            ['testigos_requeridos', '2.5', 'debe ser un número entero'],
            ['testigos_requeridos', '9', 'no puede ser mayor que 5 testigos'],
            ['serie_folio_titulo', 'tit', 'letras mayúsculas o números'],
            ['monumento_altura_maxima_m', 'abc', 'debe ser un número, por ejemplo 0.90'],
        ];

        foreach ($casos as [$clave, $valor, $esperado]) {
            try {
                Parametro::where('clave', $clave)->first()->nuevaVersion(['valor' => $valor], Carbon::parse('2027-01-01'));
                $this->fail("Se aceptó {$valor} en {$clave}.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($esperado, $e->getMessage());
            }
        }
        $this->assertSame(15, Parametro::count(), 'Ninguna versión inválida quedó guardada.');
        $this->assertSame(15, Parametro::abiertas()->count(), 'La versión vigente sigue abierta.');
    }

    public function test_falta_un_parametro_y_el_mensaje_dice_como_resolverlo(): void
    {
        DB::table('parametros')->where('clave', 'testigos_requeridos')->delete();

        $this->expectExceptionMessage('Falta configurar el parámetro «testigos_requeridos»');
        Parametro::entero('testigos_requeridos');
    }

    // ── Correcciones en vigor ─────────────────────────────────────────────────

    public function test_se_corrige_la_redaccion_en_vigor_pero_no_lo_que_decide(): void
    {
        $regla = ReglaPlazo::where('clave', 'temporalidad-minima')->first();
        $regla->update(['fundamento' => 'Art. 38 del Reglamento de Panteones']);
        $this->assertSame('Art. 38 del Reglamento de Panteones', $regla->fresh()->fundamento);
        $this->assertSame(1, ReglaPlazo::where('clave', 'temporalidad-minima')->count(), 'Sin versión nueva.');

        $parametro = Parametro::where('clave', 'testigos_requeridos')->first();
        $parametro->update(['ayuda' => 'Testigos que se piden.']);

        $this->expectException(LogicException::class);
        $parametro->update(['valor' => '3']);
    }

    public function test_fundamento_sanitario_actualizado(): void
    {
        $this->assertStringStartsWith('Art. 67 del Reglamento de la Ley General de Salud', ReglaPlazo::where('clave', 'sanitario-15-o-mas')->value('fundamento'));
    }

    // ── Roles y permisos ──────────────────────────────────────────────────────

    public function test_roles_sembrados_y_permisos(): void
    {
        $this->assertSame(9, Rol::count());

        $encargado = $this->usuario('encargado_panteones');
        $this->assertTrue($encargado->puede(Permiso::AutorizarInhumacion));
        $this->assertFalse($encargado->puede(Permiso::AutorizarAdquisicion), 'Los títulos los autoriza la Secretaría.');

        $doble = $this->usuario('ventanilla', 'tesoreria');
        $this->assertTrue($doble->puede(Permiso::TramitesCapturar));
        $this->assertTrue($doble->puede(Permiso::CatalogosTabulador));

        Rol::where('clave', 'tesoreria')->update(['activo' => false]);
        $this->assertFalse($doble->puede(Permiso::CatalogosTabulador), 'Un rol inactivo no da permisos.');

        $doble->update(['activo' => false]);
        $this->assertFalse($doble->puede(Permiso::TramitesCapturar), 'Un usuario inactivo no puede nada.');
        $this->assertTrue($doble->permisos()->isEmpty());
    }

    public function test_un_rol_sólo_guarda_permisos_que_existen(): void
    {
        $rol = Rol::create(['clave' => 'prueba', 'nombre' => 'Prueba', 'permisos' => ['tramites.ver', 'inventado.x', 'tramites.ver']]);

        $this->assertSame(['tramites.ver'], $rol->fresh()->permisos);
    }

    public function test_siempre_queda_alguien_que_administre(): void
    {
        $admin = $this->usuario('administrador');
        $ventanilla = $this->usuario('ventanilla');
        $accesos = app(AdministracionAccesos::class);

        foreach ([
            fn () => $accesos->asignarRoles($admin, [Rol::where('clave', 'ventanilla')->value('id')], $admin),
            fn () => $accesos->activarUsuario($admin, false, $admin),
            fn () => $accesos->guardarPermisos(Rol::where('clave', 'administrador')->first(), [Permiso::TramitesVer], $admin),
            fn () => $accesos->activarRol(Rol::where('clave', 'administrador')->first(), false, $admin),
        ] as $cambio) {
            try {
                $cambio();
                $this->fail('Se permitió dejar el sistema sin administración.');
            } catch (AccionBloqueada $e) {
                $this->assertStringContainsString('nadie podría administrar usuarios', $e->getMessage());
            }
        }
        $this->assertTrue($admin->fresh()->puede(Permiso::AdministrarUsuarios), 'Los cambios se deshicieron.');
        $this->assertTrue($admin->activo, 'El registro en memoria también vuelve a lo guardado.');

        // Con un segundo administrador, el primero sí puede dejar el rol.
        $accesos->asignarRoles($ventanilla, Rol::whereIn('clave', ['ventanilla', 'administrador'])->pluck('id')->all(), $admin);
        $accesos->asignarRoles($admin, [Rol::where('clave', 'tesoreria')->value('id')], $admin);
        $this->assertFalse($admin->fresh()->puede(Permiso::AdministrarUsuarios));

        $this->expectException(AccionBloqueada::class);
        $accesos->activarUsuario($ventanilla, false, $admin); // ya no es administrador
    }

    public function test_crear_administrador_desde_la_terminal(): void
    {
        $this->artisan('panteones:crear-administrador', ['--email' => 'admin@coatepec.gob.mx'])
            ->expectsQuestion('Nombre completo', 'Ana López')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'una-clave-larga-123')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'admin@coatepec.gob.mx')->first()->puede(Permiso::AdministrarUsuarios));
    }

    // ── Diagnóstico ───────────────────────────────────────────────────────────

    public function test_diagnostico_de_un_sistema_recien_instalado(): void
    {
        $diagnostico = app(DiagnosticoConfiguracion::class);
        $textos = $diagnostico->revisar()->map(fn (Pendiente $p) => $p->nivel.': '.$p->motivo)->implode("\n");

        $this->assertFalse($diagnostico->listoParaOperar());
        $this->assertStringContainsString('bloqueante: Nadie puede administrar usuarios', $textos);
        $this->assertStringContainsString('panteones:crear-administrador', $textos);
        $this->assertStringContainsString('bloqueante: No hay ningún panteón registrado', $textos);
        $this->artisan('panteones:diagnostico')->assertFailed();
    }

    public function test_diagnostico_de_un_sistema_listo_con_pendientes_concretos(): void
    {
        $this->usuario('administrador');
        $this->usuario('ventanilla');
        $this->usuario('encargado_panteones', 'secretaria_ayuntamiento');
        $panteon = Panteon::create(['clave' => 'MUN', 'nombre' => 'Panteón Municipal']);
        $seccion = Seccion::create(['panteon_id' => $panteon->id, 'clave' => 'A']);
        Seccion::create(['panteon_id' => $panteon->id, 'clave' => 'B']);
        Cuota::create(['concepto_id' => ConceptoCobro::where('clave', 'titulo_adquisicion')->value('id'), 'seccion_id' => $seccion->id,
            'importe' => 1500, 'acuerdo_cabildo' => 'Acta 1/2026', 'vigente_desde' => '2026-01-01']);
        Firmante::create(['cargo_id' => Cargo::where('clave', 'secretario_ayuntamiento')->value('id'), 'nombre' => 'Persona', 'vigente_desde' => '2026-01-01']);
        $lote = Lote::create(['seccion_id' => $seccion->id, 'numero' => '1']);
        $espacio = Espacio::create(['lote_id' => $lote->id, 'tipo' => TipoEspacio::Fosa, 'numero' => '1']);
        Inhumado::create(['espacio_id' => $espacio->id, 'nombre' => 'Antiguo', 'fecha_inhumacion' => '1980-01-01']);

        $diagnostico = app(DiagnosticoConfiguracion::class);
        $pendientes = $diagnostico->revisar();
        $textos = $pendientes->map(fn (Pendiente $p) => (string) $p->motivo)->implode("\n");

        $this->assertTrue($diagnostico->listoParaOperar());
        $this->assertStringContainsString('Falta la cuota de «Título por adquisición» en 1 sección: Panteón Municipal, sección B', $textos);
        $this->assertStringContainsString('Faltan 10 cuotas del tabulador: «Título por regularización o cambio de propietario», «Inhumación»', $textos);
        $this->assertStringNotContainsString('Renovación', $textos, 'Los conceptos de la fase 2 están inactivos.');
        $this->assertStringContainsString('Falta registrar quién firma como «Presidente Municipal», «Síndico», «Edil del Ramo', $textos);
        $this->assertStringContainsString('«Encargado de Panteones». Los documentos saldrán sin esos nombres.', $textos);
        $this->assertStringNotContainsString('«Secretario del Ayuntamiento»', $textos, 'Ya tiene firmante.');
        $this->assertSame(1, $pendientes->where('area', 'Firmantes')->count(), 'Un solo aviso de firmantes.');
        $this->assertStringContainsString('1 finado no tiene edad al fallecer', $textos);
        $this->assertStringContainsString('Al membrete de los documentos le falta: domicilio, teléfono, escudo', $textos);
        $this->assertStringNotContainsString('Nadie puede', $textos);
        $this->artisan('panteones:diagnostico')->assertSuccessful();
    }

    public function test_diagnostico_detecta_que_las_tareas_automaticas_no_corren(): void
    {
        $this->usuario('administrador');
        $ventanilla = $this->usuario('ventanilla');
        $panteon = Panteon::create(['clave' => 'MUN', 'nombre' => 'Panteón Municipal']);
        $lote = Lote::create(['seccion_id' => Seccion::create(['panteon_id' => $panteon->id, 'clave' => 'A'])->id, 'numero' => '1']);
        $espacio = Espacio::create(['lote_id' => $lote->id, 'tipo' => TipoEspacio::Fosa, 'numero' => '1']);
        $t = app(GestorTramites::class)->capturar(TipoTramite::Adquisicion, $espacio, Persona::create(['nombre' => 'Ana']), ['tiempo_posesion' => '1 año'], $ventanilla);

        Carbon::setTestNow('2027-11-30 10:00:00'); // nadie corrió la caducidad
        $textos = app(DiagnosticoConfiguracion::class)->revisar()->map(fn ($p) => (string) $p->motivo)->implode("\n");

        $this->assertStringContainsString('1 solicitud debió caducar y sigue abierta', $textos);
        $this->assertSame(EstadoTramite::Capturado, $t->fresh()->estado, 'El diagnóstico sólo avisa.');
    }

    // ── Idioma y formato ──────────────────────────────────────────────────────

    public function test_la_validacion_habla_espanol_llano(): void
    {
        try {
            ReglasDatos::validar(TipoTramite::Inhumacion, ['finado' => ['nombre' => '', 'sexo' => 'x', 'fecha_defuncion' => '2099-01-01']]);
            $this->fail('Debió fallar.');
        } catch (ValidationException $e) {
            $mensajes = collect($e->errors())->flatten()->implode("\n");
        }

        $this->assertStringContainsString('Falta el nombre del finado.', $mensajes);
        $this->assertStringContainsString('Elige una opción de la lista en el sexo del finado.', $mensajes);
        $this->assertStringContainsString('La fecha de defunción no puede ser una fecha futura.', $mensajes);
        $this->assertStringContainsString('Falta el número de acta de defunción.', $mensajes);
        $this->assertStringNotContainsString('field', $mensajes);
    }

    public function test_formatos_de_la_guia(): void
    {
        $this->assertSame('3 oct 2026', Formato::fecha(Carbon::parse('2026-10-03')));
        $this->assertSame('$1,500.00', Formato::dinero(1500));
        $this->assertSame('1,284', Formato::numero(1284));
        $this->assertSame('1 año', Formato::cantidad(1, 'año'));
        $this->assertSame('3 secciones', Formato::cantidad(3, 'sección', 'secciones'));
    }

    public function test_la_traduccion_cubre_todas_las_reglas_de_laravel(): void
    {
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $es = require lang_path('es/validation.php');

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($es))), 'Reglas sin traducir.');
    }
}
