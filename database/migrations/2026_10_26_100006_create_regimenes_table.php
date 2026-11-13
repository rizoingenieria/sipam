<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regímenes de derecho de uso (perpetuidad, temporalidad), versionados.
 * Un cambio no edita la fila: cierra su vigencia y abre una nueva, para que cada título
 * conserve el régimen con el que se emitió.
 * Va en esta entrega porque el título depende de él; sus valores se siembran en la entrega 2.
 * Fundamento: art. 3, fr. XXX, XLII, XL (temporalidad máxima, así numerada) y XLIV; art. 27.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regimenes', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 30);          // perpetuidad, temporalidad…
            $table->string('nombre', 100);
            $table->unsignedSmallInteger('duracion_anios');
            $table->boolean('renovable')->default(false);
            $table->boolean('permite_cesion')->default(true); // art. 27: pendiente de Jurídico
            $table->string('fundamento', 255);
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            // Una sola versión abierta por clave (NULL no choca en el índice UNIQUE).
            $table->unsignedTinyInteger('version_abierta')
                ->virtualAs('IF(vigente_hasta IS NULL, 1, NULL)');
            $table->timestamps();

            $table->unique(['clave', 'version_abierta']);
        });

        DB::statement('ALTER TABLE regimenes ADD CONSTRAINT regimenes_duracion_positiva CHECK (duracion_anios > 0)');
        DB::statement('ALTER TABLE regimenes ADD CONSTRAINT regimenes_vigencia_ordenada CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)');
    }

    public function down(): void
    {
        Schema::dropIfExists('regimenes');
    }
};
