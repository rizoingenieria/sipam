<?php

namespace Tests\Feature;

use App\Enums\EventoBitacora;
use App\Filament\Auth\Acceso;
use App\Filament\Auth\CodigoCelular;
use App\Filament\Auth\MiCuenta;
use App\Filament\Resources\Usuarios\Pages\ManageUsuarios;
use App\Models\Bitacora;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Verificación en dos pasos: obligatoria según el rol (configurable en pantalla), inicio de sesión con el código
 * del celular o con un código de recuperación, secretos cifrados y fuera de la bitácora, y el administrador
 * puede quitarla a quien perdió el celular.
 */
class DosPasosTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVAR = '/multi-factor-authentication/set-up';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('El esquema usa columnas generadas y CHECK de MySQL; configure DB_CONNECTION=mysql.');
        }

        $this->seed(CatalogosSeeder::class);
    }

    public function test_de_fabrica_la_exige_el_administrador_y_no_la_ventanilla(): void
    {
        $this->assertTrue(Rol::where('clave', 'administrador')->value('exige_dos_pasos'));
        $this->assertFalse(Rol::where('clave', 'ventanilla')->value('exige_dos_pasos'));

        $admin = $this->usuario('administrador');
        $this->actingAs($admin)->get('/')->assertRedirect(self::ACTIVAR);
        $this->get('/usuarios')->assertRedirect(self::ACTIVAR);
        $this->get('/reportes/pdf/libro')->assertRedirect(self::ACTIVAR); // también las rutas propias (PDF, archivos)
        $this->get(self::ACTIVAR)->assertOk()->assertSee('Antes de seguir, activa el código del celular');

        $this->actingAs($this->usuario('ventanilla'))->get('/')->assertOk();

        $this->conDosPasos($admin);
        $this->actingAs($admin->fresh())->get('/')->assertOk();
    }

    public function test_se_configura_por_rol_desde_la_pantalla(): void
    {
        $persona = $this->usuario('ventanilla');
        $this->actingAs($persona)->get('/')->assertOk();

        Rol::where('clave', 'ventanilla')->update(['exige_dos_pasos' => true]);
        $this->get('/')->assertRedirect(self::ACTIVAR);

        // Un rol apagado no cuenta.
        Rol::where('clave', 'ventanilla')->update(['activo' => false]);
        $otro = $this->usuario('ventanilla', 'tesoreria');
        $this->actingAs($otro)->get('/')->assertOk();
    }

    public function test_al_entrar_pide_el_codigo_del_celular_y_acepta_uno_de_recuperacion(): void
    {
        $proveedor = CodigoCelular::make()->recoverable();
        $u = User::factory()->create(['email' => 'ana@coatepec.gob.mx', 'password' => Hash::make('clave-buena-123')]);
        $u->roles()->attach(Rol::where('clave', 'administrador')->value('id'));
        $secreto = $proveedor->generateSecret();
        $u->saveAppAuthenticationSecret($secreto);
        $u->saveAppAuthenticationRecoveryCodes(array_map(fn ($c) => Hash::make($c), ['RECUP-UNO-1', 'RECUP-DOS-2']));

        // Contraseña correcta: todavía no entra, pide el código.
        $pagina = Livewire::test(Acceso::class)
            ->fillForm(['email' => 'ana@coatepec.gob.mx', 'password' => 'clave-buena-123'])
            ->call('authenticate')
            ->assertSet('userUndertakingMultiFactorAuthentication', fn ($v) => filled($v))
            ->assertSee('Confirma que eres tú');
        $this->assertGuest();

        // Código equivocado: no entra.
        $pagina->set('data.multiFactor.app.code', '000000')->call('authenticate')->assertHasErrors();
        $this->assertGuest();

        // Código de la aplicación: entra.
        $pagina->set('data.multiFactor.app.code', $proveedor->getCurrentCode($u->fresh(), $secreto))->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($u);

        // Sin el celular: con un código de recuperación, que sólo sirve una vez.
        auth()->logout();
        $entrar = fn () => Livewire::test(Acceso::class)
            ->fillForm(['email' => 'ana@coatepec.gob.mx', 'password' => 'clave-buena-123'])->call('authenticate')
            ->set('data.multiFactor.app.useRecoveryCode', true)->set('data.multiFactor.app.recoveryCode', 'RECUP-DOS-2')
            ->call('authenticate');
        $entrar()->assertHasNoErrors();
        $this->assertAuthenticatedAs($u);
        auth()->logout();
        $this->travel(2)->minutes(); // fuera del límite de intentos de acceso: así se prueba el código y no el límite
        $segundo = $entrar();
        $segundo->assertSet('userUndertakingMultiFactorAuthentication', fn ($v) => filled($v));
        $segundo->assertHasErrors(['data.multiFactor.app.recoveryCode']);
        $this->assertGuest();
    }

    public function test_los_codigos_de_recuperacion_se_copian_a_mano_sin_confundirse(): void
    {
        $proveedor = CodigoCelular::make()->recoverable();
        $codigos = $proveedor->generateRecoveryCodes();

        $this->assertCount(8, $codigos);
        $this->assertCount(8, array_unique($codigos));
        foreach ($codigos as $c) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $c, 'sin I, O, 0 ni 1');
        }

        $u = $this->usuario('ventanilla');
        $proveedor->saveRecoveryCodes($u, $codigos);

        // Escrito como lo copiaría alguien de una hoja: minúsculas y espacios. Sirve una sola vez.
        $aMano = ' '.strtolower(str_replace('-', ' ', $codigos[3])).' ';
        $this->assertTrue($proveedor->verifyRecoveryCode($aMano, $u->fresh()));
        $this->assertFalse($proveedor->verifyRecoveryCode($codigos[3], $u->fresh()));
        $this->assertCount(7, $u->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertFalse($proveedor->verifyRecoveryCode('AAAA-BBBB-CCCC', $u->fresh()));
    }

    public function test_el_secreto_va_cifrado_y_la_bitacora_no_lo_guarda(): void
    {
        $u = $this->usuario('ventanilla');
        $this->actingAs($u);
        $secreto = AppAuthentication::make()->generateSecret();
        $u->saveAppAuthenticationSecret($secreto);
        $u->saveAppAuthenticationRecoveryCodes([Hash::make('RECUP-UNO-1')]);

        $crudo = DB::table('users')->where('id', $u->id)->first();
        $this->assertNotSame($secreto, $crudo->app_authentication_secret);
        $this->assertStringNotContainsString($secreto, (string) $crudo->app_authentication_secret);
        $this->assertSame($secreto, $u->fresh()->getAppAuthenticationSecret());
        $this->assertArrayNotHasKey('app_authentication_secret', $u->fresh()->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $u->fresh()->toArray());

        $bitacora = json_encode(Bitacora::where('modelo_tipo', User::class)->where('modelo_id', $u->id)->get()->toArray());
        $this->assertStringContainsString('(dato reservado)', $bitacora);
        $this->assertStringNotContainsString($secreto, $bitacora);
    }

    public function test_el_administrador_la_quita_a_quien_perdio_el_celular(): void
    {
        $admin = $this->conDosPasos($this->usuario('administrador'));
        $persona = $this->conDosPasos($this->usuario('ventanilla'));
        $sinVerificacion = $this->usuario('tesoreria');
        $this->actingAs($admin);

        Livewire::test(ManageUsuarios::class)
            ->assertActionHidden(TestAction::make('quitarDosPasos')->table($sinVerificacion))
            ->callAction(TestAction::make('quitarDosPasos')->table($persona))
            ->assertNotified('Se quitó la verificación en dos pasos');

        $this->assertFalse($persona->fresh()->tieneDosPasos());
        $this->assertNull($persona->fresh()->getAppAuthenticationRecoveryCodes());
        $linea = Bitacora::where('modelo_tipo', User::class)->where('modelo_id', $persona->id)->latest('id')->first();
        $this->assertSame(EventoBitacora::Modificado, $linea->evento);
        $this->assertStringStartsWith('Quitó la verificación en dos pasos de', $linea->descripcion);
        $this->assertSame($admin->id, $linea->usuario_id);

        // Quien no administra usuarios no llega a esa pantalla.
        $this->actingAs($persona)->get('/usuarios')->assertForbidden();
    }

    public function test_mi_cuenta_cambia_la_contrasena_pero_no_el_correo(): void
    {
        $u = User::factory()->create(['email' => 'luis@coatepec.gob.mx', 'password' => Hash::make('clave-actual-123')]);
        $u->roles()->attach(Rol::where('clave', 'ventanilla')->value('id'));
        $this->actingAs($u);
        Filament::setCurrentPanel('panteones');

        $this->get('/profile')->assertOk()->assertSee('Mi cuenta')->assertSee('Verificación en dos pasos')->assertSee('Código del celular');

        Livewire::test(MiCuenta::class)
            ->assertFormFieldIsDisabled('email')
            ->assertFormFieldIsDisabled('name')
            ->fillForm(['email' => 'otro@x.mx', 'password' => 'clave-nueva-456789', 'passwordConfirmation' => 'clave-nueva-456789', 'currentPassword' => 'clave-actual-123'])
            ->call('save')
            ->assertHasNoFormErrors();

        $u->refresh();
        $this->assertSame('luis@coatepec.gob.mx', $u->email);
        $this->assertTrue(Hash::check('clave-nueva-456789', $u->password));

        // Contraseña corta: no se acepta.
        Livewire::test(MiCuenta::class)
            ->fillForm(['password' => 'corta-1', 'passwordConfirmation' => 'corta-1', 'currentPassword' => 'clave-nueva-456789'])
            ->call('save')
            ->assertHasFormErrors(['password']);
    }

    private function usuario(string ...$roles): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Rol::whereIn('clave', $roles)->pluck('id'));

        return $u;
    }
}
