<?php

namespace App\Domain\Contracts\Servicios;

use App\Domain\Exceptions\DocumentoNoSoportadoException;
use App\Domain\ValueObjects\DocumentoExtraido;

interface ExtractorDocumentoInterface
{
    public function soporta(string $nombreArchivo): bool;

    /** @throws DocumentoNoSoportadoException */
    public function extraer(string $rutaAbsoluta, ?string $nombreOriginal = null): DocumentoExtraido;
}
