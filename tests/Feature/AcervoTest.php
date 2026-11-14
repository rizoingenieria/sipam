<?php

namespace Tests\Feature;

use App\Enums\EstadoInhumado;
use App\Enums\TipoEspacio;
use App\Filament\Resources\Acervo\AcervoResource;
use App\Filament\Resources\Acervo\Pages\ListAcervo;
use App\Filament\Resources\Acervo\Pages\NuevoDocumento;
use App\Filament\Resources\Acervo\Pages\VerDocumento;
use App\Models\ArchivoAcervo;
use App\Models\Bitacora;
use App\Models\DocumentoAcervo;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Lote;
use App\Models\Panteon;
use App\Models\Parametro;
use App\Models\Rol;
use App\Models\Seccion;
use App\Models\TipoDocumentoAcervo;
use App\Models\User;
use App\Services\Acervo\AcervoHistorico;
use App\Support\AccionBloqueada;
use Database\Seeders\CatalogosSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Acervo histórico: los originales escaneados se guardan byte por byte con su huella, no se modifican ni se borran,
 * se ligan a espacios, títulos y finados, se encuentran sin OCR por sus datos y se respaldan y verifican aparte.
 */
class AcervoTest extends TestCase
{
    use RefreshDatabase;

    private AcervoHistorico $acervo;

    private User $encargado;

    private Espacio $espacio;

