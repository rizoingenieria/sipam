<?php

namespace Tests\Feature;

use App\Enums\EstadoInhumado;
use App\Enums\EstadoOrdenPago;
use App\Enums\EstadoRequisitoEntregado;
use App\Enums\EstadoTitulo;
use App\Enums\EstadoTramite;
use App\Enums\MotivoExhumacion;
use App\Enums\OrigenTitulo;
use App\Enums\ParteTramite;
use App\Enums\Permiso;
use App\Enums\RolTitularidad;
use App\Enums\Sexo;
use App\Enums\TipoArchivo;
use App\Enums\TipoEspacio;
use App\Enums\TipoTramite;
use App\Filament\Resources\Finados\Pages\VerFinado;
use App\Filament\Resources\Tramites\Pages\CapturarTramite;
use App\Filament\Resources\Tramites\Pages\ListTramites;
use App\Filament\Resources\Tramites\Pages\VerTramite;
use App\Filament\Resources\Tramites\RelationManagers\ParticipantesRelationManager;
use App\Filament\Resources\Tramites\RelationManagers\RequisitosRelationManager;
use App\Filament\Support\CamposEspacio;
use App\Filament\Widgets\BandejaTramites;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Lote;
use App\Models\Panteon;
use App\Models\Parentesco;
use App\Models\Persona;
use App\Models\Regimen;
use App\Models\Rol;
use App\Models\Seccion;
use App\Models\Titulo;
use App\Models\Tramite;
use App\Models\User;
use App\Services\Tramites\GestorTramites;
use App\Services\Tramites\GuiaTramite;
use App\Support\AccionBloqueada;
use Database\Seeders\CatalogosSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pantallas de trámites: captura guiada, expediente con «Qué sigue», pestañas, finados y títulos.
 * Recorre un trámite completo sólo con lo que una persona hace en pantalla.
 */
class TramitesPanelTest extends TestCase
{
    use RefreshDatabase;

    private GestorTramites $gestor;

    private User $ventanilla;

    private User $encargado;

