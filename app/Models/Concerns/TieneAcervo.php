<?php

namespace App\Models\Concerns;

use App\Models\DocumentoAcervo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/** Documentos del acervo histórico ligados a este registro (espacio, título o finado). Los retirados no cuentan. */
trait TieneAcervo
{
    public function documentosAcervo(): MorphToMany
    {
        return $this->morphToMany(DocumentoAcervo::class, 'vinculable', 'acervo_vinculos', 'vinculable_id', 'documento_id')
            ->whereNull('documentos_acervo.retirado_en')
            ->orderByRaw('COALESCE(documentos_acervo.anio_desde, documentos_acervo.anio_hasta, 9999)')
            ->orderBy('documentos_acervo.id');
    }
}
