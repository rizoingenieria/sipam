<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de restos: inhumación, exhumación, reinhumación y traslado.
 * Son la fuente de los libros cronológicos y no se editan ni se borran (el modelo lo impide).
 * Fundamento: art. 21, fr. III, incisos a) a d); arts. 36, 41, 42 y 68.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inhumado_id')->constrained('inhumados')->restrictOnDelete();
            $table->string('tipo', 20); // App\Enums\TipoMovimiento
            $table->dateTime('fecha_hora');
            $table->foreignId('espacio_origen_id')->nullable()->constrained('espacios')->restrictOnDelete();
            $table->foreignId('espacio_destino_id')->nullable()->constrained('espacios')->restrictOnDelete();
            $table->string('destino_externo', 255)->nullable();   // traslado fuera del municipio
            $table->string('causa', 255)->nullable();             // causa de la exhumación, inciso b)
            $table->string('realizado_por', 150)->nullable();     // quien traslada, inciso c)
            $table->foreignId('solicitante_id')->nullable()->constrained('personas')->restrictOnDelete(); // inciso d)
            $table->string('permiso_folio', 50)->nullable();      // permiso del Registro Civil o autoridad
            $table->date('permiso_fecha')->nullable();
            $table->boolean('por_orden_autoridad')->default(false); // judicial o fiscalía: sin pago (art. 68)
            $table->foreignId('registrado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['tipo', 'fecha_hora']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos');
    }
};
