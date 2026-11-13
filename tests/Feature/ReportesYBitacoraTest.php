<?php

namespace Tests\Feature;

use App\Enums\EstadoInhumado;
use App\Enums\EstadoOrdenPago;
use App\Enums\EstadoTitulo;
use App\Enums\EventoBitacora;
use App\Enums\OrigenTitulo;
use App\Enums\RolTitularidad;
use App\Enums\Sexo;
use App\Enums\TipoEspacio;
use App\Enums\TipoMovimiento;
use App\Filament\Auth\Acceso;
use App\Filament\Pages\Reportes\LibroDeRegistro;
use App\Filament\Pages\Reportes\ReporteIngresos;
use App\Filament\Resources\Bitacora\BitacoraResource;
use App\Filament\Resources\Bitacora\Pages\ListBitacora;
use App\Models\Bitacora;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Lote;
use App\Models\Movimiento;
use App\Models\OrdenPago;
use App\Models\Panteon;
use App\Models\Persona;
use App\Models\Regimen;
use App\Models\Rol;
use App\Models\Seccion;
use App\Models\Titulo;
use App\Models\Tramite;
use App\Models\User;
use App\Services\Inventario\GeneradorInventario;
use App\Services\Reportes\Disponibilidad;
use App\Services\Reportes\GeneradorReportes;
use App\Services\Reportes\Informes;
use App\Services\Reportes\Ingresos;
use App\Services\Reportes\LibroRegistro;
use App\Services\Reportes\Periodo;
use App\Support\AccionBloqueada;
use App\Support\Csv;
use Database\Seeders\CatalogosSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Reportes del reglamento (libro, informes, ingresos) y bitácora de cambios.
 */
class ReportesYBitacoraTest extends TestCase
{
    use RefreshDatabase;

    private User $encargado;

    private Panteon $panteon;

    private Seccion $seccion;

