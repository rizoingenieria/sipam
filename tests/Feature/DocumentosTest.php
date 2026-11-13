<?php

namespace Tests\Feature;

use App\Enums\EstadoInhumado;
use App\Enums\EstadoTitulo;
use App\Enums\EstadoTramite;
use App\Enums\MotivoExhumacion;
use App\Enums\OrigenTitulo;
use App\Enums\ParteTramite;
use App\Enums\RolTitularidad;
use App\Enums\Sexo;
use App\Enums\TipoEspacio;
use App\Enums\TipoTramite;
use App\Filament\Resources\Titulos\Pages\VerTitulo;
use App\Filament\Resources\Tramites\Pages\VerTramite;
use App\Models\Cargo;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\Espacio;
use App\Models\Firmante;
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
use App\Services\Documentos\DocumentosDelTramite;
use App\Services\Documentos\GeneradorDocumentos;
use App\Services\Tramites\GestorTramites;
use App\Support\AccionBloqueada;
use App\Support\EnLetra;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Documentos oficiales: que digan lo que deben (datos del art. 25, importe con letra, firmantes de su fecha),
 * que lleven sello cuando ya no valen, quién puede abrirlos y que aparezcan en el paso donde se necesitan.
 */
class DocumentosTest extends TestCase
{
    use RefreshDatabase;

    private GestorTramites $gestor;

    private GeneradorDocumentos $docs;

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
        $this->lote = Lote::create(['seccion_id' => $seccion->id, 'numero' => '12']);
        foreach (['inhumacion' => 850, 'exhumacion' => 700, 'traslado' => 300] as $clave => $importe) {
            Cuota::create(['concepto_id' => ConceptoCobro::where('clave', $clave)->value('id'), 'importe' => $importe,
                'acuerdo_cabildo' => 'Acta 2/2026, punto 4', 'vigente_desde' => '2026-01-01']);
        }
        $firmante = fn (string $cargo, string $nombre, string $desde, ?string $hasta = null) => Firmante::create([
            'cargo_id' => Cargo::where('clave', $cargo)->value('id'), 'titulo_profesional' => 'Lic.', 'nombre' => $nombre,
            'vigente_desde' => $desde, 'vigente_hasta' => $hasta]);
        $firmante('encargado_panteones', 'Carlos Ramírez Soto', '2025-01-01');
        $firmante('secretario_ayuntamiento', 'Secretario Anterior', '2008-01-01', '2024-12-31');
        $firmante('secretario_ayuntamiento', 'Secretaria Actual', '2025-01-01');

        $this->gestor = app(GestorTramites::class);
        $this->docs = app(GeneradorDocumentos::class);
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

    private function titulo(Espacio $e, Persona $titular, string $emision = '2010-05-10'): Titulo
    {
        $t = Titulo::create(['espacio_id' => $e->id, 'regimen_id' => Regimen::where('clave', 'perpetuidad')->value('id'),
            'folio' => 'TIT-0457', 'expediente' => 'EXP-88', 'origen' => OrigenTitulo::Legado, 'estado' => EstadoTitulo::Vigente, 'fecha_emision' => $emision]);
        $t->personas()->attach($titular->id, ['rol' => RolTitularidad::Titular->value, 'orden' => 1]);

        return $t;
    }

    private function inhumacion(?Espacio $e = null): Tramite
    {
        $e ??= $this->espacio();

        return $this->gestor->capturar(TipoTramite::Inhumacion, $e, Persona::create(['nombre' => 'Rosa', 'primer_apellido' => 'Luna']), [
            'finado' => ['nombre' => 'Pedro', 'primer_apellido' => 'Luna', 'sexo' => 'hombre', 'fecha_defuncion' => '2026-10-25', 'edad_al_fallecer' => 74,
                'numero_acta_defuncion' => '01987', 'lugar_fallecimiento' => 'Coatepec'],
            'permiso_folio' => 'RC-5521', 'permiso_fecha' => '2026-10-26',
        ], $this->ventanilla, causaFallecimiento: 'Natural', porOrdenAutoridad: false);
    }

    /** Lleva el trámite a «Por pagar» sin pasar por el expediente (lo prueba TramitesPanelTest). */
    private function porPagar(Tramite $t): Tramite
    {
        DB::table('tramites')->where('id', $t->id)->update(['estado' => EstadoTramite::EnRevision->value]);
        $metodo = new \ReflectionMethod(GestorTramites::class, 'emitirOrdenes');
        $metodo->invoke($this->gestor, $t->fresh(), $this->ventanilla);
        DB::table('tramites')->where('id', $t->id)->update(['estado' => EstadoTramite::PorPagar->value]);

        return $t->fresh();
    }

