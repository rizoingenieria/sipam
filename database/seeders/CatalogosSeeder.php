<?php

namespace Database\Seeders;

use App\Models\Cargo;
use App\Models\ConceptoCobro;
use App\Models\EntePublico;
use App\Models\Parametro;
use App\Models\Parentesco;
use App\Models\Regimen;
use App\Models\ReglaPlazo;
use App\Models\Requisito;
use App\Models\Rol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Siembra los catálogos desde database/seeders/data/*.json.
 *
 * Idempotente y conservador: sólo crea lo que falta. Nunca sobrescribe un registro existente,
 * para no deshacer lo que el ayuntamiento haya editado desde la interfaz.
 * En los catálogos versionados, «existe» significa «hay una versión abierta con esa clave».
 *
 * No siembra importes del tabulador, firmantes ni usuarios: son datos que el ayuntamiento captura.
 * La primera cuenta de administración se crea con: php artisan panteones:crear-administrador
 *
 * Uso: php artisan db:seed --class=CatalogosSeeder
 */
class CatalogosSeeder extends Seeder
{
    /** @var array<string, int> */
    public array $creados = [];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->versionados(Regimen::class, 'regimenes', ['clave']);
            $this->versionados(ReglaPlazo::class, 'reglas_plazo', ['clave']);
            $this->versionados(Requisito::class, 'requisitos', ['tipo_tramite', 'clave'], vigenteDesde: '2023-05-01');
            $this->versionados(Parametro::class, 'parametros', ['clave']);
            $this->simples(ConceptoCobro::class, 'conceptos_cobro');
            $this->simples(Cargo::class, 'cargos');
            $this->simples(Parentesco::class, 'parentescos');
            $this->simples(Rol::class, 'roles');

            if (! EntePublico::query()->exists()) {
                EntePublico::create($this->leer('ente_publico'));
                $this->creados['ente_publico'] = 1;
            }
        });

        foreach ($this->creados as $catalogo => $n) {
            $this->command?->info("{$catalogo}: {$n} creados");
        }
        if ($this->creados === []) {
            $this->command?->info('Catálogos al día: no faltaba nada.');
        }
    }

    /** @param class-string<Model> $modelo */
    private function versionados(string $modelo, string $archivo, array $llave, ?string $vigenteDesde = null): void
    {
        foreach ($this->leer($archivo) as $fila) {
            $fila['vigente_desde'] ??= $vigenteDesde;
            $existe = $modelo::query()->where(array_intersect_key($fila, array_flip($llave)))->whereNull('vigente_hasta')->exists();

            if (! $existe) {
                $modelo::create($fila);
                $this->creados[$archivo] = ($this->creados[$archivo] ?? 0) + 1;
            }
        }
    }

    /** @param class-string<Model> $modelo */
    private function simples(string $modelo, string $archivo): void
    {
        foreach ($this->leer($archivo) as $fila) {
            $registro = $modelo::firstOrCreate(['clave' => $fila['clave']], $fila);

            if ($registro->wasRecentlyCreated) {
                $this->creados[$archivo] = ($this->creados[$archivo] ?? 0) + 1;
            }
        }
    }

    private function leer(string $archivo): array
    {
        $ruta = database_path("seeders/data/{$archivo}.json");
        $datos = json_decode((string) file_get_contents($ruta), true);

        if (! is_array($datos)) {
            throw new RuntimeException("JSON inválido en {$ruta}: ".json_last_error_msg());
        }

        return $datos;
    }
}
