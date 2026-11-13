<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca el requisito cuya entrega activa la bandera «pago de derechos de uso al corriente»:
 * el comprobante de Tesorería que presenta el solicitante.
 * Fundamento: arts. 27, fr. II, y 33, fr. II.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitos', function (Blueprint $table) {
            $table->boolean('activa_pago_derechos')->default(false)->after('emitido_por_sistema');
        });

        // Los requisitos ya sembrados por la entrega 2. Es un ajuste de esquema, no una edición
        // de contenido: no se versiona.
        DB::table('requisitos')->where('clave', 'comprobante_derechos')->update(['activa_pago_derechos' => true]);
    }

    public function down(): void
    {
        Schema::table('requisitos', function (Blueprint $table) {
            $table->dropColumn('activa_pago_derechos');
        });
    }
};
