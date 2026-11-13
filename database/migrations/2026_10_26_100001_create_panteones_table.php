<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panteones municipales.
 * Fundamento: Reglamento de Panteones de Coatepec, arts. 9, 10, 17 fr. III y XVI, 21 fr. XXX, 105.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panteones', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 20)->unique();
            $table->string('nombre', 150)->unique();
            $table->string('domicilio', 255)->nullable();
            // Datos que exige el informe anual a la Secretaría de Salud (art. 21, fr. XXX).
            $table->decimal('extension_m2', 12, 2)->nullable();
            $table->unsignedInteger('capacidad')->nullable();
            $table->string('estado', 20)->default('operando'); // App\Enums\EstadoPanteon
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panteones');
    }
};
