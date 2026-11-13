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
use App\Enums\RolTitularidad;
use App\Enums\TipoArchivo;
use App\Enums\TipoEspacio;
use App\Enums\TipoMovimiento;
use App\Enums\TipoTramite;
use App\Models\Cesion;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Lote;
use App\Models\OrdenPago;
use App\Models\Panteon;
use App\Models\Parametro;
use App\Models\Persona;
use App\Models\Regimen;
use App\Models\Rol;
use App\Models\Seccion;
use App\Models\Titulo;
use App\Models\Tramite;
use App\Models\User;
use App\Services\Tramites\Folios;
use App\Services\Tramites\GestorTramites;
use App\Services\Tramites\TramiteException;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * Los cinco trámites de extremo a extremo, con roles, evidencias y mensajes en lenguaje llano.
 * Requiere DB_CONNECTION=mysql y una base de prueba propia (RefreshDatabase la vacía).
 */
class TramitesTest extends TestCase
{
    use RefreshDatabase;

    private GestorTramites $gestor;

    private User $ventanilla;

    private User $encargado;

    private User $secretaria;

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

        $cuota = fn (string $clave, float $importe, ?Seccion $s = null) => Cuota::create([
            'concepto_id' => ConceptoCobro::where('clave', $clave)->value('id'), 'seccion_id' => $s?->id,
            'importe' => $importe, 'acuerdo_cabildo' => 'Acta 1/2026', 'vigente_desde' => '2026-01-01',
        ]);
        $cuota('titulo_adquisicion', 1500, $seccion);
        $cuota('titulo_regularizacion', 900);
        $cuota('inhumacion', 800);
        $cuota('exhumacion', 700);
        $cuota('traslado', 300);
        $cuota('obra_monumento', 600);

        $this->gestor = app(GestorTramites::class);
        $this->ventanilla = $this->usuario('ventanilla');
        $this->encargado = $this->usuario('encargado_panteones');
        $this->secretaria = $this->usuario('secretaria_ayuntamiento');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Ayudas ────────────────────────────────────────────────────────────────

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

    private function persona(string $nombre): Persona
    {
        return Persona::create(['nombre' => $nombre, 'primer_apellido' => 'Prueba']);
    }

    private function titular(Espacio $e, Persona ...$personas): Titulo
    {
        $titulo = Titulo::create([
            'espacio_id' => $e->id, 'regimen_id' => Regimen::where('clave', 'perpetuidad')->value('id'),
            'folio' => 'LEG-'.$e->id, 'expediente' => 'LEG', 'origen' => OrigenTitulo::Legado,
            'estado' => EstadoTitulo::Vigente, 'fecha_emision' => '2010-01-01',
        ]);
        foreach ($personas as $i => $p) {
            $titulo->personas()->attach($p->id, ['rol' => ($i === 0 ? RolTitularidad::Titular : RolTitularidad::Copropietario)->value, 'orden' => $i + 1]);
        }

        return $titulo;
    }

    private function foto(string $nombre = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\x00", 64)."\xFF\xD9");
    }

