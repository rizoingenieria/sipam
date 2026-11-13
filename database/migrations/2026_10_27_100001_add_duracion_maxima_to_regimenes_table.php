<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tope acumulado de un régimen renovable: la temporalidad va de 7 años (mínima) a 21 (máxima).
 * Fundamento: art. 3, fr. XLII y la que el texto publicado numera como XL (temporalidad máxima).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regimenes', function (Blueprint $table) {
            $table->unsignedSmallInteger('duracion_maxima_anios')->nullable()->after('duracion_anios');
        });

        DB::statement('ALTER TABLE regimenes ADD CONSTRAINT regimenes_maxima_cubre_minima CHECK (duracion_maxima_anios IS NULL OR duracion_maxima_anios >= duracion_anios)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE regimenes DROP CHECK regimenes_maxima_cubre_minima');

        Schema::table('regimenes', function (Blueprint $table) {
            $table->dropColumn('duracion_maxima_anios');
        });
    }
};
