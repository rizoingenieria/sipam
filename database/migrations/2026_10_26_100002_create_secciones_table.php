<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secciones de cada panteón. La cuota del título depende de la sección.
 * Fundamento: art. 25, fr. IV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panteon_id')->constrained('panteones')->restrictOnDelete();
            $table->string('clave', 20);
            $table->string('nombre', 150)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['panteon_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secciones');
    }
};
