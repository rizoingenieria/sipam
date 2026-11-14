<?php

namespace Tests\Feature;

use App\Enums\TipoEspacio;
use App\Enums\TipoTramite;
use App\Filament\Pages\DatosAyuntamiento;
use App\Filament\Resources\Conceptos\Pages\ManageConceptos;
use App\Filament\Resources\Cuotas\Pages\ManageCuotas;
use App\Filament\Resources\Cuotas\Widgets\CuotasFaltantes;
use App\Filament\Resources\Espacios\Pages\ManageEspacios;
use App\Filament\Resources\Panteones\Pages\EditPanteon;
use App\Filament\Resources\Panteones\RelationManagers\SeccionesRelationManager;
use App\Filament\Resources\Parametros\Pages\ManageParametros;
use App\Filament\Resources\Plazos\Pages\ManagePlazos;
use App\Filament\Resources\Plazos\PlazoResource;
use App\Filament\Resources\Regimenes\Pages\ManageRegimenes;
use App\Filament\Resources\Requisitos\Pages\ManageRequisitos;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\EntePublico;
use App\Models\Espacio;
use App\Models\Lote;
use App\Models\Panteon;
use App\Models\Parametro;
use App\Models\Regimen;
use App\Models\ReglaPlazo;
use App\Models\Requisito;
use App\Models\Rol;
use App\Models\Seccion;
use App\Models\User;
use App\Services\Diagnostico\DiagnosticoConfiguracion;
use App\Services\Inventario\GeneradorInventario;
use App\Support\AccionBloqueada;
use Database\Seeders\CatalogosSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Panel: acceso por rol, que cada pantalla abra, y que las acciones de configuración hagan lo
 * que dicen (versiones, deshacer, dejar de aplicar, altas con clave automática, inventario).
 */
class PanelTest extends TestCase
{
    use RefreshDatabase;

    private const PANTALLAS = [
        '/', '/parametros', '/plazos-para-exhumar', '/requisitos', '/tipos-de-titulo', '/parentescos',
        '/cuotas', '/conceptos-de-cobro', '/firmantes', '/cargos', '/datos-del-ayuntamiento',
        '/panteones', '/espacios', '/usuarios', '/roles',
    ];

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

    private function inventario(): Seccion
    {
        $panteon = Panteon::create(['clave' => 'MUN', 'nombre' => 'Panteón Municipal']);

        return Seccion::create(['panteon_id' => $panteon->id, 'clave' => 'A']);
    }

    // ── Acceso ────────────────────────────────────────────────────────────────

    public function test_el_acceso_habla_de_tu_y_sin_sesion_redirige(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()
            ->assertSee('Usuario (tu correo)')->assertSee('Contraseña')->assertSee('Iniciar sesión')
            ->assertSee('images/sipam-logo.png')->assertSee('images/acceso-fondo.webp')
            ->assertSee('Pide a quien administra el sistema')
            ->assertSee('Tu correo de usuario')            // etiqueta para lectores de pantalla
            ->assertDontSee('Mantener la sesión abierta')  // computadora compartida en ventanilla
            ->assertDontSee('Entre a su cuenta');
    }

    public function test_la_marca_es_sipam_con_el_municipio_y_el_nombre_viejo_se_actualiza(): void
    {
        EntePublico::query()->update(['nombre_sistema' => 'Asústame Panteón']);
        (require database_path('migrations/2026_11_13_100001_renombrar_sistema_a_sipam.php'))->up();
        $this->assertSame('SIPAM', EntePublico::actual()->fresh()->nombre_sistema);

        EntePublico::query()->update(['nombre_sistema' => 'Panteones de Xico']);
        (require database_path('migrations/2026_11_13_100001_renombrar_sistema_a_sipam.php'))->up();
        $this->assertSame('Panteones de Xico', EntePublico::actual()->fresh()->nombre_sistema, 'un nombre propio no se pisa');

        $this->actingAs($this->conDosPasos($this->usuario('administrador')))->get('/')->assertOk()->assertSee('images/sipam-logo-corto.png')->assertSee('Ayuntamiento de')->assertSee('Coatepec')
            ->assertDontSee('images/acceso-fondo.webp'); // el fondo es sólo de la pantalla de acceso
    }

