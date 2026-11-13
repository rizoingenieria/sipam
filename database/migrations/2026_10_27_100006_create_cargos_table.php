<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargos que intervienen o firman (Presidente, Síndico, Secretario, Edil del Ramo, Encargado…).
 * Quién ocupa cada cargo y cuándo vive en «firmantes».
 * Fundamento: arts. 8, 12, 16 a 21.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->boolean('firma_documentos')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargos');
    }
};
