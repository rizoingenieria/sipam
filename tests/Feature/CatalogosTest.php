<?php

namespace Tests\Feature;

use App\Enums\EstadoPanteon;
use App\Enums\EstadoTitulo;
use App\Enums\MotivoExhumacion;
use App\Enums\OrigenTitulo;
use App\Enums\TipoEspacio;
use App\Enums\TipoHorario;
use App\Enums\TipoTramite;
use App\Models\Cargo;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\EntePublico;
use App\Models\Espacio;
use App\Models\Firmante;
use App\Models\Horario;
use App\Models\Inhumado;
use App\Models\Lote;
use App\Models\Panteon;
use App\Models\Parentesco;
use App\Models\Regimen;
use App\Models\ReglaPlazo;
use App\Models\Requisito;
use App\Models\Seccion;
use App\Models\Titulo;
use App\Services\Plazos\EvaluadorPlazoExhumacion;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Catálogos versionados, seeder y evaluador de plazos, contra MySQL 8.4.
 * Requiere DB_CONNECTION=mysql y una base de prueba propia (RefreshDatabase la vacía).
 */
class CatalogosTest extends TestCase
{
    use RefreshDatabase;

    private Seccion $seccion;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('El esquema usa columnas generadas y CHECK de MySQL; configure DB_CONNECTION=mysql.');
        }

        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->seed(CatalogosSeeder::class);

        $panteon = Panteon::create(['clave' => 'MUN', 'nombre' => 'Panteón Municipal']);
        $this->seccion = Seccion::create(['panteon_id' => $panteon->id, 'clave' => 'A']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Seeder ────────────────────────────────────────────────────────────────

    public function test_el_seeder_siembra_lo_esperado_y_es_idempotente(): void
    {
        $conteos = fn () => [
            Regimen::count(), ReglaPlazo::count(), Requisito::count(), ConceptoCobro::count(),
            Cargo::count(), Parentesco::count(), EntePublico::count(),
        ];

        $this->assertSame([2, 5, 42, 13, 9, 10, 1], $conteos());
        $this->assertSame(0, Cuota::count(), 'Los importes los captura el ayuntamiento.');
        $this->assertSame(0, Firmante::count(), 'Los firmantes los captura el ayuntamiento.');

        $this->seed(CatalogosSeeder::class);
        $this->assertSame([2, 5, 42, 13, 9, 10, 1], $conteos());
    }

    public function test_el_seeder_no_pisa_lo_editado(): void
    {
        $cargo = Cargo::where('clave', 'sindico')->first();
        $cargo->update(['nombre' => 'Síndica Única']);

        $this->seed(CatalogosSeeder::class);

        $this->assertSame('Síndica Única', $cargo->fresh()->nombre);
    }

    public function test_requisitos_por_tramite(): void
    {
        $adquisicion = Requisito::deTramite(TipoTramite::Adquisicion)->get();

        $this->assertCount(9, $adquisicion);
        $this->assertSame('escrito_solicitud', $adquisicion->first()->clave);
        $this->assertTrue($adquisicion->firstWhere('clave', 'responsiva_encargado')->emitido_por_sistema);
        $this->assertSame(2, $adquisicion->firstWhere('clave', 'testigos')->cantidad);

        $alternativos = Requisito::deTramite(TipoTramite::Inhumacion)->whereNotNull('grupo_alternativa')->pluck('clave')->all();
        $this->assertSame(['titulo', 'acta_defuncion_ultimo'], $alternativos);
    }

    public function test_vigencia_de_un_documento(): void
    {
        $acta = Requisito::deTramite(TipoTramite::Adquisicion)->where('clave', 'acta_nacimiento')->first();

        $this->assertTrue($acta->documentoVigente(Carbon::parse('2026-07-28')));  // 90 días justos
        $this->assertFalse($acta->documentoVigente(Carbon::parse('2026-07-27'))); // 91 días
    }

    // ── Versionado ────────────────────────────────────────────────────────────

    public function test_una_version_en_vigor_no_se_edita_ni_se_borra(): void
    {
        $regla = ReglaPlazo::where('clave', 'temporalidad-minima')->first();

        try {
            $regla->update(['anios_minimos' => 8]);
            $this->fail('Se editó una versión en vigor.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $regla->delete();
    }

    public function test_una_version_futura_si_se_corrige(): void
    {
        $regla = ReglaPlazo::where('clave', 'temporalidad-minima')->first()
            ->nuevaVersion(['anios_minimos' => 8], Carbon::parse('2027-01-01'));

        $regla->update(['anios_minimos' => 9]);

        $this->assertSame(9, $regla->fresh()->anios_minimos);
    }

    public function test_nueva_version_cierra_la_anterior_y_cada_fecha_ve_la_suya(): void
    {
        $original = ReglaPlazo::where('clave', 'temporalidad-minima')->first();
        $nueva = $original->nuevaVersion(['anios_minimos' => 8, 'fundamento' => 'Reforma hipotética'], Carbon::parse('2027-01-01'));

        $this->assertSame('2026-12-31', $original->fresh()->vigente_hasta->toDateString());
        $this->assertSame(7, ReglaPlazo::where('clave', 'temporalidad-minima')->vigenteEn(Carbon::parse('2026-12-31'))->first()->anios_minimos);
        $this->assertSame(8, ReglaPlazo::where('clave', 'temporalidad-minima')->vigenteEn(Carbon::parse('2027-01-01'))->first()->anios_minimos);
        $this->assertTrue($nueva->is(ReglaPlazo::where('clave', 'temporalidad-minima')->abiertas()->first()));
    }

    public function test_la_base_impide_dos_versiones_abiertas(): void
    {
        $this->expectException(QueryException::class);
        ReglaPlazo::create([
            'clave' => 'temporalidad-minima', 'descripcion' => 'x', 'anios_minimos' => 7,
            'fundamento' => 'x', 'vigente_desde' => '2026-11-01',
        ]);
    }

    // ── Tabulador ─────────────────────────────────────────────────────────────

    public function test_cuota_por_seccion_versionada(): void
    {
        $titulo = ConceptoCobro::where('clave', 'titulo_adquisicion')->first();
        $cuota = Cuota::create([
            'concepto_id' => $titulo->id, 'seccion_id' => $this->seccion->id, 'importe' => 1500,
            'acuerdo_cabildo' => 'Acta 1/2026, punto 4', 'vigente_desde' => '2026-01-01',
        ]);
        $cuota->nuevaVersion(['importe' => 1650, 'acuerdo_cabildo' => 'Acta 2/2027, punto 3'], Carbon::parse('2027-01-01'));

        $this->assertSame('1500.00', $titulo->cuotaPara($this->seccion)->importe);
        $this->assertSame('1650.00', $titulo->cuotaPara($this->seccion, Carbon::parse('2027-03-01'))->importe);
        $this->assertNull($titulo->cuotaPara(null), 'Sin sección no hay cuota de un concepto por sección.');
    }

    public function test_cuota_general_y_reglas_de_seccion(): void
    {
        $inhumacion = ConceptoCobro::where('clave', 'inhumacion')->first();
        Cuota::create(['concepto_id' => $inhumacion->id, 'importe' => 800, 'acuerdo_cabildo' => 'Acta 1/2026', 'vigente_desde' => '2026-01-01']);

        $this->assertSame('800.00', $inhumacion->cuotaPara()->importe);

        $this->expectException(InvalidArgumentException::class);
        Cuota::create(['concepto_id' => $inhumacion->id, 'seccion_id' => $this->seccion->id, 'importe' => 900, 'acuerdo_cabildo' => 'x', 'vigente_desde' => '2026-01-01']);
    }

    public function test_dos_cuotas_generales_abiertas_chocan_en_la_base(): void
    {
        $inhumacion = ConceptoCobro::where('clave', 'inhumacion')->first();
        Cuota::create(['concepto_id' => $inhumacion->id, 'importe' => 800, 'acuerdo_cabildo' => 'x', 'vigente_desde' => '2026-01-01']);

        $this->expectException(QueryException::class);
        Cuota::create(['concepto_id' => $inhumacion->id, 'importe' => 850, 'acuerdo_cabildo' => 'y', 'vigente_desde' => '2026-02-01']);
    }

    // ── Firmantes y horarios ──────────────────────────────────────────────────

    public function test_el_firmante_es_el_de_la_fecha_del_documento(): void
    {
        $cargo = Cargo::where('clave', 'secretario_ayuntamiento')->first();
        $anterior = Firmante::create(['cargo_id' => $cargo->id, 'titulo_profesional' => 'Lic.', 'nombre' => 'Persona Anterior', 'vigente_desde' => '2022-01-01']);
        $anterior->nuevaVersion(['nombre' => 'Persona Nueva', 'titulo_profesional' => 'Mtra.'], Carbon::parse('2026-01-01'));

        $this->assertSame('Lic. Persona Anterior', Cargo::firmante('secretario_ayuntamiento', Carbon::parse('2025-06-30'))->nombre_para_firma);
        $this->assertSame('Mtra. Persona Nueva', Cargo::firmante('secretario_ayuntamiento')->nombre_para_firma);
    }

    public function test_horario_de_recepcion(): void
    {
        $panteon = $this->seccion->panteon;
        Horario::create(['panteon_id' => $panteon->id, 'tipo' => TipoHorario::Recepcion, 'dia_semana' => 1, 'abre' => '08:00', 'cierra' => '17:30']);

        $this->assertTrue(Horario::abierto($panteon, TipoHorario::Recepcion, Carbon::parse('2026-10-26 17:29'))); // lunes
        $this->assertFalse(Horario::abierto($panteon, TipoHorario::Recepcion, Carbon::parse('2026-10-26 17:30')));
        $this->assertFalse(Horario::abierto($panteon, TipoHorario::Recepcion, Carbon::parse('2026-10-27 09:00'))); // martes sin horario
    }

    // ── Evaluador de plazos ───────────────────────────────────────────────────

    private function inhumado(array $datos, ?string $regimen = null): Inhumado
    {
        static $n = 0;
        $n++;
        $lote = Lote::firstOrCreate(['seccion_id' => $this->seccion->id, 'numero' => '1']);
        $espacio = Espacio::create(['lote_id' => $lote->id, 'tipo' => TipoEspacio::Fosa, 'numero' => (string) $n]);

        if ($regimen !== null) {
            Titulo::create([
                'espacio_id' => $espacio->id, 'regimen_id' => Regimen::where('clave', $regimen)->abiertas()->value('id'),
                'folio' => "F-{$n}", 'expediente' => "E-{$n}", 'origen' => OrigenTitulo::Adquisicion,
                'estado' => EstadoTitulo::Vigente, 'fecha_emision' => '2015-01-01',
            ]);
        }

        return Inhumado::create(['espacio_id' => $espacio->id, 'nombre' => 'Finado'] + $datos);
    }

    public function test_adulto_seis_anios(): void
    {
        $evaluador = new EvaluadorPlazoExhumacion;
        $i = $this->inhumado(['edad_al_fallecer' => 70, 'fecha_inhumacion' => '2020-10-27'], 'perpetuidad');

        $r = $evaluador->evaluar($i, MotivoExhumacion::Vencimiento);
        $this->assertFalse($r->permitido);
        $this->assertSame('2026-10-27', $r->fechaPermitida->toDateString());
        $this->assertSame('sanitario-15-o-mas', $r->reglaDeterminante->clave);

        $this->assertTrue($evaluador->evaluar($i, MotivoExhumacion::Vencimiento, Carbon::parse('2026-10-27'))->permitido);
    }

    public function test_menor_cinco_anios_y_quince_exactos_cuenta_como_adulto(): void
    {
        $evaluador = new EvaluadorPlazoExhumacion;

        $nino = $this->inhumado(['edad_al_fallecer' => 14, 'fecha_inhumacion' => '2021-10-26']);
        $this->assertTrue($evaluador->evaluar($nino, MotivoExhumacion::Vencimiento)->permitido);

        $quince = $this->inhumado(['edad_al_fallecer' => 15, 'fecha_inhumacion' => '2021-10-26']);
        $r = $evaluador->evaluar($quince, MotivoExhumacion::Vencimiento);
        $this->assertFalse($r->permitido);
        $this->assertSame('sanitario-15-o-mas', $r->reglaDeterminante->clave);
    }

    public function test_temporalidad_y_traslado_suben_a_siete(): void
    {
        $evaluador = new EvaluadorPlazoExhumacion;

        $temporal = $this->inhumado(['edad_al_fallecer' => 50, 'fecha_inhumacion' => '2020-01-01'], 'temporalidad');
        $r = $evaluador->evaluar($temporal, MotivoExhumacion::Vencimiento);
        $this->assertFalse($r->permitido);
        $this->assertSame('temporalidad-minima', $r->reglaDeterminante->clave);
        $this->assertCount(2, $r->reglasAplicadas);

        $traslado = $this->inhumado(['edad_al_fallecer' => 50, 'fecha_inhumacion' => '2020-01-01'], 'perpetuidad');
        $this->assertSame('traslado-familiares', $evaluador->evaluar($traslado, MotivoExhumacion::Traslado)->reglaDeterminante->clave);
        $this->assertTrue($evaluador->evaluar($traslado, MotivoExhumacion::Vencimiento)->permitido);
    }

    public function test_panteon_saturado_exige_mas_de_seis(): void
    {
        $evaluador = new EvaluadorPlazoExhumacion;
        $i = $this->inhumado(['edad_al_fallecer' => 40, 'fecha_inhumacion' => '2020-10-26'], 'perpetuidad');

        $this->assertTrue($evaluador->evaluar($i, MotivoExhumacion::ReutilizacionFosa)->permitido);

        $this->seccion->panteon->update(['estado' => EstadoPanteon::Saturado]);
        $r = $evaluador->evaluar($i, MotivoExhumacion::ReutilizacionFosa);
        $this->assertFalse($r->permitido);
        $this->assertSame('2026-10-27', $r->fechaPermitida->toDateString());
        $this->assertSame('reutilizacion-panteon-saturado', $r->reglaDeterminante->clave);
    }

    public function test_datos_faltantes_y_orden_de_autoridad(): void
    {
        $evaluador = new EvaluadorPlazoExhumacion;

        $sinEdad = $this->inhumado(['fecha_inhumacion' => '2000-01-01']);
        $this->assertFalse($evaluador->evaluar($sinEdad, MotivoExhumacion::Vencimiento)->permitido);
        $this->assertStringContainsString('edad', $evaluador->evaluar($sinEdad, MotivoExhumacion::Vencimiento)->explicacion);

        $sinFecha = $this->inhumado(['edad_al_fallecer' => 60]);
        $this->assertFalse($evaluador->evaluar($sinFecha, MotivoExhumacion::Vencimiento)->permitido);

        $reciente = $this->inhumado(['edad_al_fallecer' => 60, 'fecha_inhumacion' => '2026-10-01']);
        $this->assertTrue($evaluador->evaluar($reciente, MotivoExhumacion::OrdenAutoridad)->permitido);
    }

    public function test_una_reforma_cambia_el_plazo_sin_tocar_codigo(): void
    {
        $evaluador = new EvaluadorPlazoExhumacion;
        $i = $this->inhumado(['edad_al_fallecer' => 70, 'fecha_inhumacion' => '2019-11-01'], 'perpetuidad');

        $this->assertTrue($evaluador->evaluar($i, MotivoExhumacion::Vencimiento, Carbon::parse('2026-12-01'))->permitido);

        ReglaPlazo::where('clave', 'sanitario-15-o-mas')->first()
            ->nuevaVersion(['anios_minimos' => 8, 'fundamento' => 'Reforma hipotética'], Carbon::parse('2026-11-15'));

        $despues = $evaluador->evaluar($i, MotivoExhumacion::Vencimiento, Carbon::parse('2026-12-01'));
        $this->assertFalse($despues->permitido);
        $this->assertSame('Reforma hipotética', $despues->reglaDeterminante->fundamento);
        $this->assertTrue($evaluador->evaluar($i, MotivoExhumacion::Vencimiento, Carbon::parse('2026-11-14'))->permitido);
    }

    public function test_sin_reglas_aplicables_se_niega(): void
    {
        ReglaPlazo::query()->update(['vigente_hasta' => '2026-10-25']);
        $i = $this->inhumado(['edad_al_fallecer' => 70, 'fecha_inhumacion' => '1990-01-01']);

        $r = (new EvaluadorPlazoExhumacion)->evaluar($i, MotivoExhumacion::Vencimiento);

        $this->assertFalse($r->permitido);
        $this->assertStringContainsString('Ninguna regla', $r->explicacion);
    }
}
