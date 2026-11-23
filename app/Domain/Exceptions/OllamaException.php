<?php

namespace App\Domain\Exceptions;

/**
 * Error al comunicarse con Ollama. El mensaje es entendible por el usuario
 * e incluye la acción para resolverlo.
 */
class OllamaException extends ExpedienteException
{
    public const NO_DISPONIBLE = 'no_disponible';
    public const MODELO_NO_ENCONTRADO = 'modelo_no_encontrado';
    public const TIEMPO_AGOTADO = 'tiempo_agotado';
    public const RESPUESTA_INVALIDA = 'respuesta_invalida';
    public const ERROR_SERVIDOR = 'error_servidor';

    public function __construct(string $mensaje, public readonly string $codigo = self::ERROR_SERVIDOR, ?\Throwable $previo = null)
    {
        parent::__construct($mensaje, 0, $previo);
    }

    public static function noDisponible(string $url, ?\Throwable $e = null): self
    {
        return new self(
            "No se pudo conectar con Ollama en {$url}. Verifique que Ollama esté instalado y en ejecución (comando: ollama serve).",
            self::NO_DISPONIBLE,
            $e
        );
    }

    public static function modeloNoEncontrado(string $modelo): self
    {
        return new self(
            "El modelo \"{$modelo}\" no está descargado en Ollama. Ejecute en una terminal: ollama pull {$modelo}",
            self::MODELO_NO_ENCONTRADO
        );
    }

    public static function tiempoAgotado(int $segundos): self
    {
        return new self(
            "Ollama tardó más de {$segundos} segundos en responder. Pruebe con un modelo más ligero o aumente OLLAMA_TIMEOUT en el archivo .env.",
            self::TIEMPO_AGOTADO
        );
    }
}
