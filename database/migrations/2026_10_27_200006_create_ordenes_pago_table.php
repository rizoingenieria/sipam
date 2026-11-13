<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes de pago. El sistema no cobra: emite la orden con la cuota vigente y registra el recibo
 * que expide Tesorería. El importe y la versión de la cuota quedan congelados al emitirse.
 * Fundamento: arts. 19 fr. II, 36, 52 fr. V, 65 y 70.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_pago', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 30)->unique();
            $table->foreignId('tramite_id')->constrained('tramites')->restrictOnDelete();
            $table->foreignId('concepto_id')->constrained('conceptos_cobro')->restrictOnDelete();
            $table->foreignId('cuota_id')->constrained('cuotas')->restrictOnDelete();
            $table->decimal('importe', 12, 2);
            $table->string('estado', 20)->default('emitida'); // App\Enums\EstadoOrdenPago
            $table->foreignId('emitida_por')->constrained('users')->restrictOnDelete();
            $table->string('recibo_folio', 50)->nullable();   // recibo oficial de Tesorería
            $table->date('pagada_en')->nullable();
            $table->foreignId('pago_registrado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tramite_id', 'concepto_id']);
            $table->index(['estado', 'pagada_en']);
        });

        DB::statement('ALTER TABLE ordenes_pago ADD CONSTRAINT ordenes_pago_importe_no_negativo CHECK (importe >= 0)');
        DB::statement("ALTER TABLE ordenes_pago ADD CONSTRAINT ordenes_pago_pagada_completa CHECK (estado <> 'pagada' OR (recibo_folio IS NOT NULL AND pagada_en IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_pago');
    }
};
