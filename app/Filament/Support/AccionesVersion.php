<?php

namespace App\Filament\Support;

use App\Support\AccionBloqueada;
use App\Support\Formato;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * Las mismas cuatro acciones en todos los catálogos versionados, para que se aprendan una vez:
 *
 * - Cambiar:           lo que decide (importe, plazo, valor). Crea una versión nueva desde una fecha;
 *                      la anterior queda en el historial y lo ya hecho con ella no cambia.
 * - Corregir texto:    la redacción (nombre, fundamento). Se corrige en el momento.
 * - Deshacer el cambio de hoy: si alguien se equivocó al capturar y nada lo ha usado todavía.
 * - Historial:         todas las versiones, para saber qué aplicaba en cada fecha.
 *
 * Las filas de la tabla son la versión más reciente de cada registro (la abierta).
 */
final class AccionesVersion
{
    /**
     * @param  array|Closure  $campos  Campos del formulario de lo que decide.
     * @param  list<string>  $llenarCon  Atributos con que se llena el formulario.
     */
    public static function cambiar(string $etiqueta, array|Closure $campos, array $llenarCon): Action
    {
        return Action::make('cambiar')
            ->label($etiqueta)
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading($etiqueta)
            ->modalDescription('El valor actual se conserva en el historial. El nuevo aplica desde la fecha que elijas; lo ya hecho con el anterior no cambia.')
            ->modalSubmitActionLabel('Guardar cambio')
            ->fillForm(fn (Model $record): array => $record->only($llenarCon) + ['vigente_desde' => self::primeraFechaPosible($record)->toDateString()])
            ->schema(fn (Model $record): array => [
                ...(is_array($campos) ? $campos : $campos($record)),
                DatePicker::make('vigente_desde')
                    ->label('Aplica a partir del')
                    ->required()
                    ->minDate(self::primeraFechaPosible($record))
                    ->helperText('Hoy, o una fecha futura si el cambio entra en vigor más adelante.'),
            ])
            ->action(function (Model $record, array $data, Action $action): void {
                $desde = Carbon::parse($data['vigente_desde']);
                unset($data['vigente_desde']);

                try {
                    $record->nuevaVersion($data, $desde);
                } catch (InvalidArgumentException|LogicException $e) {
                    Avisos::bloqueado(AccionBloqueada::por($e->getMessage()));
                    $action->halt();
                }

                Avisos::listo('Cambio guardado', 'Aplica a partir del '.Formato::fecha($desde).'.');
            })
            ->visible(fn (Model $record): bool => $record->vigente_hasta === null && (auth()->user()?->can('update', $record) ?? false));
    }

    /**
     * Acciones de fila: la principal a la vista y las demás en «Más opciones», para que la fila no
     * se llene de botones y quede claro qué es lo que se hace normalmente.
     *
     * @param  list<Action>  $otras
     */
    public static function fila(Action $principal, array $otras): array
    {
        return [
            $principal,
            ActionGroup::make($otras)
                ->label('Más opciones')
                ->icon(Heroicon::OutlinedEllipsisHorizontalCircle)
                ->color('gray')
                ->link(),
        ];
    }

    /** @param  array  $campos  Sólo campos descriptivos (los $editablesEnVigor del modelo). */
    public static function corregirTexto(array $campos): Action
    {
        return Action::make('corregir')
            ->label('Corregir texto')
            ->icon(Heroicon::OutlinedLanguage)
            ->color('gray')
            ->modalHeading('Corregir texto')
            ->modalDescription('Para arreglar la redacción o una errata. No cambia ningún valor ni crea una versión nueva.')
            ->modalSubmitActionLabel('Guardar corrección')
            ->fillForm(fn (Model $record): array => $record->only(array_map(fn ($c) => $c->getName(), $campos)))
            ->schema($campos)
            ->action(function (Model $record, array $data): void {
                $record->update($data);
                Avisos::listo('Corrección guardada');
            })
            ->visible(fn (Model $record): bool => auth()->user()?->can('update', $record) ?? false);
    }

