<?php

namespace App\Models;

use App\Enums\EstadoOrdenPago;
use App\Enums\EstadoTramite;
use App\Enums\MotivoExhumacion;
use App\Enums\ParteTramite;
use App\Enums\Permiso;
use App\Enums\TipoTramite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trámite. El estado sólo cambia a través de App\Services\Tramites\GestorTramites,
 * que valida la transición, sus candados y deja huella en el historial.
 */
class Tramite extends Model
{
    protected $table = 'tramites';

    public const TRANSICIONES = [
        'capturado' => ['en_revision', 'rechazado', 'caducado'],
        'en_revision' => ['en_publicacion', 'por_pagar', 'por_autorizar', 'rechazado', 'caducado'],
        'en_publicacion' => ['por_pagar', 'rechazado', 'caducado'],
        'por_pagar' => ['por_autorizar', 'rechazado', 'caducado'],
        'por_autorizar' => ['concluido', 'rechazado', 'caducado'],
        'concluido' => [],
        'rechazado' => [],
        'caducado' => [],
    ];

    protected $fillable = [
        'folio', 'tipo', 'estado', 'espacio_id', 'titulo_id', 'inhumado_id', 'solicitante_id',
        'motivo_exhumacion', 'por_orden_autoridad', 'datos', 'causa_fallecimiento',
        'pago_derechos_al_corriente', 'pago_derechos_folio', 'pago_derechos_fecha',
        'cotejo_en', 'cotejo_por', 'cotejo_observaciones', 'publicado_en', 'caduca_en',
        'evaluacion_plazo', 'titulo_resultante_id', 'resolucion', 'resuelto_en', 'resuelto_por',
        'capturado_por',
    ];

    /** Dato sensible: cifrado en la base y fuera de toArray()/JSON. */
    protected $hidden = ['causa_fallecimiento'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoTramite::class,
            'estado' => EstadoTramite::class,
            'motivo_exhumacion' => MotivoExhumacion::class,
            'por_orden_autoridad' => 'boolean',
            'datos' => 'array',
            'causa_fallecimiento' => 'encrypted',
            'pago_derechos_al_corriente' => 'boolean',
            'pago_derechos_fecha' => 'date',
            'cotejo_en' => 'datetime',
            'publicado_en' => 'date',
            'caduca_en' => 'date',
            'evaluacion_plazo' => 'array',
            'resuelto_en' => 'datetime',
        ];
    }

    public function puedeTransicionarA(EstadoTramite $destino): bool
    {
        return in_array($destino->value, self::TRANSICIONES[$this->estado->value] ?? [], true);
    }

    /** Dato del JSON por ruta con puntos: $tramite->dato('finado.nombre'). */
    public function dato(string $ruta, mixed $omision = null): mixed
    {
        return data_get($this->datos, $ruta, $omision);
    }

    /**
     * La causa de fallecimiento sólo para quien tiene el permiso; para los demás, null.
     * La interfaz y los reportes deben leerla siempre por aquí, nunca del atributo directo.
     */
    public function causaVisiblePara(?User $usuario): ?string
    {
        return $usuario?->puede(Permiso::InhumadosVerCausa) ? $this->causa_fallecimiento : null;
    }

    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class);
    }

    public function titulo(): BelongsTo
    {
        return $this->belongsTo(Titulo::class);
    }

    public function tituloResultante(): BelongsTo
    {
        return $this->belongsTo(Titulo::class, 'titulo_resultante_id');
    }

    public function inhumado(): BelongsTo
    {
        return $this->belongsTo(Inhumado::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'solicitante_id');
    }

    public function capturadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capturado_por');
    }

    public function resueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    public function requisitos(): HasMany
    {
        return $this->hasMany(TramiteRequisito::class)->orderBy('id');
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(TramiteParticipante::class);
    }

    public function participantesCon(ParteTramite $papel)
    {
        return $this->participantes()->where('papel', $papel->value)->with('persona')->get();
    }

    public function ordenesPago(): HasMany
    {
        return $this->hasMany(OrdenPago::class);
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(TramiteArchivo::class)->orderBy('id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(TramiteHistorial::class)->orderBy('id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    public function todoPagado(): bool
    {
        return $this->ordenesPago()->where('estado', '!=', EstadoOrdenPago::Pagada->value)
            ->where('estado', '!=', EstadoOrdenPago::Cancelada->value)->doesntExist();
    }
}
