<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Lo que una persona puede hacer en el sistema. Cada permiso corresponde a un paso del código,
 * por eso la lista es fija; qué roles lo tienen se configura en la interfaz.
 * Etiquetas y descripciones en lenguaje llano: se muestran como casillas al editar un rol.
 */
enum Permiso: string implements HasDescription, HasLabel
{
    // Trámites
    case TramitesVer = 'tramites.ver';
    case TramitesCapturar = 'tramites.capturar';
    case TramitesEmitirDocumentos = 'tramites.emitir_documentos';
    case TramitesPublicar = 'tramites.publicar';
    case TramitesRegistrarPagos = 'tramites.registrar_pagos';
    case TramitesRechazar = 'tramites.rechazar';
    case AutorizarAdquisicion = 'tramites.autorizar.adquisicion';
    case AutorizarRegularizacion = 'tramites.autorizar.regularizacion';
    case AutorizarInhumacion = 'tramites.autorizar.inhumacion';
    case AutorizarExhumacion = 'tramites.autorizar.exhumacion';
    case AutorizarConstruccion = 'tramites.autorizar.construccion';

    // Registro de finados
    case InhumadosVerCausa = 'inhumados.ver_causa';
    case InhumadosCorregir = 'inhumados.corregir';

    // Configuración
    case CatalogosInventario = 'catalogos.inventario';
    case CatalogosTabulador = 'catalogos.tabulador';
    case CatalogosReglas = 'catalogos.reglas';
    case CatalogosFirmantes = 'catalogos.firmantes';

    // Consulta y administración
    case ReportesVer = 'reportes.ver';
    case BitacoraVer = 'bitacora.ver';
    case AdministrarUsuarios = 'administracion.usuarios';

    public function getLabel(): string
    {
        return match ($this) {
            self::TramitesVer => 'Consultar trámites',
            self::TramitesCapturar => 'Capturar trámites y armar el expediente',
            self::TramitesEmitirDocumentos => 'Emitir responsivas y cartas compromiso',
            self::TramitesPublicar => 'Registrar la publicación en estrados',
            self::TramitesRegistrarPagos => 'Registrar recibos de Tesorería',
            self::TramitesRechazar => 'Rechazar trámites',
            self::AutorizarAdquisicion => 'Autorizar adquisiciones de título',
            self::AutorizarRegularizacion => 'Autorizar cambios de propietario',
            self::AutorizarInhumacion => 'Autorizar inhumaciones',
            self::AutorizarExhumacion => 'Autorizar exhumaciones',
            self::AutorizarConstruccion => 'Autorizar permisos de construcción',
            self::InhumadosVerCausa => 'Ver la causa de fallecimiento',
            self::InhumadosCorregir => 'Corregir datos de finados',
            self::CatalogosInventario => 'Administrar panteones, secciones, lotes y espacios',
            self::CatalogosTabulador => 'Capturar las cuotas del tabulador',
            self::CatalogosReglas => 'Editar plazos, requisitos y parámetros',
            self::CatalogosFirmantes => 'Registrar a quién firma cada documento',
            self::ReportesVer => 'Ver reportes',
            self::BitacoraVer => 'Ver la bitácora de cambios',
            self::AdministrarUsuarios => 'Administrar usuarios y roles',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::TramitesVer => 'Ver trámites, títulos y finados, sin cambiar nada.',
            self::TramitesCapturar => 'Recibir solicitudes, marcar documentos entregados, registrar testigos, comparecencias, cotejo y fotos.',
            self::TramitesEmitirDocumentos => 'Firmar y subir la responsiva del Encargado y la carta compromiso de obra.',
            self::TramitesPublicar => 'Registrar que la notificación de una adquisición se colocó en estrados y en el lote (art. 34).',
            self::TramitesRegistrarPagos => 'Capturar el folio del recibo oficial que entrega Tesorería.',
            self::TramitesRechazar => 'Cerrar un trámite que no procede, escribiendo el motivo.',
            self::AutorizarAdquisicion => 'Concluir la adquisición y emitir el título (arts. 18 y 24).',
            self::AutorizarRegularizacion => 'Concluir el cambio de propietario y emitir el título nuevo (arts. 18 y 27).',
            self::AutorizarInhumacion => 'Concluir la inhumación (art. 21, fr. VI).',
            self::AutorizarExhumacion => 'Concluir la exhumación (art. 21, fr. VI).',
            self::AutorizarConstruccion => 'Concluir el permiso de construcción o remodelación (art. 59).',
            self::InhumadosVerCausa => 'Es un dato personal sensible. Dalo sólo a quien lo necesita para su trabajo.',
            self::InhumadosCorregir => 'Por ejemplo, capturar la edad al fallecer que falta en un registro antiguo.',
            self::CatalogosInventario => 'Dar de alta y corregir panteones, secciones, lotes y espacios.',
            self::CatalogosTabulador => 'Registrar los importes que aprueba el Cabildo cada año (art. 69).',
            self::CatalogosReglas => 'Cambiar plazos de exhumación, documentos que se piden y parámetros del reglamento.',
            self::CatalogosFirmantes => 'Registrar quién ocupa cada cargo, para que aparezca en los documentos.',
            self::ReportesVer => 'Ver y descargar libros, informes y estadísticas.',
            self::BitacoraVer => 'Ver quién cambió qué y cuándo.',
            self::AdministrarUsuarios => 'Crear usuarios, asignarles roles y definir qué puede hacer cada rol.',
        };
    }

    public function grupo(): string
    {
        return match (true) {
            str_starts_with($this->value, 'tramites.autorizar.') => 'Autorizaciones',
            str_starts_with($this->value, 'tramites.') => 'Trámites',
            str_starts_with($this->value, 'inhumados.') => 'Registro de finados',
            str_starts_with($this->value, 'catalogos.') => 'Configuración',
            default => 'Consulta y administración',
        };
    }

    public static function autorizar(TipoTramite $tipo): self
    {
        return match ($tipo) {
            TipoTramite::Adquisicion => self::AutorizarAdquisicion,
            TipoTramite::Regularizacion => self::AutorizarRegularizacion,
            TipoTramite::Inhumacion => self::AutorizarInhumacion,
            TipoTramite::Exhumacion => self::AutorizarExhumacion,
            TipoTramite::Construccion => self::AutorizarConstruccion,
        };
    }
}
