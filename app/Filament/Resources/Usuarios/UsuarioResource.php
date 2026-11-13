<?php

namespace App\Filament\Resources\Usuarios;

use App\Filament\Resources\Recurso;
use App\Filament\Resources\Usuarios\Pages\ManageUsuarios;
use App\Filament\Support\Avisos;
use App\Models\Rol;
use App\Models\User;
use App\Services\Accesos\AdministracionAccesos;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Cuentas de acceso. Roles y estado se guardan a través de AdministracionAccesos:
 * así nunca se queda el sistema sin alguien que pueda administrarlo.
 */
class UsuarioResource extends Recurso
{
    protected static ?string $model = User::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'usuarios';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'usuarios';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre completo')->required()->maxLength(255),
            TextInput::make('email')->label('Correo')->email()->required()->maxLength(255)
                ->unique('users', 'email', ignoreRecord: true)
                ->helperText('Con este correo entra al sistema.'),
            TextInput::make('password')->label('Contraseña')->password()->revealable()
                ->minLength(12)->maxLength(255)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'create'
                    ? 'Al menos 12 caracteres. Entrégala en persona.'
                    : 'Déjala vacía para no cambiarla.'),
            Toggle::make('activo')->label('Puede entrar al sistema')->default(true)
                ->helperText('Apágalo cuando la persona deje el puesto. Su historial se conserva.'),
            CheckboxList::make('rol_ids')
                ->label('Roles')
                ->helperText('Lo que puede hacer. Una persona puede tener varios roles.')
                ->options(fn () => Rol::where('activo', true)->orderBy('id')->pluck('nombre', 'id'))
                ->descriptions(fn () => Rol::where('activo', true)->orderBy('id')->pluck('descripcion', 'id'))
                ->required()
                ->validationMessages(['required' => 'Elige al menos un rol; sin rol no puede entrar al sistema.']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nombre')->weight('semibold')->searchable()->description(fn (User $u) => $u->email),
                TextColumn::make('roles.nombre')->label('Roles')->badge()->color('gray')->wrap(),
                TextColumn::make('activo')->label('Estado')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Activo' : 'Sin acceso')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('activo')->label('Estado')->trueLabel('Activos')->falseLabel('Sin acceso')->placeholder('Todos')->default(true),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Editar')
                    ->modalHeading(fn (User $record) => "Editar a {$record->name}")
                    ->modalSubmitActionLabel('Guardar cambios')
                    ->mutateRecordDataUsing(fn (array $data, User $record): array => $data + ['rol_ids' => $record->roles()->pluck('roles.id')->all()])
                    ->using(fn (User $record, array $data, Action $action) => self::guardar($record, $data, $action)),
            ]);
    }

    /** Guarda datos, roles y estado en una transacción; si deja el sistema sin administración, no guarda nada. */
    public static function guardar(User $usuario, array $data, Action $action): User
    {
        $roles = array_map('intval', $data['rol_ids'] ?? []);
        $activo = (bool) ($data['activo'] ?? true);
        unset($data['rol_ids'], $data['activo']);
        $accesos = app(AdministracionAccesos::class);
        $actor = auth()->user();

        Avisos::intentar(fn () => DB::transaction(function () use ($usuario, $data, $roles, $activo, $accesos, $actor): void {
            $usuario->fill($data)->save();
            $accesos->asignarRoles($usuario, $roles, $actor);
            $accesos->activarUsuario($usuario, $activo, $actor);
        }), $action);

        return $usuario;
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsuarios::route('/')];
    }
}