    private function pdf(string $nombre = 'firmado.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    /** Entrega todo lo que el solicitante presenta; de cada grupo alternativo, sólo el primero. */
    private function integrar(Tramite $t): void
    {
        $grupos = [];
        foreach ($t->requisitos()->with('requisito')->get() as $fila) {
            $r = $fila->requisito;
            if ($r->emitido_por_sistema || $fila->estado !== EstadoRequisitoEntregado::Pendiente) {
                continue;
            }
            if ($r->grupo_alternativa !== null) {
                if (isset($grupos[$r->grupo_alternativa])) {
                    continue;
                }
                $grupos[$r->grupo_alternativa] = true;
            }
            $this->gestor->entregarRequisito($fila, $this->ventanilla, Carbon::today(), "DOC-{$fila->id}");
        }
    }

    private function emitidos(Tramite $t): void
    {
        foreach ($t->requisitos()->whereHas('requisito', fn ($q) => $q->where('emitido_por_sistema', true))->get() as $fila) {
            $this->gestor->emitirDocumento($fila, $this->encargado, $this->pdf());
        }
    }

    private function testigos(Tramite $t): void
    {
        $this->gestor->agregarParticipante($t, $this->persona('Testigo uno'), ParteTramite::Testigo, $this->ventanilla, aniosResidencia: 10);
        $this->gestor->agregarParticipante($t, $this->persona('Testigo dos'), ParteTramite::Testigo, $this->ventanilla, aniosResidencia: 6);
    }

    private function comparecer(Tramite $t, ParteTramite $papel): void
    {
        foreach ($t->participantes()->where('papel', $papel->value)->get() as $p) {
            $this->gestor->registrarComparecencia($p, $this->ventanilla);
        }
    }

    private function pagarTodo(Tramite $t): void
    {
        foreach ($t->ordenesPago()->where('estado', EstadoOrdenPago::Emitida->value)->get() as $orden) {
            $this->gestor->registrarPago($orden, "REC-{$orden->id}", Carbon::today(), $this->ventanilla);
        }
    }

    /** @return list<string> */
    private function motivos(callable $paso): array
    {
        try {
            $paso();
        } catch (TramiteException $e) {
            return $e->textos();
        }
        $this->fail('El paso debió bloquearse.');
    }

    private function regularizar(Espacio $e, Persona $solicitante, Persona $nuevo): Tramite
    {
        $t = $this->gestor->capturar(TipoTramite::Regularizacion, $e, $solicitante, ['via' => 'cesion'], $this->ventanilla);
        $this->gestor->iniciarRevision($t, $this->ventanilla);
        $this->gestor->agregarParticipante($t, $nuevo, ParteTramite::NuevoTitular, $this->ventanilla);
        $this->testigos($t);
        $this->integrar($t);
        $this->gestor->registrarCotejo($t, $this->ventanilla);

        return $t;
    }

    private function datosInhumacion(): array
    {
        return [
            'finado' => [
                'nombre' => 'Ramón', 'primer_apellido' => 'Pérez', 'sexo' => 'hombre',
                'fecha_defuncion' => '2026-10-25', 'edad_al_fallecer' => 81,
                'numero_acta_defuncion' => 'ACTA-123', 'lugar_fallecimiento' => 'Coatepec, Ver.',
            ],
            'permiso_folio' => 'RC-555', 'permiso_fecha' => '2026-10-25',
        ];
    }

    // ── Adquisición ───────────────────────────────────────────────────────────

    public function test_adquisicion_de_extremo_a_extremo(): void
    {
        $ana = $this->persona('Ana');
        $t = $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), $ana, ['tiempo_posesion' => '12 años'], $this->ventanilla);

        $this->assertSame('ADQ-2026-000001', $t->folio);
        $this->assertSame('2027-10-26', $t->caduca_en->toDateString());
        $this->assertSame(10, $t->requisitos()->count(), '9 requisitos; los testigos son dos filas.');

        $this->gestor->iniciarRevision($t, $this->ventanilla);
        $this->gestor->agregarParticipante($t, $ana, ParteTramite::NuevoTitular, $this->ventanilla);
        $this->testigos($t);
        $this->integrar($t);
        $this->gestor->registrarCotejo($t, $this->ventanilla);

        $this->assertStringContainsString('publicación', $this->motivos(fn () => $this->gestor->completarRevision($t, $this->ventanilla))[0]);

        $faltan = implode("\n", $this->motivos(fn () => $this->gestor->registrarPublicacion($t, $this->encargado, Carbon::today())));
        $this->assertStringContainsString('responsiva', $faltan);
        $this->assertStringContainsString('foto de la notificación en estrados', $faltan);
        $this->assertStringContainsString('foto de la notificación en el lote', $faltan);

        $this->assertStringContainsString('no tiene permiso para registrar publicaciones', $this->motivos(fn () => $this->gestor->registrarPublicacion($t, $this->ventanilla, Carbon::today()))[0]);

        $this->emitidos($t);
        $this->gestor->adjuntarArchivo($t, $this->foto('estrados.jpg'), TipoArchivo::FotoEstrados, $this->ventanilla);
        $this->gestor->adjuntarArchivo($t, $this->foto('lote.jpg'), TipoArchivo::FotoLote, $this->ventanilla);
        $this->gestor->registrarPublicacion($t, $this->encargado, Carbon::today());
        $this->gestor->concluirPublicacion($t, $this->ventanilla);

        $orden = $t->ordenesPago()->sole();
        $this->assertSame('OP-2026-000001', $orden->folio);
        $this->assertSame('1500.00', $orden->importe);

        $this->pagarTodo($t);
        $this->assertSame(EstadoTramite::PorAutorizar, $t->fresh()->estado);

