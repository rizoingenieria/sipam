<?php

namespace App\Services\CargaInicial;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Lo que se puede cargar del registro anterior, en este orden: los títulos y los sepultados
 * se ubican en espacios que ya deben existir.
 */
enum TipoCarga: string implements HasDescription, HasLabel
{
    case Espacios = 'espacios';
    case Titulos = 'titulos';
    case Sepultados = 'sepultados';

    public function getLabel(): string
    {
        return match ($this) {
            self::Espacios => '1. Espacios (fosas, nichos, criptas)',
            self::Titulos => '2. Títulos y sus titulares',
            self::Sepultados => '3. Personas sepultadas',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Espacios => 'Cada renglón es un espacio. Si la sección o el lote no existen, se crean. El panteón debe darse de alta antes en Inventario.',
            self::Titulos => 'Cada renglón es un título vigente, con su titular. El espacio ya debe estar cargado.',
            self::Sepultados => 'Cada renglón es una persona sepultada, como viene en el libro. El espacio ya debe estar cargado.',
        };
    }

    /** @return list<Columna> */
    public function columnas(): array
    {
        $ubicacion = [
            Columna::de('panteon', 'Panteón', true, 'Clave corta del panteón, como está en Inventario › Panteones.', 'MUN'),
            Columna::de('seccion', 'Sección', true, 'Clave de la sección.', 'A'),
            Columna::de('lote', 'Lote', true, 'Número del lote.', '12'),
            Columna::de('espacio', 'Espacio', true, 'Número de la fosa, nicho o cripta dentro del lote. Si el lote sólo tiene una, pon 1.', '1'),
        ];

        return match ($this) {
            self::Espacios => [
                ...$ubicacion,
                Columna::de('tipo', 'Tipo', false, 'fosa, nicho, cripta o gaveta. Si lo dejas vacío, se toma como fosa.', 'fosa'),
                Columna::de('largo', 'Largo (m)', false, 'Medida en metros.', '2.20'),
                Columna::de('ancho', 'Ancho (m)', false, 'Medida en metros.', '1.10'),
                Columna::de('profundidad', 'Profundidad (m)', false, 'Medida en metros.', '1.50'),
                Columna::de('disponible', 'Disponible', false, 'sí o no. «no» si no puede usarse (derrumbe, invasión). Vacío es «sí».', 'sí'),
                Columna::de('fosa_comun', 'Fosa común', false, 'sí o no. Vacío es «no».', 'no'),
                Columna::de('monumento_historico', 'Monumento histórico', false, 'sí o no (anterior a 1931). Vacío es «no».', 'no'),
                Columna::de('observaciones', 'Observaciones', false, 'Lo que convenga anotar.', ''),
            ],
            self::Titulos => [
                ...$ubicacion,
                Columna::de('folio', 'Folio del título', true, 'Como viene impreso en el título. No se puede repetir.', '0457'),
                Columna::de('expediente', 'Expediente', false, 'Número de expediente, si lo tiene.', 'EXP-1998-031'),
                Columna::de('tipo_titulo', 'Tipo de título', false, 'perpetuidad o temporalidad. Vacío es «perpetuidad».', 'perpetuidad'),
                Columna::de('fecha_emision', 'Fecha de emisión', true, 'Como día/mes/año.', '10/05/2004'),
                Columna::de('titular_nombre', 'Titular: nombre(s)', true, 'Nombre o nombres del titular.', 'Juan'),
                Columna::de('titular_primer_apellido', 'Titular: primer apellido', false, '', 'Pérez'),
                Columna::de('titular_segundo_apellido', 'Titular: segundo apellido', false, '', 'García'),
                Columna::de('titular_curp', 'Titular: CURP', false, 'Si se conoce. Evita registrar dos veces a la misma persona.', ''),
                Columna::de('copropietarios', 'Copropietarios', false, 'Nombres completos separados por punto y coma (;).', 'Ana Pérez López; Luis Pérez López'),
                Columna::de('beneficiarios', 'Beneficiarios', false, 'Nombres completos separados por punto y coma (;).', ''),
                Columna::de('observaciones', 'Observaciones', false, 'Lo que convenga anotar.', ''),
            ],
            self::Sepultados => [
                ...$ubicacion,
                Columna::de('nombre', 'Nombre(s)', true, 'Como viene en el libro. Si no se identificó, escribe «Desconocido».', 'Carmen'),
                Columna::de('primer_apellido', 'Primer apellido', false, '', 'García'),
                Columna::de('segundo_apellido', 'Segundo apellido', false, '', 'Ruiz'),
                Columna::de('sexo', 'Sexo', false, 'mujer u hombre (también femenino/masculino, F/H). No uses «M»: es ambigua. Vacío es «no especificado».', 'mujer'),
                Columna::de('fecha_inhumacion', 'Fecha de inhumación', false, 'Día/mes/año. Sin ella no se puede calcular cuándo se podría exhumar.', '04/02/2015'),
                Columna::de('fecha_defuncion', 'Fecha de defunción', false, 'Día/mes/año.', '03/02/2015'),
                Columna::de('edad_al_fallecer', 'Edad al fallecer', false, 'En años. De ella depende el plazo para exhumar.', '81'),
                Columna::de('fecha_nacimiento', 'Fecha de nacimiento', false, 'Si no hay edad, con esta y la de defunción se calcula.', ''),
                Columna::de('acta', 'Número de acta de defunción', false, '', '00231'),
                Columna::de('lugar', 'Lugar de fallecimiento', false, '', 'Coatepec, Ver.'),
                Columna::de('causa', 'Causa de fallecimiento', false, 'Dato sensible: sólo lo verá quien tenga permiso.', ''),
                Columna::de('situacion', 'Situación', false, '«sepultado» o «restos en sitio» (restos áridos al pie de la fosa). Vacío es «sepultado».', 'sepultado'),
                Columna::de('observaciones', 'Observaciones', false, 'Lo que convenga anotar.', ''),
            ],
        };
    }
}