    /**
     * Deja de aplicar un registro (una regla o un requisito que una reforma eliminó). No se borra:
     * queda en el historial y los trámites que ya lo usaron lo conservan.
     */
    public static function dejarDeAplicar(string $etiqueta, string $consecuencia): Action
    {
        return Action::make('dejarDeAplicar')
            ->label($etiqueta)
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading($etiqueta.'?')
            ->modalDescription($consecuencia.' No se borra: queda en el historial, y lo que ya se hizo con él no cambia.')
            ->modalSubmitActionLabel($etiqueta)
            ->modalCancelActionLabel('Seguir aplicándolo')
            ->fillForm(fn (Model $record): array => ['ultimo_dia' => Carbon::today()->max($record->vigente_desde)->toDateString()])
            ->schema(fn (Model $record): array => [
                DatePicker::make('ultimo_dia')
                    ->label('Último día en que aplica')
                    ->required()
                    ->minDate(Carbon::today()->max($record->vigente_desde))
                    ->helperText('Hoy, o una fecha futura si la reforma entra en vigor más adelante.'),
            ])
            ->action(function (Model $record, array $data): void {
                $record->update(['vigente_hasta' => Carbon::parse($data['ultimo_dia'])]);
                Avisos::listo('Listo', 'Deja de aplicar después del '.Formato::fecha(Carbon::parse($data['ultimo_dia'])).'.');
            })
            ->visible(fn (Model $record): bool => $record->vigente_hasta === null && (auth()->user()?->can('update', $record) ?? false));
    }

    public static function deshacer(): Action
    {
        return Action::make('deshacer')
            ->label('Deshacer el cambio de hoy')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('¿Deshacer este cambio?')
            ->modalDescription(fn (Model $record): string => $record->versionAnterior()
                ? 'Se borra este cambio y vuelve a aplicar la versión anterior, como si no se hubiera hecho.'
                : 'Se borra este registro, como si no se hubiera capturado.')
            ->modalSubmitActionLabel('Deshacer el cambio')
            ->modalCancelActionLabel('Conservarlo')
            ->action(function (Model $record): void {
                $record->deshacer();
                Avisos::listo('Cambio deshecho');
            })
            ->visible(fn (Model $record): bool => $record->puedeDeshacerse() && (auth()->user()?->can('update', $record) ?? false));
    }

    /**
     * @param  Closure(Model): string  $valor  Cómo describir cada versión en una línea.
     */
    public static function historial(Closure $valor): Action
    {
        return Action::make('historial')
            ->label('Historial')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->modalHeading('Historial de cambios')
            ->modalDescription('De lo más reciente a lo más antiguo. Cada fila muestra qué aplicaba en esas fechas.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->modalContent(fn (Model $record) => view('filament.historial', [
                'versiones' => $record->historial()->map(fn (Model $v) => [
                    'desde' => Formato::fecha($v->vigente_desde),
                    'hasta' => $v->vigente_hasta ? Formato::fecha($v->vigente_hasta) : 'Vigente',
                    'valor' => $valor($v),
                    'actual' => $v->vigente_hasta === null && $v->vigente_desde->lte(Carbon::today()),
                ]),
            ]));
    }

    /** Descripción de fila para catálogos que pueden dejar de aplicar: cuándo termina o terminó. */
    public static function descripcionFin(Model $record): ?string
    {
        if ($record->vigente_hasta === null) {
            return null;
        }

        return $record->vigente_hasta->lt(Carbon::today())
            ? 'Ya no aplica desde el '.Formato::fecha($record->vigente_hasta->copy()->addDay()).'.'
            : 'Deja de aplicar después del '.Formato::fecha($record->vigente_hasta).'.';
    }

    /** Descripción de fila: avisa si la versión todavía no empieza y qué aplica mientras. */
    public static function descripcionProgramada(Model $record, Closure $valor): ?string
    {
        if ($record->vigente_desde->lte(Carbon::today())) {
            return null;
        }
        $anterior = $record->versionAnterior();

        return 'Programado para el '.Formato::fecha($record->vigente_desde).'.'
            .($anterior ? ' Mientras, aplica: '.$valor($anterior).'.' : '');
    }

    private static function primeraFechaPosible(Model $record): Carbon
    {
        $hoy = Carbon::today();
        $despues = $record->vigente_desde->copy()->addDay();

        return $despues->gt($hoy) ? $despues : $hoy;
    }
}
