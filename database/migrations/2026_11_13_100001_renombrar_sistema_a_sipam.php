<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El sistema se llama SIPAM (Sistema Integral de Panteones Municipales).
 * Sólo cambia el nombre de fábrica: si el ayuntamiento ya le puso otro desde «Datos del ayuntamiento», se respeta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ente_publico')->where('nombre_sistema', 'Asústame Panteón')->update(['nombre_sistema' => 'SIPAM']);
    }

    public function down(): void
    {
        DB::table('ente_publico')->where('nombre_sistema', 'SIPAM')->update(['nombre_sistema' => 'Asústame Panteón']);
    }
};
