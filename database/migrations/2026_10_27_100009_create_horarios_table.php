<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horarios por panteón: de servicio al público y de recepción de documentos.
 * Día de la semana ISO: 1 = lunes … 7 = domingo.
 * Fundamento: arts. 21 fr. II y 37.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panteon_id')->constrained('panteones')->restrictOnDelete();
            $table->string('tipo', 20); // App\Enums\TipoHorario
            $table->unsignedTinyInteger('dia_semana');
            $table->time('abre');
            $table->time('cierra');
            $table->timestamps();

            $table->unique(['panteon_id', 'tipo', 'dia_semana']);
        });

        DB::statement('ALTER TABLE horarios ADD CONSTRAINT horarios_dia_valido CHECK (dia_semana BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE horarios ADD CONSTRAINT horarios_cierre_posterior CHECK (cierra > abre)');
    }

    public function down(): void
    {
        Schema::dropIfExists('horarios');
    }
};
