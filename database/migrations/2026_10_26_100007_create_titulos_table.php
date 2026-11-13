<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Títulos de derechos de uso (en pantalla, «título»).
 * Invariante: un solo título vigente por espacio.
 * Un cambio de propietario no edita el título: emite uno nuevo y el anterior queda «sustituido».
 * Fundamento: art. 3, fr. XLIV; arts. 18 fr. II, 24 y 25.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('espacio_id')->constrained('espacios')->restrictOnDelete();
            $table->foreignId('regimen_id')->constrained('regimenes')->restrictOnDelete();
            $table->string('folio', 30)->unique();            // art. 25, fr. I
            $table->string('expediente', 30);                  // art. 25, fr. II
            $table->string('origen', 20);                      // App\Enums\OrigenTitulo
            $table->string('estado', 20)->default('vigente');  // App\Enums\EstadoTitulo
            $table->date('fecha_emision');                     // art. 25, fr. VIII
            $table->date('fecha_vencimiento');                 // emisión + años del régimen
            $table->unsignedBigInteger('titulo_anterior_id')->nullable();
            $table->text('observaciones')->nullable();
            $table->unsignedTinyInteger('vigente_flag')
                ->virtualAs("IF(estado = 'vigente', 1, NULL)");
            $table->timestamps();

            $table->unique(['espacio_id', 'vigente_flag']);
            $table->index('expediente');
            $table->index(['estado', 'fecha_vencimiento']);
        });

        // FK autorreferencial aparte del create (evita el error 1822).
        Schema::table('titulos', function (Blueprint $table) {
            $table->foreign('titulo_anterior_id')->references('id')->on('titulos')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE titulos ADD CONSTRAINT titulos_vencimiento_posterior CHECK (fecha_vencimiento >= fecha_emision)');
    }

    public function down(): void
    {
        Schema::dropIfExists('titulos');
    }
};
