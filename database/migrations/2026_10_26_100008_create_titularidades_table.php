<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas de cada título y su papel: titular, copropietario o beneficiario.
 * Fundamento: art. 25, fr. VII; art. 28 (copropiedad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titularidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titulo_id')->constrained('titulos')->restrictOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->restrictOnDelete();
            $table->string('rol', 20); // App\Enums\RolTitularidad
            $table->unsignedTinyInteger('orden')->default(1);
            $table->timestamps();

            $table->unique(['titulo_id', 'persona_id', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titularidades');
    }
};
