<?php

namespace App\Filament\Resources\Panteones\RelationManagers;

use App\Enums\TipoEspacio;
use App\Filament\Support\Avisos;
use App\Models\Seccion;
use App\Services\Inventario\GeneradorInventario;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Unique;

class SeccionesRelationManager extends RelationManager
{
    protected static string $relationship = 'secciones';

    protected static ?string $title = 'Secciones';

    /** Se carga junto con la página: un recuadro vacío mientras carga confunde. */
    protected static bool $isLazy = false;

    protected static ?string $modelLabel = 'sección';

    protected static ?string $pluralModelLabel = 'secciones';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('clave')->label('Clave de la sección')
                ->helperText('Como aparece en los planos y en los títulos, por ejemplo «A» o «3».')
                ->required()->maxLength(20)
                ->unique('secciones', 'clave', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('panteon_id', $this->getOwnerRecord()->id)),
            TextInput::make('nombre')->label('Nombre')->helperText('Opcional.')->maxLength(150),
        ]);
    }

    public function table(Table $table): Table
    {
        // Conteos con subconsultas simples (withCount sobre la consulta de la relación falla en Filament 4).
        $lotes = DB::table('lotes')->whereColumn('lotes.seccion_id', 'secciones.id')->selectRaw('count(*)');
        $espacios = DB::table('espacios')->join('lotes', 'lotes.id', '=', 'espacios.lote_id')
            ->whereColumn('lotes.seccion_id', 'secciones.id')->selectRaw('count(*)');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select('secciones.*')->selectSub($lotes, 'lotes_count')->selectSub($espacios, 'espacios_count'))
            ->defaultSort('clave')
            ->columns([
                TextColumn::make('clave')->label('Sección')->weight('semibold')->description(fn (Seccion $s) => $s->nombre),
                TextColumn::make('lotes_count')->label('Lotes')->alignEnd()->formatStateUsing(fn ($state) => Formato::numero($state)),
                TextColumn::make('espacios_count')->label('Espacios')->alignEnd()->formatStateUsing(fn ($state) => Formato::numero($state)),
            ])
            ->emptyStateHeading('Este panteón aún no tiene secciones')
            ->emptyStateDescription('La cuota del título depende de la sección. Agrega al menos una.')
            ->headerActions([
                CreateAction::make()->label('Nueva sección')->modalHeading('Nueva sección')->modalSubmitActionLabel('Guardar sección')->createAnother(false),
            ])
            ->recordActions([
                Action::make('generar')
                    ->label('Generar lotes')
                    ->icon(Heroicon::OutlinedSquare2Stack)
                    ->modalHeading(fn (Seccion $record) => "Generar lotes de la sección {$record->clave}")
                    ->modalDescription('Crea de una vez los lotes numerados y sus espacios. Si un lote ya existe, se deja igual; puedes repetirlo sin duplicar.')
                    ->modalSubmitActionLabel('Generar')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('desde')->label('Del lote')->numeric()->integer()->minValue(1)->default(1)->required(),
                            TextInput::make('hasta')->label('Al lote')->numeric()->integer()->minValue(1)->required()
                                ->gte('desde')->validationMessages(['gte' => 'Debe ser igual o mayor que el primer lote.']),
                            TextInput::make('espacios')->label('Espacios en cada lote')->numeric()->integer()->minValue(1)->maxValue(50)->default(1)->required(),
                            Select::make('tipo')->label('Tipo de espacio')->options(TipoEspacio::class)->default(TipoEspacio::Fosa)->required(),
                        ]),
                    ])
                    ->action(function (Seccion $record, array $data, Action $action): void {
                        $r = Avisos::intentar(fn () => app(GeneradorInventario::class)->generar(
                            $record, (int) $data['desde'], (int) $data['hasta'], (int) $data['espacios'],
                            $data['tipo'] instanceof TipoEspacio ? $data['tipo'] : TipoEspacio::from($data['tipo']),
                        ), $action);

                        Avisos::listo(
                            'Se crearon '.Formato::cantidad($r['lotes_nuevos'], 'lote').' y '.Formato::cantidad($r['espacios_nuevos'], 'espacio'),
                            $r['lotes_existentes'] > 0 ? Formato::cantidad($r['lotes_existentes'], 'lote ya existía', 'lotes ya existían').' y se dejaron igual.' : null,
                        );
                    })
                    ->visible(fn () => auth()->user()?->can('create', Seccion::class) ?? false),
                EditAction::make()->label('Editar')->modalHeading('Editar sección')->modalSubmitActionLabel('Guardar sección'),
            ]);
    }
}
