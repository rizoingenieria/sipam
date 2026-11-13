<?php

namespace Tests\Feature;

use App\Enums\EstadoInhumado;
use App\Enums\EstadoTitulo;
use App\Enums\Sexo;
use App\Enums\TipoMovimiento;
use App\Filament\Pages\CargaInicialPage;
use App\Models\Bitacora;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Panteon;
use App\Models\Rol;
use App\Models\Titulo;
use App\Models\User;
use App\Services\CargaInicial\CargaInicial;
use App\Services\CargaInicial\LectorHoja;
use App\Services\CargaInicial\Plantillas;
use App\Services\CargaInicial\TipoCarga;
use App\Services\Diagnostico\DiagnosticoConfiguracion;
use App\Services\Instalacion\Verificacion;
use App\Support\AccionBloqueada;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Puesta en producción: carga inicial desde Excel, respaldos, tareas programadas, verificación del servidor
 * y cabeceras de seguridad.
 */
class ProduccionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('El esquema usa columnas generadas y CHECK de MySQL; configure DB_CONNECTION=mysql.');
        }

        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->seed(CatalogosSeeder::class);
        Panteon::create(['clave' => 'MUN', 'nombre' => 'Panteón Municipal']);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Rol::whereIn('clave', ['administrador'])->pluck('id'));
        $this->actingAs($this->admin);
        $this->tmp = sys_get_temp_dir().'/carga-'.uniqid();
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Excel con el encabezado de la plantilla y los renglones dados. */
    private function excel(TipoCarga $tipo, array $renglones, ?array $encabezado = null): string
    {
        $ruta = $this->tmp.'/'.$tipo->value.uniqid().'.xlsx';
        $w = new Writer;
        $w->openToFile($ruta);
        $w->addRow(Row::fromValues($encabezado ?? array_map(fn ($c) => $c->encabezado, $tipo->columnas())));
        foreach ($renglones as $r) {
            $w->addRow(Row::fromValues($r));
        }
        $w->close();

        return $ruta;
    }

    private function leer(TipoCarga $tipo, string $ruta): array
    {
        return app(LectorHoja::class)->leer($ruta, $tipo);
    }

    private function cargarEspacios(): void
    {
        $filas = $this->leer(TipoCarga::Espacios, $this->excel(TipoCarga::Espacios, [
            ['MUN', 'C', '1', '1', 'fosa', '2.2', '1,1', '', 'sí', '', ''],
            ['MUN', 'C', '1', '2', '', '', '', '', '', '', ''],
            ['MUN', 'C', '2', '1', 'Nicho', '', '', '', 'no', 'no', 'sí'],
        ]));
        $this->assertTrue(app(CargaInicial::class)->cargar(TipoCarga::Espacios, $filas, 'espacios.xlsx')->sinErrores());
    }

    // ── Carga inicial ────────────────────────────────────────────────────────

    public function test_la_plantilla_trae_encabezados_e_instrucciones_y_se_lee_vacia(): void
    {
        $ruta = $this->tmp.'/plantilla.xlsx';
        app(Plantillas::class)->crear(TipoCarga::Titulos, $ruta);

        $this->assertSame([], $this->leer(TipoCarga::Titulos, $ruta));
        $zip = new \ZipArchive;
        $zip->open($ruta);
        $libro = $zip->getFromName('xl/workbook.xml');
        $this->assertStringContainsString('name="Datos"', $libro);
        $this->assertStringContainsString('name="Instrucciones"', $libro);
    }

    public function test_espacios_se_cargan_crean_seccion_y_lote_y_no_se_duplican(): void
    {
        $this->cargarEspacios();

        $this->assertSame(3, Espacio::count());
        $nicho = Espacio::whereHas('lote', fn ($q) => $q->where('numero', '2'))->sole();
        $this->assertSame('MUN-C-2-1', $nicho->clave_completa);
        $this->assertSame('nicho', $nicho->tipo->value);
        $this->assertFalse($nicho->habilitado);
        $this->assertTrue($nicho->es_monumento_historico);
        $this->assertEquals(1.1, (float) Espacio::where('largo_m', 2.2)->sole()->ancho_m);

        // Mismo archivo otra vez: todo «ya existía».
        $this->cargarEspacios();
        $this->assertSame(3, Espacio::count());

        $resumen = Bitacora::where('descripcion', 'Carga inicial: espacios (fosas, nichos, criptas)')->get();
        $this->assertCount(2, $resumen);
        $this->assertSame(0, Bitacora::where('modelo_tipo', Espacio::class)->count());
    }

    public function test_un_error_en_un_renglon_impide_cargar_todo_y_dice_cual(): void
    {
        $filas = $this->leer(TipoCarga::Espacios, $this->excel(TipoCarga::Espacios, [
            ['MUN', 'C', '1', '1', '', '', '', '', '', '', ''],
            ['XYZ', 'C', '1', '2', '', '', '', '', '', '', ''],
            ['MUN', 'C', '1', '1', '', '', '', '', '', '', ''],
            ['MUN', 'C', '1', '3', 'barco', 'abc', '', '', 'quizá', '', ''],
        ]));
        $r = app(CargaInicial::class)->cargar(TipoCarga::Espacios, $filas, 'x.xlsx');

        $this->assertFalse($r->sinErrores());
        $this->assertSame([3, 4, 5], array_keys($r->errores));
        $this->assertStringContainsString('«XYZ»', $r->errores[3][0]);
        $this->assertStringContainsString('renglón 2', $r->errores[4][0]);
        $this->assertCount(3, $r->errores[5]);
        $this->assertSame(0, Espacio::count());
    }

    public function test_titulos_con_titular_copropietarios_y_vencidos(): void
    {
        $this->cargarEspacios();
        $filas = $this->leer(TipoCarga::Titulos, $this->excel(TipoCarga::Titulos, [
            ['MUN', 'C', '1', '1', 'T-100', 'EXP-1', 'perpetuidad', new \DateTimeImmutable('2004-05-10'), 'Juan', 'Pérez', 'García', 'pegj600101hvzrrn01', 'Ana Pérez; Luis Pérez', 'Rosa Luna', ''],
            ['MUN', 'C', '1', '2', 'T-101', '', 'Temporalidad', '10/05/2001', 'Rosa', 'Luna', '', '', '', '', ''],
        ]));
        $c = app(CargaInicial::class);
        $revision = $c->revisar(TipoCarga::Titulos, $filas);
        $this->assertTrue($revision->sinErrores());
        $this->assertStringContainsString('ya venció', $revision->avisos[3][0]);
        $c->cargar(TipoCarga::Titulos, $filas, 't.xlsx');

        $t = Titulo::where('folio', 'T-100')->sole();
        $this->assertSame(EstadoTitulo::Vigente, $t->estado);
        $this->assertSame('2004-05-10', $t->fecha_emision->toDateString());
        $this->assertSame(['Juan Pérez García', 'Ana Pérez', 'Luis Pérez'], $t->titulares()->orderBy('titularidades.orden')->pluck('nombre_completo')->all());
        $this->assertSame('PEGJ600101HVZRRN01', $t->titulares()->first()->curp);
        $this->assertSame(['Rosa Luna'], $t->beneficiarios()->pluck('nombre_completo')->all());
        $this->assertSame(EstadoTitulo::Vencido, Titulo::where('folio', 'T-101')->sole()->estado);

        // Repetir no duplica; otro folio en un espacio con título es error.
        $this->assertSame(2, $c->revisar(TipoCarga::Titulos, $filas)->existentes);
        $otro = $this->leer(TipoCarga::Titulos, $this->excel(TipoCarga::Titulos, [['MUN', 'C', '1', '1', 'T-999', '', '', '01/01/2010', 'X', '', '', '', '', '', '']]));
        $this->assertStringContainsString('ya tiene el título vigente T-100', $c->revisar(TipoCarga::Titulos, $otro)->errores[2][0]);
    }

    public function test_sepultados_quedan_en_el_libro_y_con_plazo_calculable(): void
    {
        $this->cargarEspacios();
        $filas = $this->leer(TipoCarga::Sepultados, $this->excel(TipoCarga::Sepultados, [
            ['MUN', 'C', '1', '1', 'Carmen', 'García', 'Ruiz', 'Mujer', 42039, '03/02/2015', '81', '', '00231', 'Coatepec', 'Infarto', 'sepultado', ''],
            ['MUN', 'C', '1', '2', 'Desconocido', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['MUN', 'C', '2', '1', 'Pedro', '', '', 'h', '06/01/2010', '05/01/2010', '', '01/01/1940', '', '', '', 'restos en sitio', ''],
        ]));
        $r = app(CargaInicial::class)->cargar(TipoCarga::Sepultados, $filas, 's.xlsx');
        $this->assertTrue($r->sinErrores());
        $this->assertSame(3, $r->nuevos);

        $carmen = Inhumado::where('nombre', 'Carmen')->sole();
        $this->assertSame('2015-02-04', $carmen->fecha_inhumacion->toDateString()); // número de serie de Excel
        $this->assertSame(81, $carmen->edad_al_fallecer);
        $this->assertSame('Infarto', $carmen->causa_fallecimiento);
        $this->assertSame(TipoMovimiento::Inhumacion, $carmen->movimientos()->sole()->tipo);
        $this->assertSame(70, Inhumado::where('nombre', 'Pedro')->sole()->edad_al_fallecer); // calculada de las fechas
        $this->assertSame(EstadoInhumado::RestosEnSitio, Inhumado::where('nombre', 'Pedro')->sole()->estado);
        $this->assertTrue(Inhumado::where('nombre', 'Desconocido')->sole()->es_desconocido);
        $this->assertSame(0, Inhumado::where('nombre', 'Desconocido')->sole()->movimientos()->count());
        $this->assertStringNotContainsString('Infarto', Bitacora::all()->toJson());
    }

    public function test_sexo_m_es_ambigua_y_no_se_adivina(): void
    {
        $this->cargarEspacios();
        $filas = $this->leer(TipoCarga::Sepultados, $this->excel(TipoCarga::Sepultados, [
            ['MUN', 'C', '1', '1', 'Ana', '', '', 'Femenino', '', '', '', '', '', '', '', '', ''],
            ['MUN', 'C', '1', '2', 'Luis', '', '', 'masculino', '', '', '', '', '', '', '', '', ''],
            ['MUN', 'C', '2', '1', 'Rosa', '', '', 'M', '', '', '', '', '', '', '', '', ''],
        ]));
        $r = app(CargaInicial::class)->revisar(TipoCarga::Sepultados, $filas);

        $this->assertSame([4], array_keys($r->errores));
        $this->assertStringContainsString('«M» puede significar mujer o masculino', implode(' ', $r->errores[4]));

        unset($filas[4]);
        app(CargaInicial::class)->cargar(TipoCarga::Sepultados, $filas, 's.xlsx');
        $this->assertSame(Sexo::Mujer, Inhumado::where('nombre', 'Ana')->sole()->sexo);
        $this->assertSame(Sexo::Hombre, Inhumado::where('nombre', 'Luis')->sole()->sexo);
    }

    public function test_csv_de_excel_en_espanol_y_columnas_faltantes(): void
    {
        $ruta = $this->tmp.'/e.csv';
        file_put_contents($ruta, "\xEF\xBB\xBFPanteón;Sección;Lote;Espacio\nMUN;D;5;1\n;;;\nMUN;D;5;2\n");
        $filas = app(LectorHoja::class)->leer($ruta, TipoCarga::Espacios);
        $this->assertSame([2, 4], array_keys($filas));
        $this->assertSame('D', $filas[2]['seccion']);

        $this->expectException(AccionBloqueada::class);
        $this->leer(TipoCarga::Titulos, $this->excel(TipoCarga::Titulos, [['x']], ['Panteón', 'Sección']));
    }

    public function test_pantalla_de_carga_revisa_y_luego_carga(): void
    {
        Storage::fake('local');
        $archivo = UploadedFile::fake()->createWithContent('espacios.xlsx', file_get_contents($this->excel(TipoCarga::Espacios, [['MUN', 'C', '9', '1', '', '', '', '', '', '', '']])));

        $pagina = Livewire::test(CargaInicialPage::class)
            ->fillForm(['tipo' => 'espacios', 'archivo' => $archivo])
            ->call('revisar')
            ->assertSet('revision.nuevos', 1)
            ->assertSee('El archivo está listo para cargarse');
        $this->assertSame(0, Espacio::count());

        $pagina->call('cargar')->assertNotified('Carga terminada')->assertSet('revision', null);
        $this->assertSame(1, Espacio::count());

        $this->actingAs(tap(User::factory()->create(), fn ($u) => $u->roles()->attach(Rol::where('clave', 'ventanilla')->value('id'))))
            ->get('/carga-inicial')->assertForbidden();
    }

    // ── Respaldos, cron y verificación ───────────────────────────────────────

    public function test_respaldo_exitoso_borra_los_antiguos_y_anota_en_la_bitacora(): void
    {
        $raiz = $this->tmp.'/respaldos';
        config(['respaldos.ruta' => $raiz, 'respaldos.dias' => 30]);
        File::ensureDirectoryExists($raiz.'/2026-08-01_023000');
        File::ensureDirectoryExists($raiz.'/2026-10-20_023000');
        Process::fake(function (PendingProcess $p) {
            if (str_contains($p->command, 'gzip')) {
                preg_match("/'([^']+)\\.sql'/", $p->command, $m);
                file_put_contents($m[1].'.sql.gz', str_repeat('x', 500));
            }

            return Process::result();
        });

        $this->artisan('panteones:respaldar')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $p) => str_contains($p->command, '--single-transaction') && ! str_contains($p->command, 'secret')
            && ($p->environment['MYSQL_PWD'] ?? null) === (string) config('database.connections.mysql.password'));
        $carpetas = array_map('basename', File::directories($raiz));
        $this->assertNotContains('2026-08-01_023000', $carpetas);
        $this->assertContains('2026-10-20_023000', $carpetas);
        $this->assertContains('2026-10-26_100000', $carpetas);
        $this->assertSame('Respaldo diario', Bitacora::latest('id')->first()->descripcion);
    }

    public function test_respaldo_fallido_no_deja_una_carpeta_que_parezca_buena(): void
    {
        $raiz = $this->tmp.'/respaldos';
        config(['respaldos.ruta' => $raiz]);
        Process::fake(fn () => Process::result(errorOutput: 'mysqldump: Got error: 1045: Access denied', exitCode: 2));

        $this->artisan('panteones:respaldar')->assertFailed();
        $this->assertSame([], File::directories($raiz));
        $this->assertSame('Respaldo FALLIDO de la base de datos', Bitacora::latest('id')->first()->descripcion);
    }

    public function test_tareas_programadas_registradas(): void
    {
        $eventos = collect(app(Schedule::class)->events());
        $this->assertTrue($eventos->contains(fn ($e) => str_contains((string) $e->command, 'panteones:caducar-tramites') && $e->expression === '10 1 * * *'));
        $this->assertTrue($eventos->contains(fn ($e) => str_contains((string) $e->command, 'panteones:respaldar') && $e->expression === '30 2 * * *'));
        $this->assertTrue($eventos->contains(fn ($e) => $e->description === 'latido'));
    }

    public function test_verificacion_detecta_lo_que_falta_y_lo_que_esta_bien(): void
    {
        config(['respaldos.ruta' => $this->tmp.'/respaldos']);
        $estado = fn () => collect(app(Verificacion::class)->revisar())->pluck('estado', 'punto');

        $e = $estado();
        $this->assertSame('falla', $e['Modo de producción']);   // en pruebas, APP_ENV=testing
        $this->assertSame('falla', $e['Tareas programadas (cron)']);
        $this->assertSame('aviso', $e['Respaldo reciente']);
        $this->assertSame('ok', $e['Base de datos']);

        Cache::forever('panteones:latido', now()->subMinutes(3)->toIso8601String());
        File::ensureDirectoryExists($this->tmp.'/respaldos/2026-10-26_023000');
        file_put_contents($this->tmp.'/respaldos/2026-10-26_023000/base.sql.gz', str_repeat('x', 500));
        $e = $estado();
        $this->assertSame('ok', $e['Tareas programadas (cron)']);
        $this->assertSame('ok', $e['Respaldo reciente']);

        // Un respaldo vacío no cuenta.
        File::deleteDirectory($this->tmp.'/respaldos/2026-10-26_023000');
        File::ensureDirectoryExists($this->tmp.'/respaldos/2026-10-26_090000');
        file_put_contents($this->tmp.'/respaldos/2026-10-26_090000/base.sql.gz', 'x');
        $this->assertSame('aviso', $estado()['Respaldo reciente']);
    }

    public function test_cabeceras_de_seguridad(): void
    {
        auth()->logout();
        $this->get('/login')->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_datos_de_demostracion_dejan_el_sistema_listo_y_no_corren_en_produccion(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class); // la segunda vez no duplica

        $this->assertSame(5, User::where('email', 'like', '%@demo.test')->count());
        $this->assertTrue(app(DiagnosticoConfiguracion::class)->listoParaOperar());
        $this->assertSame([], app(DiagnosticoConfiguracion::class)->cuotasFaltantes());

        $this->app['env'] = 'production';
        $this->expectExceptionMessage('no se ejecuta en producción');
        app(DemoSeeder::class)->run();
    }

    public function test_la_hora_es_la_del_centro_de_mexico(): void
    {
        $this->assertSame('America/Mexico_City', config('app.timezone'));
        Carbon::setTestNow(Carbon::parse('2026-10-27 01:30:00', 'UTC'));
        $this->assertSame('2026-10-26', now()->toDateString(), 'Las 19:30 del 26 en Coatepec no son el día 27.');
    }
}
