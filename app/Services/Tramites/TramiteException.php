<?php

namespace App\Services\Tramites;

use App\Support\AccionBloqueada;

/** Un trámite no puede avanzar. Hereda de AccionBloqueada: motivos en lenguaje llano. */
final class TramiteException extends AccionBloqueada {}
