<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personas inhumadas. espacio_id es la ubicación actual: queda en NULL si los restos
 * salieron del municipio por traslado. El historial completo vive en «movimientos».
 * La causa de fallecimiento es dato sensible: se guarda cifrada (cast «encrypted»).
 * Fundamento: art. 21, fr. III, inciso a); arts. 38, 44 y 46.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inhumados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('espacio_id')->nullable()->constrained('espacios')->restrictOnDelete();
            $table->string('nombre', 100);
            $table->string('primer_apellido', 100)->nullable();
            $table->string('segundo_apellido', 100)->nullable();
            $table->string('nombre_completo', 310)
                ->storedAs("CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)");
            $table->boolean('es_desconocido')->default(false); // art. 3, fr. XXXII; art. 46
            $table->string('sexo', 20)->default('no_especificado'); // App\Enums\Sexo
            $table->date('fecha_nacimiento')->nullable();
            $table->date('fecha_defuncion')->nullable();
            // Edad según el acta; base de las reglas de plazo de exhumación.
            $table->unsignedSmallInteger('edad_al_fallecer')->nullable();
            $table->string('numero_acta_defuncion', 50)->nullable();
            $table->string('lugar_fallecimiento', 255)->nullable();
            $table->text('causa_fallecimiento')->nullable(); // cifrada
            $table->date('fecha_inhumacion')->nullable();
            $table->string('estado', 30)->default('inhumado'); // App\Enums\EstadoInhumado
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index('nombre_completo');
            $table->index(['espacio_id', 'fecha_inhumacion']);
            $table->index('numero_acta_defuncion');
        });

        DB::statement('ALTER TABLE inhumados ADD CONSTRAINT inhumados_edad_razonable CHECK (edad_al_fallecer IS NULL OR edad_al_fallecer <= 130)');
        DB::statement('ALTER TABLE inhumados ADD CONSTRAINT inhumados_fechas_ordenadas CHECK (
            (fecha_nacimiento IS NULL OR fecha_defuncion IS NULL OR fecha_defuncion >= fecha_nacimiento)
            AND (fecha_defuncion IS NULL OR fecha_inhumacion IS NULL OR fecha_inhumacion >= fecha_defuncion)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('inhumados');
    }
};