    public function test_el_administrador_abre_todas_las_pantallas(): void
    {
        $seccion = $this->inventario();
        $this->actingAs($this->conDosPasos($this->usuario('administrador')));

        foreach ([...self::PANTALLAS, '/panteones/'.$seccion->panteon_id] as $ruta) {
            $this->get($ruta)->assertOk();
        }
    }

    public function test_ventanilla_entra_pero_no_ve_la_configuracion(): void
    {
        $this->actingAs($this->usuario('ventanilla'));

        $this->get('/')->assertOk()->assertDontSee('revisa estos pendientes')->assertDontSee('Antes de operar');
        foreach (['/parametros', '/plazos-para-exhumar', '/cuotas', '/usuarios', '/roles', '/datos-del-ayuntamiento'] as $ruta) {
            $this->get($ruta)->assertForbidden();
        }
    }

    public function test_sin_rol_activo_o_con_cuenta_apagada_no_entra(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertForbidden();

        $apagado = $this->usuario('administrador');
        $apagado->update(['activo' => false]);
        $this->actingAs($apagado)->get('/')->assertForbidden();
    }

    public function test_tesoreria_solo_ve_el_tabulador_de_lo_que_configura(): void
    {
        $this->actingAs($this->usuario('tesoreria'));

        $this->get('/cuotas')->assertOk();
        $this->get('/plazos-para-exhumar')->assertForbidden();
        $this->get('/usuarios')->assertForbidden();
    }

    // ── Parámetros y versiones ───────────────────────────────────────────────

    public function test_cambiar_un_parametro_crea_version_y_valida_el_valor(): void
    {
        $this->actingAs($this->usuario('administrador'));
        $testigos = Parametro::where('clave', 'testigos_requeridos')->abiertas()->first();

        Livewire::test(ManageParametros::class)
            ->callAction(TestAction::make('cambiar')->table($testigos), data: ['valor' => '99', 'vigente_desde' => '2026-10-26'])
            ->assertHasActionErrors(['valor']);
        $this->assertSame(1, Parametro::where('clave', 'testigos_requeridos')->count());

        Livewire::test(ManageParametros::class)
            ->callAction(TestAction::make('cambiar')->table($testigos), data: ['valor' => '3', 'vigente_desde' => '2026-11-01'])
            ->assertHasNoActionErrors();

        $this->assertSame(2, Parametro::entero('testigos_requeridos'));
        $this->assertSame(3, Parametro::entero('testigos_requeridos', Carbon::parse('2026-11-01')));
        $this->assertSame(2, Parametro::where('clave', 'testigos_requeridos')->count());
    }

    public function test_deshacer_el_cambio_de_hoy_reabre_la_version_anterior(): void
    {
        $this->actingAs($this->usuario('administrador'));
        $anterior = Parametro::where('clave', 'testigos_requeridos')->abiertas()->first();
        $nueva = $anterior->nuevaVersion(['valor' => '4'], Carbon::today());

        $this->assertTrue($nueva->puedeDeshacerse());
        Livewire::test(ManageParametros::class)
            ->assertActionVisible(TestAction::make('deshacer')->table($nueva))
            ->callAction(TestAction::make('deshacer')->table($nueva));

        $this->assertNull($anterior->fresh()->vigente_hasta);
        $this->assertSame(1, Parametro::where('clave', 'testigos_requeridos')->count());
        $this->assertSame(2, Parametro::entero('testigos_requeridos'));

        // Una versión que ya empezó antes de hoy no se deshace.
        $this->assertFalse($anterior->fresh()->puedeDeshacerse());
        $this->expectException(LogicException::class);
        $anterior->fresh()->deshacer();
    }

    public function test_una_cuota_usada_no_se_puede_deshacer(): void
    {
        $seccion = $this->inventario();
        $cuota = Cuota::create(['concepto_id' => ConceptoCobro::where('clave', 'titulo_adquisicion')->value('id'),
            'seccion_id' => $seccion->id, 'importe' => 1500, 'acuerdo_cabildo' => 'Acta 1/2026', 'vigente_desde' => '2026-10-26']);
        $this->assertTrue($cuota->puedeDeshacerse());

        $usada = new class extends Cuota
        {
            public function estaEnUso(): bool
            {
                return true;
            }
        };
        $usada->setRawAttributes($cuota->getAttributes(), true);
        $usada->exists = true;
        $this->assertFalse($usada->puedeDeshacerse());
    }

