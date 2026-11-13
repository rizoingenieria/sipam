<?php

namespace App\Filament\Pages;

use App\Enums\Permiso;
use App\Filament\Support\Avisos;
use App\Models\EntePublico;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Datos del ayuntamiento para el membrete y la marca del sistema.
 * Con ellos el mismo sistema sirve a otro municipio sin tocar código.
 */
class DatosAyuntamiento extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Documentos oficiales';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Datos del ayuntamiento';

    protected static ?string $title = 'Datos del ayuntamiento';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'datos-del-ayuntamiento';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->puede(Permiso::CatalogosReglas) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Aparecen en el membrete de los documentos y en la parte superior del sistema.';
    }

    public function mount(): void
    {
        $this->form->fill(EntePublico::actual()?->attributesToArray() ?? []);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Identidad')
                ->columns(2)
                ->schema([
                    TextInput::make('nombre_ente')->label('Nombre oficial')->helperText('Como va en el membrete, por ejemplo «H. Ayuntamiento Constitucional de Coatepec, Ver.».')
                        ->required()->maxLength(200)->columnSpanFull(),
                    TextInput::make('municipio')->label('Municipio')->required()->maxLength(100),
                    TextInput::make('estado')->label('Estado')->required()->maxLength(100),
                    TextInput::make('nombre_sistema')->label('Nombre del sistema')->required()->maxLength(100),
                ]),
            Section::make('Contacto')
                ->columns(2)
                ->schema([
                    TextInput::make('domicilio')->label('Domicilio')->maxLength(255)->columnSpanFull(),
                    TextInput::make('telefono')->label('Teléfono')->tel()->maxLength(30),
                    TextInput::make('correo')->label('Correo')->email()->maxLength(150),
                    TextInput::make('sitio_web')->label('Sitio web')->url()->maxLength(150)->columnSpanFull(),
                ]),
            Section::make('Imágenes')
                ->description('PNG o JPG con fondo transparente o blanco. Se ven mejor si son cuadradas o horizontales.')
                ->columns(2)
                ->schema([
                    FileUpload::make('escudo_path')->label('Escudo del municipio')->image()->disk('public')->directory('marca')->maxSize(2048),
                    FileUpload::make('logo_path')->label('Logotipo de la administración')->helperText('Opcional. Si no lo hay, se usa el escudo.')
                        ->image()->disk('public')->directory('marca')->maxSize(2048),
                ]),
            Section::make('Documentos')
                ->schema([
                    Textarea::make('reglamento_cita')->label('Cita del reglamento')->helperText('Se usa como fundamento general en los documentos.')->rows(2)->maxLength(500),
                    Textarea::make('leyenda_documentos')->label('Leyenda al pie de los documentos')->helperText('Opcional. Por ejemplo, el aviso de privacidad simplificado.')->rows(2)->maxLength(500),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('guardar')
                ->footer([
                    Actions::make([
                        Action::make('guardar')->label('Guardar datos')->submit('guardar'),
                    ]),
                ]),
        ]);
    }

    public function guardar(): void
    {
        $datos = $this->form->getState();
        $ente = EntePublico::actual() ?? new EntePublico;
        $ente->fill($datos)->save();

        Avisos::listo('Datos guardados', 'El membrete y la marca ya usan los datos nuevos.');
    }
}
