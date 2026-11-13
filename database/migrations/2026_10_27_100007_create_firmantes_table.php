<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Titular de cada cargo por periodo. Un documento toma al firmante vigente en su fecha de
 * emisión, así que un cambio de administración no altera los documentos ya emitidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firmantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cargo_id')->constrained('cargos')->restrictOnDelete();
            $table->string('titulo_profesional', 20)->nullable(); // Lic., Mtro., Ing.…
            $table->string('nombre', 200);
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->unsignedTinyInteger('version_abierta')->virtualAs('IF(vigente_hasta IS NULL, 1, NULL)');
            $table->timestamps();

            $table->unique(['cargo_id', 'version_abierta']);
        });

        DB::statement('ALTER TABLE firmantes ADD CONSTRAINT firmantes_vigencia_ordenada CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)');
    }

    public function down(): void
    {
        Schema::dropIfExists('firmantes');
    }
};
