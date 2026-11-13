<?php

namespace App\Filament\Resources\Tramites\RelationManagers\Concerns;

use App\Enums\EstadoTramite;
use App\Enums\Permiso;
use App\Filament\Support\Avisos;
use App\Models\Tramite;
use App\Services\Tramites\GestorTramites;
use Filament\Actions\Action;
use Livewire\Attributes\On;

/**
 * Lo común a las pestañas del expediente: todas cambian el trámite sólo por el GestorTramites,
 * avisan al resto de la página para que «Qué sigue» se actualice, y se actualizan cuando otra cambia algo.
 */
trait ParteDelExpediente
{
    /** En la página del expediente sí se actúa: Filament las deja de sólo lectura por omisión. */
    public function isReadOnly(): bool
    {
        return false;
    }

    #[On('expediente-actualizado')]
    public function refrescarParte(): void
    {
        $this->flushCachedTableRecords();
    }

    protected function tramite(): Tramite
    {
        return $this->getOwnerRecord()->refresh();
    }

    protected function gestor(): GestorTramites
    {
        return app(GestorTramites::class);
    }

    protected function puede(Permiso $permiso): bool
    {
        return auth()->user()?->puede($permiso) ?? false;
    }

    protected function editable(): bool
    {
        return in_array($this->getOwnerRecord()->estado, [EstadoTramite::Capturado, EstadoTramite::EnRevision], true);
    }

    protected function abierto(): bool
    {
        return ! $this->getOwnerRecord()->estado->esTerminal();
    }

    /** Ejecuta un paso del gestor; si se bloquea, muestra los motivos y deja la ventana abierta. */
    protected function paso(Action $action, callable $paso, string $listo): void
    {
        try {
            Avisos::intentar($paso, $action);
        } finally {
            $this->dispatch('expediente-actualizado');
        }
        Avisos::listo($listo);
    }
}
