<?php

namespace App\Services\CargaInicial;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Plantillas de Excel para la carga inicial: la hoja «Datos» sólo con los encabezados (se llena desde el renglón 2)
 * y la hoja «Instrucciones» con qué poner en cada columna y un ejemplo.
 */
final class Plantillas
{
    public function crear(TipoCarga $tipo, string $ruta): void
    {
        $negritas = (new Style)->setFontBold()->setBackgroundColor('E3ECE4');
        $columnas = $tipo->columnas();

        $w = new Writer;
        $w->openToFile($ruta);

        $datos = $w->getCurrentSheet();
        $datos->setName('Datos');
        foreach (array_keys($columnas) as $i) {
            $datos->setColumnWidth(22, $i + 1);
        }
        $w->addRow(Row::fromValues(array_map(fn (Columna $c) => $c->encabezado, $columnas), $negritas));

        $ayuda = $w->addNewSheetAndMakeItCurrent();
        $ayuda->setName('Instrucciones');
        $ayuda->setColumnWidth(28, 1);
        $ayuda->setColumnWidth(14, 2);
        $ayuda->setColumnWidth(70, 3);
        $ayuda->setColumnWidth(28, 4);
        $w->addRow(Row::fromValues([$tipo->getLabel()], (new Style)->setFontBold()->setFontSize(14)));
        $w->addRow(Row::fromValues([$tipo->getDescription()]));
        $w->addRow(Row::fromValues(['Llena la hoja «Datos» desde el renglón 2, un registro por renglón. No cambies el renglón 1.']));
        $w->addRow(Row::fromValues(['']));
        $w->addRow(Row::fromValues(['Columna', 'Obligatoria', 'Qué poner', 'Ejemplo'], $negritas));
        foreach ($columnas as $c) {
            $w->addRow(Row::fromValues([$c->encabezado, $c->obligatoria ? 'Sí' : 'No', $c->ayuda, $c->ejemplo]));
        }
        $w->close();
    }

    public static function nombreArchivo(TipoCarga $tipo): string
    {
        return 'plantilla-'.$tipo->value.'.xlsx';
    }
}
