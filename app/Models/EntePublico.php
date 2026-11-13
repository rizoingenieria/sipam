<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Marca y membrete del municipio. Una sola fila. */
class EntePublico extends Model
{
    protected $table = 'ente_publico';

    protected $fillable = [
        'nombre_sistema', 'nombre_ente', 'municipio', 'estado', 'domicilio', 'telefono', 'correo',
        'sitio_web', 'escudo_path', 'logo_path', 'leyenda_documentos', 'reglamento_cita',
    ];

    /**
     * La fila del ente, o null si aún no existe o la base no responde.
     * El panel la lee al arrancar: nunca debe romper el arranque.
     */
    public static function actual(): ?self
    {
        try {
            return static::query()->first();
        } catch (Throwable) {
            return null;
        }
    }
}
