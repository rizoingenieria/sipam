<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Requisitos documentales por trámite, versionados.
 * grupo_alternativa: requisitos del mismo grupo se satisfacen entre sí
 * (p. ej., «título» o, en su defecto, «acta del último sepultado»).
 * Fundamento: arts. 27, 33, 36, 42 y 52; hojas de requisitos del ayuntamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisitos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_tramite', 30);   // App\Enums\TipoTramite
            $table->string('clave', 60);
            $table->string('documento', 200);
            $table->string('descripcion', 500)->nullable();
            $table->string('de_quien', 30);       // App\Enums\ParteTramite
            $table->unsignedTinyInteger('cantidad')->default(1);
            $table->string('presentacion', 100)->nullable(); // «original y 2 copias», «copia simple»…
            $table->boolean('obligatorio')->default(true);
            $table->unsignedSmallInteger('vigencia_maxima_dias')->nullable(); // antigüedad máxima del documento
            $table->string('grupo_alternativa', 60)->nullable();
            $table->boolean('emitido_por_sistema')->default(false); // responsiva, carta compromiso…
            $table->string('fundamento', 255)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->unsignedTinyInteger('version_abierta')->virtualAs('IF(vigente_hasta IS NULL, 1, NULL)');
            $table->timestamps();

            $table->unique(['tipo_tramite', 'clave', 'version_abierta'], 'requisitos_una_abierta');
            $table->index(['tipo_tramite', 'orden']);
        });

        DB::statement('ALTER TABLE requisitos ADD CONSTRAINT requisitos_cantidad_positiva CHECK (cantidad > 0)');
        DB::statement('ALTER TABLE requisitos ADD CONSTRAINT requisitos_vigencia_ordenada CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)');
    }

    public function down(): void
    {
        Schema::dropIfExists('requisitos');
    }
};
