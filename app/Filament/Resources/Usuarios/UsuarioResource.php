<?php

namespace App\Filament\Resources\Usuarios;

use App\Enums\EventoBitacora;
use App\Filament\Resources\Recurso;
use App\Filament\Resources\Usuarios\Pages\ManageUsuarios;
use App\Filament\Support\Avisos;
use App\Models\Bitacora;
use App\Models\Rol;
use App\Models\User;
use App\Services\Accesos\AdministracionAccesos;
use App\Support\Auditoria;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
                TextColumn::make('dos_pasos')->label('Dos pasos')->badge()
                    ->state(fn (User $u) => $u->tieneDosPasos() ? 'Activada' : ($u->exigeDosPasos() ? 'Pendiente' : 'Sin activar'))
                    ->color(fn (string $state) => match ($state) {
                        'Activada' => 'success',
                        'Pendiente' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (string $state) => $state === 'Pendiente' ? 'Su rol la pide: la activará la próxima vez que entre.' : null),
                TextColumn::make('activo')->label('Estado')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Activo' : 'Sin acceso')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('activo')->label('Estado')->trueLabel('Activos')->falseLabel('Sin acceso')->placeholder('Todos')->default(true),
            ])
            ->recordActions([
                self::quitarDosPasos(),
                EditAction::make()
                    ->label('Editar')
                    ->modalHeading(fn (User $record) => "Editar a {$record->name}")
                    ->modalSubmitActionLabel('Guardar cambios')
                    ->mutateRecordDataUsing(fn (array $data, User $record): array => $data + ['rol_ids' => $record->roles()->pluck('roles.id')->all()])
                    ->using(fn (User $record, array $data, Action $action) => self::guardar($record, $data, $action)),
            ]);
    }

    /**
     * Para quien perdió o cambió de celular y ya no tiene sus códigos de recuperación.
     * Si su rol pide la verificación, la vuelve a configurar en cuanto entre.
     */
    public static function quitarDosPasos(): Action
    {
        return Action::make('quitarDosPasos')
            ->label('Quitar dos pasos')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->color('danger')
            ->visible(fn (User $record) => $record->tieneDosPasos())
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => "¿Quitar la verificación en dos pasos a {$record->name}?")
            ->modalDescription('Hazlo sólo si la persona perdió o cambió su celular y no tiene sus códigos de recuperación. Confirma primero que es ella quien lo pide. Podrá entrar sólo con su contraseña hasta que vuelva a activar el código.')
            ->modalSubmitActionLabel('Sí, quitarla')
            ->action(function (User $record): void {
                // Un solo renglón claro en la bitácora en vez del cambio genérico del usuario.
                Auditoria::sinAnotar(fn () => $record->quitarDosPasos());
                Bitacora::anotar(EventoBitacora::Modificado, 'Quitó la verificación en dos pasos de '.$record->name.' ('.$record->email.')', $record);
                Notification::make()->success()->title('Se quitó la verificación en dos pasos')
                    ->body($record->exigeDosPasos() ? 'Su rol la pide: deberá activarla otra vez la próxima vez que entre.' : 'Podrá activarla otra vez desde «Mi cuenta».')
                    ->send();
            });
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
