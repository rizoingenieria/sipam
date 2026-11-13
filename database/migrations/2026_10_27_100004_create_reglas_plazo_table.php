<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas de plazo mínimo para exhumar, configurables y versionadas.
 * Una regla aplica si coinciden su motivo, su régimen y su rango de edad (NULL = cualquiera).
 * Cuando aplican varias, manda la más larga.
 * Fundamento: arts. 29, 38, 41 fr. III y 105.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglas_plazo', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50);
            $table->string('descripcion', 255);
            $table->string('motivo', 30)->nullable();         // App\Enums\MotivoExhumacion; NULL = todos
            $table->string('regimen_clave', 30)->nullable();  // regimenes.clave; NULL = cualquiera o sin título
            $table->unsignedSmallInteger('edad_minima')->nullable(); // edad al fallecer, inclusive
            $table->unsignedSmallInteger('edad_maxima')->nullable(); // inclusive
            $table->boolean('solo_panteon_saturado')->default(false); // art. 105
            $table->unsignedSmallInteger('anios_minimos');
            $table->string('comparacion', 20)->default('al_menos'); // App\Enums\ComparacionPlazo
            $table->string('fundamento', 255);
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->unsignedTinyInteger('version_abierta')->virtualAs('IF(vigente_hasta IS NULL, 1, NULL)');
            $table->timestamps();

            $table->unique(['clave', 'version_abierta']);
        });

        DB::statement('ALTER TABLE reglas_plazo ADD CONSTRAINT reglas_plazo_anios_positivos CHECK (anios_minimos > 0)');
        DB::statement('ALTER TABLE reglas_plazo ADD CONSTRAINT reglas_plazo_edades_ordenadas CHECK (edad_minima IS NULL OR edad_maxima IS NULL OR edad_maxima >= edad_minima)');
        DB::statement('ALTER TABLE reglas_plazo ADD CONSTRAINT reglas_plazo_vigencia_ordenada CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reglas_plazo');
    }
};
