<?php

namespace App\Filament\Resources\Roles;

use App\Enums\Permiso;
use App\Filament\Resources\Recurso;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Support\Avisos;
use App\Models\Rol;
use App\Services\Accesos\AdministracionAccesos;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Roles y lo que permite cada uno. Los permisos se muestran agrupados y con su explicación,
 * para decidir sin conocer el sistema por dentro.
 */
class RolResource extends Recurso
{
    protected static ?string $model = Rol::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $modelLabel = 'rol';

    protected static ?string $pluralModelLabel = 'roles y permisos';

    protected static ?string $navigationLabel = 'Roles y permisos';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'roles';

    /** @return array<string, list<Permiso>> */
    public static function permisosPorGrupo(): array
    {
        return collect(Permiso::cases())->groupBy(fn (Permiso $p) => $p->grupo())->map->all()->all();
    }

    public static function form(Schema $schema): Schema
    {
        $grupos = [];
        foreach (self::permisosPorGrupo() as $grupo => $permisos) {
            $grupos[] = Section::make($grupo)
                ->compact()
                ->schema([
                    CheckboxList::make('permisos_'.Str::slug($grupo, '_'))
                        ->hiddenLabel()
                        ->options(collect($permisos)->mapWithKeys(fn (Permiso $p) => [$p->value => $p->getLabel()]))
                        ->descriptions(collect($permisos)->mapWithKeys(fn (Permiso $p) => [$p->value => $p->getDescription()]))
                        ->bulkToggleable()
                        ->columns(2),
                ]);
        }

        return $schema->components([
            TextInput::make('nombre')->label('Nombre del rol')->required()->maxLength(100),
            Textarea::make('descripcion')->label('Para qué sirve')->rows(2)->maxLength(500),
            Toggle::make('activo')->label('Rol activo')->default(true)
                ->helperText('Si lo apagas, quienes lo tienen pierden estos permisos.'),
            Toggle::make('exige_dos_pasos')->label('Pide verificación en dos pasos')->default(false)
                ->helperText('Quienes tengan este rol deberán activar el código del celular para entrar. Recomendado para roles que administran, autorizan o cobran.'),
            ...$grupos,
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('nombre')->label('Rol')->weight('semibold')->description(fn (Rol $r) => $r->descripcion)->wrap()->width('50%'),
                TextColumn::make('usuarios_count')->label('Personas')->counts('usuarios')->alignEnd(),
                // «permisos» es una lista: Filament pintaría un valor por elemento. Se resume en uno.
                TextColumn::make('resumen_permisos')->label('Permisos')
                    ->state(fn (Rol $r) => count((array) $r->permisos).' de '.count(Permiso::cases()))->alignEnd(),
                TextColumn::make('exige_dos_pasos')->label('Dos pasos')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Obligatoria' : 'Opcional')
                    ->color(fn (bool $state) => $state ? 'primary' : 'gray'),
                TextColumn::make('activo')->label('Estado')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Activo' : 'Apagado')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Editar')
                    ->modalHeading(fn (Rol $record) => "Editar el rol «{$record->nombre}»")
                    ->modalSubmitActionLabel('Guardar rol')
                    ->modalWidth('4xl')
                    ->mutateRecordDataUsing(fn (array $data, Rol $record): array => $data + self::repartir((array) $record->permisos))
                    ->using(fn (Rol $record, array $data, Action $action) => self::guardar($record, $data, $action)),
            ]);
    }

    /** Reparte los permisos guardados en las casillas de cada grupo. */
    public static function repartir(array $permisos): array
    {
        $datos = [];
        foreach (self::permisosPorGrupo() as $grupo => $delGrupo) {
            $valores = array_map(fn (Permiso $p) => $p->value, $delGrupo);
            $datos['permisos_'.Str::slug($grupo, '_')] = array_values(array_intersect($valores, $permisos));
        }

        return $datos;
    }

    public static function guardar(Rol $rol, array $data, Action $action): Rol
    {
        $permisos = [];
        foreach (array_keys(self::permisosPorGrupo()) as $grupo) {
            $campo = 'permisos_'.Str::slug($grupo, '_');
            $permisos = [...$permisos, ...($data[$campo] ?? [])];
            unset($data[$campo]);
        }
        $activo = (bool) ($data['activo'] ?? true);
        unset($data['activo']);
        $accesos = app(AdministracionAccesos::class);
        $actor = auth()->user();

        Avisos::intentar(fn () => DB::transaction(function () use ($rol, $data, $permisos, $activo, $accesos, $actor): void {
            if (! $rol->exists) {
                $rol->fill($data + ['clave' => self::claveUnica($data['nombre']), 'permisos' => [], 'activo' => $activo])->save();
            } else {
                $rol->fill($data)->save();
            }
            $accesos->guardarPermisos($rol, $permisos, $actor);
            $accesos->activarRol($rol, $activo, $actor);
        }), $action);

        return $rol;
    }

    private static function claveUnica(string $nombre): string
    {
        $base = Str::slug($nombre, '_') ?: 'rol';
        $clave = $base;
        for ($i = 2; Rol::where('clave', $clave)->exists(); $i++) {
            $clave = "{$base}_{$i}";
        }

        return $clave;
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
