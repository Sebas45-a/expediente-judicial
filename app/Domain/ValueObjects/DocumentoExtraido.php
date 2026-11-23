<?php

namespace App\Domain\ValueObjects;

use App\Domain\Enums\FormatoArchivo;

final class DocumentoExtraido
{
    /**
     * @param  string[]  $parrafos  Párrafos en orden de lectura (incluye celdas de tablas)
     */
    public function __construct(
        public readonly FormatoArchivo $formato,
        public readonly array $parrafos,
        public readonly array $metadata = [],
    ) {}

    public function texto(): string
    {
        return implode("\n", $this->parrafos);
    }

    public function vacio(): bool
    {
        return trim($this->texto()) === '';
    }
}
