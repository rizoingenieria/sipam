<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Acceso;
use App\Filament\Auth\CodigoCelular;
use App\Filament\Auth\MiCuenta;
use App\Filament\Pages\Inicio;
use App\Filament\Support\Paleta;
use App\Http\Controllers\DocumentosController;
use App\Http\Controllers\ReportesController;
use App\Http\Controllers\VerArchivoAcervo;
use App\Http\Controllers\VerArchivoTramite;
use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\ExigirDosPasos;
use App\Models\EntePublico;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel único de SIPAM, en la raíz del sitio:
 * - colores sólo de la paleta del logo (App\Filament\Support\Paleta), sin modo oscuro;
 * - Atkinson Hyperlegible Next, una tipografía diseñada para leerse con facilidad;
 * - una hoja de estilos simple (resources/css/sipam.css) que no requiere compilar nada;
 * - pantalla de acceso propia (App\Filament\Auth\Acceso) con el logo y la foto del panteón.
 * El municipio se lee de la base: el mismo sistema sirve a otro ayuntamiento sin tocar código.
 */
class PanteonesPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('panteones')
            ->path('')
            ->login(Acceso::class)
            // «Mi cuenta»: contraseña y verificación en dos pasos, dentro del panel.
            ->profile(MiCuenta::class, isSimple: false)
            // Verificación en dos pasos con una aplicación de códigos en el celular, con códigos de recuperación.
            // «Requerida» para que Filament registre su pantalla y proteja todas las páginas; quién debe usarla
            // lo decide ExigirDosPasos según el rol (Roles y permisos › «Pide verificación en dos pasos»).
            ->multiFactorAuthentication([CodigoCelular::make()->brandName('SIPAM')->recoverable()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(ExigirDosPasos::class)
            ->brandName(fn (): string => EntePublico::actual()?->nombre_sistema ?? config('app.name'))
            ->brandLogo(fn () => view('filament.marca', ['ente' => EntePublico::actual()]))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('images/favicon-64.png'))
            ->colors(Paleta::colores())
            ->font('Atkinson Hyperlegible Next', provider: GoogleFontProvider::class)
            ->darkMode(false)
            ->sidebarWidth('17rem')
            ->maxContentWidth(Width::SevenExtraLarge)
            ->globalSearch(false)
            ->unsavedChangesAlerts()
            ->assets([Css::make('sipam', resource_path('css/sipam.css'))])
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn () => view('filament.acceso.marca'), scopes: Acceso::class)
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('filament.acceso.ayuda'), scopes: Acceso::class)
            ->navigationGroups([
                NavigationGroup::make('Trámites'),
                NavigationGroup::make('Registros'),
                NavigationGroup::make('Reportes'),
                NavigationGroup::make('Inventario'),
                // Agrupado por cómo lo piensa el personal: qué se cobra, qué dice el reglamento,
                // qué llevan los documentos. «Configuración» a secas no le dice nada a nadie.
                NavigationGroup::make('Cobros'),
                NavigationGroup::make('Reglamento'),
                NavigationGroup::make('Documentos oficiales'),
                NavigationGroup::make('Administración'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Inicio::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->authenticatedRoutes(function (): void {
                // También aquí se exige la verificación en dos pasos a quien su rol se la pide.
                Route::middleware(ExigirDosPasos::class)->group(function (): void {
                    // Evidencias de los trámites: disco privado, sólo con sesión iniciada.
                    Route::get('archivos/{archivo}', VerArchivoTramite::class)->name('archivo');
                    // Originales escaneados del acervo histórico (disco «acervo»).
                    Route::get('acervo/archivo/{archivo}', VerArchivoAcervo::class)->name('acervo.archivo');
                    // Documentos oficiales en PDF, generados al momento con los datos guardados.
                    Route::prefix('documentos')->name('documentos.')->controller(DocumentosController::class)->group(function (): void {
                        Route::get('orden-de-pago/{orden}', 'orden')->name('orden');
                        Route::get('para-firmar/{fila}', 'paraFirmar')->name('para-firmar');
                        Route::get('consentimiento/{participante}', 'consentimiento')->name('consentimiento');
                        Route::get('aviso/{tramite}', 'notificacion')->name('notificacion');
                        Route::get('resolucion/{tramite}', 'resolucion')->name('resolucion');
                        Route::get('titulo/{titulo}', 'titulo')->name('titulo');
                    });
                    // Reportes en PDF con los filtros de su pantalla.
                    Route::prefix('reportes/pdf')->name('reportes.')->controller(ReportesController::class)->group(function (): void {
                        Route::get('libro', 'libro')->name('libro');
                        Route::get('ingresos', 'ingresos')->name('ingresos');
                        Route::get('mensual', 'mensual')->name('mensual');
                        Route::get('salud', 'salud')->name('salud');
                    });
                });
            })
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                CabecerasSeguridad::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
