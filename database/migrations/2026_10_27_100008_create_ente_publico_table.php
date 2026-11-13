<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca y membrete del municipio (una sola fila). Permite reutilizar el sistema en otro
 * ayuntamiento sin tocar código. Los colores no van aquí: la interfaz sigue «Papel y tinta».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ente_publico', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_sistema', 100);
            $table->string('nombre_ente', 200);     // H. Ayuntamiento Constitucional de …
            $table->string('municipio', 100);
            $table->string('estado', 100);
            $table->string('domicilio', 255)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('sitio_web', 150)->nullable();
            $table->string('escudo_path', 255)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('leyenda_documentos', 500)->nullable(); // pie fijo de los PDF
            $table->string('reglamento_cita', 500)->nullable();    // cita completa del reglamento
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ente_publico');
    }
};
