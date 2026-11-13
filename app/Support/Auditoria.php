<?php

namespace App\Support;

use App\Models\Cargo;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\EntePublico;
use App\Models\Espacio;
use App\Models\Firmante;
use App\Models\Inhumado;
use App\Models\Lote;
use App\Models\OrdenPago;
use App\Models\Panteon;
use App\Models\Parametro;
use App\Models\Parentesco;
use App\Models\Persona;
use App\Models\Regimen;
use App\Models\ReglaPlazo;
use App\Models\Requisito;
use App\Models\Rol;
use App\Models\Seccion;
use App\Models\Titulo;
use App\Models\Tramite;
use App\Models\TramiteArchivo;
use App\Models\TramiteParticipante;
use App\Models\TramiteRequisito;
use App\Models\User;
use App\Observers\AuditarCambios;
use Illuminate\Database\Eloquent\Model;

/**
 * Qué se anota en la bitácora y cómo se nombra cada registro. Se registra en un solo lugar,
 * sin tocar los modelos: AppServiceProvider llama a Auditoria::registrar().
 */
final class Auditoria
{
    /** Clase => nombre en la bitácora. */
    public const MODELOS = [
        Parametro::class => 'Parámetro',
        Cuota::class => 'Cuota',
        ConceptoCobro::class => 'Concepto de cobro',
        ReglaPlazo::class => 'Plazo para exhumar',
        Requisito::class => 'Requisito',
        Regimen::class => 'Tipo de título',
        Parentesco::class => 'Parentesco',
        Firmante::class => 'Firmante',
        Cargo::class => 'Cargo',
        EntePublico::class => 'Datos del ayuntamiento',
        Rol::class => 'Rol',
        User::class => 'Usuario',
        Panteon::class => 'Panteón',
        Seccion::class => 'Sección',
        Lote::class => 'Lote',
        Espacio::class => 'Espacio',
        Persona::class => 'Persona',
        Inhumado::class => 'Finado',
        Titulo::class => 'Título',
        Tramite::class => 'Trámite',
        TramiteRequisito::class => 'Documento del trámite',
        TramiteParticipante::class => 'Persona en trámite',
        OrdenPago::class => 'Orden de pago',
        TramiteArchivo::class => 'Archivo del trámite',
    ];

    /** Campos que nunca se anotan (además de contraseñas, tokens y fechas de sistema). */
    public const OMITIR = ['password', 'remember_token', 'created_at', 'updated_at', 'version_abierta', 'nombre_completo'];

    private static int $pausa = 0;

    public static function registrar(): void
    {
        foreach (array_keys(self::MODELOS) as $clase) {
            $clase::observe(AuditarCambios::class);
        }
    }

    /** Para operaciones masivas (generar 2,000 lotes): se anota un solo renglón de resumen, no uno por registro. */
    public static function sinAnotar(callable $paso): mixed
    {
        self::$pausa++;
        try {
            return $paso();
        } finally {
            self::$pausa--;
        }
    }

    public static function enPausa(): bool
    {
        return self::$pausa > 0;
    }

    public static function etiqueta(string $clase): string
    {
        return self::MODELOS[$clase] ?? class_basename($clase);
    }

    public static function descripcion(Model $m): string
    {
        $nombre = match (true) {
            $m instanceof Cuota => ($m->concepto?->nombre ?? 'concepto '.$m->concepto_id).($m->seccion_id ? ', sección '.$m->seccion?->clave : ''),
            $m instanceof TramiteRequisito => ($m->requisito?->documento ?? '').' · '.($m->tramite?->folio ?? ''),
            $m instanceof TramiteParticipante => ($m->persona?->nombre_completo ?? '').' · '.($m->tramite?->folio ?? ''),
            $m instanceof TramiteArchivo => $m->nombre_original.' · '.($m->tramite?->folio ?? ''),
            $m instanceof Espacio => $m->lote ? $m->clave_completa : '#'.$m->getKey(),
            $m instanceof Lote => 'lote '.$m->numero.($m->seccion ? ', sección '.$m->seccion->clave : ''),
            $m instanceof Firmante => ($m->nombre ?? '').' · '.($m->cargo?->nombre ?? ''),
            $m instanceof User => $m->name.' ('.$m->email.')',
            $m instanceof EntePublico => $m->nombre_ente ?? '',
            default => (string) ($m->folio ?? $m->nombre_completo ?? $m->nombre ?? $m->clave ?? '#'.$m->getKey()),
        };

        return self::etiqueta($m::class).': '.$nombre;
    }
}
