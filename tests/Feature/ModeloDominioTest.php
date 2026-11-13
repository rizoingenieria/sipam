<?php

namespace Tests\Feature;

use App\Enums\EstadoInhumado;
use App\Enums\EstadoTitulo;
use App\Enums\OrigenTitulo;
use App\Enums\RolTitularidad;
use App\Enums\Sexo;
use App\Enums\TipoEspacio;
use App\Enums\TipoMovimiento;
use App\Models\Cesion;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Lote;
use App\Models\Movimiento;
use App\Models\Panteon;
use App\Models\Persona;
use App\Models\Regimen;
use App\Models\Seccion;
use App\Models\Titulo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Verifica las invariantes del modelo de dominio contra MySQL 8.4.
 * Requiere DB_CONNECTION=mysql y una base de prueba propia (ver phpunit.xml): RefreshDatabase la vacía.
 */
class ModeloDominioTest extends TestCase
{
    use RefreshDatabase;

    private Regimen $perpetuidad;

    private Regimen $temporalidad;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('El esquema usa columnas generadas y CHECK de MySQL; configure DB_CONNECTION=mysql.');
        }

        $this->perpetuidad = Regimen::create([
            'clave' => 'perpetuidad', 'nombre' => 'Perpetuidad', 'duracion_anios' => 99,
            'fundamento' => 'Art. 3, fr. XXX y XLIV', 'vigente_desde' => '2023-05-01',
        ]);
        $this->temporalidad = Regimen::create([
            'clave' => 'temporalidad', 'nombre' => 'Temporalidad mínima', 'duracion_anios' => 7,
            'renovable' => true, 'fundamento' => 'Art. 3, fr. XLII', 'vigente_desde' => '2023-05-01',
        ]);
    }

    private function espacio(string $numero = '1'): Espacio
    {
        $panteon = Panteon::firstOrCreate(['clave' => 'MUN'], ['nombre' => 'Panteón Municipal']);
        $seccion = Seccion::firstOrCreate(['panteon_id' => $panteon->id, 'clave' => 'A']);
        $lote = Lote::firstOrCreate(['seccion_id' => $seccion->id, 'numero' => '12']);

        return Espacio::create(['lote_id' => $lote->id, 'tipo' => TipoEspacio::Fosa, 'numero' => $numero]);
    }

    private function titulo(Espacio $espacio, string $folio, ?Regimen $regimen = null, EstadoTitulo $estado = EstadoTitulo::Vigente): Titulo
    {
        return Titulo::create([
            'espacio_id' => $espacio->id, 'regimen_id' => ($regimen ?? $this->perpetuidad)->id,
            'folio' => $folio, 'expediente' => "EXP-{$folio}", 'origen' => OrigenTitulo::Adquisicion,
            'estado' => $estado, 'fecha_emision' => '2026-10-26',
        ]);
    }

    public function test_jerarquia_y_clave_completa(): void
    {
        $espacio = $this->espacio('3')->load('lote.seccion.panteon');

        $this->assertSame('MUN-A-12-3', $espacio->clave_completa);
    }

    public function test_el_vencimiento_se_deriva_del_regimen(): void
    {
        $perpetuo = $this->titulo($this->espacio('1'), 'F-1');
        $temporal = $this->titulo($this->espacio('2'), 'F-2', $this->temporalidad);

        $this->assertSame('2125-10-26', $perpetuo->fecha_vencimiento->toDateString());
        $this->assertSame('2033-10-26', $temporal->fecha_vencimiento->toDateString());
    }

    public function test_un_solo_titulo_vigente_por_espacio(): void
    {
        $espacio = $this->espacio();
        $anterior = $this->titulo($espacio, 'F-1');

        // Un sustituido convive con el vigente…
        $anterior->update(['estado' => EstadoTitulo::Sustituido]);
        $nuevo = $this->titulo($espacio, 'F-2');
        $this->assertTrue($espacio->tituloVigente->is($nuevo));

        // …pero dos vigentes no.
        $this->expectException(QueryException::class);
        $this->titulo($espacio, 'F-3');
    }

    public function test_un_espacio_se_cede_una_sola_vez_a_la_misma_persona(): void
    {
        $espacio = $this->espacio();
        $t1 = $this->titulo($espacio, 'F-1', estado: EstadoTitulo::Sustituido);
        $t2 = $this->titulo($espacio, 'F-2');
        $persona = Persona::create(['nombre' => 'Ana', 'primer_apellido' => 'Pérez']);

        Cesion::create(['espacio_id' => $espacio->id, 'titulo_anterior_id' => $t1->id, 'titulo_nuevo_id' => $t2->id, 'cesionario_id' => $persona->id, 'fecha' => '2026-10-26']);

        $this->expectException(QueryException::class);
        Cesion::create(['espacio_id' => $espacio->id, 'titulo_anterior_id' => $t1->id, 'titulo_nuevo_id' => $t2->id, 'cesionario_id' => $persona->id, 'fecha' => '2026-10-27']);
    }

    public function test_titulares_y_beneficiarios(): void
    {
        $titulo = $this->titulo($this->espacio(), 'F-1');
        $ana = Persona::create(['nombre' => 'Ana']);
        $luis = Persona::create(['nombre' => 'Luis']);
        $sofia = Persona::create(['nombre' => 'Sofía']);

        $titulo->personas()->attach($ana->id, ['rol' => RolTitularidad::Titular->value, 'orden' => 1]);
        $titulo->personas()->attach($luis->id, ['rol' => RolTitularidad::Copropietario->value, 'orden' => 2]);
        $titulo->personas()->attach($sofia->id, ['rol' => RolTitularidad::Beneficiario->value, 'orden' => 3]);

        $this->assertEqualsCanonicalizing(['Ana', 'Luis'], $titulo->titulares->pluck('nombre')->all());
        $this->assertSame(['Sofía'], $titulo->beneficiarios->pluck('nombre')->all());
        $this->assertSame(RolTitularidad::Copropietario, $titulo->titularidades[1]->rol);
    }

    public function test_un_regimen_tiene_una_sola_version_abierta(): void
    {
        $this->expectException(QueryException::class);
        Regimen::create([
            'clave' => 'perpetuidad', 'nombre' => 'Perpetuidad 2', 'duracion_anios' => 99,
            'fundamento' => 'x', 'vigente_desde' => '2027-01-01',
        ]);
    }

    public function test_la_base_rechaza_datos_imposibles(): void
    {
        $espacio = $this->espacio();

        try {
            DB::table('titulos')->insert([
                'espacio_id' => $espacio->id, 'regimen_id' => $this->perpetuidad->id, 'folio' => 'X',
                'expediente' => 'X', 'origen' => 'legado', 'estado' => 'vigente',
                'fecha_emision' => '2026-01-01', 'fecha_vencimiento' => '2020-01-01',
            ]);
            $this->fail('El CHECK de vencimiento no actuó.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('titulos_vencimiento_posterior', $e->getMessage());
        }

        try {
            Inhumado::create(['espacio_id' => $espacio->id, 'nombre' => 'X', 'edad_al_fallecer' => 200]);
            $this->fail('El CHECK de edad no actuó.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('inhumados_edad_razonable', $e->getMessage());
        }
    }

    public function test_la_causa_de_fallecimiento_va_cifrada_y_oculta(): void
    {
        $inhumado = Inhumado::create([
            'espacio_id' => $this->espacio()->id, 'nombre' => 'Juan', 'sexo' => Sexo::Hombre,
            'fecha_nacimiento' => '1950-03-10', 'fecha_defuncion' => '2026-10-01',
            'causa_fallecimiento' => 'Dato sensible', 'fecha_inhumacion' => '2026-10-02',
        ]);

        $crudo = DB::table('inhumados')->where('id', $inhumado->id)->value('causa_fallecimiento');
        $this->assertNotSame('Dato sensible', $crudo);
        $this->assertSame('Dato sensible', $inhumado->fresh()->causa_fallecimiento);
        $this->assertArrayNotHasKey('causa_fallecimiento', $inhumado->toArray());
        $this->assertSame(76, $inhumado->edad_al_fallecer);
        $this->assertSame(EstadoInhumado::Inhumado, $inhumado->fresh()->estado);
    }

    public function test_los_movimientos_validan_su_libro_y_no_se_editan(): void
    {
        $espacio = $this->espacio();
        $inhumado = Inhumado::create(['espacio_id' => $espacio->id, 'nombre' => 'Juan', 'fecha_inhumacion' => '2018-01-10']);

        $mov = Movimiento::create(['inhumado_id' => $inhumado->id, 'tipo' => TipoMovimiento::Inhumacion, 'fecha_hora' => '2018-01-10 10:00', 'espacio_destino_id' => $espacio->id]);

        try {
            Movimiento::create(['inhumado_id' => $inhumado->id, 'tipo' => TipoMovimiento::Exhumacion, 'fecha_hora' => '2026-10-26 09:00', 'espacio_origen_id' => $espacio->id]);
            $this->fail('Se aceptó una exhumación sin causa.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(LogicException::class);
        $mov->update(['observaciones' => 'cambio']);
    }

    public function test_la_busqueda_ignora_acentos_y_mayusculas(): void
    {
        Persona::create(['nombre' => 'José', 'primer_apellido' => 'Muñoz', 'curp' => ' pexj800101hvzrrn09 ']);

        $persona = Persona::where('nombre_completo', 'like', '%jose munoz%')->first();

        $this->assertNotNull($persona);
        $this->assertSame('PEXJ800101HVZRRN09', $persona->curp);
    }

    public function test_ultimo_inhumado_del_espacio(): void
    {
        $espacio = $this->espacio();
        Inhumado::create(['espacio_id' => $espacio->id, 'nombre' => 'Primero', 'fecha_inhumacion' => '2001-05-01']);
        Inhumado::create(['espacio_id' => $espacio->id, 'nombre' => 'Último', 'fecha_inhumacion' => '2019-08-15']);

        $this->assertSame('Último', $espacio->ultimoInhumado->nombre);
        $this->assertSame(7, $espacio->ultimoInhumado->aniosSepultadoA(now()->setDate(2026, 10, 26)));
    }
}
