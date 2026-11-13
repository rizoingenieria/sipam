<?php

namespace App\Filament\Resources\Parametros;

use App\Enums\TipoParametro;
use App\Filament\Resources\Parametros\Pages\ManageParametros;
use App\Filament\Resources\Recurso;
use App\Filament\Support\AccionesVersion;
use App\Models\Parametro;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Parámetros del reglamento: testigos, plazos, medidas, series de folio. Se ajustan aquí, sin programador.
 */
class ParametroResource extends Recurso
{
    protected static ?string $model = Parametro::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Reglamento';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $modelLabel = 'parámetro';

    protected static ?string $pluralModelLabel = 'parámetros del reglamento';

    protected static ?string $navigationLabel = 'Parámetros';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false; // la lista es fija: cada parámetro lo usa el sistema
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->abiertas();
    }

    public static function table(Table $table): Table
    {
        $valor = fn (Parametro $p): string => trim($p->valor.' '.($p->unidad ?? ''));

        return $table
            ->defaultGroup(Group::make('grupo')->label('Grupo')->titlePrefixedWithLabel(false))
            ->groupingSettingsHidden()
            ->defaultSort('orden')
            ->paginated(false)
            ->columns([
                TextColumn::make('nombre')
                    ->label('Parámetro')
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (Parametro $p) => $p->ayuda),
                TextColumn::make('valor')
                    ->wrap(false)->extraAttributes(['style' => 'white-space:nowrap'])
                    ->label('Valor')
                    ->formatStateUsing(fn ($state, Parametro $p) => $valor($p))
                    ->description(fn (Parametro $p) => AccionesVersion::descripcionProgramada($p, $valor))
                    ->wrap(),
                TextColumn::make('fundamento')->label('Fundamento')->color('gray')->size('sm')->wrap()->placeholder('—'),
            ])
            ->recordActions(AccionesVersion::fila(
                AccionesVersion::cambiar('Cambiar valor', fn (Parametro $p) => [
                    TextInput::make('valor')
                        ->label('Valor nuevo')
                        ->required()
                        ->suffix($p->unidad)
                        ->helperText(self::ayudaRango($p))
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($p): void {
                            $prueba = $p->replicate(['version_abierta']);
                            $prueba->valor = (string) $value;
                            if ($error = $prueba->errorDeValor()) {
                                $fail($error);
                            }
                        }),
                ], ['valor']),
                [
                    AccionesVersion::corregirTexto([
                        TextInput::make('nombre')->label('Nombre')->required()->maxLength(150),
                        Textarea::make('ayuda')->label('Ayuda')->helperText('Qué controla y cuándo conviene cambiarlo.')->maxLength(500)->rows(3),
                        TextInput::make('fundamento')->label('Fundamento')->maxLength(255),
                    ]),
                    AccionesVersion::deshacer(),
                    AccionesVersion::historial($valor),
                ],
            ));
    }

    private static function ayudaRango(Parametro $p): ?string
    {
        if ($p->tipo === TipoParametro::Serie) {
            return 'De 1 a 10 letras mayúsculas o números. Si la cambias, la numeración de la serie nueva empieza en 1.';
        }
        $partes = array_filter([
            $p->minimo !== null ? 'mínimo '.(float) $p->minimo : null,
            $p->maximo !== null ? 'máximo '.(float) $p->maximo : null,
        ]);

        return $partes === [] ? null : ucfirst(implode(', ', $partes)).($p->unidad ? " {$p->unidad}" : '').'.';
    }

    public static function getPages(): array
    {
        return ['index' => ManageParametros::route('/')];
    }
}
