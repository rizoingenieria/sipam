<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros normativos que el ayuntamiento ajusta desde la interfaz, sin tocar código:
 * testigos, residencia, caducidad, medidas de monumentos, series de folios, tamaño de archivos.
 * Versionados: una reforma registra el valor nuevo con su fecha y el anterior queda como historia.
 * Sustituye a config/panteones.php de la entrega 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametros', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 60);
            $table->string('grupo', 40);           // para agrupar en pantalla
            $table->string('nombre', 150);         // en lenguaje llano
            $table->string('ayuda', 500)->nullable(); // qué controla y cuándo cambiarlo
            $table->string('tipo', 20);            // App\Enums\TipoParametro
            $table->string('valor', 100);
            $table->string('unidad', 30)->nullable();
            $table->decimal('minimo', 12, 2)->nullable();
            $table->decimal('maximo', 12, 2)->nullable();
            $table->string('fundamento', 255)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->unsignedTinyInteger('version_abierta')->virtualAs('IF(vigente_hasta IS NULL, 1, NULL)');
            $table->timestamps();

            $table->unique(['clave', 'version_abierta']);
            $table->index(['clave', 'vigente_desde']);
        });

        DB::statement('ALTER TABLE parametros ADD CONSTRAINT parametros_vigencia_ordenada CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)');
        DB::statement('ALTER TABLE parametros ADD CONSTRAINT parametros_rango_ordenado CHECK (minimo IS NULL OR maximo IS NULL OR maximo >= minimo)');
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros');
    }
};