    private Inhumado $finado;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('El esquema usa columnas generadas y CHECK de MySQL; configure DB_CONNECTION=mysql.');
        }

        Carbon::setTestNow('2026-11-14 10:00:00');
        Storage::fake('acervo');
        $this->seed(CatalogosSeeder::class);
        Filament::setCurrentPanel('panteones');
        $this->acervo = app(AcervoHistorico::class);
        $this->encargado = $this->usuario('encargado_panteones');

        $panteon = Panteon::create(['clave' => 'MUN', 'nombre' => 'Panteón Municipal']);
        $seccion = Seccion::create(['panteon_id' => $panteon->id, 'clave' => 'A']);
        $lote = Lote::create(['seccion_id' => $seccion->id, 'numero' => '12']);
        $this->espacio = Espacio::create(['lote_id' => $lote->id, 'tipo' => TipoEspacio::Fosa, 'numero' => '1']);
        $this->finado = Inhumado::create(['espacio_id' => $this->espacio->id, 'nombre' => 'Emilia', 'primer_apellido' => 'Cházaro', 'segundo_apellido' => 'Landa',
            'fecha_inhumacion' => '1948-03-02', 'estado' => EstadoInhumado::Inhumado]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guarda_los_originales_tal_cual_con_su_huella_y_los_liga(): void
    {
        $pdf = $this->pdf('libro-3-fojas-45-52.pdf');
        $jpg = $this->jpg('foja-46.jpg');
        $huellaPdf = hash_file('sha256', $pdf->getRealPath());

        $d = $this->acervo->registrar($this->datos(), [$pdf, $jpg], ['espacios' => [$this->espacio->id], 'inhumados' => [$this->finado->id]], $this->encargado);

        $this->assertSame('ACV-2026-000001', $d->folio);
        $this->assertSame('1940 a 1950', $d->periodo());
        $archivos = $d->archivos;
        $this->assertSame([1, 2], $archivos->pluck('orden')->all());
        $this->assertSame(['application/pdf', 'image/jpeg'], $archivos->pluck('mime')->all());
        $this->assertSame($huellaPdf, $archivos[0]->sha256);
        // Byte por byte: lo guardado es exactamente lo que se subió.
        $this->assertSame(file_get_contents($pdf->getRealPath()), Storage::disk('acervo')->get($archivos[0]->ruta));
        $this->assertStringStartsWith('2026/ACV-2026-000001/001-', $archivos[0]->ruta);

        $this->assertTrue($this->espacio->documentosAcervo()->whereKey($d->id)->exists());
        $this->assertTrue($this->finado->documentosAcervo()->whereKey($d->id)->exists());
        $this->assertSame('espacio', DB::table('acervo_vinculos')->where('vinculable_id', $this->espacio->id)->value('vinculable_type'), 'alias estable, no nombre de clase');
        $this->assertSame(1, Bitacora::where('modelo_tipo', DocumentoAcervo::class)->count());
        $this->assertSame(2, Bitacora::where('modelo_tipo', ArchivoAcervo::class)->count());
    }

    public function test_rechaza_lo_que_no_es_un_escaneo_valido_y_no_deja_basura(): void
    {
        $intentos = [
            'sin archivos' => [[], 'Falta el archivo escaneado'],
            'texto renombrado' => [[UploadedFile::fake()->createWithContent('acta.pdf', 'esto no es un pdf')], 'no es un PDF ni una imagen'],
            'vacío' => [[UploadedFile::fake()->createWithContent('vacio.pdf', '')], 'no es un PDF ni una imagen'],
        ];
        foreach ($intentos as $caso => [$archivos, $mensaje]) {
            try {
                $this->acervo->registrar($this->datos(), $archivos, [], $this->encargado);
                $this->fail("Debió rechazar: {$caso}");
            } catch (AccionBloqueada $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage(), $caso);
            }
        }

        Parametro::where('clave', 'acervo_tamano_maximo_mb')->update(['valor' => '1']);
        $grande = UploadedFile::fake()->createWithContent('grande.pdf', "%PDF-1.4\n".str_repeat('x', 1024 * 1024 + 10));
        $this->assertBloqueado(fn () => $this->acervo->registrar($this->datos(), [$grande], [], $this->encargado), 'pesa más de 1 MB');

        $this->assertBloqueado(fn () => $this->acervo->registrar($this->datos(['anio_desde' => 1950, 'anio_hasta' => 1940]), [$this->pdf()], [], $this->encargado), 'posterior');
        $this->assertBloqueado(fn () => $this->acervo->registrar($this->datos(['anio_desde' => 2031]), [$this->pdf()], [], $this->encargado), 'no es válido');
        $this->assertBloqueado(fn () => $this->acervo->registrar($this->datos(), [$this->pdf()], [], $this->usuario('ventanilla')), 'Tu usuario no puede subir');

        $this->assertSame(0, DocumentoAcervo::count());
        $this->assertSame([], Storage::disk('acervo')->allFiles());
    }

    public function test_un_archivo_del_acervo_no_se_modifica_ni_se_borra(): void
    {
        $a = $this->documento()->archivos->first();

        $this->expectException(LogicException::class);
        try {
            $a->update(['ruta' => 'otra.pdf']);
        } finally {
            try {
                $a->delete();
                $this->fail('No debió borrarse.');
            } catch (LogicException) {
                $this->assertTrue(ArchivoAcervo::whereKey($a->id)->exists());
            }
        }
    }

    public function test_agregar_paginas_y_corregir_datos(): void
    {
        $d = $this->documento();
        $this->acervo->agregarArchivos($d, [$this->png('foja-53.png'), $this->tiff('plano.tif')], $this->encargado);
        $this->assertSame([1, 2, 3], $d->fresh()->archivos->pluck('orden')->all());
        $this->assertSame('image/tiff', $d->fresh()->archivos->last()->mime);

        $this->acervo->actualizar($d, ['titulo' => 'Libro 3, fojas 45 a 53', 'tipo_id' => $d->tipo_id, 'ubicacion_fisica' => ' Caja 12 '],
            ['titulos' => [], 'inhumados' => [$this->finado->id]], $this->encargado);
        $d->refresh();
        $this->assertSame('Libro 3, fojas 45 a 53', $d->titulo);
        $this->assertSame('Caja 12', $d->ubicacion_fisica);
        $this->assertSame(0, $d->espacios()->count(), 'los vínculos quedan como se indicaron');
    }

    public function test_se_ve_con_permiso_y_sale_identico(): void
    {
        $d = $this->documento();
        $this->acervo->agregarArchivos($d, [$this->tiff('plano.tif')], $this->encargado);
        [$pdf, $tif] = $d->fresh()->archivos->all();
        $url = fn (ArchivoAcervo $a) => route('filament.panteones.acervo.archivo', $a);

        $r = $this->actingAs($this->usuario('ventanilla'))->get($url($pdf))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Sha256', $pdf->sha256);
        $this->assertStringContainsString('inline', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('ACV-2026-000001-001.pdf', $r->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk('acervo')->get($pdf->ruta), $r->streamedContent());

        $this->assertStringContainsString('attachment', $this->get($url($tif))->headers->get('Content-Disposition'), 'TIFF se descarga');
        $this->assertStringContainsString('attachment', $this->get($url($pdf).'?descargar=1')->headers->get('Content-Disposition'));

        $this->actingAs($this->usuario('tesoreria'))->get($url($pdf))->assertForbidden();
        $this->actingAs($this->usuario('tesoreria'))->get(AcervoResource::getUrl('index'))->assertForbidden();
    }

    public function test_retirar_lo_oculta_pero_conserva_los_archivos(): void
    {
        $d = $this->documento(['espacios' => [$this->espacio->id]]);
        $ruta = $d->archivos->first()->ruta;

        $this->assertBloqueado(fn () => $this->acervo->retirar($d, 'error', $this->encargado), 'Escribe el motivo');
        $this->assertBloqueado(fn () => $this->acervo->retirar($d, 'Se subió en el documento equivocado.', $this->usuario('ventanilla')), 'Tu usuario no puede retirar');

        $this->acervo->retirar($d, 'Se subió en el documento equivocado.', $this->encargado);
        $d->refresh();
        $this->assertTrue($d->retirado());
        $this->assertSame(0, $this->espacio->documentosAcervo()->count(), 'ya no aparece en la ficha');
        $this->assertTrue(Storage::disk('acervo')->exists($ruta), 'el archivo se conserva');
        $this->assertBloqueado(fn () => $this->acervo->agregarArchivos($d, [$this->pdf()], $this->encargado), 'está retirado');

        $this->actingAs($this->encargado);
        Livewire::test(ListAcervo::class)->assertCanNotSeeTableRecords([$d])
            ->set('tableFilters.retirados.isActive', true)->assertCanSeeTableRecords([$d]);
    }

    public function test_se_encuentra_sin_ocr_por_sus_datos_y_por_lo_que_tiene_ligado(): void
    {
        $libro = $this->documento(['inhumados' => [$this->finado->id]]);
        $plano = $this->acervo->registrar($this->datos(['titulo' => 'Plano de la sección B', 'anio_desde' => 1975, 'anio_hasta' => null, 'ubicacion_fisica' => 'Planoteca, cajón 2']),
            [$this->pdf()], [], $this->encargado);
        $this->actingAs($this->usuario('ventanilla'));

        $lista = Livewire::test(ListAcervo::class);
        $lista->searchTable('Cházaro')->assertCanSeeTableRecords([$libro])->assertCanNotSeeTableRecords([$plano]);
        $lista->searchTable('cajón 2')->assertCanSeeTableRecords([$plano])->assertCanNotSeeTableRecords([$libro]);
        $lista->searchTable('MUN-A-12')->assertCanNotSeeTableRecords([$libro, $plano], 'el libro no está ligado al espacio');
        $lista->searchTable('ACV-2026-000002')->assertCanSeeTableRecords([$plano]);
        $lista->searchTable('')->filterTable('periodo', ['desde' => 1970, 'hasta' => 1980])
            ->assertCanSeeTableRecords([$plano])->assertCanNotSeeTableRecords([$libro]);
        $lista->filterTable('periodo', ['desde' => null, 'hasta' => null])->filterTable('sin_ligar', true)
            ->assertCanSeeTableRecords([$plano])->assertCanNotSeeTableRecords([$libro]);

        // Desde la ficha de un finado se llega ya filtrado.
        Livewire::withQueryParams(['inhumado' => $this->finado->id])->test(ListAcervo::class)
            ->assertCanSeeTableRecords([$libro])->assertCanNotSeeTableRecords([$plano])
            ->assertSee('Documentos de Emilia Cházaro Landa');
    }

    public function test_alta_desde_la_pantalla_ya_ligada_a_la_ficha_de_origen(): void
    {
        $this->actingAs($this->encargado);
        $tipo = TipoDocumentoAcervo::where('clave', 'libro_registro')->value('id');

        Livewire::withQueryParams(['inhumado' => $this->finado->id])->test(NuevoDocumento::class)
            ->assertFormSet(['inhumados' => [$this->finado->id]])
            ->fillForm(['tipo_id' => $tipo, 'titulo' => 'Libro 3 de inhumaciones, foja 45', 'anio_desde' => 1948,
                'ubicacion_fisica' => 'Archivo municipal, caja 12', 'archivos' => [$this->pdf('foja-45.pdf')]])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(AcervoResource::getUrl('view', ['record' => DocumentoAcervo::sole()]));

        $d = DocumentoAcervo::sole();
        $this->assertSame([$this->finado->id], $d->inhumados()->pluck('inhumados.id')->all());
        $this->assertSame(1, $d->archivos()->count());

        $this->get(AcervoResource::getUrl('view', ['record' => $d]))->assertOk()
            ->assertSee('Libro 3 de inhumaciones, foja 45')->assertSee('foja-45.pdf')->assertSee('Archivo municipal, caja 12')->assertSee('Emilia Cházaro Landa');
        $this->get('/finados/'.$this->finado->id)->assertOk()->assertSee('Acervo histórico')->assertSee('Libro 3 de inhumaciones, foja 45');

        // Quien sólo consulta no ve los botones de carga.
        $this->actingAs($this->usuario('ventanilla'));
        Livewire::test(VerDocumento::class, ['record' => $d->getRouteKey()])
            ->assertActionHidden('agregarArchivos')->assertActionHidden('retirar')->assertActionHidden('corregir');
    }

    public function test_verificacion_detecta_un_archivo_alterado_o_perdido(): void
    {
        $d = $this->documento();
        $this->acervo->agregarArchivos($d, [$this->png('foja.png')], $this->encargado);
        [$a, $b] = $d->fresh()->archivos->all();

        $this->artisan('panteones:verificar-acervo')->assertSuccessful();
        $this->assertTrue($a->fresh()->integro);

        Storage::disk('acervo')->put($a->ruta, 'alterado');
        Storage::disk('acervo')->delete($b->ruta);
        $this->artisan('panteones:verificar-acervo')->assertSuccessful(); // recién verificados: no se revisan otra vez hoy
        $this->artisan('panteones:verificar-acervo --todos')->assertFailed()->expectsOutputToContain('2 archivos no coinciden');

        $this->assertFalse($a->fresh()->integro);
        $this->assertFalse($b->fresh()->integro);
        $this->assertStringContainsString('NO coinciden', Bitacora::latest('id')->value('descripcion'));
        $this->actingAs($this->encargado)->get(AcervoResource::getUrl('view', ['record' => $d]))->assertSee('¡NO COINCIDE!');
    }

    public function test_respaldo_copia_solo_lo_nuevo_y_nunca_sobrescribe(): void
    {
        $destino = storage_path('framework/testing/respaldo-acervo-'.uniqid());
        config(['respaldos.acervo_ruta' => $destino]);
        try {
            $d = $this->documento();
            $a = $d->archivos->first();

            $this->artisan('panteones:respaldar-acervo')->assertSuccessful()->expectsOutputToContain('1 archivos nuevos copiados, 0 ya estaban');
            $this->assertSame(Storage::disk('acervo')->get($a->ruta), file_get_contents($destino.'/'.$a->ruta));

            $this->acervo->agregarArchivos($d, [$this->jpg()], $this->encargado);
            $this->artisan('panteones:respaldar-acervo')->assertSuccessful()->expectsOutputToContain('1 archivos nuevos copiados, 1 ya estaban');

            // Una copia dañada no se pisa: se avisa.
            file_put_contents($destino.'/'.$a->ruta, 'dañado');
            $this->artisan('panteones:respaldar-acervo')->assertFailed()->expectsOutputToContain('no coincide');
            $this->assertSame('dañado', file_get_contents($destino.'/'.$a->ruta));
        } finally {
            File::deleteDirectory($destino);
        }
    }

    public function test_el_tope_de_subida_ya_no_es_el_de_12_mb_de_livewire(): void
    {
        $this->assertContains('max:'.(config('archivos.subida_maxima_mb') * 1024), config('livewire.temporary_file_upload.rules'));
        $this->assertSame(100, config('archivos.subida_maxima_mb'));

        // El parámetro puede pedir menos que el servidor, nunca más.
        Parametro::where('clave', 'acervo_tamano_maximo_mb')->update(['valor' => '500']);
        $this->assertSame(100, $this->acervo->tamanoMaximoMb());
    }

    public function test_permisos_de_fabrica(): void
    {
        $permisos = fn (string $rol) => array_values(array_filter(Rol::where('clave', $rol)->value('permisos'), fn ($p) => str_starts_with($p, 'acervo.')));

        $this->assertSame(['acervo.consultar', 'acervo.cargar', 'acervo.retirar'], $permisos('encargado_panteones'));
        $this->assertSame(['acervo.consultar'], $permisos('ventanilla'));
        $this->assertSame([], $permisos('tesoreria'));
    }

    // ── Ayudantes ──────────────────────────────────────────────────────────────

    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'tipo_id' => TipoDocumentoAcervo::where('clave', 'libro_registro')->value('id'),
            'titulo' => 'Libro 3 de inhumaciones, fojas 45 a 52',
            'descripcion' => 'Inhumaciones de la sección A, lotes 1 al 40.',
            'anio_desde' => 1940,
            'anio_hasta' => 1950,
            'ubicacion_fisica' => 'Archivo municipal, caja 12, libro 3',
        ];
    }

    private function documento(array $vinculos = []): DocumentoAcervo
    {
        return $this->acervo->registrar($this->datos(), [$this->pdf()], $vinculos, $this->encargado);
    }

    private function usuario(string ...$roles): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Rol::whereIn('clave', $roles)->pluck('id'));

        return $u;
    }

    private function pdf(string $nombre = 'escaneo.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n".uniqid()."\n%%EOF\n");
    }

    private function jpg(string $nombre = 'foja.jpg'): UploadedFile
    {
        // JPEG real de 1×1 (sin depender de GD), con bytes al final para que cada uno tenga huella distinta.
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        return UploadedFile::fake()->createWithContent($nombre, $jpeg.uniqid());
    }

    private function png(string $nombre = 'foja.png'): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($nombre, $png.uniqid());
    }

    private function tiff(string $nombre = 'plano.tif'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "II*\0".str_repeat("\0", 64).uniqid());
    }

    private function assertBloqueado(callable $paso, string $mensaje): void
    {
        try {
            $paso();
            $this->fail('Debió bloquearse: '.$mensaje);
        } catch (AccionBloqueada $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());
        }
    }
}
