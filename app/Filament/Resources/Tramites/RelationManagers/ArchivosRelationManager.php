<?php

namespace App\Filament\Resources\Tramites\RelationManagers;

use App\Enums\Permiso;
use App\Enums\TipoArchivo;
use App\Filament\Resources\Tramites\RelationManagers\Concerns\ParteDelExpediente;
use App\Models\Parametro;
use App\Models\TramiteArchivo;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Fotos y escaneos del trámite. Se guardan fuera de la carpeta pública y sólo se ven con sesión. */
class ArchivosRelationManager extends RelationManager
{
    use ParteDelExpediente;

    protected static string $relationship = 'archivos';

    protected static ?string $title = 'Fotos y archivos';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedPhoto;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('subidoPor'))
            ->paginated(false)
            ->columns([
                TextColumn::make('tipo')->label('Qué es')->badge()->color('gray')->wrap(),
                TextColumn::make('descripcion')->label('Descripción')->wrap()
                    ->state(fn (TramiteArchivo $a) => $a->descripcion ?: $a->nombre_original)
                    ->description(fn (TramiteArchivo $a) => strtoupper(pathinfo($a->ruta, PATHINFO_EXTENSION)).' · '.Formato::numero($a->bytes / 1024 / 1024, 1).' MB'),
                TextColumn::make('created_at')->label('Subido')
                    ->formatStateUsing(fn ($state) => Formato::fecha($state))
                    ->description(fn (TramiteArchivo $a) => $a->subidoPor?->name),
            ])
            ->emptyStateHeading('Aún no hay fotos ni archivos')
            ->emptyStateDescription('Sube las fotos de la notificación, escaneos de documentos u otras evidencias.')
            ->headerActions([
                Action::make('subir')
                    ->label('Subir foto o documento')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->modalHeading('Subir foto o documento')
                    ->modalSubmitActionLabel('Subir')
                    ->schema([
                        Select::make('tipo')->label('¿Qué es?')->required()
                            ->options(collect(TipoArchivo::cases())->reject(fn ($t) => $t === TipoArchivo::DocumentoEmitido)
                                ->mapWithKeys(fn (TipoArchivo $t) => [$t->value => $t->getLabel()])),
                        FileUpload::make('archivo')->label('Archivo')->required()->storeFiles(false)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->helperText(fn () => 'Foto (JPG, PNG) o PDF de hasta '.Parametro::entero('archivo_tamano_maximo_mb').' MB.'),
                        TextInput::make('descripcion')->label('Descripción')->helperText('Opcional. Por ejemplo «Notificación en la puerta del panteón».')->maxLength(255),
                    ])
                    ->action(fn (array $data, Action $action) => $this->paso($action, fn () => $this->gestor()->adjuntarArchivo(
                        $this->tramite(), $data['archivo'], TipoArchivo::from($data['tipo']), auth()->user(), null, $data['descripcion'] ?? null,
                    ), 'Archivo guardado'))
                    ->visible(fn () => $this->abierto() && $this->puede(Permiso::TramitesCapturar)),
            ])
            ->recordActions([
                Action::make('ver')->label('Ver')->icon(Heroicon::OutlinedEye)
                    ->url(fn (TramiteArchivo $record) => route('filament.panteones.archivo', $record), shouldOpenInNewTab: true),
                Action::make('quitar')
                    ->label('Quitar')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('¿Quitar este archivo?')
                    ->modalDescription('Se borra del trámite. Hazlo sólo si lo subiste por error.')
                    ->modalSubmitActionLabel('Quitar archivo')
                    ->action(fn (TramiteArchivo $record, Action $action) => $this->paso($action,
                        fn () => $this->gestor()->quitarArchivo($record, auth()->user()), 'Archivo quitado'))
                    ->visible(fn () => $this->abierto() && $this->puede(Permiso::TramitesCapturar)),
            ]);
    }
}
