<?php

namespace App\Domain\Contracts\Servicios;

use App\Domain\Exceptions\OllamaException;

/**
 * Contrato para un modelo de lenguaje local (implementado por Ollama).
 */
interface ClienteLlmInterface
{
    /** Estado completo: disponible, versión, modelos instalados, si faltan modelos y mensaje. */
    public function estado(bool $forzar = false): array;

    public function disponible(): bool;

    /**
     * @param  array<int, array{role: string, content: string}>  $mensajes
     * @param  string|null  $formato  null o 'json' para forzar salida JSON
     *
     * @throws OllamaException
     */
    public function chat(array $mensajes, array $opciones = [], ?string $formato = null): string;

    /**
     * Igual que chat() pero exige y decodifica una respuesta JSON.
     *
     * @throws OllamaException
     */
    public function chatJson(array $mensajes, array $opciones = []): array;

    /**
     * @param  string[]  $textos
     * @return array<int, float[]>
     *
     * @throws OllamaException
     */
    public function embeddings(array $textos): array;

    /** @return string[] */
    public function modelosInstalados(): array;

    public function modelo(): string;

    public function modeloEmbeddings(): string;

    public function usarModelo(string $modelo): void;

    /** Tamaño aproximado (en caracteres) de contexto que se puede enviar sin exceder num_ctx. */
    public function caracteresContextoMaximo(): int;
}
