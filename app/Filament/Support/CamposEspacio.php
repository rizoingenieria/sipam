<?php

namespace App\Filament\Support;

use App\Enums\EstadoInhumado;
use App\Models\Espacio;
use App\Support\Formato;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Buscar un espacio como lo conoce la gente: por su clave (MUN-A-12-1), por el número de lote,
 * por el nombre de quien tiene el título o por el de quien está sepultado ahí.
 */
final class CamposEspacio
{
    public static function select(string $nombre, string $etiqueta): Select
    {
        return Select::make($nombre)
            ->label($etiqueta)
            ->searchable()
            ->placeholder('Toca aquí y escribe para buscar')
            ->searchPrompt('Escribe la clave, el lote, o el nombre del titular o del sepultado')
            ->noSearchResultsMessage('No se encontró. Revisa la clave o busca por un nombre.')
            ->getSearchResultsUsing(fn (string $search): array => self::buscar($search))
            ->getOptionLabelUsing(fn ($value): ?string => ($e = self::cargar($value)) ? self::etiqueta($e) : null);
    }

    /** @return array<int, string> */
    public static function buscar(string $texto, int $limite = 30): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return [];
        }

        return self::consulta()
            ->where(fn (Builder $q) => $q
                ->whereRaw("CONCAT_WS('-', panteones.clave, secciones.clave, lotes.numero, espacios.numero) LIKE ?", ['%'.$texto.'%'])
                ->orWhere('lotes.numero', $texto)
                ->orWhereHas('tituloVigente.personas', fn (Builder $p) => $p->where('nombre_completo', 'like', "%{$texto}%"))
                ->orWhereHas('inhumados', fn (Builder $i) => $i->where('nombre_completo', 'like', "%{$texto}%")))
            ->limit($limite)->get()
            ->mapWithKeys(fn (Espacio $e) => [$e->id => self::etiqueta($e)])->all();
    }

    public static function cargar(mixed $id): ?Espacio
    {
        return blank($id) ? null : self::consulta()->find($id);
    }

    private static function consulta(): Builder
    {
        return Espacio::query()
            ->with(['lote.seccion.panteon', 'tituloVigente.titulares', 'tituloVigente.regimen', 'ultimoInhumado'])
            ->join('lotes', 'lotes.id', '=', 'espacios.lote_id')
            ->join('secciones', 'secciones.id', '=', 'lotes.seccion_id')
            ->join('panteones', 'panteones.id', '=', 'secciones.panteon_id')
            ->select('espacios.*')
            ->orderBy('panteones.nombre')->orderBy('secciones.clave')
            ->orderByRaw('CAST(lotes.numero AS UNSIGNED), CAST(espacios.numero AS UNSIGNED)');
    }

    public static function etiqueta(Espacio $e): string
    {
        $titulo = $e->tituloVigente;
        $partes = [$e->clave_completa, $e->tipo->getLabel()];
        $partes[] = $titulo ? 'título de '.($titulo->titulares->first()?->nombre_completo ?? $titulo->folio) : 'sin título';
        if ($e->ultimoInhumado && $e->ultimoInhumado->estado === EstadoInhumado::Inhumado) {
            $partes[] = 'sepultado: '.$e->ultimoInhumado->nombre_completo;
        }

        return implode(' · ', $partes);
    }

    /** Ficha corta del espacio para confirmar que es el correcto antes de seguir. */
    public static function ficha(?Espacio $e): HtmlString
    {
        if ($e === null) {
            return new HtmlString('<p style="margin:0;color:var(--sp-ink-soft)">Elige un espacio para ver sus datos.</p>');
        }
        $s = $e->lote->seccion;
        $filas = [
            'Ubicación' => "{$s->panteon->nombre}, sección {$s->clave}, lote {$e->lote->numero}, {$e->tipo->getLabel()} {$e->numero}",
        ];
        $titulo = $e->tituloVigente;
        $filas['Título'] = $titulo
            ? "{$titulo->folio} · {$titulo->regimen->nombre} · a nombre de ".($titulo->titulares->pluck('nombre_completo')->implode(', ') ?: 'nadie registrado')
                .($titulo->regimen->duracion_anios < 99 ? ' · vence el '.Formato::fecha($titulo->fecha_vencimiento) : '')
            : 'No tiene título vigente.';
        $sepultados = $e->inhumados()->where('estado', EstadoInhumado::Inhumado->value)->orderByDesc('fecha_inhumacion')->get();
        $filas['Sepultados'] = $sepultados->isEmpty() ? 'Nadie.' : $sepultados
            ->map(fn ($i) => $i->nombre_completo.($i->fecha_inhumacion ? ' (desde el '.Formato::fecha($i->fecha_inhumacion).')' : ''))->implode('; ');
        if (! $e->habilitado) {
            $filas['Aviso'] = 'Este espacio está marcado como no disponible.';
        }

        $html = '<dl style="display:grid;grid-template-columns:max-content 1fr;gap:.4rem 1.25rem;margin:0">';
        foreach ($filas as $k => $v) {
            $html .= '<dt style="font-weight:600">'.e($k).'</dt><dd style="margin:0">'.e($v).'</dd>';
        }

        return new HtmlString($html.'</dl>');
    }
}