    private Lote $lote;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('El esquema usa columnas generadas y CHECK de MySQL; configure DB_CONNECTION=mysql.');
        }

        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->seed(CatalogosSeeder::class);
        $this->panteon = Panteon::create(['clave' => 'MUN', 'nombre' => 'Panteón Municipal', 'domicilio' => 'Calle Panteón s/n', 'extension_m2' => 18500, 'capacidad' => 3200]);
        $this->seccion = Seccion::create(['panteon_id' => $this->panteon->id, 'clave' => 'A']);
        $this->lote = Lote::create(['seccion_id' => $this->seccion->id, 'numero' => '1']);
        $this->encargado = $this->usuario('encargado_panteones');
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

    private function espacio(bool $habilitado = true): Espacio
    {
        $this->n++;

        return Espacio::create(['lote_id' => $this->lote->id, 'tipo' => TipoEspacio::Fosa, 'numero' => (string) $this->n, 'habilitado' => $habilitado]);
    }

    private function sepultado(Espacio $e, string $cuando, ?string $causa = 'Infarto'): Inhumado
    {
        $i = Inhumado::create(['espacio_id' => $e->id, 'nombre' => 'Pedro', 'primer_apellido' => 'Luna', 'sexo' => Sexo::Hombre, 'edad_al_fallecer' => 74,
            'numero_acta_defuncion' => '01987', 'lugar_fallecimiento' => 'Coatepec', 'causa_fallecimiento' => $causa,
            'fecha_inhumacion' => $cuando, 'estado' => EstadoInhumado::Inhumado]);
        Movimiento::create(['inhumado_id' => $i->id, 'tipo' => TipoMovimiento::Inhumacion, 'fecha_hora' => $cuando.' 11:00:00',
            'espacio_destino_id' => $e->id, 'permiso_folio' => 'RC-1', 'registrado_por' => $this->encargado->id]);

        return $i;
    }

    private function titulo(Espacio $e): Titulo
    {
        $t = Titulo::create(['espacio_id' => $e->id, 'regimen_id' => Regimen::where('clave', 'perpetuidad')->value('id'), 'folio' => 'T-'.$e->id,
            'expediente' => 'X', 'origen' => OrigenTitulo::Legado, 'estado' => EstadoTitulo::Vigente, 'fecha_emision' => '2010-01-01']);
        $t->personas()->attach(Persona::create(['nombre' => 'Juan'])->id, ['rol' => RolTitularidad::Titular->value, 'orden' => 1]);

        return $t;
    }

    /** Orden pagada sin pasar por todo el trámite (lo prueban TramitesTest y TramitesPanelTest). */
    private function pago(float $importe, string $concepto, string $fecha, string $estado = 'pagada'): OrdenPago
    {
        $persona = Persona::create(['nombre' => 'Rosa', 'primer_apellido' => 'Luna']);
        $t = Tramite::create(['folio' => 'INH-'.uniqid(), 'tipo' => 'inhumacion', 'estado' => 'concluido', 'espacio_id' => $this->espacio()->id,
            'solicitante_id' => $persona->id, 'datos' => [], 'capturado_por' => $this->encargado->id]);
        $c = ConceptoCobro::where('clave', $concepto)->first();
        $cuota = Cuota::firstOrCreate(['concepto_id' => $c->id, 'seccion_id' => null], ['importe' => $importe, 'acuerdo_cabildo' => 'Acta 1', 'vigente_desde' => '2026-01-01']);

        return OrdenPago::create(['folio' => 'OP-'.uniqid(), 'tramite_id' => $t->id, 'concepto_id' => $c->id, 'cuota_id' => $cuota->id, 'importe' => $importe,
            'estado' => $estado, 'recibo_folio' => $estado === 'pagada' ? 'REC-'.uniqid() : null, 'pagada_en' => $estado === 'pagada' ? $fecha : null,
            'emitida_por' => $this->encargado->id]);
    }

    // ── Libro de registro ────────────────────────────────────────────────────

    public function test_libro_con_los_datos_del_articulo_21_y_causa_reservada(): void
    {
        $this->sepultado($this->espacio(), '2026-10-05');
        $this->sepultado($this->espacio(), '2026-09-30');

        $octubre = LibroRegistro::consulta(Periodo::de('2026-10-01', '2026-10-31'))->get();
        $this->assertCount(1, $octubre);

        $r = LibroRegistro::renglon($octubre->first(), verCausa: true);
        $this->assertSame('05/10/2026 11:00', $r['fecha']);
        $this->assertSame('Inhumación', $r['movimiento']);
        $this->assertSame('Pedro Luna', $r['nombre']);
        $this->assertSame('74 años', $r['edad']);
        $this->assertSame('01987', $r['acta']);
        $this->assertSame('Infarto', $r['causa_fallecimiento']);
        $this->assertSame('Coatepec', $r['lugar_fallecimiento']);
        $this->assertStringStartsWith('MUN-A-1-', $r['destino']);
        $this->assertSame('Reservada', LibroRegistro::renglon($octubre->first(), verCausa: false)['causa_fallecimiento']);

        $this->assertCount(0, LibroRegistro::consulta(Periodo::de('2026-10-01', '2026-10-31'), TipoMovimiento::Exhumacion)->get());
        $otro = Panteon::create(['clave' => 'SUR', 'nombre' => 'Panteón Sur']);
        $this->assertCount(0, LibroRegistro::consulta(Periodo::de('2026-10-01', '2026-10-31'), null, $otro->id)->get());
        $this->assertCount(1, LibroRegistro::consulta(Periodo::de('2026-10-01', '2026-10-31'), null, $this->panteon->id)->get());
    }

    public function test_pantalla_y_excel_del_libro(): void
    {
        $this->sepultado($this->espacio(), '2026-10-05');
        $septiembre = $this->sepultado($this->espacio(), '2026-09-15');
        $this->actingAs($this->usuario('juridico_contraloria'));

        Livewire::test(LibroDeRegistro::class)
            ->assertCanSeeTableRecords(Movimiento::whereMonth('fecha_hora', 10)->get())
            ->assertCanNotSeeTableRecords($septiembre->movimientos)
            ->set('filtros.desde', '2026-09-01')
            ->assertCanSeeTableRecords($septiembre->movimientos)
            ->callAction('excel')
            ->assertFileDownloaded('libro-de-registro-2026-09-01.csv');

        $this->actingAs($this->usuario('ventanilla'))->get('/reportes/libro-de-registro')->assertForbidden();
    }

    public function test_pdf_del_libro_horizontal_y_con_limite(): void
    {
        $this->sepultado($this->espacio(), '2026-10-05');
        $this->actingAs($this->encargado);

        $this->get('/reportes/pdf/libro?desde=2026-10-01&hasta=2026-10-31')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $doc = app(GeneradorReportes::class)->libro(Periodo::de('2026-10-01', '2026-10-31'));
        $this->assertTrue($doc->horizontal);
        $this->assertStringContainsString('Pedro Luna', $doc->html());
        $this->assertStringContainsString('del 1 de octubre de 2026 al 31 de octubre de 2026', $doc->html());

        DB::table('movimientos')->insert(array_fill(0, LibroRegistro::LIMITE_PDF, [
            'inhumado_id' => Inhumado::value('id'), 'tipo' => 'inhumacion', 'fecha_hora' => '2026-10-06 10:00:00',
            'espacio_destino_id' => Espacio::value('id'), 'por_orden_autoridad' => false, 'registrado_por' => $this->encargado->id,
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->expectException(AccionBloqueada::class);
        app(GeneradorReportes::class)->libro(Periodo::de('2026-10-01', '2026-10-31'));
    }

    // ── Disponibilidad, informes e ingresos ──────────────────────────────────

    public function test_disponibilidad_distingue_ocupados_con_titulo_libres_y_no_disponibles(): void
    {
        $this->sepultado($this->espacio(), '2020-01-01');
        $this->titulo($this->espacio());
        $this->espacio();
        $this->espacio();
        $this->espacio(habilitado: false);

        $s = Disponibilidad::porSeccion()->sole();
        $this->assertSame([5, 1, 1, 2, 1], [$s->total, $s->ocupados, $s->con_titulo_vacios, $s->libres, $s->no_disponibles]);
        $this->assertSame(2, Disponibilidad::porPanteon()->sole()->libres);
    }

    public function test_ingresos_por_fecha_del_recibo_y_pendientes(): void
    {
        $this->pago(850, 'inhumacion', '2026-10-03');
        $this->pago(850, 'inhumacion', '2026-10-20');
        $this->pago(700, 'exhumacion', '2026-10-21');
        $this->pago(700, 'exhumacion', '2026-09-30');
        $this->pago(850, 'inhumacion', '2026-10-21', estado: EstadoOrdenPago::Emitida->value);

        $oct = Periodo::de('2026-10-01', '2026-10-31');
        $r = Ingresos::porConcepto($oct)->keyBy('concepto');
        $this->assertSame(2, $r['Inhumación']->ordenes);
        $this->assertEqualsWithDelta(1700.0, $r['Inhumación']->importe, 0.001);
        $this->assertEqualsWithDelta(700.0, $r['Exhumación']->importe, 0.001);
        $this->assertSame(1, Ingresos::pendientes($oct));

        $this->actingAs($this->usuario('tesoreria'));
        Livewire::test(ReporteIngresos::class)
            ->assertSee('$2,400.00')
            ->callAction('excel')
            ->assertFileDownloaded('ingresos-2026-10-01.csv');
        $this->get('/reportes/pdf/ingresos?desde=2026-10-01&hasta=2026-10-31')->assertOk();
    }

    public function test_informe_mensual_y_anual_a_salud(): void
    {
        $this->sepultado($this->espacio(), '2026-09-10');
        $this->sepultado($this->espacio(), '2026-10-02');
        $this->espacio();
        $this->pago(850, 'inhumacion', '2026-09-11');

        $sep = app(Informes::class)->mensual(2026, 9);
        $this->assertSame(1, $sep['movimientos']->sole()->inhumacion);
        $this->assertSame(1, $sep['ingresos']->sole()->ordenes);

        $anual = app(Informes::class)->anualSalud(2026)['panteones']->sole();
        $this->assertSame('Calle Panteón s/n', $anual->domicilio);
        $this->assertSame(3200, $anual->capacidad);
        $this->assertSame(2, $anual->inhumaciones);
        $this->assertSame(3 + 1, $anual->registrados); // el espacio de la orden de pago de prueba también cuenta
        $this->assertSame(2, $anual->ocupados);

        $this->actingAs($this->encargado);
        $this->get('/reportes/pdf/mensual?anio=2026&mes=9')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/reportes/pdf/salud?anio=2026')->assertOk();
        $this->get('/reportes/pdf/mensual?anio=2026&mes=13')->assertSessionHasErrors('mes');
        foreach (['/reportes/libro-de-registro', '/reportes/informe-mensual', '/reportes/informe-salud', '/reportes/ingresos'] as $ruta) {
            $this->get($ruta)->assertOk();
        }
        $this->actingAs($this->usuario('ventanilla'))->get('/reportes/pdf/salud?anio=2026')->assertForbidden();
    }

    public function test_el_excel_abre_con_acentos_y_no_ejecuta_formulas(): void
    {
        ob_start();
        Csv::descargar('x.csv', ['Nombre', 'Importe'], [['José Núñez', 850.5], ['=HYPERLINK("http://x")', '-1'], [null, true]])->sendContent();
        $csv = ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"José Núñez",850.5', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'-1", $csv);
        $this->assertStringContainsString(',Sí', $csv);
    }

    // ── Bitácora ─────────────────────────────────────────────────────────────

    public function test_bitacora_anota_altas_cambios_y_bajas_con_quien_y_el_antes_y_despues(): void
    {
        $this->actingAs($this->encargado);
        $cuota = Cuota::create(['concepto_id' => ConceptoCobro::where('clave', 'inhumacion')->value('id'), 'importe' => 800, 'acuerdo_cabildo' => 'Acta 1', 'vigente_desde' => '2026-01-01']);
        $cuota->nuevaVersion(['importe' => 950, 'acuerdo_cabildo' => 'Acta 9'], Carbon::parse('2026-11-01'));

        $alta = Bitacora::where('evento', EventoBitacora::Creado->value)->where('modelo_tipo', Cuota::class)->first();
        $this->assertSame($this->encargado->id, $alta->usuario_id);
        $this->assertSame('Cuota: Inhumación', $alta->descripcion);
        $this->assertEquals(800, $alta->cambios['importe']['despues']);

        $cierre = Bitacora::where('evento', EventoBitacora::Modificado->value)->where('modelo_id', $cuota->id)->where('modelo_tipo', Cuota::class)->sole();
        $this->assertNull($cierre->cambios['vigente_hasta']['antes']);
        $this->assertSame('2026-10-31 00:00:00', $cierre->cambios['vigente_hasta']['despues']);
        $this->assertSame(2, Bitacora::where('modelo_tipo', Cuota::class)->where('evento', EventoBitacora::Creado->value)->count());
    }

    public function test_bitacora_no_guarda_contrasenas_ni_datos_sensibles(): void
    {
        $u = User::factory()->create(['password' => 'secreta-123']);
        $u->update(['password' => 'otra-secreta-456', 'name' => 'Nuevo nombre']);
        foreach (Bitacora::where('modelo_tipo', User::class)->get() as $b) {
            $this->assertArrayNotHasKey('password', $b->cambios);
            $this->assertStringNotContainsString('secreta', json_encode($b->cambios));
        }

        $finado = $this->sepultado($this->espacio(), '2020-01-01', causa: 'Diagnóstico confidencial');
        $finado->update(['causa_fallecimiento' => 'Otro diagnóstico confidencial', 'edad_al_fallecer' => 75]);
        $cambio = Bitacora::where('modelo_tipo', Inhumado::class)->where('evento', EventoBitacora::Modificado->value)->sole();
        $this->assertSame('(dato reservado)', $cambio->cambios['causa_fallecimiento']['antes']);
        $this->assertSame('(dato reservado)', $cambio->cambios['causa_fallecimiento']['despues']);
        $this->assertSame(75, $cambio->cambios['edad_al_fallecer']['despues']);
        $this->assertStringNotContainsString('confidencial', Bitacora::all()->toJson());
    }

    public function test_la_bitacora_no_se_edita_ni_se_borra(): void
    {
        $b = Bitacora::create(['evento' => EventoBitacora::Entrada, 'descripcion' => 'x']);
        try {
            $b->update(['descripcion' => 'y']);
            $this->fail('No debía poder editarse.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $b->delete();
    }

    public function test_generar_lotes_deja_un_solo_renglon_de_resumen(): void
    {
        $this->actingAs($this->encargado);
        $antes = Bitacora::count();
        app(GeneradorInventario::class)->generar($this->seccion, 10, 59, 2, TipoEspacio::Fosa);

        $this->assertSame($antes + 1, Bitacora::count());
        $resumen = Bitacora::latest('id')->first();
        $this->assertSame(50, $resumen->cambios['lotes_nuevos']['despues']);
        $this->assertSame(100, $resumen->cambios['espacios_nuevos']['despues']);
    }

    public function test_entradas_y_entradas_fallidas_quedan_anotadas(): void
    {
        $u = User::factory()->create(['email' => 'ana@coatepec.gob.mx', 'password' => Hash::make('clave-buena-123')]);
        $u->roles()->attach(Rol::where('clave', 'administrador')->value('id'));

        Livewire::test(Acceso::class)->fillForm(['email' => 'ana@coatepec.gob.mx', 'password' => 'mala'])->call('authenticate');
        $fallida = Bitacora::where('evento', EventoBitacora::EntradaFallida->value)->sole();
        $this->assertSame('Correo: ana@coatepec.gob.mx', $fallida->descripcion);
        $this->assertStringNotContainsString('mala', json_encode($fallida->toArray()));

        Livewire::test(Acceso::class)->fillForm(['email' => 'ana@coatepec.gob.mx', 'password' => 'clave-buena-123'])->call('authenticate');
        $this->assertSame($u->id, Bitacora::where('evento', EventoBitacora::Entrada->value)->sole()->usuario_id);
    }

    public function test_visor_de_la_bitacora_solo_para_quien_tiene_permiso(): void
    {
        $this->actingAs($this->encargado);
        Panteon::first()->update(['capacidad' => 4000]);
        $cambio = Bitacora::where('modelo_tipo', Panteon::class)->latest('id')->first();

        $this->actingAs($this->usuario('ventanilla'))->get('/bitacora')->assertForbidden();
        $this->actingAs($this->usuario('juridico_contraloria'))->get('/bitacora')->assertOk();
        $this->assertSame('Cambió: capacidad', BitacoraResource::resumen($cambio));

        Livewire::test(ListBitacora::class)
            ->assertCanSeeTableRecords([$cambio])
            ->filterTable('modelo_tipo', Panteon::class)
            ->assertCanSeeTableRecords([$cambio])
            ->mountAction(TestAction::make('ver')->table($cambio))
            ->assertMountedActionModalSee('3200')
            ->assertMountedActionModalSee('4000');
        Livewire::test(ListBitacora::class)->callAction('descargar')->assertFileDownloaded();
    }
}
