<?php

namespace App\Services\Acervo;

use App\Enums\Permiso;
use App\Models\ArchivoAcervo;
use App\Models\DocumentoAcervo;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Parametro;
use App\Models\Titulo;
use App\Models\User;
use App\Services\Tramites\Folios;
use App\Support\AccionBloqueada;
use App\Support\Motivo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reglas del acervo histórico. Toda alta o cambio pasa por aquí (pantallas, pruebas, futuros importadores):
 * - el tipo de archivo se reconoce por su contenido, no por su nombre;
 * - el original se guarda byte por byte, con su huella SHA-256, y nunca se sobrescribe ni se borra;
 * - un documento sin archivos no se registra: el acervo es de documentos escaneados.
 */
final class AcervoHistorico
{
    public const DISCO = 'acervo';

    /** mime => [extensión, nombre para la persona] */
    public const TIPOS = [
        'application/pdf' => ['pdf', 'PDF'],
        'image/jpeg' => ['jpg', 'JPG'],
        'image/png' => ['png', 'PNG'],
        'image/tiff' => ['tif', 'TIFF'],
    ];

    public function __construct(private readonly Folios $folios) {}

    /**
     * @param  array<string, mixed>  $datos  tipo_id, titulo, descripcion, anio_desde, anio_hasta, ubicacion_fisica, observaciones
     * @param  list<UploadedFile>  $archivos  en el orden de las páginas
     * @param  array{espacios?: list<int>, titulos?: list<int>, inhumados?: list<int>}  $vinculos
     */
    public function registrar(array $datos, array $archivos, array $vinculos, User $usuario): DocumentoAcervo
    {
        $this->exigir($usuario, Permiso::AcervoCargar, 'subir documentos al acervo');
        if ($archivos === []) {
            throw AccionBloqueada::por(Motivo::de('Falta el archivo escaneado.', 'Sube al menos un PDF o una imagen del documento.'));
        }
        $this->validarPeriodo($datos);
        array_map(fn (UploadedFile $a) => $this->revisarArchivo($a), $archivos);

        $guardados = [];
        try {
            return DB::transaction(function () use ($datos, $archivos, $vinculos, $usuario, &$guardados): DocumentoAcervo {
                $documento = DocumentoAcervo::create($this->datosPermitidos($datos) + [
                    'folio' => $this->folios->siguiente(Parametro::texto('serie_folio_acervo')),
                    'capturado_por' => $usuario->id,
                ]);
                foreach ($archivos as $i => $archivo) {
                    $guardados[] = $this->guardarArchivo($documento, $archivo, $i + 1, $usuario);
                }
                $this->sincronizarVinculos($documento, $vinculos);

                return $documento;
            });
        } catch (\Throwable $e) {
            // Si la base no aceptó el alta, no deben quedar archivos huérfanos en el disco.
            foreach ($guardados as $ruta) {
                Storage::disk(self::DISCO)->delete($ruta);
            }
            throw $e;
        }
    }

    /** Corrige los datos y los vínculos. Los archivos no se tocan. */
    public function actualizar(DocumentoAcervo $documento, array $datos, array $vinculos, User $usuario): DocumentoAcervo
    {
        $this->exigir($usuario, Permiso::AcervoCargar, 'corregir documentos del acervo');
        $this->exigirVigente($documento);
        $this->validarPeriodo($datos);

        DB::transaction(function () use ($documento, $datos, $vinculos): void {
            $documento->fill($this->datosPermitidos($datos))->save();
            $this->sincronizarVinculos($documento, $vinculos);
        });

        return $documento;
    }

    /** @param list<UploadedFile> $archivos  páginas que faltaban; van al final, en el orden recibido */
    public function agregarArchivos(DocumentoAcervo $documento, array $archivos, User $usuario): void
    {
        $this->exigir($usuario, Permiso::AcervoCargar, 'agregar páginas a documentos del acervo');
        $this->exigirVigente($documento);
        array_map(fn (UploadedFile $a) => $this->revisarArchivo($a), $archivos);

        $guardados = [];
        try {
            DB::transaction(function () use ($documento, $archivos, $usuario, &$guardados): void {
                $siguiente = (int) $documento->archivos()->lockForUpdate()->max('orden');
                foreach ($archivos as $archivo) {
                    $guardados[] = $this->guardarArchivo($documento, $archivo, ++$siguiente, $usuario);
                }
            });
        } catch (\Throwable $e) {
            foreach ($guardados as $ruta) {
                Storage::disk(self::DISCO)->delete($ruta);
            }
            throw $e;
        }
    }

    /** El documento deja de aparecer en las fichas y búsquedas; sus archivos se conservan. */
    public function retirar(DocumentoAcervo $documento, string $motivo, User $usuario): void
    {
        $this->exigir($usuario, Permiso::AcervoRetirar, 'retirar documentos del acervo');
        $this->exigirVigente($documento);
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 10) {
            throw AccionBloqueada::por(Motivo::de('Escribe el motivo del retiro.', 'Por ejemplo: «Se subió en el documento equivocado; el correcto es ACV-2026-000015».'));
        }

