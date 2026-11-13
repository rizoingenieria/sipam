<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consecutivos por serie y año para folios oficiales (trámites, órdenes de pago, títulos).
 * Se toman con bloqueo de fila: dos capturas simultáneas nunca reciben el mismo folio.
 * Para continuar la numeración del legado, se precarga «ultimo» con el último folio emitido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consecutivos', function (Blueprint $table) {
            $table->id();
            $table->string('serie', 20);
            $table->unsignedSmallInteger('anio');
            $table->unsignedInteger('ultimo')->default(0);
            $table->timestamps();

            $table->unique(['serie', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consecutivos');
    }
};
