<?php

namespace App\Services\Documentos;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as ArchivoPdf;

/**
 * Un documento listo para imprimir: la vista y sus datos. Se convierte a PDF (para el usuario)
 * o a HTML (para revisarlo en pruebas sin tener que leer el PDF).
 */
final readonly class Documento
{
    public function __construct(
        public string $vista,
        public ?string $folio,
        public array $datos,
        public bool $horizontal = false,
    ) {}

    public function html(): string
    {
        return view($this->vista, $this->datos)->render();
    }

    public function pdf(): ArchivoPdf
    {
        $pdf = Pdf::setOptions(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'chroot' => base_path()])
            ->loadHTML($this->html())
            ->setPaper('letter', $this->horizontal ? 'landscape' : 'portrait');

        // dompdf sólo conoce el total de páginas después de dibujar (counter(pages) da 0).
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $fuente = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        [$x, $y] = $this->horizontal ? [630, 590] : [470, 770];
        $dompdf->getCanvas()->page_text($x, $y, ($this->folio ? "{$this->folio} · " : '')."P\u{00E1}gina {PAGE_NUM} de {PAGE_COUNT}", $fuente, 7, [0.29, 0.36, 0.4]);

        return $pdf;
    }

    /** Páginas del PDF ya dibujado. */
    public function paginas(): int
    {
        return $this->pdf()->getDomPDF()->getCanvas()->get_page_count();
    }
}
