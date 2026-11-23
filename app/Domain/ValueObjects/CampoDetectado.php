<?php

namespace App\Domain\ValueObjects;

use App\Domain\Enums\TipoCampo;

/**
 * Un dato importante encontrado en un documento que se convertirá en espacio en blanco.
 */
final class CampoDetectado
{
    public function __construct(
        public readonly string $clave,
        public readonly string $etiqueta,
        public readonly TipoCampo $tipo,
        public readonly string $valor,
        public readonly int $ocurrencias = 1,
        public readonly string $fuente = 'regla',
        public readonly float $confianza = 0.9,
    ) {}

    public function conClave(string $clave, ?string $etiqueta = null): self
    {
        return new self($clave, $etiqueta ?? $this->etiqueta, $this->tipo, $this->valor, $this->ocurrencias, $this->fuente, $this->confianza);
    }

    public function conOcurrencias(int $n): self
    {
        return new self($this->clave, $this->etiqueta, $this->tipo, $this->valor, $n, $this->fuente, $this->confianza);
    }

    public function toArray(): array
    {
        return [
            'clave' => $this->clave,
            'etiqueta' => $this->etiqueta,
            'tipo' => $this->tipo->value,
            'valor' => $this->valor,
            'ocurrencias' => $this->ocurrencias,
            'fuente' => $this->fuente,
            'confianza' => $this->confianza,
        ];
    }

    public static function desdeArray(array $a): self
    {
        return new self(
            (string) $a['clave'],
            (string) ($a['etiqueta'] ?? $a['clave']),
            TipoCampo::desdeTexto($a['tipo'] ?? null),
            (string) $a['valor'],
            (int) ($a['ocurrencias'] ?? 1),
            (string) ($a['fuente'] ?? 'regla'),
            (float) ($a['confianza'] ?? 0.9),
        );
    }
}
