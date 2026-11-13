<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de cambios de estado de cada trámite: quién, cuándo y por qué. Sólo se agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tramite_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramites')->restrictOnDelete();
            $table->string('de_estado', 30)->nullable();
            $table->string('a_estado', 30);
            // NULL = acción del sistema (p. ej., la caducidad automática de la adquisición).
            $table->foreignId('usuario_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('comentario')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tramite_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramite_historial');
    }
};
