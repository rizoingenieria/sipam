<?php

namespace App\Filament\Support;

use App\Models\Persona;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;

/**
 * Elegir a una persona ya registrada, o registrarla ahí mismo sin salir del formulario.
 * Se busca por nombre o CURP: así no se duplican personas que vuelven a otro trámite.
 */
final class CamposPersona
{
    public static function select(string $nombre, string $etiqueta): Select
    {
        return Select::make($nombre)
            ->label($etiqueta)
            ->searchable()
            ->placeholder('Toca aquí y escribe para buscar')
            ->searchPrompt('Escribe el nombre o la CURP')
            ->noSearchResultsMessage('No está registrada. Usa el botón «+» para agregarla.')
            ->getSearchResultsUsing(fn (string $search): array => Persona::query()
                ->where(fn ($q) => $q->where('nombre_completo', 'like', '%'.trim($search).'%')
                    ->orWhere('curp', strtoupper(trim($search))))
                ->orderBy('nombre_completo')->limit(30)->get()
                ->mapWithKeys(fn (Persona $p) => [$p->id => self::etiqueta($p)])->all())
            ->getOptionLabelUsing(fn ($value): ?string => ($p = Persona::find($value)) ? self::etiqueta($p) : null)
            ->createOptionForm(self::formulario())
            ->createOptionUsing(fn (array $data): int => Persona::create($data)->id)
            ->createOptionModalHeading('Registrar persona');
    }

    public static function etiqueta(Persona $p): string
    {
        return $p->nombre_completo.($p->curp ? " · {$p->curp}" : '');
    }

    public static function formulario(): array
    {
        return [
            Grid::make(3)->schema([
                TextInput::make('nombre')->label('Nombre o nombres')->required()->maxLength(100),
                TextInput::make('primer_apellido')->label('Primer apellido')->maxLength(100),
                TextInput::make('segundo_apellido')->label('Segundo apellido')->maxLength(100),
            ]),
            Grid::make(2)->schema([
                TextInput::make('curp')->label('CURP')->helperText('Opcional, pero evita duplicados. Viene en la identificación.')
                    ->length(18)
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail): void {
                        if (filled($value) && Persona::where('curp', strtoupper(trim($value)))->exists()) {
                            $fail('Ya hay una persona registrada con esa CURP. Búscala por su CURP en lugar de registrarla otra vez.');
                        }
                    }),
                TextInput::make('telefono')->label('Teléfono')->tel()->maxLength(20)->helperText('Opcional.'),
            ]),
            TextInput::make('domicilio')->label('Domicilio')->maxLength(255)->helperText('Opcional.'),
        ];
    }
}
