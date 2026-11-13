<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas que intervienen en un trámite y su papel: nuevo titular, testigo, autorizante, constructor.
 * comparecio_en registra la comparecencia presencial: no se acepta carta poder.
 * Fundamento: arts. 27 fr. IV, 28, 33 fr. V, 52 fr. III; hojas de requisitos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tramite_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramites')->restrictOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->restrictOnDelete();
            $table->string('papel', 30); // App\Enums\ParteTramite
            $table->foreignId('parentesco_id')->nullable()->constrained('parentescos')->restrictOnDelete();
            $table->unsignedSmallInteger('anios_residencia')->nullable(); // testigos
            $table->dateTime('comparecio_en')->nullable();
            $table->foreignId('comparecencia_registrada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tramite_id', 'persona_id', 'papel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramite_participantes');
    }
};
