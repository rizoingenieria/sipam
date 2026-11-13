<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parentescos y su orden de prelación para autorizar en ausencia del titular fallecido.
 * Fundamento: hojas de requisitos de inhumación y exhumación; art. 77 (hasta cuarto grado, línea recta o colateral).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parentescos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 30)->unique();
            $table->string('nombre', 100);
            $table->string('linea', 20); // App\Enums\LineaParentesco
            $table->unsignedTinyInteger('grado')->nullable(); // NULL para el cónyuge
            $table->unsignedTinyInteger('prelacion');         // 1 = primero en autorizar
            $table->boolean('puede_autorizar')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parentescos');
    }
};
