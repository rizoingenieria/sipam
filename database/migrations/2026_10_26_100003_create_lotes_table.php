<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de cada sección. Clave natural: panteón + sección + lote.
 * El número es texto porque el legado puede traer numeraciones alfanuméricas.
 * Fundamento: art. 3, fr. XXVI; art. 25, fr. V.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->constrained('secciones')->restrictOnDelete();
            $table->string('numero', 20);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['seccion_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes');
    }
};
