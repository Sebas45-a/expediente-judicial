<?php

namespace App\Domain\ValueObjects;

final class ResultadoClasificacion
{
    /**
     * @param  array<int, float>  $alternativas  tipoId => probabilidad (0-1)
     */
    public function __construct(
        public readonly ?int $tipoId,
        public readonly float $confianza,
        public readonly string $metodo,
        public readonly array $alternativas = [],
    ) {}

    public static function sinResultado(): self
    {
        return new self(null, 0.0, 'ninguno');
    }

    public function esConfiable(float $umbral): bool
    {
        return $this->tipoId !== null && $this->confianza >= $umbral;
    }

    public function porcentaje(): float
    {
        return round($this->confianza * 100, 1);
    }
}