    // ── Con letra ─────────────────────────────────────────────────────────────

    public function test_importes_y_fechas_con_letra(): void
    {
        $this->assertSame('MIL QUINIENTOS PESOS 00/100 M.N.', EnLetra::pesos(1500));
        $this->assertSame('OCHOCIENTOS CINCUENTA PESOS 00/100 M.N.', EnLetra::pesos('850.00'));
        $this->assertSame('UN PESO 00/100 M.N.', EnLetra::pesos(1));
        $this->assertSame('VEINTIÚN PESOS 50/100 M.N.', EnLetra::pesos(21.5));
        $this->assertSame('CIENTO UN MIL PESOS 00/100 M.N.', EnLetra::pesos(101000));
        $this->assertSame('VEINTIÚN MIL CIEN PESOS 00/100 M.N.', EnLetra::pesos(21100));
        $this->assertSame('UN MILLÓN DE PESOS 00/100 M.N.', EnLetra::pesos(1000000));
        $this->assertSame('DOS MILLONES QUINIENTOS MIL PESOS 99/100 M.N.', EnLetra::pesos(2500000.99));
        $this->assertSame('CERO PESOS 00/100 M.N.', EnLetra::pesos(0));
        $this->assertSame('27 de octubre de 2026', EnLetra::fecha(Carbon::parse('2026-10-27')));
    }

    // ── Contenido ─────────────────────────────────────────────────────────────

    public function test_orden_de_pago_con_importe_con_letra_y_sello_cuando_ya_no_vale(): void
    {
        $t = $this->porPagar($this->inhumacion());
        $orden = $t->ordenesPago()->sole();

        $html = $this->docs->ordenPago($orden)->html();
        $this->assertStringContainsString($orden->folio, $html);
        $this->assertStringContainsString('$850.00', $html);
        $this->assertStringContainsString('OCHOCIENTOS CINCUENTA PESOS 00/100 M.N.', $html);
        $this->assertStringContainsString('Tesorería Municipal', $html);
        $this->assertStringContainsString('Acta 2/2026, punto 4', $html);
        $this->assertStringContainsString('Lic. Carlos Ramírez Soto', $html);
        $this->assertStringNotContainsString('class="sello"', $html);

        $this->gestor->registrarPago($orden, 'REC-1', Carbon::today(), $this->ventanilla);
        $this->assertStringContainsString('PAGADA', $this->docs->ordenPago($orden->fresh())->html());

        $otra = $this->porPagar($this->inhumacion())->ordenesPago()->sole();
        $this->gestor->rechazar($otra->tramite, $this->encargado, 'No procede');
        $this->assertStringContainsString('CANCELADA', $this->docs->ordenPago($otra->fresh())->html());
    }

    public function test_el_pdf_sale_completo_en_una_hoja(): void
    {
        $orden = $this->porPagar($this->inhumacion())->ordenesPago()->sole();
        $documento = $this->docs->ordenPago($orden);

        $this->assertStringStartsWith('%PDF-', $documento->pdf()->output());
        $this->assertSame(1, $documento->paginas());
    }

