<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Catálogo de tipos de documento del acervo histórico (libro de registro, título antiguo, acta…). */
class TipoDocumentoAcervo extends Model
{
    protected $table = 'tipos_documento_acervo';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'orden' => 'integer'];
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoAcervo::class, 'tipo_id');
    }
}