    // ── Tabulador ────────────────────────────────────────────────────────────

    public function test_capturar_cuota_y_la_lista_de_faltantes_se_reduce(): void
    {
        $seccion = $this->inventario();
        $this->actingAs($this->usuario('tesoreria'));
        $diag = app(DiagnosticoConfiguracion::class);
        $antes = count($diag->cuotasFaltantes());
        $this->assertContains('Título por adquisición · Panteón Municipal, sección A', $diag->cuotasFaltantes());

        Livewire::test(ManageCuotas::class)
            ->callAction('create', data: [
                'concepto_id' => ConceptoCobro::where('clave', 'titulo_adquisicion')->value('id'),
                'seccion_id' => $seccion->id, 'importe' => 1800, 'acuerdo_cabildo' => 'Acta 4/2026, punto 2', 'vigente_desde' => '2026-10-26',
            ])
            ->assertHasNoActionErrors()
            ->assertDispatched('cuotas-actualizadas');

        $this->assertCount($antes - 1, $diag->cuotasFaltantes());
        Livewire::test(CuotasFaltantes::class)->assertDontSee('Título por adquisición · Panteón Municipal, sección A');

        // La misma cuota otra vez: se avisa y no se duplica.
        Livewire::test(ManageCuotas::class)
            ->callAction('create', data: [
                'concepto_id' => ConceptoCobro::where('clave', 'titulo_adquisicion')->value('id'),
                'seccion_id' => $seccion->id, 'importe' => 1900, 'acuerdo_cabildo' => 'Acta 5/2026', 'vigente_desde' => '2026-10-26',
            ]);
        $this->assertSame(1, Cuota::count());
    }

    public function test_el_filtro_por_tipo_de_concepto_filtra_de_verdad(): void
    {
        $this->actingAs($this->usuario('tesoreria'));
        $concepto = fn (string $c) => ConceptoCobro::where('clave', $c)->value('id');
        $servicio = Cuota::create(['concepto_id' => $concepto('inhumacion'), 'importe' => 850, 'acuerdo_cabildo' => 'Acta 1', 'vigente_desde' => '2026-01-01']);
        $obra = Cuota::create(['concepto_id' => $concepto('obra_lapida'), 'importe' => 400, 'acuerdo_cabildo' => 'Acta 1', 'vigente_desde' => '2026-01-01']);

        Livewire::test(ManageCuotas::class)
            ->filterTable('categoria', 'obra')
            ->assertCanSeeTableRecords([$obra])
            ->assertCanNotSeeTableRecords([$servicio]);
    }

    // ── Plazos para exhumar ──────────────────────────────────────────────────

    public function test_regla_de_plazo_se_explica_en_palabras(): void
    {
        $menores = ReglaPlazo::where('clave', 'sanitario-menores-15')->abiertas()->first();
        $temporalidad = ReglaPlazo::where('clave', 'temporalidad-minima')->abiertas()->first();

        $this->assertSame('Al menos 5 años', PlazoResource::plazo($menores));
        $this->assertSame('Aplica si falleció con menos de 15 años.', PlazoResource::enPalabras($menores));
        $this->assertSame('Aplica si la fosa tiene título de temporalidad.', PlazoResource::enPalabras($temporalidad));
    }