    public function test_responsiva_de_adquisicion_con_las_dos_opciones_para_marcar(): void
    {
        $t = $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), Persona::create(['nombre' => 'Rosa', 'primer_apellido' => 'Luna']),
            ['tiempo_posesion' => 'desde 1998'], $this->ventanilla);
        $fila = $t->requisitos()->whereHas('requisito', fn ($q) => $q->where('clave', 'responsiva_encargado'))->sole();

        $html = $this->docs->paraFirmar($fila)->html();
        $this->assertStringContainsString('Rosa Luna', $html);
        $this->assertStringContainsString('tiempo de posesión: desde 1998', $html);
        $this->assertStringContainsString('No existe', $html);
        $this->assertStringContainsString('Existe', $html);
        $this->assertSame(2, substr_count($html, 'class="casilla"'));
        $this->assertStringContainsString('Art. 33, fr. VII', $html);

        $aviso = $this->docs->notificacion($t)->html();
        $this->assertStringContainsString('AVISO AL PÚBLICO', $aviso);
        $this->assertStringContainsString('mejor derecho', $aviso);
        $this->assertStringContainsString('Lic. Secretaria Actual', $aviso);
    }

    public function test_carta_compromiso_lleva_al_responsable_de_la_obra(): void
    {
        $juan = Persona::create(['nombre' => 'Juan', 'primer_apellido' => 'Pérez']);
        $e = $this->espacio();
        $this->titulo($e, $juan);
        $t = $this->gestor->capturar(TipoTramite::Construccion, $e, $juan, [
            'concepto_clave' => 'obra_monumento', 'descripcion' => 'Monumento de granito', 'materiales' => 'Granito y cemento',
            'tiempo_estimado_dias' => 15, 'largo_m' => 1.8, 'permiso_obras_folio' => 'OP-1',
        ], $this->ventanilla);
        $this->gestor->agregarParticipante($t, Persona::create(['nombre' => 'Mario', 'primer_apellido' => 'Albañil']), ParteTramite::Constructor, $this->ventanilla);
        $fila = $t->requisitos()->whereHas('requisito', fn ($q) => $q->where('clave', 'carta_compromiso'))->sole();

        $html = $this->docs->paraFirmar($fila)->html();
        $this->assertStringContainsString('Mario Albañil', $html);
        $this->assertStringContainsString('largo 1.8 m', $html);
        $this->assertStringContainsString('15 días', $html);
        $this->assertStringContainsString('Vo. Bo. Encargado de Panteones', $html);
    }

    public function test_consentimiento_dice_en_que_caracter_firma(): void
    {
        $juan = Persona::create(['nombre' => 'Juan', 'primer_apellido' => 'Pérez']);
        $e = $this->espacio();
        $this->titulo($e, $juan);
        $t = $this->inhumacion($e);
        $titular = $this->gestor->agregarParticipante($t, $juan, ParteTramite::Autorizante, $this->ventanilla);
        $hija = $this->gestor->agregarParticipante($t, Persona::create(['nombre' => 'Ana']), ParteTramite::Autorizante, $this->ventanilla, Parentesco::where('clave', 'hijo')->first());

        $this->assertStringContainsString('titular del derecho de uso', $this->docs->consentimiento($titular)->html());
        $html = $this->docs->consentimiento($hija)->html();
        $this->assertStringContainsString('hijo o hija de la persona finada', $html);
        $this->assertStringContainsString('inhumación</strong> de <strong>Pedro Luna', $html);

        // Exhumación: también el destino de los restos.
        $f = Inhumado::create(['espacio_id' => $e->id, 'nombre' => 'Carmen', 'sexo' => Sexo::Mujer, 'edad_al_fallecer' => 80,
            'fecha_inhumacion' => '2010-01-01', 'estado' => EstadoInhumado::Inhumado]);
        $exh = $this->gestor->capturar(TipoTramite::Exhumacion, $this->espacioCon($f), $juan, [
            'destino' => ['tipo' => 'traslado', 'destino_externo' => 'Panteón de Xalapa', 'realizado_por' => 'Funeraria San José'],
            'permiso_folio' => 'SS-1', 'permiso_fecha' => '2026-10-20'], $this->ventanilla, $f, MotivoExhumacion::Traslado);
        $p = $this->gestor->agregarParticipante($exh, $juan, ParteTramite::Autorizante, $this->ventanilla);
        $this->assertStringContainsString('Traslado a Panteón de Xalapa', $this->docs->consentimiento($p)->html());

        $testigo = $this->gestor->agregarParticipante($t, Persona::create(['nombre' => 'Otro']), ParteTramite::Testigo, $this->ventanilla, aniosResidencia: 9);
        $this->expectException(AccionBloqueada::class);
        $this->docs->consentimiento($testigo);
    }

    private function espacioCon(Inhumado $f): Espacio
    {
        return Espacio::findOrFail($f->espacio_id);
    }

    public function test_titulo_con_los_datos_del_articulo_25_y_el_secretario_de_su_fecha(): void
    {
        $titulo = $this->titulo($this->espacio(), Persona::create(['nombre' => 'Juan', 'primer_apellido' => 'Pérez']));

        $html = $this->docs->titulo($titulo)->html();
        foreach (['I. Número de folio', 'II. Número de expediente', 'III. Número de fosa', 'IV. Sección', 'V. Lote', 'VI. Panteón',
            'VII. Beneficiarios', 'VIII. Fecha de emisión', 'IX. Firma autógrafa del Secretario'] as $dato) {
            $this->assertStringContainsString($dato, $html);
        }
        $this->assertStringContainsString('TIT-0457', $html);
        $this->assertStringContainsString('EXP-88', $html);
        $this->assertStringContainsString('10 de mayo de 2010', $html);
        $this->assertStringContainsString('Juan Pérez', $html);
        $this->assertStringContainsString('No designados', $html);
        // Firma quien era Secretario cuando se emitió, no el actual.
        $this->assertStringContainsString('Lic. Secretario Anterior', $html);
        $this->assertStringNotContainsString('Secretaria Actual', $html);

        $titulo->update(['estado' => EstadoTitulo::Sustituido]);
        $this->assertStringContainsString('SIN VALIDEZ', $this->docs->titulo($titulo->fresh())->html());
    }

    public function test_resolucion_solo_cuando_se_resolvio(): void
    {
        $t = $this->inhumacion();
        try {
            $this->docs->resolucion($t);
            $this->fail('Sin resolución no hay documento.');
        } catch (AccionBloqueada) {
        }

        $this->gestor->rechazar($t, $this->encargado, 'El acta de defunción no corresponde al finado (art. 36).');
        $html = $this->docs->resolucion($t->fresh())->html();
        $this->assertStringContainsString('RESOLUCIÓN DE IMPROCEDENCIA', $html);
        $this->assertStringContainsString('El acta de defunción no corresponde', $html);
        $this->assertStringContainsString('Código de Procedimientos Administrativos', $html);

        $hecho = $this->inhumacion();
        DB::table('tramites')->where('id', $hecho->id)->update(['estado' => 'concluido', 'resolucion' => 'Procedente.', 'resuelto_en' => now()]);
        $this->assertStringContainsString('AUTORIZACIÓN DE INHUMACIÓN', $this->docs->resolucion($hecho->fresh())->html());
    }

    // ── Acceso ────────────────────────────────────────────────────────────────

    public function test_quien_puede_abrir_cada_documento(): void
    {
        $orden = $this->porPagar($this->inhumacion())->ordenesPago()->sole();
        $titulo = $this->titulo($this->espacio(), Persona::create(['nombre' => 'Juan']));
        $otra = $this->inhumacion();

        $this->get("/documentos/orden-de-pago/{$orden->id}")->assertRedirect('/login');

        $this->actingAs($this->usuario('tesoreria'));
        $this->get("/documentos/orden-de-pago/{$orden->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/documentos/titulo/{$titulo->id}")->assertForbidden();
        $this->get("/documentos/aviso/{$otra->id}")->assertStatus(409);

        $this->actingAs($this->usuario('secretaria_ayuntamiento'));
        $this->get("/documentos/titulo/{$titulo->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    // ── Dónde aparecen ────────────────────────────────────────────────────────

    public function test_que_sigue_ofrece_lo_que_hay_que_imprimir_en_ese_paso(): void
    {
        $this->actingAs($this->ventanilla);
        $t = $this->inhumacion();
        $p = $this->gestor->agregarParticipante($t, Persona::create(['nombre' => 'Ana']), ParteTramite::Autorizante, $this->ventanilla, Parentesco::where('clave', 'hijo')->first());

        $this->assertSame(['Imprimir consentimiento de Ana'], array_column(DocumentosDelTramite::delPaso($t), 'etiqueta'));
        $this->gestor->registrarComparecencia($p, $this->ventanilla);
        $this->assertSame([], DocumentosDelTramite::delPaso($t->fresh()));

        $t = $this->porPagar($t);
        $orden = $t->ordenesPago()->sole();
        Livewire::test(VerTramite::class, ['record' => $t->id])
            ->assertSee("Imprimir orden de pago {$orden->folio}")
            ->assertSee('Imprimir');

        $this->gestor->registrarPago($orden, 'REC-1', Carbon::today(), $this->ventanilla);
        $this->assertSame([], DocumentosDelTramite::delPaso($t->fresh()));
    }

    public function test_el_boton_del_titulo_solo_para_quien_lo_emite(): void
    {
        $titulo = $this->titulo($this->espacio(), Persona::create(['nombre' => 'Juan']));

        $this->actingAs($this->usuario('tesoreria'));
        Livewire::test(VerTitulo::class, ['record' => $titulo->id])->assertActionHidden('imprimir');

        $this->actingAs($this->usuario('secretaria_ayuntamiento'));
        Livewire::test(VerTitulo::class, ['record' => $titulo->id])->assertActionVisible('imprimir');
    }
}
