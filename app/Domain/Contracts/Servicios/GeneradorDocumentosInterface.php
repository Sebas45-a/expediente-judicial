<?php

namespace App\Domain\Contracts\Servicios;

use App\Domain\ValueObjects\CampoDetectado;
use App\Domain\ValueObjects\DocumentoExtraido;

/**
 * Construye plantillas DOCX (conservando el formato original cuando es posible)
 * y genera documentos rellenos o en blanco.
 */
interface GeneradorDocumentosInterface
{
    /**
     * @param  CampoDetectado[]  $campos
     */
    public function construirPlantilla(string $rutaOriginal, DocumentoExtraido $documento, array $campos, string $rutaDestino): void;

    /**
     * @param  array<string, string>  $valores  clave => valor (vacío = espacio en blanco)
     */
    public function generar(string $rutaPlantilla, array $valores, string $rutaDestino): void;
}
