<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cesiones o donaciones de derechos de uso.
 * Invariante: un espacio sólo puede cederse una vez a la misma persona (art. 27, fr. VI).
 * Una fila por cesionario; la herencia o legado (art. 67) no es cesión y no pasa por aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cesiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('espacio_id')->constrained('espacios')->restrictOnDelete();
            $table->foreignId('titulo_anterior_id')->constrained('titulos')->restrictOnDelete();
            $table->foreignId('titulo_nuevo_id')->constrained('titulos')->restrictOnDelete();
            $table->foreignId('cesionario_id')->constrained('personas')->restrictOnDelete();
            $table->date('fecha');
            $table->timestamps();

            $table->unique(['espacio_id', 'cesionario_id'], 'cesiones_una_vez_por_persona');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cesiones');
    }
};
