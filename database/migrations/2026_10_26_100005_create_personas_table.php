<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas que intervienen: titulares, copropietarios, beneficiarios, solicitantes,
 * testigos, autorizantes y constructores. Una sola tabla; el papel lo da la relación.
 * Fundamento: arts. 25 fr. VII, 27, 28 y 33.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('primer_apellido', 100)->nullable();
            $table->string('segundo_apellido', 100)->nullable();
            // Nombre completo para búsqueda; con utf8mb4_0900_ai_ci ignora acentos y mayúsculas.
            $table->string('nombre_completo', 310)
                ->storedAs("CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)");
            // Varios NULL caben en un índice UNIQUE: el legado puede no traer CURP.
            $table->char('curp', 18)->nullable()->unique();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('domicilio', 255)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('correo', 150)->nullable();
            $table->timestamps();

            $table->index('nombre_completo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