    public function test_reforma_de_plazo_con_fecha_futura_y_regla_que_deja_de_aplicar(): void
    {
        $this->actingAs($this->usuario('administrador'));
        $regla = ReglaPlazo::where('clave', 'sanitario-15-o-mas')->abiertas()->first();

        Livewire::test(ManagePlazos::class)
            ->callAction(TestAction::make('cambiar')->table($regla), data: [
                'comparacion' => 'al_menos', 'anios_minimos' => 7, 'motivo' => null, 'regimen_clave' => null,
                'edad_minima' => 15, 'edad_maxima' => null, 'solo_panteon_saturado' => false, 'vigente_desde' => '2027-01-01',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(6, ReglaPlazo::where('clave', 'sanitario-15-o-mas')->vigenteEn()->value('anios_minimos'));
        $this->assertSame(7, ReglaPlazo::where('clave', 'sanitario-15-o-mas')->vigenteEn(Carbon::parse('2027-01-01'))->value('anios_minimos'));

        // La tabla muestra una sola fila por regla: la versión más reciente.
        $nueva = ReglaPlazo::where('clave', 'sanitario-15-o-mas')->abiertas()->first();
        Livewire::test(ManagePlazos::class)->assertCanSeeTableRecords([$nueva])->assertCanNotSeeTableRecords([$regla->fresh()]);

        $traslado = ReglaPlazo::where('motivo', 'traslado')->abiertas()->first();
        Livewire::test(ManagePlazos::class)
            ->callAction(TestAction::make('dejarDeAplicar')->table($traslado), data: ['ultimo_dia' => '2026-12-31'])
            ->assertHasNoActionErrors();
        $this->assertSame('2026-12-31', $traslado->fresh()->vigente_hasta->toDateString());

        // Mientras siga aplicando se ve; después, sólo con el filtro de las que ya no aplican.
        Livewire::test(ManagePlazos::class)->assertCanSeeTableRecords([$traslado]);
        Carbon::setTestNow('2027-01-02');
        Livewire::test(ManagePlazos::class)->assertCanNotSeeTableRecords([$traslado])
            ->filterTable('aplican', false)->assertCanSeeTableRecords([$traslado]);
    }

    public function test_nueva_regla_recibe_clave_automatica(): void
    {
        $this->actingAs($this->usuario('administrador'));

        foreach ([1, 2] as $_) {
            Livewire::test(ManagePlazos::class)
                ->callAction('create', data: [
                    'descripcion' => 'Plazo para restos áridos', 'comparacion' => 'al_menos', 'anios_minimos' => 1,
                    'fundamento' => 'Art. 99', 'vigente_desde' => '2026-10-26',
                ])
                ->assertHasNoActionErrors();
        }

        $this->assertEqualsCanonicalizing(['plazo-para-restos-aridos', 'plazo-para-restos-aridos-2'],
            ReglaPlazo::where('fundamento', 'Art. 99')->pluck('clave')->all());
    }

    // ── Requisitos y tipos de título ─────────────────────────────────────────

    public function test_nuevo_requisito_va_al_final_de_su_tramite_y_luego_deja_de_pedirse(): void
    {
        $this->actingAs($this->usuario('administrador'));
        $ultimo = Requisito::where('tipo_tramite', 'inhumacion')->max('orden');

        Livewire::test(ManageRequisitos::class)
            ->callAction('create', data: [
                'tipo_tramite' => 'inhumacion', 'documento' => 'Constancia de no adeudo', 'de_quien' => 'solicitante',
                'cantidad' => 1, 'obligatorio' => true, 'vigente_desde' => '2026-10-26',
            ])
            ->assertHasNoActionErrors();

        $nuevo = Requisito::where('clave', 'constancia_de_no_adeudo')->first();
        $this->assertSame($ultimo + 10, $nuevo->orden);
        $this->assertTrue(Requisito::deTramite(TipoTramite::Inhumacion)->get()->contains('id', $nuevo->id));

        Livewire::test(ManageRequisitos::class)
            ->callAction(TestAction::make('dejarDeAplicar')->table($nuevo), data: ['ultimo_dia' => '2026-10-26'])
            ->assertHasNoActionErrors();
        $this->assertFalse(Requisito::deTramite(TipoTramite::Inhumacion, Carbon::parse('2026-10-27'))->get()->contains('id', $nuevo->id));
    }

    public function test_tipo_de_titulo_cambia_condiciones_con_version(): void
    {
        $this->actingAs($this->usuario('administrador'));
        $temporalidad = Regimen::where('clave', 'temporalidad')->abiertas()->first();

        Livewire::test(ManageRegimenes::class)
            ->assertActionDoesNotExist('create')
            ->callAction(TestAction::make('cambiar')->table($temporalidad), data: [
                'duracion_anios' => 10, 'duracion_maxima_anios' => 30, 'renovable' => true, 'permite_cesion' => false, 'vigente_desde' => '2026-11-01',
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue(Regimen::where('clave', 'temporalidad')->vigenteEn()->value('permite_cesion'));
        $this->assertFalse((bool) Regimen::where('clave', 'temporalidad')->vigenteEn(Carbon::parse('2026-11-01'))->value('permite_cesion'));
    }

    public function test_concepto_nuevo_recibe_clave_y_no_cambia_por_seccion_al_editar(): void
    {
        $this->actingAs($this->usuario('administrador'));

        Livewire::test(ManageConceptos::class)
            ->callAction('create', data: ['nombre' => 'Colocación de jardinera', 'categoria' => 'obra', 'unidad' => 'pieza', 'por_seccion' => false, 'activo' => true])
            ->assertHasNoActionErrors();
        $concepto = ConceptoCobro::where('clave', 'colocacion_de_jardinera')->firstOrFail();

        Livewire::test(ManageConceptos::class)
            ->callAction(TestAction::make('edit')->table($concepto), data: ['nombre' => 'Jardinera', 'por_seccion' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('Jardinera', $concepto->fresh()->nombre);
        $this->assertFalse($concepto->fresh()->por_seccion);
    }

    // ── Inventario ───────────────────────────────────────────────────────────

    public function test_generar_lotes_desde_la_seccion_es_repetible(): void
    {
        $seccion = $this->inventario();
        $this->actingAs($this->usuario('administrador'));

        $prueba = fn () => Livewire::test(SeccionesRelationManager::class, ['ownerRecord' => $seccion->panteon, 'pageClass' => EditPanteon::class])
            ->callAction(TestAction::make('generar')->table($seccion), data: ['desde' => 1, 'hasta' => 10, 'espacios' => 2, 'tipo' => 'fosa'])
            ->assertHasNoActionErrors();
        $prueba();
        $prueba();

        $this->assertSame(10, Lote::count());
        $this->assertSame(20, Espacio::count());
    }

    public function test_el_generador_rechaza_rangos_invalidos(): void
    {
        $seccion = $this->inventario();
        $generador = app(GeneradorInventario::class);

        try {
            $generador->generar($seccion, 5, 1, 0, TipoEspacio::Fosa);
            $this->fail('Debía bloquear');
        } catch (AccionBloqueada $e) {
            $this->assertCount(2, $e->motivos);
        }
        $this->assertSame(0, Lote::count());
    }

    public function test_agregar_un_espacio_crea_el_lote_y_no_duplica(): void
    {
        $seccion = $this->inventario();
        $this->actingAs($this->usuario('administrador'));
        $datos = ['panteon_id' => $seccion->panteon_id, 'seccion_id' => $seccion->id, 'lote_numero' => '120', 'numero' => '1',
            'tipo' => 'nicho', 'habilitado' => true, 'es_fosa_comun' => false, 'es_monumento_historico' => false];

        Livewire::test(ManageEspacios::class)->callAction('create', data: $datos)->assertHasNoActionErrors();
        Livewire::test(ManageEspacios::class)->callAction('create', data: $datos);

        $this->assertSame(1, Espacio::count());
        $espacio = Espacio::first();
        $this->assertSame('MUN-A-120-1', $espacio->clave_completa);
        $this->assertSame(TipoEspacio::Nicho, $espacio->tipo);

        Livewire::test(ManageEspacios::class)->searchTable('120')->assertCanSeeTableRecords([$espacio]);
        Livewire::test(ManageEspacios::class)->searchTable('999')->assertCanNotSeeTableRecords([$espacio]);
    }

    public function test_lotes_en_orden_numerico(): void
    {
        $seccion = $this->inventario();
        app(GeneradorInventario::class)->generar($seccion, 1, 12, 1, TipoEspacio::Fosa);
        $this->actingAs($this->usuario('administrador'));

        $ordenados = Espacio::with('lote.seccion.panteon')->get()->sortBy(fn (Espacio $e) => (int) $e->lote->numero)->values();
        Livewire::test(ManageEspacios::class)->assertCanSeeTableRecords($ordenados, inOrder: true);
    }

    // ── Datos del ayuntamiento ───────────────────────────────────────────────

    public function test_guardar_datos_del_ayuntamiento(): void
    {
        $this->actingAs($this->usuario('administrador'));

        Livewire::test(DatosAyuntamiento::class)
            ->fillForm(['telefono' => '228 816 0000', 'correo' => 'no-es-correo'])
            ->call('guardar')
            ->assertHasFormErrors(['correo']);

        Livewire::test(DatosAyuntamiento::class)
            ->fillForm(['telefono' => '228 816 0000', 'correo' => 'panteones@coatepec.gob.mx'])
            ->call('guardar')
            ->assertHasNoFormErrors();

        $this->assertSame('panteones@coatepec.gob.mx', EntePublico::actual()->correo);
    }
}
