<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos reglas que en la entrega 3 dependían del nombre interno del requisito pasan a ser banderas
 * del catálogo, editables desde la interfaz:
 * - se_multiplica_por: el requisito se pide una vez por titular o por inhumado (antes: clave «anuencia»
 *   y parte «inhumados del lote»).
 * - exento_con_orden_autoridad: no se pide cuando ordena la autoridad judicial o la fiscalía
 *   (antes: sólo la clave «comprobante_pago»). Con orden de autoridad no hay pago (art. 68); en la
 *   exhumación no se exige más que la orden escrita (art. 42), y en la fosa común no hay título ni
 *   titular que autorice (art. 46). Por eso se exentan también título, identificación y domicilio.
 *
 * Además corrige el fundamento de las reglas sanitarias de exhumación (texto descriptivo, no cambia plazos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitos', function (Blueprint $table) {
            $table->string('se_multiplica_por', 20)->nullable()->after('cantidad'); // App\Enums\MultiplicadorRequisito
            $table->boolean('exento_con_orden_autoridad')->default(false)->after('activa_pago_derechos');
        });

        // Ajuste de esquema sobre lo ya sembrado: no se versiona.
        DB::table('requisitos')->where('clave', 'anuencia')->update(['se_multiplica_por' => 'titulares']);
        DB::table('requisitos')->where('de_quien', 'inhumados_del_lote')->update(['se_multiplica_por' => 'inhumados']);
        DB::table('requisitos')->where('tipo_tramite', 'inhumacion')
            ->whereIn('clave', ['titulo', 'acta_defuncion_ultimo', 'identificacion_autorizante', 'comprobante_domicilio', 'comprobante_pago'])
            ->update(['exento_con_orden_autoridad' => true]);
        DB::table('requisitos')->where('tipo_tramite', 'exhumacion')
            ->whereIn('clave', ['acta_defuncion', 'titulo', 'acta_defuncion_ultimo', 'identificacion_autorizante', 'comprobante_domicilio', 'comprobante_pago'])
            ->update(['exento_con_orden_autoridad' => true]);

        // Conceptos de la fase 2 (renovaciones y copias certificadas): inactivos hasta entonces.
        DB::table('conceptos_cobro')->whereIn('clave', ['renovacion_derecho', 'copia_certificada_titulo'])->update(['activo' => false]);

        DB::table('reglas_plazo')->whereIn('clave', ['sanitario-menores-15', 'sanitario-15-o-mas'])->update([
            'fundamento' => 'Art. 67 del Reglamento de la Ley General de Salud en materia de control sanitario de la disposición de órganos, tejidos y cadáveres de seres humanos; arts. 29 y 38 del Reglamento de Panteones',
        ]);
    }

    public function down(): void
    {
        Schema::table('requisitos', function (Blueprint $table) {
            $table->dropColumn(['se_multiplica_por', 'exento_con_orden_autoridad']);
        });
    }
};
