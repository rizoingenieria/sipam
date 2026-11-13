<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importes del tabulador, versionados. Un cambio cierra la cuota anterior y abre otra;
 * cada orden de pago conserva la versión con la que se emitió.
 * seccion_id NULL = cuota general del concepto (conceptos que no dependen de la sección).
 * Fundamento: arts. 65, 69 y 70.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concepto_id')->constrained('conceptos_cobro')->restrictOnDelete();
            $table->foreignId('seccion_id')->nullable()->constrained('secciones')->restrictOnDelete();
            $table->decimal('importe', 12, 2);
            $table->string('acuerdo_cabildo', 255);  // acta y punto que fija la cuota (art. 69)
            $table->string('fundamento', 255)->nullable();
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            // NULL no choca en UNIQUE: se normaliza la cuota general a 0 para el índice.
            $table->unsignedBigInteger('seccion_clave')->virtualAs('IFNULL(seccion_id, 0)');
            $table->unsignedTinyInteger('version_abierta')->virtualAs('IF(vigente_hasta IS NULL, 1, NULL)');
            $table->timestamps();

            $table->unique(['concepto_id', 'seccion_clave', 'version_abierta'], 'cuotas_una_abierta');
            $table->index(['concepto_id', 'vigente_desde']);
        });

        DB::statement('ALTER TABLE cuotas ADD CONSTRAINT cuotas_importe_no_negativo CHECK (importe >= 0)');
        DB::statement('ALTER TABLE cuotas ADD CONSTRAINT cuotas_vigencia_ordenada CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cuotas');
    }
};
