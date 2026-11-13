<?php

namespace App\Filament\Resources\Tramites\RelationManagers;

use App\Enums\ParteTramite;
use App\Enums\Permiso;
use App\Enums\TipoTramite;
use App\Filament\Resources\Tramites\RelationManagers\Concerns\ParteDelExpediente;
use App\Filament\Support\CamposPersona;
use App\Models\Parametro;
use App\Models\Parentesco;
use App\Models\Persona;
use App\Models\TramiteParticipante;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Quiénes intervienen: nuevos titulares, testigos, quien autoriza o quien construye; y si ya vinieron a firmar. */
class ParticipantesRelationManager extends RelationManager
{
    use ParteDelExpediente;

    protected static string $relationship = 'participantes';

    protected static ?string $title = 'Personas';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedUsers;

    protected static bool $isLazy = false;

    /** @return array<string, string> papel => explicación */
    public static function papeles(TipoTramite $tipo): array
    {
        $testigos = fn () => 'Se piden '.Formato::cantidad(Parametro::entero('testigos_requeridos'), 'testigo').' con al menos '
            .Formato::cantidad(Parametro::entero('residencia_minima_testigos_anios'), 'año').' viviendo en el municipio.';

        return match ($tipo) {
            TipoTramite::Adquisicion, TipoTramite::Regularizacion => [
                ParteTramite::NuevoTitular->value => 'A nombre de quien quedará el título. Si son varios, agrega a cada uno.',
                ParteTramite::Testigo->value => $testigos(),
            ],
            TipoTramite::Inhumacion, TipoTramite::Exhumacion => [
                ParteTramite::Autorizante->value => 'El titular del espacio o, si falleció, su familiar más directo. Debe venir en persona a firmar.',
            ],
            TipoTramite::Construccion => [
                ParteTramite::Constructor->value => 'La persona que hará la obra (art. 52).',
            ],
        };
    }

    public function table(Table $table): Table
    {
        $tipo = $this->getOwnerRecord()->tipo;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['persona', 'parentesco']))
            ->paginated(false)
            ->description(collect(self::papeles($tipo))->map(fn ($texto, $papel) => ParteTramite::from($papel)->getLabel().': '.$texto)->implode(' '))
            ->columns([
                TextColumn::make('persona.nombre_completo')->label('Persona')->weight('semibold')->wrap()
                    ->description(fn (TramiteParticipante $p) => $p->persona->curp),
                TextColumn::make('papel')->label('Interviene como')->badge()->color('gray')
                    ->description(fn (TramiteParticipante $p) => collect([
                        $p->parentesco?->nombre,
                        $p->anios_residencia !== null ? Formato::cantidad($p->anios_residencia, 'año').' en el municipio' : null,
                    ])->filter()->implode(' · ') ?: null),
                TextColumn::make('comparecio_en')->label('Firma en persona')
                    ->state(fn (TramiteParticipante $p) => $p->comparecio() ? 'Vino el '.Formato::fecha($p->comparecio_en) : 'Todavía no viene')
                    ->badge()->color(fn (TramiteParticipante $p) => $p->comparecio() ? 'success' : 'gray'),
            ])
            ->emptyStateHeading('Aún no hay personas registradas')
            ->emptyStateDescription('Agrega a cada persona que interviene en el trámite.')
            ->headerActions([
                Action::make('agregar')
                    ->label('Agregar persona')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->modalHeading('Agregar persona al trámite')
                    ->modalSubmitActionLabel('Agregar')
                    ->schema([
                        Select::make('papel')->label('Interviene como')->required()->live()
                            ->options(collect(self::papeles($tipo))->mapWithKeys(fn ($t, $p) => [$p => ParteTramite::from($p)->getLabel()]))
                            ->default(count(self::papeles($tipo)) === 1 ? array_key_first(self::papeles($tipo)) : null)
                            ->helperText(fn (Get $get) => self::papeles($tipo)[$get('papel')] ?? null),
                        CamposPersona::select('persona_id', 'Persona')->required(),
                        Select::make('parentesco_id')->label('Parentesco con el finado')
                            ->options(fn () => Parentesco::where('puede_autorizar', true)->orderBy('prelacion')->pluck('nombre', 'id'))
                            ->placeholder('Es el titular del espacio')
                            ->helperText('Déjalo así si la persona es la titular. Si es familiar, elige su parentesco.')
                            ->visible(fn (Get $get) => $get('papel') === ParteTramite::Autorizante->value),
                        TextInput::make('anios_residencia')->label('Años viviendo en el municipio')->numeric()->integer()->minValue(0)->maxValue(120)->suffix('años')
                            ->required()->visible(fn (Get $get) => $get('papel') === ParteTramite::Testigo->value),
                    ])
                    ->action(fn (array $data, Action $action) => $this->paso($action, fn () => $this->gestor()->agregarParticipante(
                        $this->tramite(), Persona::findOrFail($data['persona_id']), ParteTramite::from($data['papel']), auth()->user(),
                        filled($data['parentesco_id'] ?? null) ? Parentesco::find($data['parentesco_id']) : null,
                        isset($data['anios_residencia']) ? (int) $data['anios_residencia'] : null,
                    ), 'Persona agregada'))
                    ->visible(fn () => $this->editable() && $this->puede(Permiso::TramitesCapturar)),
            ])
            ->recordActions([
                Action::make('imprimirConsentimiento')
                    ->label('Imprimir consentimiento')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (TramiteParticipante $record) => route('filament.panteones.documentos.consentimiento', $record), shouldOpenInNewTab: true)
                    ->visible(fn (TramiteParticipante $record) => $record->papel === ParteTramite::Autorizante),
                Action::make('comparecio')
                    ->label('Vino a firmar')
                    ->icon(Heroicon::OutlinedPencil)
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading(fn (TramiteParticipante $record) => "¿{$record->persona->nombre_completo} vino en persona y firmó?")
                    ->modalDescription('Revisa su identificación oficial. No se acepta carta poder.')
                    ->modalSubmitActionLabel('Sí, vino y firmó')
                    ->action(fn (TramiteParticipante $record, Action $action) => $this->paso($action,
                        fn () => $this->gestor()->registrarComparecencia($record, auth()->user()), 'Firma registrada'))
                    ->visible(fn (TramiteParticipante $record) => ! $record->comparecio()
                        && in_array($record->papel, [ParteTramite::NuevoTitular, ParteTramite::Autorizante], true)
                        && $this->abierto() && $this->puede(Permiso::TramitesCapturar)),
                Action::make('quitar')
                    ->label('Quitar')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('¿Quitar a esta persona del trámite?')
                    ->modalDescription('Úsalo si la agregaste por error. La persona sigue registrada para otros trámites.')
                    ->modalSubmitActionLabel('Quitar')
                    ->action(fn (TramiteParticipante $record, Action $action) => $this->paso($action,
                        fn () => $this->gestor()->quitarParticipante($record, auth()->user()), 'Persona quitada del trámite'))
                    ->visible(fn () => $this->editable() && $this->puede(Permiso::TramitesCapturar)),
            ]);
    }
}
