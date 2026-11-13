<?php

namespace App\Models;

use App\Enums\TipoParametro;
use App\Models\Concerns\Versionable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Parámetro normativo editable desde la interfaz.
 * Uso: Parametro::entero('testigos_requeridos'), Parametro::decimal('monumento_altura_maxima_m').
 */
class Parametro extends Model
{
    use Versionable;

    protected $table = 'parametros';

    protected array $columnasGeneradas = ['version_abierta'];

    /** Columnas que identifican al mismo registro en todas sus versiones. */
    protected array $llaveVersion = ['clave'];

    /** Corregir la redacción no cambia el valor: se permite en vigor. */
    protected array $editablesEnVigor = ['nombre', 'ayuda', 'fundamento', 'grupo', 'orden'];

    protected $fillable = [
        'clave', 'grupo', 'nombre', 'ayuda', 'tipo', 'valor', 'unidad', 'minimo', 'maximo',
        'fundamento', 'orden', 'vigente_desde', 'vigente_hasta',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoParametro::class,
            'minimo' => 'decimal:2',
            'maximo' => 'decimal:2',
            'orden' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Parametro $p): void {
            if ($error = $p->errorDeValor()) {
                throw ValidationException::withMessages(['valor' => $error]);
            }
        });
    }

    /** Mensaje en lenguaje llano si el valor no sirve; null si está bien. */
    public function errorDeValor(): ?string
    {
        $valor = trim((string) $this->valor);
        $nombre = $this->nombre ?: $this->clave;

        return match ($this->tipo) {
            TipoParametro::Entero => preg_match('/^\d+$/', $valor) !== 1
                ? "«{$nombre}» debe ser un número entero, sin decimales."
                : $this->errorDeRango((float) $valor, $nombre),
            TipoParametro::Decimal => ! is_numeric($valor)
                ? "«{$nombre}» debe ser un número, por ejemplo 0.90."
                : $this->errorDeRango((float) $valor, $nombre),
            TipoParametro::Serie => preg_match('/^[A-Z0-9]{1,10}$/', $valor) !== 1
                ? "«{$nombre}» debe llevar de 1 a 10 letras mayúsculas o números, sin espacios ni acentos (por ejemplo, INH)."
                : null,
            TipoParametro::Texto => $valor === '' ? "Falta el valor de «{$nombre}»." : null,
            null => 'Elige el tipo de dato del parámetro.',
        };
    }

    private function errorDeRango(float $valor, string $nombre): ?string
    {
        $unidad = $this->unidad ? " {$this->unidad}" : '';
        if ($this->minimo !== null && $valor < (float) $this->minimo) {
            return "«{$nombre}» no puede ser menor que ".(float) $this->minimo."{$unidad}.";
        }
        if ($this->maximo !== null && $valor > (float) $this->maximo) {
            return "«{$nombre}» no puede ser mayor que ".(float) $this->maximo."{$unidad}.";
        }

        return null;
    }

    // ── Lectura ───────────────────────────────────────────────────────────────

    public static function vigente(string $clave, ?CarbonInterface $fecha = null): self
    {
        return static::where('clave', $clave)->vigenteEn($fecha)->first()
            ?? throw new RuntimeException(
                "Falta configurar el parámetro «{$clave}». Pide al administrador que lo registre en Reglamento › Parámetros, o que ejecute el diagnóstico (php artisan panteones:diagnostico)."
            );
    }

    public static function entero(string $clave, ?CarbonInterface $fecha = null): int
    {
        return (int) static::vigente($clave, $fecha)->valor;
    }

    public static function decimal(string $clave, ?CarbonInterface $fecha = null): float
    {
        return (float) static::vigente($clave, $fecha)->valor;
    }

    public static function texto(string $clave, ?CarbonInterface $fecha = null): string
    {
        return (string) static::vigente($clave, $fecha)->valor;
    }

    /** Claves que el sistema necesita, según el archivo de siembra. */
    public static function clavesRequeridas(): array
    {
        $datos = json_decode((string) file_get_contents(database_path('seeders/data/parametros.json')), true) ?? [];

        return array_column($datos, 'nombre', 'clave');
    }
}
