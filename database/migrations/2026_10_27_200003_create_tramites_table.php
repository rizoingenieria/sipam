<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trámites: adquisición, regularización, inhumación, exhumación y permiso de construcción.
 * Todo trámite actúa sobre un espacio. Los datos propios de cada tipo van en «datos» (JSON);
 * la causa de fallecimiento, por ser sensible, va aparte y cifrada.
 * Fundamento: arts. 21 fr. XXIX (cotejo), 27, 33 a 36, 38 a 44, 51 a 59.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tramites', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 30)->unique();
            $table->string('tipo', 30);                       // App\Enums\TipoTramite
            $table->string('estado', 30)->default('capturado'); // App\Enums\EstadoTramite
            $table->foreignId('espacio_id')->constrained('espacios')->restrictOnDelete();
            $table->foreignId('titulo_id')->nullable()->constrained('titulos')->restrictOnDelete();
            $table->foreignId('inhumado_id')->nullable()->constrained('inhumados')->restrictOnDelete();
            $table->foreignId('solicitante_id')->constrained('personas')->restrictOnDelete();
            $table->string('motivo_exhumacion', 30)->nullable(); // App\Enums\MotivoExhumacion
            $table->boolean('por_orden_autoridad')->default(false); // judicial o fiscalía: sin pago (art. 68)
            $table->json('datos')->nullable();
            $table->text('causa_fallecimiento')->nullable(); // cifrada; sólo inhumación

            // Bandera de pago de derechos al corriente (arts. 27 fr. II y 33 fr. II).
            $table->boolean('pago_derechos_al_corriente')->default(false);
            $table->string('pago_derechos_folio', 50)->nullable();
            $table->date('pago_derechos_fecha')->nullable();

            // Cotejo exhaustivo previo a todo acto (art. 21, fr. XXIX).
            $table->dateTime('cotejo_en')->nullable();
            $table->foreignId('cotejo_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cotejo_observaciones')->nullable();

            $table->date('publicado_en')->nullable();   // estrados y lote (art. 34)
            $table->date('caduca_en')->nullable();      // adquisición: 12 meses (art. 34)
            $table->json('evaluacion_plazo')->nullable(); // última evaluación del plazo y su regla

            $table->foreignId('titulo_resultante_id')->nullable()->constrained('titulos')->restrictOnDelete();
            $table->text('resolucion')->nullable();
            $table->dateTime('resuelto_en')->nullable();
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('capturado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tipo', 'estado']);
            $table->index(['estado', 'caduca_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramites');
    }
};
