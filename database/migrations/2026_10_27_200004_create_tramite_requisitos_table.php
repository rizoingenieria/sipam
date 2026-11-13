<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requisitos de cada trámite, copiados del catálogo al capturarlo: el trámite guarda la versión
 * exacta que se le exigió. Una fila por documento esperado (dos testigos = dos filas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tramite_requisitos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramites')->restrictOnDelete();
            $table->foreignId('requisito_id')->constrained('requisitos')->restrictOnDelete();
            $table->unsignedTinyInteger('ordinal')->default(1);
            $table->string('estado', 20)->default('pendiente'); // App\Enums\EstadoRequisitoEntregado
            $table->foreignId('persona_id')->nullable()->constrained('personas')->restrictOnDelete();
            $table->date('fecha_expedicion')->nullable();
            $table->string('folio_documento', 60)->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('revisado_en')->nullable();
            $table->timestamps();

            $table->unique(['tramite_id', 'requisito_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramite_requisitos');
    }
};
