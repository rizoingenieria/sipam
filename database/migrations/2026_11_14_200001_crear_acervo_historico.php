<?php

use App\Models\Parametro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acervo histórico: los documentos originales escaneados (libros de registro, títulos antiguos, actas, planos…)
 * guardados tal cual, con su huella SHA-256, y ligados a los espacios, títulos y personas sepultadas.
 *
 * - tipos_documento_acervo: catálogo editable desde pantalla.
 * - documentos_acervo: la ficha (qué es, de cuándo, dónde está el original en papel). No se borra: se retira.
 * - archivos_acervo: las páginas o archivos de cada documento. Inmutables.
 * - acervo_vinculos: a qué espacios, títulos o finados se refiere cada documento (muchos a muchos).
 */
return new class extends Migration
{
    public const PERMISOS = [
        'encargado_panteones' => ['acervo.consultar', 'acervo.cargar', 'acervo.retirar'],
        'ventanilla' => ['acervo.consultar'],
        'secretaria_ayuntamiento' => ['acervo.consultar'],
        'direccion_servicios' => ['acervo.consultar'],
        'juridico_contraloria' => ['acervo.consultar'],
    ];

    public function up(): void
    {
        Schema::create('tipos_documento_acervo', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 255)->nullable();
            $table->unsignedSmallInteger('orden')->default(100);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('documentos_acervo', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 30)->unique();
            $table->foreignId('tipo_id')->constrained('tipos_documento_acervo')->restrictOnDelete();
            $table->string('titulo', 255);
            $table->text('descripcion')->nullable();
            $table->unsignedSmallInteger('anio_desde')->nullable();
            $table->unsignedSmallInteger('anio_hasta')->nullable();
            $table->string('ubicacion_fisica', 255)->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('capturado_por')->constrained('users')->restrictOnDelete();
            $table->timestamp('retirado_en')->nullable();
            $table->foreignId('retirado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('motivo_retiro', 500)->nullable();
            $table->timestamps();

            $table->index(['anio_desde', 'anio_hasta']);
            $table->index('retirado_en');
        });

        Schema::create('archivos_acervo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('documento_id')->constrained('documentos_acervo')->restrictOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->string('disco', 30);
            $table->string('ruta', 255);
            $table->string('nombre_original', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->foreignId('subido_por')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('verificado_en')->nullable();
            $table->boolean('integro')->nullable();

            $table->unique(['documento_id', 'orden']);
            $table->index('sha256');
        });

        Schema::create('acervo_vinculos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('documento_id')->constrained('documentos_acervo')->cascadeOnDelete();
            $table->string('vinculable_type', 100);
            $table->unsignedBigInteger('vinculable_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['documento_id', 'vinculable_type', 'vinculable_id'], 'acervo_vinculo_unico');
            $table->index(['vinculable_type', 'vinculable_id']);
        });

        $ahora = now();
        DB::table('tipos_documento_acervo')->insert(array_map(fn (array $t) => $t + ['activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora], [
            ['clave' => 'libro_registro', 'orden' => 10, 'nombre' => 'Libro o foja de registro', 'descripcion' => 'Hojas de los libros de inhumaciones, exhumaciones o propietarios.'],
            ['clave' => 'titulo_antiguo', 'orden' => 20, 'nombre' => 'Título o constancia antigua', 'descripcion' => 'Títulos de propiedad o de derecho de uso expedidos antes del sistema.'],
            ['clave' => 'acta', 'orden' => 30, 'nombre' => 'Acta', 'descripcion' => 'Actas de defunción, de exhumación, de entrega o de cabildo.'],
            ['clave' => 'expediente', 'orden' => 40, 'nombre' => 'Expediente', 'descripcion' => 'Expediente completo de un trámite anterior al sistema.'],
            ['clave' => 'recibo', 'orden' => 50, 'nombre' => 'Recibo de pago', 'descripcion' => 'Recibos de derechos pagados en Tesorería.'],
            ['clave' => 'plano', 'orden' => 60, 'nombre' => 'Plano o croquis', 'descripcion' => 'Planos del panteón, de secciones o de lotes.'],
            ['clave' => 'fotografia', 'orden' => 70, 'nombre' => 'Fotografía', 'descripcion' => 'Fotografías de monumentos, criptas o del panteón.'],
            ['clave' => 'otro', 'orden' => 99, 'nombre' => 'Otro', 'descripcion' => null],
        ]));

        // Parámetros nuevos, sólo en instalaciones que ya tienen su catálogo cargado (en una nueva los carga
        // CatalogosSeeder desde parametros.json). Vigentes desde la misma fecha que el resto del catálogo.
        if (DB::table('parametros')->exists()) {
            foreach (self::parametros() as $p) {
                if (! DB::table('parametros')->where('clave', $p['clave'])->whereNull('vigente_hasta')->exists()) {
                    Parametro::create($p + ['vigente_desde' => '2023-05-01']);
                }
            }
        }

        // Permisos nuevos a los roles de fábrica que ya existen (en una instalación nueva los trae roles.json).
        foreach (self::PERMISOS as $clave => $nuevos) {
            $rol = DB::table('roles')->where('clave', $clave)->first();
            if ($rol) {
                $permisos = array_values(array_unique([...(json_decode($rol->permisos, true) ?: []), ...$nuevos]));
                DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($permisos)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acervo_vinculos');
        Schema::dropIfExists('archivos_acervo');
        Schema::dropIfExists('documentos_acervo');
        Schema::dropIfExists('tipos_documento_acervo');
        DB::table('parametros')->whereIn('clave', array_column(self::parametros(), 'clave'))->delete();
    }

    /** @return list<array<string, mixed>> */
    public static function parametros(): array
    {
        return [
            ['clave' => 'acervo_tamano_maximo_mb', 'grupo' => 'Archivos', 'orden' => 20, 'nombre' => 'Tamaño máximo de cada archivo del acervo histórico',
                'ayuda' => 'Un PDF escaneado a 300 dpi pesa de 0.5 a 1 MB por página. No puede ser mayor que el límite del servidor (SUBIDA_MAXIMA_MB).',
                'tipo' => 'entero', 'valor' => '100', 'unidad' => 'MB', 'minimo' => 1, 'maximo' => 1000, 'fundamento' => null],
            ['clave' => 'serie_folio_acervo', 'grupo' => 'Folios', 'orden' => 80, 'nombre' => 'Serie de folios del acervo histórico',
                'ayuda' => 'Letras al inicio del folio de cada documento del acervo. Si la cambias, la numeración de la serie nueva empieza en 1.',
                'tipo' => 'serie', 'valor' => 'ACV', 'unidad' => null, 'minimo' => null, 'maximo' => null, 'fundamento' => null],
        ];
    }
};
