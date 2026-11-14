<?php

namespace App\Providers;

use App\Listeners\AnotarAccesos;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Titulo;
use App\Support\Auditoria;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Subidas: Livewire trae un tope fijo de 12 MB que ignoraba los parámetros de tamaño de la pantalla.
        // El tope real es el del servidor (config/archivos.php); cada pantalla valida además su parámetro.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(config('archivos.subida_maxima_mb') * 1024)]]);
        config(['livewire.temporary_file_upload.max_upload_time' => 30]); // minutos: un libro escaneado por una red lenta

        // Vínculos del acervo histórico: alias estables en la base, no nombres de clase.
        Relation::morphMap(['espacio' => Espacio::class, 'titulo' => Titulo::class, 'inhumado' => Inhumado::class]);

        // Bitácora: altas, cambios y bajas de los registros que importan, y entradas al sistema.
        Auditoria::registrar();
        Event::listen(Login::class, [AnotarAccesos::class, 'entrada']);
        Event::listen(Logout::class, [AnotarAccesos::class, 'salida']);
        Event::listen(Failed::class, [AnotarAccesos::class, 'fallida']);

        // Números y moneda con formato mexicano (1,500.00); meses y días en español.
        Number::useLocale('es_MX');
        Carbon::setLocale('es');

        // Tablas: páginas de 25, sin franjas de color (guía «Papel y tinta», sección 5).
        Table::configureUsing(fn (Table $table) => $table
            ->striped(false)
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            // Los filtros aplican al elegirlos; un botón «Aplicar» extra se olvida y parece que no funcionan.
            ->deferFilters(false)
            // «Quitar filtros» en gris: el rojo es sólo para errores y acciones que borran.
            ->filtersResetAction(fn (Action $action) => $action->label('Quitar filtros')->color('gray')));

        // Fechas siempre como «27/10/2026» y calendario en español que empieza en lunes,
        // sin depender del idioma del navegador de cada computadora.
        DatePicker::configureUsing(fn (DatePicker $picker) => $picker
            ->native(false)
            ->displayFormat('d/m/Y')
            ->locale('es')
            ->firstDayOfWeek(1)
            ->closeOnDateSelection());

        // Subir archivos: decir qué hacer con palabras de todos los días; en el celular abre la cámara.
        FileUpload::configureUsing(fn (FileUpload $upload) => $upload
            ->placeholder('Arrastra aquí el archivo o <span class="filepond--label-action">toca para elegirlo o tomar una foto</span>'));
    }
}