        $documento->forceFill(['retirado_en' => Carbon::now(), 'retirado_por' => $usuario->id, 'motivo_retiro' => mb_substr($motivo, 0, 500)])->save();
    }

    public function tiposEnPalabras(): string
    {
        return collect(self::TIPOS)->map(fn ($t) => $t[1])->join(', ', ' o ');
    }

    public function tamanoMaximoMb(): int
    {
        return min(Parametro::entero('acervo_tamano_maximo_mb'), (int) config('archivos.subida_maxima_mb'));
    }

    private function revisarArchivo(UploadedFile $archivo): void
    {
        $origen = $archivo->getRealPath();
        $mime = $origen ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($origen) : (string) $archivo->getMimeType();
        $nombre = $archivo->getClientOriginalName();

        if (! isset(self::TIPOS[$mime])) {
            throw AccionBloqueada::por(Motivo::de("«{$nombre}» no es un PDF ni una imagen.", 'Sube el escaneo en '.$this->tiposEnPalabras().'.'));
        }
        if ($archivo->getSize() === 0) {
            throw AccionBloqueada::por(Motivo::de("«{$nombre}» está vacío.", 'Vuelve a escanearlo.'));
        }
        $maximo = $this->tamanoMaximoMb();
        if ($archivo->getSize() > $maximo * 1024 * 1024) {
            throw AccionBloqueada::por(Motivo::de("«{$nombre}» pesa más de {$maximo} MB.", 'Divídelo en varios archivos (por ejemplo, por grupos de fojas) o pide al administrador que suba el límite.'));
        }
    }

    /** Guarda el archivo tal cual y devuelve su ruta en el disco. */
    private function guardarArchivo(DocumentoAcervo $documento, UploadedFile $archivo, int $orden, User $usuario): string
    {
        $origen = (string) $archivo->getRealPath();
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($origen);
        $sha256 = hash_file('sha256', $origen);
        // Carpeta por año y folio: fácil de ubicar a mano y de respaldar por partes.
        $carpeta = Carbon::now()->format('Y').'/'.$documento->folio;
        $ruta = $carpeta.'/'.sprintf('%03d', $orden).'-'.Str::uuid()->toString().'.'.self::TIPOS[$mime][0];

        $flujo = fopen($origen, 'rb');
        try {
            Storage::disk(self::DISCO)->writeStream($ruta, $flujo);
        } finally {
            is_resource($flujo) && fclose($flujo);
        }
        if (hash_file('sha256', Storage::disk(self::DISCO)->path($ruta)) !== $sha256) {
            Storage::disk(self::DISCO)->delete($ruta);
            throw AccionBloqueada::por(Motivo::de("«{$archivo->getClientOriginalName()}» no se guardó completo.", 'Vuelve a subirlo. Si se repite, avisa a soporte técnico: puede faltar espacio en el disco.'));
        }

        ArchivoAcervo::create([
            'documento_id' => $documento->id,
            'orden' => $orden,
            'disco' => self::DISCO,
            'ruta' => $ruta,
            'nombre_original' => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'mime' => $mime,
            'bytes' => (int) $archivo->getSize(),
            'sha256' => $sha256,
            'subido_por' => $usuario->id,
        ]);

        return $ruta;
    }

    /** @param array{espacios?: list<int>, titulos?: list<int>, inhumados?: list<int>} $vinculos */
    private function sincronizarVinculos(DocumentoAcervo $documento, array $vinculos): void
    {
        $documento->espacios()->sync(Espacio::whereKey($vinculos['espacios'] ?? [])->pluck('id'));
        $documento->titulos()->sync(Titulo::whereKey($vinculos['titulos'] ?? [])->pluck('id'));
        $documento->inhumados()->sync(Inhumado::whereKey($vinculos['inhumados'] ?? [])->pluck('id'));
    }

    private function datosPermitidos(array $datos): array
    {
        $limpios = array_intersect_key($datos, array_flip(['tipo_id', 'titulo', 'descripcion', 'anio_desde', 'anio_hasta', 'ubicacion_fisica', 'observaciones']));

        return array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $limpios);
    }

    private function validarPeriodo(array $datos): void
    {
        $desde = $datos['anio_desde'] ?? null;
        $hasta = $datos['anio_hasta'] ?? null;
        $actual = (int) Carbon::now()->year;
        foreach (array_filter([$desde, $hasta]) as $anio) {
            if ($anio < 1800 || $anio > $actual) {
                throw AccionBloqueada::por(Motivo::de("El año {$anio} no es válido.", "Escribe un año entre 1800 y {$actual}."));
            }
        }
        if ($desde && $hasta && $desde > $hasta) {
            throw AccionBloqueada::por(Motivo::de('El año «desde» es posterior al año «hasta».', 'Revisa el periodo del documento.'));
        }
    }

    private function exigir(User $usuario, Permiso $permiso, string $accion): void
    {
        if (! $usuario->puede($permiso)) {
            throw AccionBloqueada::por(Motivo::de("Tu usuario no puede {$accion}.", 'Pide a quien administra el sistema el permiso «'.$permiso->getLabel().'».'));
        }
    }

    private function exigirVigente(DocumentoAcervo $documento): void
    {
        if ($documento->retirado()) {
            throw AccionBloqueada::por(Motivo::de("El documento {$documento->folio} está retirado.", 'Ya no se puede modificar; registra uno nuevo si hace falta.'));
        }
    }
}
