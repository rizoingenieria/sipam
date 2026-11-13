<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencias de cada trámite: fotos de la publicación (art. 34), escaneos de lo que entrega el ciudadano
 * y documentos firmados por el ayuntamiento. Disco privado; la huella sha256 permite demostrar ante
 * una auditoría que el archivo no cambió desde que se subió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tramite_archivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramites')->restrictOnDelete();
            $table->foreignId('tramite_requisito_id')->nullable()->constrained('tramite_requisitos')->restrictOnDelete();
            $table->string('tipo', 30); // App\Enums\TipoArchivo
            $table->string('disco', 30);
            $table->string('ruta', 255);
            $table->string('nombre_original', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->string('descripcion', 255)->nullable();
            $table->foreignId('subido_por')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tramite_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramite_archivos');
    }
};
