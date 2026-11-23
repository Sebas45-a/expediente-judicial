<?php

namespace App\Domain\ValueObjects;

final class RespuestaIa
{
    public function __construct(
        public readonly bool $exito,
        public readonly string $texto,
        public readonly ?string $modelo = null,
        public readonly int $duracionMs = 0,
        public readonly array $fragmentos = [],
        public readonly ?string $error = null,
    ) {}

    public static function fallo(string $error, ?string $modelo = null): self
    {
        return new self(false, '', $modelo, 0, [], $error);
    }
}
