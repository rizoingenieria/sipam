<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Espacios dentro de un lote: fosa, nicho, cripta o gaveta.
 * Es la unidad que se titula, se ocupa y se tramita.
 * Fundamento: art. 3, fr. XI, XIX y XXIX; art. 25, fr. III; arts. 63, 64, 86 y 90.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('espacios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lote_id')->constrained('lotes')->restrictOnDelete();
            $table->string('tipo', 20); // App\Enums\TipoEspacio
            $table->string('numero', 20);
            $table->decimal('largo_m', 5, 2)->nullable();
            $table->decimal('ancho_m', 5, 2)->nullable();
            $table->decimal('profundidad_m', 5, 2)->nullable();
            $table->boolean('es_fosa_comun')->default(false);          // arts. 46 y 90
            $table->boolean('es_monumento_historico')->default(false); // art. 86 (anteriores a 1931)
            $table->boolean('habilitado')->default(true);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['lote_id', 'numero']);
            $table->index('tipo');
        });

        DB::statement('ALTER TABLE espacios ADD CONSTRAINT espacios_dimensiones_positivas CHECK (
            (largo_m IS NULL OR largo_m > 0) AND (ancho_m IS NULL OR ancho_m > 0) AND (profundidad_m IS NULL OR profundidad_m > 0)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('espacios');
    }
};
