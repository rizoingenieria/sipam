<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de cambios: quién cambió qué, cuándo y desde dónde. Sólo se agrega; nunca se edita ni se borra
 * (el modelo lo impide). Para Contraloría y para la evidencia ante el ORFIS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->useCurrent();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evento', 30);                 // App\Enums\EventoBitacora
            $table->string('modelo_tipo', 120)->nullable(); // clase del registro afectado
            $table->unsignedBigInteger('modelo_id')->nullable();
            $table->string('descripcion', 255);           // qué registro, en palabras: «Cuota: Inhumación»
            $table->json('cambios')->nullable();          // {"campo": {"antes": x, "despues": y}}
            $table->string('ip', 45)->nullable();
            $table->string('agente', 255)->nullable();
            $table->index(['modelo_tipo', 'modelo_id']);
            $table->index('created_at');
            $table->index(['usuario_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora');
    }
};
