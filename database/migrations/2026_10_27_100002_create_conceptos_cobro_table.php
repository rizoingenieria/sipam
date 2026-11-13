<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conceptos del tabulador: qué se cobra (título, servicio, obra, documento).
 * Los importes viven en «cuotas», versionados por acuerdo de Cabildo.
 * Fundamento: arts. 19 fr. II, 65, 69 y 70.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conceptos_cobro', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->string('categoria', 20);      // App\Enums\CategoriaConcepto
            $table->boolean('por_seccion')->default(false); // la cuota cambia según la sección del lote
            $table->string('unidad', 30)->default('servicio'); // servicio, m², pieza…
            $table->string('fundamento', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conceptos_cobro');
    }
};
