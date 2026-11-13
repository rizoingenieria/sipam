<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles configurables. Los permisos de cada rol son una lista (JSON) de valores de App\Enums\Permiso:
 * en la interfaz se editan como casillas, sin tablas intermedias.
 * Una persona puede tener varios roles; en un ayuntamiento pequeño es común.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 500)->nullable();
            $table->json('permisos');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('rol_usuario', function (Blueprint $table) {
            $table->foreignId('rol_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['rol_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('activo')->default(true)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('activo');
        });
        Schema::dropIfExists('rol_usuario');
        Schema::dropIfExists('roles');
    }
};
