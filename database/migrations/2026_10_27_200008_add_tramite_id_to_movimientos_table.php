<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Liga cada asiento de los libros cronológicos con el trámite que lo originó. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreignId('tramite_id')->nullable()->after('inhumado_id')->constrained('tramites')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tramite_id');
        });
    }
};
