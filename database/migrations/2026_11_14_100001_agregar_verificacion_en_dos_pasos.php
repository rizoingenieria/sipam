<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación en dos pasos con aplicación de códigos (Google Authenticator, Microsoft Authenticator…).
 * - users: el secreto y los códigos de recuperación, ambos cifrados con APP_KEY (los cifra el modelo).
 * - roles: si quien tiene el rol debe usarla. De fábrica sólo el administrador; lo demás se decide en pantalla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->boolean('exige_dos_pasos')->default(false)->after('activo');
        });

        DB::table('roles')->where('clave', 'administrador')->update(['exige_dos_pasos' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('exige_dos_pasos'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']));
    }
};