        // La ventanilla no autoriza títulos; el Encargado tampoco: los firma la Secretaría (arts. 18 y 24).
        $this->assertStringContainsString('no tiene permiso para autorizar', $this->motivos(fn () => $this->gestor->concluir($t->fresh(), $this->ventanilla))[0]);
        $this->assertStringContainsString('no tiene permiso para autorizar', $this->motivos(fn () => $this->gestor->concluir($t->fresh(), $this->encargado))[0]);
        $this->assertStringContainsString('carta poder', implode(' ', $this->motivos(fn () => $this->gestor->concluir($t->fresh(), $this->secretaria))));

        $this->comparecer($t, ParteTramite::NuevoTitular);
        $t = $this->gestor->concluir($t->fresh(), $this->secretaria);

        $titulo = $t->tituloResultante;
        $this->assertSame(EstadoTramite::Concluido, $t->estado);
        $this->assertSame('TIT-2026-000001', $titulo->folio);
        $this->assertSame('2125-10-26', $titulo->fecha_vencimiento->toDateString());
        $this->assertSame(['Ana'], $titulo->titulares->pluck('nombre')->all());
        $this->assertSame(3, $t->archivos()->count(), 'Responsiva firmada y dos fotos.');
        $this->assertSame(
            ['capturado', 'en_revision', 'en_publicacion', 'por_pagar', 'por_autorizar', 'concluido'],
            $t->historial->map(fn ($h) => $h->a_estado->value)->all(),
        );
    }

    public function test_la_revision_dice_todo_lo_que_falta_de_una_vez(): void
    {
        $t = $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), $this->persona('Ana'), ['tiempo_posesion' => '3 años'], $this->ventanilla);
        $this->gestor->iniciarRevision($t, $this->ventanilla);

        $motivos = implode("\n", $this->motivos(fn () => $this->gestor->registrarPublicacion($t, $this->encargado, Carbon::today())));

        foreach (['Falta: Escrito de solicitud', '(Testigo 2)', 'Falta el cotejo', 'derechos de uso', 'testigos con al menos 5 años', 'a nombre de quién quedará el título', 'responsiva', 'foto de la notificación en el lote'] as $esperado) {
            $this->assertStringContainsString($esperado, $motivos);
        }
    }

    public function test_cada_motivo_dice_que_hacer(): void
    {
        $t = $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), $this->persona('Ana'), ['tiempo_posesion' => '3 años'], $this->ventanilla);
        $this->gestor->iniciarRevision($t, $this->ventanilla);

        try {
            $this->gestor->registrarPublicacion($t, $this->encargado, Carbon::today());
            $this->fail('Debió bloquearse.');
        } catch (TramiteException $e) {
            foreach ($e->motivos as $m) {
                $this->assertNotEmpty($m->queHacer, "Sin qué hacer: {$m->problema}");
            }
        }
    }

    public function test_no_se_adquiere_un_espacio_ya_titulado(): void
    {
        $e = $this->espacio();
        $this->titular($e, $this->persona('Luis'));

        $motivos = $this->motivos(fn () => $this->gestor->capturar(TipoTramite::Adquisicion, $e, $this->persona('Ana'), ['tiempo_posesion' => '1 año'], $this->ventanilla));

        $this->assertStringContainsString('captura una regularización', $motivos[0]);
        $this->assertSame(0, Tramite::count());
    }

    public function test_documento_vencido_y_bandera_de_pago_de_derechos(): void
    {
        $t = $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), $this->persona('Ana'), ['tiempo_posesion' => '3 años'], $this->ventanilla);
        $fila = fn (string $clave) => $t->requisitos()->whereHas('requisito', fn ($q) => $q->where('clave', $clave))->first();

        $vencido = $this->motivos(fn () => $this->gestor->entregarRequisito($fila('acta_nacimiento'), $this->ventanilla, Carbon::parse('2026-07-01')))[0];
        $this->assertStringContainsString('ya no está vigente: se expidió el 1 jul 2026 y sólo valía hasta el 29 sep 2026', $vencido);

        $this->assertFalse($t->fresh()->pago_derechos_al_corriente);
        $this->gestor->entregarRequisito($fila('comprobante_derechos'), $this->ventanilla, Carbon::parse('2026-10-20'), 'TES-9981');
        $this->assertTrue($t->fresh()->pago_derechos_al_corriente);
        $this->assertSame('TES-9981', $t->fresh()->pago_derechos_folio);
    }

    // ── Regularización ────────────────────────────────────────────────────────

    public function test_cesion_sustituye_el_titulo_y_no_se_repite_a_la_misma_persona(): void
    {
        $e = $this->espacio();
        $luis = $this->persona('Luis');
        $ana = $this->persona('Ana');
        $original = $this->titular($e, $luis);

        $t = $this->regularizar($e, $luis, $ana);
        $this->gestor->completarRevision($t, $this->ventanilla);
        $this->assertSame('900.00', $t->ordenesPago()->sole()->importe);
        $this->pagarTodo($t);
        $this->comparecer($t, ParteTramite::NuevoTitular);
        $t = $this->gestor->concluir($t->fresh(), $this->secretaria);

        $nuevo = $t->tituloResultante;
        $this->assertSame(EstadoTitulo::Sustituido, $original->fresh()->estado);
        $this->assertSame(OrigenTitulo::Cesion, $nuevo->origen);
        $this->assertTrue($nuevo->tituloAnterior->is($original));
        $this->assertTrue(Cesion::where('espacio_id', $e->id)->where('cesionario_id', $ana->id)->exists());

        $t2 = $this->regularizar($e, $ana, $luis);
        $this->gestor->completarRevision($t2, $this->ventanilla);
        $this->pagarTodo($t2);
        $this->comparecer($t2, ParteTramite::NuevoTitular);
        $this->gestor->concluir($t2->fresh(), $this->secretaria);

        $t3 = $this->regularizar($e, $luis, $ana);
        $this->assertStringContainsString('sólo puede cedérsele una vez', implode(' ', $this->motivos(fn () => $this->gestor->completarRevision($t3, $this->ventanilla))));
    }

    public function test_una_anuencia_por_cada_copropietario_segun_la_bandera_del_catalogo(): void
    {
        $e = $this->espacio();
        $this->titular($e, $this->persona('Luis'), $this->persona('Rosa'), $this->persona('Juan'));
        Inhumado::create(['espacio_id' => $e->id, 'nombre' => 'Uno', 'fecha_inhumacion' => '1990-01-01']);
        Inhumado::create(['espacio_id' => $e->id, 'nombre' => 'Dos', 'fecha_inhumacion' => '2001-01-01']);

        $t = $this->gestor->capturar(TipoTramite::Regularizacion, $e, $this->persona('Ana'), ['via' => 'cesion'], $this->ventanilla);
        $cuenta = fn (string $clave) => $t->requisitos()->whereHas('requisito', fn ($q) => $q->where('clave', $clave))->count();

        $this->assertSame(3, $cuenta('anuencia'), 'Tres copropietarios.');
        $this->assertSame(2, $cuenta('actas_defuncion_lote'), 'Dos sepultados.');
    }

    // ── Inhumación ────────────────────────────────────────────────────────────

    public function test_inhumacion_registra_al_finado_y_bloquea_reabrir_la_fosa(): void
    {
        $e = $this->espacio();
        $luis = $this->persona('Luis');
        $this->titular($e, $luis);

        $t = $this->gestor->capturar(TipoTramite::Inhumacion, $e, $luis, $this->datosInhumacion(), $this->ventanilla, causaFallecimiento: 'Dato sensible');
        $this->gestor->iniciarRevision($t, $this->ventanilla);
        $this->gestor->agregarParticipante($t, $luis, ParteTramite::Autorizante, $this->ventanilla);
        $this->comparecer($t, ParteTramite::Autorizante);
        $this->integrar($t);
        $this->gestor->registrarCotejo($t, $this->ventanilla);
        $this->gestor->completarRevision($t, $this->ventanilla);
        $this->pagarTodo($t);
        $t = $this->gestor->concluir($t->fresh(), $this->encargado);

        $inhumado = $t->inhumado;
        $this->assertSame('Ramón Pérez', $inhumado->nombre_completo);
        $this->assertSame('Dato sensible', $inhumado->causaVisiblePara($this->encargado));
        $this->assertNull($inhumado->causaVisiblePara($this->secretaria), 'La Secretaría no tiene ese permiso.');
        $this->assertNotSame('Dato sensible', DB::table('tramites')->where('id', $t->id)->value('causa_fallecimiento'));
        $this->assertSame(TipoMovimiento::Inhumacion, $inhumado->movimientos->sole()->tipo);

        $motivos = $this->motivos(fn () => $this->gestor->capturar(TipoTramite::Inhumacion, $e, $luis, $this->datosInhumacion(), $this->ventanilla, causaFallecimiento: 'x'));
        $this->assertStringContainsString('La fosa no puede abrirse todavía: ahí está sepultado Ramón Pérez', $motivos[0]);
        $this->assertStringContainsString('Mientras, elige otra fosa', $motivos[0]);
    }

    public function test_inhumacion_en_fosa_comun_por_orden_de_autoridad(): void
    {
        $comun = Espacio::create(['lote_id' => $this->lote->id, 'tipo' => TipoEspacio::Fosa, 'numero' => 'FC-1', 'es_fosa_comun' => true]);
        $fiscalia = $this->persona('Agente del Ministerio Público');
        $datos = $this->datosInhumacion();
        $datos['finado'] = ['nombre' => 'Desconocido', 'es_desconocido' => true, 'sexo' => 'no_especificado', 'fecha_defuncion' => '2026-10-20',
            'edad_al_fallecer' => 40, 'numero_acta_defuncion' => 'ACTA-9', 'lugar_fallecimiento' => 'Coatepec, Ver.'];

        $t = $this->gestor->capturar(TipoTramite::Inhumacion, $comun, $fiscalia, $datos, $this->ventanilla, causaFallecimiento: 'Por determinar', porOrdenAutoridad: true);
        $this->gestor->iniciarRevision($t, $this->ventanilla);
        $this->integrar($t);
        $this->gestor->registrarCotejo($t, $this->ventanilla);
        $this->gestor->completarRevision($t, $this->ventanilla);

        $this->assertSame(EstadoTramite::PorAutorizar, $t->fresh()->estado, 'Sin autorizante ni pago (arts. 46 y 68).');
        $t = $this->gestor->concluir($t->fresh(), $this->encargado);
        $this->assertTrue($t->inhumado->es_desconocido);
        $this->assertTrue($t->inhumado->movimientos->sole()->por_orden_autoridad);
    }

    public function test_autorizante_sin_legitimacion_o_sin_comparecer(): void
    {
        $e = $this->espacio();
        $this->titular($e, $this->persona('Luis'));
        $extrano = $this->persona('Extraño');

        $t = $this->gestor->capturar(TipoTramite::Inhumacion, $e, $extrano, $this->datosInhumacion(), $this->ventanilla, causaFallecimiento: 'x');
        $this->gestor->iniciarRevision($t, $this->ventanilla);
        $this->gestor->agregarParticipante($t, $extrano, ParteTramite::Autorizante, $this->ventanilla);

        $motivos = implode(' ', $this->motivos(fn () => $this->gestor->completarRevision($t, $this->ventanilla)));
        $this->assertStringContainsString('no es titular del espacio', $motivos);
        $this->assertStringContainsString('presentarse en persona', $motivos);
    }

    public function test_quien_captura_no_autoriza_aunque_tenga_el_permiso(): void
    {
        $e = $this->espacio();
        $luis = $this->persona('Luis');
        $this->titular($e, $luis);

        $t = $this->gestor->capturar(TipoTramite::Inhumacion, $e, $luis, $this->datosInhumacion(), $this->encargado, causaFallecimiento: 'x');
        $this->gestor->iniciarRevision($t, $this->encargado);
        $this->gestor->agregarParticipante($t, $luis, ParteTramite::Autorizante, $this->encargado);
        $this->comparecer($t, ParteTramite::Autorizante);
        $this->integrar($t);
        $this->gestor->registrarCotejo($t, $this->encargado);
        $this->gestor->completarRevision($t, $this->encargado);
        $this->pagarTodo($t);

        $this->assertStringContainsString('no puedes autorizarlo tú', $this->motivos(fn () => $this->gestor->concluir($t->fresh(), $this->encargado))[0]);
        $this->assertSame(EstadoTramite::PorAutorizar, $t->fresh()->estado);
    }

    // ── Exhumación ────────────────────────────────────────────────────────────

    private function sepultado(Espacio $e, string $fecha, int $edad = 70): Inhumado
    {
        return Inhumado::create(['espacio_id' => $e->id, 'nombre' => 'Finado', 'edad_al_fallecer' => $edad, 'fecha_inhumacion' => $fecha]);
    }

    public function test_exhumacion_con_traslado_emite_dos_ordenes_y_asienta_los_libros(): void
    {
        $e = $this->espacio();
        $luis = $this->persona('Luis');
        $this->titular($e, $luis);
        $finado = $this->sepultado($e, '2015-01-01');

        $datos = ['destino' => ['tipo' => 'traslado', 'destino_externo' => 'Panteón de Xalapa', 'realizado_por' => 'Funeraria Ejemplo'], 'permiso_folio' => 'RC-777', 'permiso_fecha' => '2026-10-20'];
        $t = $this->gestor->capturar(TipoTramite::Exhumacion, $e, $luis, $datos, $this->ventanilla, $finado, MotivoExhumacion::Traslado);

        $this->gestor->iniciarRevision($t, $this->ventanilla);
        $this->gestor->agregarParticipante($t, $luis, ParteTramite::Autorizante, $this->ventanilla);
        $this->comparecer($t, ParteTramite::Autorizante);
        $this->integrar($t);
        $this->gestor->registrarCotejo($t, $this->ventanilla);
        $this->gestor->completarRevision($t, $this->ventanilla);

        $this->assertEqualsCanonicalizing(['700.00', '300.00'], $t->ordenesPago()->pluck('importe')->all());
        $this->gestor->registrarPago($t->ordenesPago()->first(), 'REC-1', Carbon::today(), $this->ventanilla);
        $this->assertSame(EstadoTramite::PorPagar, $t->fresh()->estado, 'Falta una orden.');
        $this->pagarTodo($t);

        $t = $this->gestor->concluir($t->fresh(), $this->encargado);
        $finado->refresh();

        $this->assertSame(EstadoInhumado::Trasladado, $finado->estado);
        $this->assertNull($finado->espacio_id);
        $this->assertSame(['exhumacion', 'traslado'], $finado->movimientos->map(fn ($m) => $m->tipo->value)->all());
    }

    public function test_exhumacion_por_orden_de_autoridad_sin_plazo_ni_pago(): void
    {
        $e = $this->espacio();
        $finado = $this->sepultado($e, '2026-01-10');
        $fiscalia = $this->persona('Agente del Ministerio Público');

        $datos = ['destino' => ['tipo' => 'en_sitio'], 'permiso_folio' => 'FGE-01', 'permiso_fecha' => '2026-10-24'];
        $t = $this->gestor->capturar(TipoTramite::Exhumacion, $e, $fiscalia, $datos, $this->ventanilla, $finado, MotivoExhumacion::OrdenAutoridad);
        $this->assertSame(EstadoRequisitoEntregado::NoAplica, $t->requisitos()->whereHas('requisito', fn ($q) => $q->where('exento_con_orden_autoridad', true))->first()->estado);

        $this->gestor->iniciarRevision($t, $this->ventanilla);
        $this->integrar($t);
        $this->gestor->registrarCotejo($t, $this->ventanilla);
        $this->gestor->completarRevision($t, $this->ventanilla);

        $this->assertSame(0, $t->ordenesPago()->count());
        $this->gestor->concluir($t->fresh(), $this->encargado);
        $this->assertSame(EstadoInhumado::RestosEnSitio, $finado->fresh()->estado);
    }

    public function test_exhumacion_antes_de_plazo_dice_desde_cuando_se_puede(): void
    {
        $e = $this->espacio();
        $finado = $this->sepultado($e, '2022-05-01');
        $datos = ['destino' => ['tipo' => 'en_sitio'], 'permiso_folio' => 'RC-1', 'permiso_fecha' => '2026-10-20'];

        $motivos = $this->motivos(fn () => $this->gestor->capturar(TipoTramite::Exhumacion, $e, $this->persona('Luis'), $datos, $this->ventanilla, $finado, MotivoExhumacion::Traslado));

        $this->assertStringContainsString('Todavía no se cumple el plazo para exhumar: deben pasar al menos 7 años', $motivos[0]);
        $this->assertStringContainsString('Podrá hacerse a partir del 1 may 2029', $motivos[0]);
    }

    // ── Construcción ──────────────────────────────────────────────────────────

    public function test_construccion_valida_dimensiones_y_avisa_cuota_faltante(): void
    {
        $e = $this->espacio();
        $luis = $this->persona('Luis');
        $this->titular($e, $luis);
        $obra = fn (string $concepto, float $altura) => [
            'concepto_clave' => $concepto, 'descripcion' => 'Monumento de granito', 'materiales' => 'Granito y concreto',
            'tiempo_estimado_dias' => 10, 'largo_m' => 2.0, 'ancho_m' => 1.0, 'altura_m' => $altura, 'permiso_obras_folio' => 'OP-44',
        ];

        $this->assertStringContainsString('mide 1.00 m y el máximo es 0.90 m', $this->motivos(fn () => $this->gestor->capturar(TipoTramite::Construccion, $e, $luis, $obra('obra_monumento', 1.0), $this->ventanilla))[0]);

        $t = $this->gestor->capturar(TipoTramite::Construccion, $e, $luis, $obra('obra_capilla', 0.8), $this->ventanilla);
        $this->gestor->iniciarRevision($t, $this->ventanilla);
        $this->gestor->agregarParticipante($t, $this->persona('Albañil'), ParteTramite::Constructor, $this->ventanilla);
        $this->integrar($t);
        $this->gestor->registrarCotejo($t, $this->ventanilla);
        $this->emitidos($t);

        $motivos = $this->motivos(fn () => $this->gestor->completarRevision($t, $this->ventanilla));
        $this->assertCount(1, $motivos);
        $this->assertStringContainsString('Falta en el tabulador la cuota de «Construcción de capilla u oratorio»', $motivos[0]);
        $this->assertStringContainsString('no pierde nada', $motivos[0]);
        $this->assertSame(EstadoTramite::EnRevision, $t->fresh()->estado);
        $this->assertSame(0, OrdenPago::count());
    }

    public function test_la_medida_maxima_se_ajusta_desde_parametros(): void
    {
        $e = $this->espacio();
        $this->titular($e, $luis = $this->persona('Luis'));
        $obra = ['concepto_clave' => 'obra_monumento', 'descripcion' => 'x', 'materiales' => 'x', 'tiempo_estimado_dias' => 5, 'altura_m' => 1.0, 'permiso_obras_folio' => 'OP-1'];

        Parametro::where('clave', 'monumento_altura_maxima_m')->first()->nuevaVersion(['valor' => '1.10'], Carbon::parse('2026-10-26'));

        $t = $this->gestor->capturar(TipoTramite::Construccion, $e, $luis, $obra, $this->ventanilla);
        $this->assertSame(EstadoTramite::Capturado, $t->estado);
    }

    // ── Evidencias ────────────────────────────────────────────────────────────

    public function test_evidencias_tipo_tamano_integridad_y_cierre(): void
    {
        $t = $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), $this->persona('Ana'), ['tiempo_posesion' => '3 años'], $this->ventanilla);

        $texto = UploadedFile::fake()->createWithContent('foto.jpg', 'esto no es una imagen');
        $this->assertStringContainsString('JPG, PNG o WEBP', $this->motivos(fn () => $this->gestor->adjuntarArchivo($t, $texto, TipoArchivo::FotoLote, $this->ventanilla))[0]);
        $this->assertStringContainsString('JPG, PNG o WEBP', $this->motivos(fn () => $this->gestor->adjuntarArchivo($t, $this->pdf(), TipoArchivo::FotoLote, $this->ventanilla))[0]);

        Parametro::where('clave', 'archivo_tamano_maximo_mb')->first()->nuevaVersion(['valor' => '1'], Carbon::parse('2026-10-26'));
        $grande = UploadedFile::fake()->createWithContent('grande.jpg', "\xFF\xD8\xFF\xE0".str_repeat('a', 1024 * 1024 + 10));
        $this->assertStringContainsString('pesa más de 1 MB', $this->motivos(fn () => $this->gestor->adjuntarArchivo($t, $grande, TipoArchivo::FotoLote, $this->ventanilla))[0]);

        $archivo = $this->gestor->adjuntarArchivo($t, $this->foto(), TipoArchivo::FotoLote, $this->ventanilla);
        $this->assertTrue($archivo->integro());
        Storage::disk('local')->assertExists($archivo->ruta);
        $this->assertStringStartsWith('tramites/ADQ-2026-000001/', $archivo->ruta);

        Storage::disk('local')->put($archivo->ruta, 'alterado');
        $this->assertFalse($archivo->integro(), 'La huella detecta un archivo alterado.');

        $this->gestor->rechazar($t, $this->encargado, 'Desistió por escrito.');
        $this->expectException(LogicException::class);
        $archivo->fresh()->delete();
    }

    // ── Reglas transversales ──────────────────────────────────────────────────

    public function test_caducidad_segun_parametro_vigente_al_capturar(): void
    {
        $t = $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), $this->persona('Ana'), ['tiempo_posesion' => '2 años'], $this->ventanilla);

        Parametro::where('clave', 'caducidad_adquisicion_meses')->first()->nuevaVersion(['valor' => '6'], Carbon::parse('2026-10-26'));
        $t2 = $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), $this->persona('Rosa'), ['tiempo_posesion' => '2 años'], $this->ventanilla);

        $this->assertSame('2027-10-26', $t->caduca_en->toDateString(), 'La anterior conserva su plazo.');
        $this->assertSame('2027-04-26', $t2->caduca_en->toDateString());

        $this->assertSame(1, $this->gestor->caducarVencidos(Carbon::parse('2027-04-27')));
        $this->assertSame(EstadoTramite::Caducado, $t2->fresh()->estado);
        $this->assertNull($t2->fresh()->historial->last()->usuario_id);
        $this->assertSame(EstadoTramite::Capturado, $t->fresh()->estado);
    }

    public function test_usuario_inactivo_o_sin_rol_no_opera(): void
    {
        $this->ventanilla->update(['activo' => false]);
        $sinRol = User::factory()->create();

        foreach ([$this->ventanilla, $sinRol] as $u) {
            $this->assertStringContainsString('no tiene permiso para capturar trámites',
                $this->motivos(fn () => $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), $this->persona('Ana'), ['tiempo_posesion' => '1 año'], $u))[0]);
        }
    }

    public function test_paso_fuera_de_orden_y_orden_de_pago_congelada(): void
    {
        $e = $this->espacio();
        $luis = $this->persona('Luis');
        $this->titular($e, $luis);
        $t = $this->regularizar($e, $luis, $this->persona('Ana'));

        $this->assertStringContainsString('este paso no corresponde', $this->motivos(fn () => $this->gestor->concluir($t, $this->secretaria))[0]);

        $this->gestor->completarRevision($t, $this->ventanilla);
        $orden = $t->ordenesPago()->sole();

        Cuota::where('concepto_id', $orden->concepto_id)->abiertas()->first()->nuevaVersion(['importe' => 1200], Carbon::parse('2026-10-27'));
        $this->assertSame('900.00', $orden->fresh()->importe, 'La orden conserva su cuota.');

        $this->expectException(LogicException::class);
        $orden->update(['importe' => 1]);
    }

    public function test_rechazo_cancela_ordenes_pendientes(): void
    {
        $e = $this->espacio();
        $luis = $this->persona('Luis');
        $this->titular($e, $luis);
        $t = $this->regularizar($e, $luis, $this->persona('Ana'));
        $this->gestor->completarRevision($t, $this->ventanilla);

        $this->assertStringContainsString('no tiene permiso para rechazar', $this->motivos(fn () => $this->gestor->rechazar($t, $this->ventanilla, 'x'))[0]);
        $this->gestor->rechazar($t, $this->encargado, 'El solicitante desistió por escrito.');

        $this->assertSame(EstadoTramite::Rechazado, $t->fresh()->estado);
        $this->assertSame(EstadoOrdenPago::Cancelada, $t->ordenesPago()->sole()->estado);
    }

    public function test_folios_consecutivos_y_serie_configurable(): void
    {
        $folios = app(Folios::class);
        $this->assertSame('X-2026-000001', $folios->siguiente('X'));
        $this->assertSame('X-2026-000002', $folios->siguiente('X'));
        $this->assertSame('X-2027-000001', $folios->siguiente('X', 2027));

        // Un folio tomado en una operación que se deshace no deja hueco: lo usa la siguiente.
        try {
            DB::transaction(function () use ($folios): void {
                $folios->siguiente('Z');
                throw new \RuntimeException('falla después de tomar el folio');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('Z-2026-000001', $folios->siguiente('Z'));

        // Dos folios de una serie nueva en la misma operación (p. ej., dos órdenes de pago).
        $par = DB::transaction(fn () => [$folios->siguiente('W'), $folios->siguiente('W')]);
        $this->assertSame(['W-2026-000001', 'W-2026-000002'], $par);

        Parametro::where('clave', 'serie_folio_adquisicion')->first()->nuevaVersion(['valor' => 'ADQC'], Carbon::parse('2026-10-26'));
        $t = $this->gestor->capturar(TipoTramite::Adquisicion, $this->espacio(), $this->persona('Ana'), ['tiempo_posesion' => '1 año'], $this->ventanilla);
        $this->assertSame('ADQC-2026-000001', $t->folio);
    }
}
