<?php

namespace Database\Seeders\Demo;

use App\Enums\Sexo;
use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Azar con semilla fija: cada corrida de la demostración produce exactamente los mismos datos,
 * así una captura de pantalla o un reporte se puede reproducir. Todos los nombres son inventados
 * por combinación; cualquier parecido con una persona real es coincidencia.
 */
final class Azar
{
    private const MUJERES = ['María', 'Guadalupe', 'Rosa', 'Juana', 'Margarita', 'Josefina', 'Teresa', 'Carmen', 'Leticia', 'Patricia',
        'Silvia', 'Elena', 'Alicia', 'Martha', 'Gloria', 'Ana', 'Lucía', 'Verónica', 'Adriana', 'Socorro', 'Esperanza', 'Irma',
        'Graciela', 'Yolanda', 'Isabel', 'Beatriz', 'Rocío', 'Claudia', 'Norma', 'Araceli'];

    private const HOMBRES = ['José', 'Juan', 'Francisco', 'Antonio', 'Jesús', 'Miguel', 'Pedro', 'Manuel', 'Alejandro', 'Roberto',
        'Rafael', 'Fernando', 'Ricardo', 'Jorge', 'Luis', 'Arturo', 'Sergio', 'Enrique', 'Ramón', 'Alfredo', 'Javier', 'Carlos',
        'Raúl', 'Gerardo', 'Mario', 'Eduardo', 'Rubén', 'Andrés', 'Ignacio', 'Héctor'];

    private const APELLIDOS = ['Hernández', 'García', 'Martínez', 'López', 'González', 'Pérez', 'Rodríguez', 'Sánchez', 'Ramírez', 'Cruz',
        'Flores', 'Gómez', 'Morales', 'Vázquez', 'Reyes', 'Jiménez', 'Torres', 'Díaz', 'Gutiérrez', 'Ruiz', 'Mendoza', 'Aguilar',
        'Ortiz', 'Moreno', 'Castillo', 'Romero', 'Álvarez', 'Méndez', 'Chávez', 'Rivera', 'Juárez', 'Domínguez', 'Lara', 'Contreras',
        'Landa', 'Utrera', 'Murrieta', 'Bonilla', 'Hidalgo', 'Barradas', 'Viveros', 'Rebolledo', 'Pozos', 'Zamudio', 'Olmos'];

    private const CALLES = ['Hidalgo', 'Morelos', 'Juárez', 'Zamora', 'Lerdo', 'Aldama', 'Allende', 'Galeana', 'Matamoros', 'Guerrero',
        'Independencia', 'Cuauhtémoc', '5 de Mayo', '16 de Septiembre', 'Reforma', 'Constitución', 'Libertad', 'Los Pinos'];

    private const COLONIAS = ['Centro', 'Centro', 'Centro', 'Tepeyac', 'Revolución', 'Unidad Veracruzana', 'Jardines', 'El Haya', 'Pastoresa', 'Benito Juárez'];

    private const CAUSAS = [
        'Infarto agudo al miocardio' => 18, 'Diabetes mellitus tipo 2' => 16, 'Neumonía' => 9, 'Insuficiencia renal crónica' => 8,
        'Enfermedad vascular cerebral' => 8, 'Hipertensión arterial sistémica' => 7, 'Cirrosis hepática' => 5,
        'Cáncer de próstata' => 3, 'Cáncer de mama' => 3, 'Cáncer cervicouterino' => 2, 'Enfermedad pulmonar obstructiva crónica' => 5,
        'Insuficiencia cardiaca' => 6, 'Accidente de tránsito' => 3, 'Senilidad' => 4,
    ];

    private Randomizer $r;

    public function __construct(int $semilla = 2026)
    {
        $this->r = new Randomizer(new Mt19937($semilla));
    }

    public function entero(int $min, int $max): int
    {
        return $this->r->getInt($min, $max);
    }

    /** true con la probabilidad dada (0 a 1). */
    public function si(float $probabilidad): bool
    {
        return $this->r->getFloat(0, 1) < $probabilidad;
    }

    /**
     * @template T
     *
     * @param  list<T>  $opciones
     * @return T
     */
    public function uno(array $opciones): mixed
    {
        return $opciones[$this->r->getInt(0, count($opciones) - 1)];
    }

    /** @param array<string, int> $pesos */
    public function ponderado(array $pesos): string
    {
        $tiro = $this->r->getInt(1, array_sum($pesos));
        foreach ($pesos as $valor => $peso) {
            if (($tiro -= $peso) <= 0) {
                return (string) $valor;
            }
        }

        return (string) array_key_last($pesos);
    }

    public function fecha(string $desde, string $hasta): Carbon
    {
        $a = Carbon::parse($desde)->startOfDay();

        return $a->copy()->addDays($this->r->getInt(0, (int) $a->diffInDays(Carbon::parse($hasta)->startOfDay())));
    }

    /** @return array{nombre: string, primer_apellido: string, segundo_apellido: string, sexo: Sexo} */
    public function persona(?Sexo $sexo = null): array
    {
        $sexo ??= $this->si(0.5) ? Sexo::Mujer : Sexo::Hombre;
        $nombre = $this->uno($sexo === Sexo::Mujer ? self::MUJERES : self::HOMBRES);
        if ($this->si(0.3)) {
            $nombre .= ' '.$this->uno($sexo === Sexo::Mujer ? self::MUJERES : self::HOMBRES);
        }

        return [
            'nombre' => $nombre,
            'primer_apellido' => $this->uno(self::APELLIDOS),
            'segundo_apellido' => $this->uno(self::APELLIDOS),
            'sexo' => $sexo,
        ];
    }

    /** Hijo o hija de alguien: comparte el primer apellido. */
    public function familiar(array $de): array
    {
        return ['primer_apellido' => $de['primer_apellido']] + $this->persona();
    }

    public function nombreCompleto(array $p): string
    {
        return trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
    }

    public function domicilio(): string
    {
        return 'Calle '.$this->uno(self::CALLES).' '.$this->entero(1, 240).', Col. '.$this->uno(self::COLONIAS).', Coatepec, Ver.';
    }

    /** Teléfono con lada de Xalapa-Coatepec y prefijo 555 de ficción: no marca a nadie real. */
    public function telefono(): string
    {
        return '228 555 '.str_pad((string) $this->entero(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function causa(Carbon $fecha): string
    {
        $pesos = self::CAUSAS;
        if ($fecha->between('2020-04-01', '2021-12-31')) {
            $pesos['COVID-19, virus identificado'] = 30;
        }

        return $this->ponderado($pesos);
    }

    public function lugarFallecimiento(): string
    {
        return $this->ponderado(['Coatepec, Ver.' => 70, 'Xalapa, Ver.' => 22, 'Xico, Ver.' => 4, 'Teocelo, Ver.' => 2, 'Veracruz, Ver.' => 2]);
    }

    /** Edad al fallecer con una distribución parecida a la de un panteón municipal. */
    public function edad(): int
    {
        return match (true) {
            $this->si(0.04) => $this->entero(0, 14),
            $this->si(0.18) => $this->entero(15, 54),
            default => $this->entero(55, 97),
        };
    }

    public function acta(): string
    {
        return str_pad((string) $this->entero(1, 1800), 5, '0', STR_PAD_LEFT);
    }
}