    private Lote $lote;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('El esquema usa columnas generadas y CHECK de MySQL; configure DB_CONNECTION=mysql.');
        }

        Carbon::setTestNow('2026-10-26 10:00:00');
        Storage::fake('local');
        $this->seed(CatalogosSeeder::class);

        $panteon = Panteon::create(['clave' => 'MUN', 'nombre' => 'Panteón Municipal']);
        $seccion = Seccion::create(['panteon_id' => $panteon->id, 'clave' => 'A']);
        $this->lote = Lote::create(['seccion_id' => $seccion->id, 'numero' => '1']);
        foreach (['inhumacion' => 800, 'exhumacion' => 700, 'traslado' => 300] as $clave => $importe) {
            Cuota::create(['concepto_id' => ConceptoCobro::where('clave', $clave)->value('id'), 'importe' => $importe,
                'acuerdo_cabildo' => 'Acta 1/2026', 'vigente_desde' => '2026-01-01']);
        }
        Cuota::create(['concepto_id' => ConceptoCobro::where('clave', 'titulo_adquisicion')->value('id'), 'seccion_id' => $seccion->id,
            'importe' => 1500, 'acuerdo_cabildo' => 'Acta 1/2026', 'vigente_desde' => '2026-01-01']);

        $this->gestor = app(GestorTramites::class);
        $this->ventanilla = $this->usuario('ventanilla');
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

    private function espacio(): Espacio
    {
        $this->n++;

        return Espacio::create(['lote_id' => $this->lote->id, 'tipo' => TipoEspacio::Fosa, 'numero' => (string) $this->n]);
    }

    private function conTitulo(Espacio $e, Persona $titular): Titulo
    {
        $t = Titulo::create(['espacio_id' => $e->id, 'regimen_id' => Regimen::where('clave', 'perpetuidad')->value('id'),
            'folio' => 'LEG-'.$e->id, 'expediente' => 'LEG', 'origen' => OrigenTitulo::Legado, 'estado' => EstadoTitulo::Vigente, 'fecha_emision' => '2010-01-01']);
        $t->personas()->attach($titular->id, ['rol' => RolTitularidad::Titular->value, 'orden' => 1]);

        return $t;
    }

    private function sepultar(Espacio $e, string $desde = '2015-01-01'): Inhumado
    {
        return Inhumado::create(['espacio_id' => $e->id, 'nombre' => 'Carmen', 'primer_apellido' => 'García', 'sexo' => Sexo::Mujer,
            'fecha_defuncion' => $desde, 'edad_al_fallecer' => 80, 'fecha_inhumacion' => $desde, 'estado' => EstadoInhumado::Inhumado]);
    }

    private function datosInhumacion(Espacio $e, Persona $solicitante): array
    {
        return [
            'tipo' => 'inhumacion', 'espacio_id' => $e->id, 'solicitante_id' => $solicitante->id, 'por_orden_autoridad' => false,
            'datos' => [
                'finado' => ['es_desconocido' => false, 'nombre' => 'Pedro', 'primer_apellido' => 'Luna', 'segundo_apellido' => null, 'sexo' => 'hombre',
                    'fecha_defuncion' => '2026-10-25', 'fecha_nacimiento' => null, 'edad_al_fallecer' => 74, 'numero_acta_defuncion' => '01987', 'lugar_fallecimiento' => 'Coatepec'],
                'permiso_folio' => 'RC-1', 'permiso_fecha' => '2026-10-26',
            ],
            'causa_fallecimiento' => 'Natural',
        ];
    }

    private function expediente(Tramite $t)
    {
        return Livewire::test(VerTramite::class, ['record' => $t->getRouteKey()]);
    }

    private function siguiente(string $accion): TestAction
    {
        return TestAction::make($accion)->schemaComponent('siguiente', schema: 'infolist');
    }

    // ── Captura guiada ───────────────────────────────────────────────────────

    public function test_captura_guiada_crea_el_tramite_y_abre_su_expediente(): void
    {
        $this->actingAs($this->ventanilla);
        $rosa = Persona::create(['nombre' => 'Rosa', 'primer_apellido' => 'Luna']);
        $e = $this->espacio();

        Livewire::test(CapturarTramite::class)
            ->fillForm($this->datosInhumacion($e, $rosa))
            ->call('guardar')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $t = Tramite::sole();
        $this->assertSame(TipoTramite::Inhumacion, $t->tipo);
        $this->assertSame('Pedro', $t->dato('finado.nombre'));
        $this->assertSame('Natural', $t->causa_fallecimiento);
        $this->assertNotEmpty($t->folio);
        $this->assertGreaterThan(0, $t->requisitos()->count());
    }

    public function test_captura_exige_los_datos_del_tramite_campo_por_campo(): void
    {
        $this->actingAs($this->ventanilla);
        $datos = $this->datosInhumacion($this->espacio(), Persona::create(['nombre' => 'Rosa']));
        $datos['datos']['finado']['numero_acta_defuncion'] = null;
        $datos['datos']['finado']['edad_al_fallecer'] = null;

        Livewire::test(CapturarTramite::class)->fillForm($datos)->call('guardar')
            ->assertHasFormErrors(['datos.finado.numero_acta_defuncion', 'datos.finado.edad_al_fallecer']);
        $this->assertSame(0, Tramite::count());
    }

    public function test_captura_bloqueada_por_candado_no_guarda_nada(): void
    {
        $this->actingAs($this->ventanilla);
        $juan = Persona::create(['nombre' => 'Juan']);
        $e = $this->espacio();
        $this->conTitulo($e, $juan);

        Livewire::test(CapturarTramite::class)
            ->fillForm(['tipo' => 'adquisicion', 'espacio_id' => $e->id, 'solicitante_id' => $juan->id, 'datos' => ['tiempo_posesion' => '20 años', 'regimen_clave' => 'perpetuidad']])
            ->call('guardar')
            ->assertNotified()
            ->assertNoRedirect();
        $this->assertSame(0, Tramite::count());
    }

    public function test_el_asistente_avisa_del_candado_al_salir_del_paso_del_espacio(): void
    {
        $this->actingAs($this->ventanilla);
        $juan = Persona::create(['nombre' => 'Juan']);
        $conTitulo = $this->espacio();
        $this->conTitulo($conTitulo, $juan);

        // Adquisición de un espacio que ya tiene título: se detiene en el paso 2, antes de llenar lo demás.
        Livewire::test(CapturarTramite::class)
            ->fillForm(['tipo' => 'adquisicion', 'espacio_id' => $conTitulo->id])
            ->goToWizardStep(3)
            ->assertNotified('Todavía no se puede');

        // Exhumación antes de que pase el plazo: también se detiene ahí, con la fecha en que sí se podrá.
        $reciente = $this->espacio();
        $finado = $this->sepultar($reciente, '2024-03-01');
        Livewire::test(CapturarTramite::class)
            ->fillForm(['tipo' => 'exhumacion', 'espacio_id' => $reciente->id, 'inhumado_id' => $finado->id, 'motivo_exhumacion' => MotivoExhumacion::Traslado->value])
            ->assertSee('Todavía no se puede exhumar')
            ->goToWizardStep(3)
            ->assertNotified('Todavía no se puede');

        // Un espacio libre pasa sin aviso.
        Livewire::test(CapturarTramite::class)
            ->fillForm(['tipo' => 'adquisicion', 'espacio_id' => $this->espacio()->id])
            ->goToWizardStep(3)
            ->assertNotNotified();
    }

    public function test_atajo_desde_un_finado_llega_con_el_espacio_elegido(): void
    {
        $this->actingAs($this->ventanilla);
        $e = $this->espacio();
        $finado = $this->sepultar($e);

        Livewire::withQueryParams(['tipo' => 'exhumacion', 'inhumado' => $finado->id])
            ->test(CapturarTramite::class)
            ->assertSet('data.tipo', 'exhumacion')
            ->assertSet('data.espacio_id', $e->id)
            ->assertSet('data.inhumado_id', $finado->id)
            ->assertSet('pasoInicial', 2);
    }

    public function test_quien_no_captura_no_entra_a_la_captura(): void
    {
        $this->actingAs($this->usuario('edil_ramo'))->get('/tramites/nuevo')->assertForbidden();
        $this->actingAs($this->usuario('edil_ramo'))->get('/tramites')->assertOk();
    }

    public function test_el_buscador_de_espacios_encuentra_por_titular_y_por_sepultado(): void
    {
        $juan = Persona::create(['nombre' => 'Juan', 'primer_apellido' => 'Pérez']);
        $conTitulo = $this->espacio();
        $this->conTitulo($conTitulo, $juan);
        $conFinado = $this->espacio();
        $this->sepultar($conFinado);

        $this->assertArrayHasKey($conTitulo->id, CamposEspacio::buscar('Juan Pérez'));
        $this->assertArrayHasKey($conFinado->id, CamposEspacio::buscar('Carmen'));
        $this->assertArrayHasKey($conFinado->id, CamposEspacio::buscar('MUN-A-1-'.$conFinado->numero));
        $this->assertStringContainsString('título de Juan Pérez', CamposEspacio::buscar('Juan')[$conTitulo->id]);
    }

    // ── Expediente: un trámite completo desde la pantalla ────────────────────

    public function test_inhumacion_completa_desde_el_expediente(): void
    {
        $rosa = Persona::create(['nombre' => 'Rosa', 'primer_apellido' => 'Luna']);
        $e = $this->espacio();
        $this->actingAs($this->ventanilla);
        $t = $this->gestor->capturar(TipoTramite::Inhumacion, $e, $rosa, $this->datosInhumacion($e, $rosa)['datos'], $this->ventanilla, causaFallecimiento: 'Natural');

        // Con pendientes, el botón del paso está a la vista pero desactivado, y la lista dice qué falta.
        $this->expediente($t)
            ->assertSee('Completar el expediente')
            ->assertSee('Falta el cotejo')
            ->assertActionVisible($this->siguiente('cerrarRevision'))
            ->assertActionDisabled($this->siguiente('cerrarRevision'));

        // Documentos: se recibe cada uno desde su pestaña (de cada grupo alternativo, uno).
        $grupos = [];
        foreach ($t->requisitos()->with('requisito')->get() as $fila) {
            $r = $fila->requisito;
            if ($r->emitido_por_sistema || ($r->grupo_alternativa && isset($grupos[$r->grupo_alternativa]))) {
                continue;
            }
            $grupos[$r->grupo_alternativa ?? 'x'.$fila->id] = true;
            Livewire::test(RequisitosRelationManager::class, ['ownerRecord' => $t, 'pageClass' => VerTramite::class])
                ->callAction(TestAction::make('recibir')->table($fila), data: [
                    'fecha_expedicion' => $r->vigencia_maxima_dias ? '2026-10-20' : null,
                    'folio' => "F-{$fila->id}",
                ])
                ->assertHasNoActionErrors()
                ->assertDispatched('expediente-actualizado');
        }

        // Personas: el titular no existe (fosa sin título), autoriza un familiar que viene a firmar.
        Livewire::test(ParticipantesRelationManager::class, ['ownerRecord' => $t, 'pageClass' => VerTramite::class])
            ->callAction(TestAction::make('agregar')->table(), data: ['papel' => ParteTramite::Autorizante->value, 'persona_id' => $rosa->id,
                'parentesco_id' => Parentesco::where('clave', 'hijo')->value('id')])
            ->assertHasNoActionErrors();
        $autorizante = $t->participantes()->sole();
        Livewire::test(ParticipantesRelationManager::class, ['ownerRecord' => $t, 'pageClass' => VerTramite::class])
            ->callAction(TestAction::make('comparecio')->table($autorizante));
        $this->assertTrue($autorizante->fresh()->comparecio());

        // Cotejo y cierre de la revisión desde «Qué sigue»: pasa de «Capturado» a «Por pagar» de un solo paso.
        $this->expediente($t)->callAction($this->siguiente('cotejo'), data: ['observaciones' => 'Todo en orden']);
        $this->expediente($t)
            ->assertActionEnabled($this->siguiente('cerrarRevision'))
            ->callAction($this->siguiente('cerrarRevision'));
        $t->refresh();
        $this->assertSame(EstadoTramite::PorPagar, $t->estado);
        $this->assertSame(1, $t->ordenesPago()->count());

        // Pago: ventanilla captura el recibo desde «Qué sigue».
        $orden = $t->ordenesPago()->sole();
        $this->expediente($t)
            ->assertSee($orden->folio)
            ->callAction($this->siguiente('registrarPago'), data: ['orden_id' => $orden->id, 'recibo_folio' => 'REC-9', 'fecha' => '2026-10-26']);
        $this->assertSame(EstadoOrdenPago::Pagada, $orden->fresh()->estado);
        $this->assertSame(EstadoTramite::PorAutorizar, $t->fresh()->estado);

        // Ventanilla no autoriza inhumaciones: no ve el botón y la pantalla dice quién lo hace.
        $this->expediente($t)
            ->assertActionHidden($this->siguiente('concluir'))
            ->assertSee('Este paso lo da:')
            ->assertSee('Encargado de Panteones');

        // El encargado autoriza y la inhumación queda en el libro.
        $this->actingAs($this->encargado);
        $this->expediente($t)->callAction($this->siguiente('concluir'), data: ['resolucion' => 'Procedente.']);
        $this->assertSame(EstadoTramite::Concluido, $t->fresh()->estado);
        $this->assertSame('Pedro', Inhumado::where('espacio_id', $e->id)->sole()->nombre);
        $this->expediente($t->fresh())->assertSee('Trámite concluido');
    }

    public function test_quien_capturo_no_puede_autorizar_y_se_le_explica(): void
    {
        $this->actingAs($this->encargado);
        $e = $this->espacio();
        $t = $this->gestor->capturar(TipoTramite::Inhumacion, $e, Persona::create(['nombre' => 'Ana']),
            $this->datosInhumacion($e, Persona::create(['nombre' => 'X']))['datos'], $this->encargado, causaFallecimiento: 'Natural', porOrdenAutoridad: true);
        DB::table('tramites')->where('id', $t->id)->update(['estado' => EstadoTramite::PorAutorizar->value]);

        $this->expediente($t->fresh())->callAction($this->siguiente('concluir'), data: ['resolucion' => 'Procedente.'])
            ->assertNotified('Todavía no se puede');
        $this->assertSame(EstadoTramite::PorAutorizar, $t->fresh()->estado);
    }

    public function test_rechazar_pide_motivo_y_cierra_el_tramite(): void
    {
        $this->actingAs($this->encargado);
        $e = $this->espacio();
        $t = $this->gestor->capturar(TipoTramite::Inhumacion, $e, Persona::create(['nombre' => 'Ana']),
            $this->datosInhumacion($e, Persona::create(['nombre' => 'X']))['datos'], $this->ventanilla, causaFallecimiento: 'Natural');

        $this->expediente($t)->callAction('rechazar', data: ['motivo' => ''])->assertHasActionErrors(['motivo']);
        $this->expediente($t)->callAction('rechazar', data: ['motivo' => 'El acta no corresponde (art. 36).']);
        $this->assertSame(EstadoTramite::Rechazado, $t->fresh()->estado);
        $this->expediente($t->fresh())->assertSee('El acta no corresponde')->assertActionHidden('rechazar');
    }

    public function test_corregir_recepciones_y_personas_mientras_se_integra(): void
    {
        $this->actingAs($this->ventanilla);
        $e = $this->espacio();
        $rosa = Persona::create(['nombre' => 'Rosa']);
        $t = $this->gestor->capturar(TipoTramite::Inhumacion, $e, $rosa, $this->datosInhumacion($e, $rosa)['datos'], $this->ventanilla, causaFallecimiento: 'Natural');

        // El comprobante de pago de derechos (adquisición) marca y, al devolverse, desmarca que está al corriente.
        $adq = $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), $rosa, ['tiempo_posesion' => '20 años'], $this->ventanilla);
        $fila = $adq->requisitos()->whereHas('requisito', fn ($q) => $q->where('activa_pago_derechos', true))->firstOrFail();
        $this->gestor->entregarRequisito($fila, $this->ventanilla, Carbon::today(), 'TES-1');
        $this->assertTrue((bool) $adq->fresh()->pago_derechos_al_corriente);

        Livewire::test(RequisitosRelationManager::class, ['ownerRecord' => $adq, 'pageClass' => VerTramite::class])
            ->callAction(TestAction::make('devolver')->table($fila->fresh()));
        $fila->refresh();
        $this->assertSame(EstadoRequisitoEntregado::Pendiente, $fila->estado);
        $this->assertNull($fila->folio_documento);
        $this->assertFalse((bool) $adq->fresh()->pago_derechos_al_corriente);

        $p = $this->gestor->agregarParticipante($t, $rosa, ParteTramite::Autorizante, $this->ventanilla);
        $otro = $this->gestor->agregarParticipante($t, Persona::create(['nombre' => 'Luis']), ParteTramite::Autorizante, $this->ventanilla);
        Livewire::test(ParticipantesRelationManager::class, ['ownerRecord' => $t, 'pageClass' => VerTramite::class])
            ->callAction(TestAction::make('quitar')->table($p));
        $this->assertSame(1, $t->participantes()->count());
        $p = $otro;

        // Con el expediente cerrado, ya no se corrige.
        $this->gestor->rechazar($t, $this->encargado, 'Prueba');
        $this->expectException(AccionBloqueada::class);
        $this->gestor->quitarParticipante($p, $this->ventanilla);
    }

    // ── Archivos ─────────────────────────────────────────────────────────────

    public function test_las_evidencias_solo_se_ven_con_sesion_y_permiso(): void
    {
        $e = $this->espacio();
        $t = $this->gestor->capturar(TipoTramite::Inhumacion, $e, Persona::create(['nombre' => 'Ana']),
            $this->datosInhumacion($e, Persona::create(['nombre' => 'X']))['datos'], $this->ventanilla, causaFallecimiento: 'Natural');
        $foto = UploadedFile::fake()->createWithContent('acta.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\x00", 64)."\xFF\xD9");
        $archivo = $this->gestor->adjuntarArchivo($t, $foto, TipoArchivo::Otro, $this->ventanilla);

        $this->get("/archivos/{$archivo->id}")->assertRedirect('/login');
        $this->actingAs($this->usuario('administrador'))->get("/archivos/{$archivo->id}")->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');

        $sinPermiso = $this->usuario('ventanilla');
        Rol::where('clave', 'ventanilla')->first()->update(['permisos' => [Permiso::TramitesCapturar->value]]);
        $this->actingAs($sinPermiso->fresh())->get("/archivos/{$archivo->id}")->assertForbidden();
    }

    // ── Guía, bandeja, listados, finados y títulos ───────────────────────────

    public function test_la_guia_dice_que_sigue_en_cada_estado(): void
    {
        $this->assertSame('Completar el expediente y publicar', GuiaTramite::siguiente(EstadoTramite::Capturado, TipoTramite::Adquisicion));
        $this->assertSame('Registrar el recibo de pago', GuiaTramite::siguiente(EstadoTramite::PorPagar, TipoTramite::Inhumacion));
        $this->assertSame('Encargado de Panteones', GuiaTramite::quienPuede(Permiso::AutorizarInhumacion));
        $this->assertStringContainsString(' o ', GuiaTramite::quienPuede(Permiso::TramitesPublicar));

        Rol::query()->update(['permisos' => []]);
        $this->assertStringStartsWith('Ningún rol', GuiaTramite::quienPuede(Permiso::TramitesPublicar));
    }

    public function test_bandeja_muestra_a_cada_quien_lo_suyo(): void
    {
        $e = $this->espacio();
        $t = $this->gestor->capturar(TipoTramite::Inhumacion, $e, Persona::create(['nombre' => 'Ana']),
            $this->datosInhumacion($e, Persona::create(['nombre' => 'X']))['datos'], $this->ventanilla, causaFallecimiento: 'Natural');
        DB::table('tramites')->where('id', $t->id)->update(['estado' => EstadoTramite::PorAutorizar->value]);

        $this->actingAs($this->encargado);
        Livewire::test(BandejaTramites::class)->assertSee('Por autorizar')->assertSee('Esperan tu autorización');

        $this->actingAs($this->usuario('tesoreria'));
        Livewire::test(BandejaTramites::class)->assertSee('Por pagar')->assertDontSee('Por autorizar')->assertDontSee('Nuevo trámite');

        $this->actingAs($this->usuario('edil_ramo'));
        Livewire::test(BandejaTramites::class)->assertSee('Tu usuario sólo consulta');
    }

    public function test_listado_por_etapas_y_busqueda_por_espacio(): void
    {
        $this->actingAs($this->ventanilla);
        $e1 = $this->espacio();
        $e2 = $this->espacio();
        $a = $this->gestor->capturar(TipoTramite::Inhumacion, $e1, Persona::create(['nombre' => 'Ana']), $this->datosInhumacion($e1, Persona::create(['nombre' => 'X']))['datos'], $this->ventanilla, causaFallecimiento: 'N');
        $b = $this->gestor->capturar(TipoTramite::Inhumacion, $e2, Persona::create(['nombre' => 'Beto']), $this->datosInhumacion($e2, Persona::create(['nombre' => 'Y']))['datos'], $this->ventanilla, causaFallecimiento: 'N');
        DB::table('tramites')->where('id', $b->id)->update(['estado' => EstadoTramite::PorPagar->value]);

        Livewire::test(ListTramites::class)->set('activeTab', 'por_pagar')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListTramites::class)->set('activeTab', 'revision')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        Livewire::test(ListTramites::class)->set('activeTab', 'todos')->searchTable($e2->clave_completa)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListTramites::class)->set('activeTab', 'todos')->searchTable('Ana')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    }

    public function test_ficha_del_finado_explica_los_plazos_y_oculta_la_causa(): void
    {
        $juan = Persona::create(['nombre' => 'Juan']);
        $e = $this->espacio();
        $this->conTitulo($e, $juan);
        $reciente = $this->sepultar($e, '2024-03-01');
        $reciente->update(['causa_fallecimiento' => 'Dato reservado']);

        $this->actingAs($this->usuario('tesoreria'));
        Livewire::test(VerFinado::class, ['record' => $reciente->id])
            ->assertSee('A partir del 1 mar 2030')               // 6 años, 15 o más (sanitario)
            ->assertSee('No aplica: el título es a perpetuidad')
            ->assertSee('Reservada')
            ->assertDontSee('Dato reservado')
            ->assertActionHidden('corregir');

        $this->actingAs($this->ventanilla);
        Livewire::test(VerFinado::class, ['record' => $reciente->id])
            ->assertSee('Dato reservado')
            ->assertActionVisible('exhumar')
            ->callAction('corregir', data: ['nombre' => 'Carmen', 'sexo' => 'mujer', 'edad_al_fallecer' => 12, 'observaciones' => 'Según acta'])
            ->assertHasNoActionErrors();
        $this->assertSame(12, $reciente->fresh()->edad_al_fallecer);
        Livewire::test(VerFinado::class, ['record' => $reciente->id])->assertSee('A partir del 1 mar 2029'); // menor de 15: 5 años
    }

    public function test_pantallas_de_registros_abren_para_quien_consulta(): void
    {
        $juan = Persona::create(['nombre' => 'Juan', 'primer_apellido' => 'Pérez']);
        $e = $this->espacio();
        $titulo = $this->conTitulo($e, $juan);
        $finado = $this->sepultar($e);
        $consulta = $this->usuario('obras_publicas');

        foreach (['/finados', "/finados/{$finado->id}", '/titulos', "/titulos/{$titulo->id}", '/tramites', '/'] as $ruta) {
            $this->actingAs($consulta)->get($ruta)->assertOk();
        }
        $this->actingAs($consulta)->get("/titulos/{$titulo->id}")->assertSee('Juan Pérez')->assertSee('Carmen')->assertDontSee('Iniciar un trámite');
        $this->actingAs($this->ventanilla)->get("/titulos/{$titulo->id}")->assertSee('Iniciar un trámite');
    }
}
