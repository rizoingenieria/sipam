<?php

namespace Database\Seeders;

use App\Enums\TipoEspacio;
use App\Models\Cargo;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\EntePublico;
use App\Models\Firmante;
use App\Models\Panteon;
use App\Models\Rol;
use App\Models\Seccion;
use App\Models\User;
use App\Services\Inventario\GeneradorInventario;
use App\Support\Auditoria;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Datos de DEMOSTRACIÓN para probar el sistema en un equipo de desarrollo:
 * un panteón con dos secciones, una cuenta por rol, todas las cuotas y un firmante por cargo.
 * Los nombres, importes y acuerdos son ficticios. Nunca corre en producción.
 *
 *   php artisan db:seed --class=CatalogosSeeder
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public const CLAVE = 'demo-panteon-2026';

    /** rol => [correo, nombre] */
    private const CUENTAS = [
        'administrador' => ['admin@demo.test', 'Ana López Ruiz'],
        'ventanilla' => ['ventanilla@demo.test', 'Luis Hernández Mora'],
        'encargado_panteones' => ['encargado@demo.test', 'Rosa Méndez Cruz'],
        'tesoreria' => ['tesoreria@demo.test', 'Jorge Ortiz Lara'],
        'secretaria_ayuntamiento' => ['secretaria@demo.test', 'Patricia Vela Soto'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder no se ejecuta en producción.');
        }
        if (Panteon::where('clave', 'DEMO')->exists()) {
            $this->command?->warn('Los datos de demostración ya están cargados.');

            return;
        }

        Auditoria::sinAnotar(fn () => DB::transaction(function (): void {
            EntePublico::query()->first()?->update([
                'domicilio' => 'Palacio Municipal s/n, Centro (dato de demostración)',
                'telefono' => '228 000 0000',
            ]);

            foreach (self::CUENTAS as $rol => [$correo, $nombre]) {
                $u = User::create(['name' => $nombre, 'email' => $correo, 'password' => self::CLAVE, 'activo' => true]);
                $u->roles()->attach(Rol::where('clave', $rol)->pluck('id'));
            }

            $p = Panteon::create(['clave' => 'DEMO', 'nombre' => 'Panteón Municipal (demostración)', 'domicilio' => 'Calle del Panteón s/n']);
            $secciones = [
                Seccion::create(['panteon_id' => $p->id, 'clave' => 'A', 'nombre' => 'Antigua']),
                Seccion::create(['panteon_id' => $p->id, 'clave' => 'B', 'nombre' => 'Nueva']),
            ];
            $inventario = app(GeneradorInventario::class);
            $inventario->generar($secciones[0], 1, 40, 1, TipoEspacio::Fosa);
            $inventario->generar($secciones[1], 1, 20, 2, TipoEspacio::Fosa);

            $acuerdo = 'Acta de Cabildo DEMO/2026, punto 1';
            foreach (ConceptoCobro::where('activo', true)->get() as $i => $c) {
                $importe = 300 + 50 * $i;
                foreach ($c->por_seccion ? $secciones : [null] as $s) {
                    Cuota::create([
                        'concepto_id' => $c->id, 'seccion_id' => $s?->id, 'importe' => $s && $s->clave === 'A' ? $importe * 2 : $importe,
                        'acuerdo_cabildo' => $acuerdo, 'vigente_desde' => '2026-01-01',
                    ]);
                }
            }

            foreach (Cargo::all() as $c) {
                Firmante::create(['cargo_id' => $c->id, 'titulo_profesional' => 'C.', 'nombre' => 'Firmante de prueba ('.$c->nombre.')', 'vigente_desde' => '2025-01-01']);
            }
        }));

        $this->command?->info('Cuentas de demostración (contraseña «'.self::CLAVE.'»): '.implode(', ', array_column(self::CUENTAS, 0)));
    }
}
