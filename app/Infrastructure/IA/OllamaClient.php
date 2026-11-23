<?php

namespace App\Infrastructure\IA;

use App\Domain\Contracts\Repositories\ConfiguracionRepositoryInterface;
use App\Domain\Contracts\Servicios\ClienteLlmInterface;
use App\Domain\Exceptions\OllamaException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP robusto para la API REST de Ollama (/api/chat, /api/embed, /api/tags).
 *
 * - Verifica conexión y modelos instalados (con caché corta).
 * - Reintenta errores de conexión transitorios, pero no los tiempos agotados.
 * - Si el modelo configurado no está descargado usa otro modelo instalado.
 * - Compatible con Ollama nuevo (/api/embed) y antiguo (/api/embeddings).
 * - Elimina bloques <think> de modelos de razonamiento (deepseek-r1, qwen3…).
 * - Traduce cada error a un mensaje claro en español con la solución.
 */
class OllamaClient implements ClienteLlmInterface
{
    private const CACHE_ESTADO = 'ollama.estado';

    private string $url;

    private string $modelo;

    private string $modeloEmbeddings;

    private ?bool $soportaEmbedNuevo = null;

    public function __construct(private readonly ConfiguracionRepositoryInterface $configuracion)
    {
        $this->url = (string) config('ollama.url');
        $this->modelo = (string) $this->configuracion->obtener('ollama.modelo', config('ollama.modelo'));
        $this->modeloEmbeddings = (string) $this->configuracion->obtener('ollama.modelo_embeddings', config('ollama.modelo_embeddings'));
    }

    // ------------------------------------------------------------------ estado

    public function estado(bool $forzar = false): array
    {
        if ($forzar) {
            Cache::forget(self::CACHE_ESTADO);
        }

        return Cache::remember(self::CACHE_ESTADO, now()->addSeconds(20), fn () => $this->consultarEstado());
    }

    private function consultarEstado(): array
    {
        $estado = [
            'disponible' => false,
            'url' => $this->url,
            'version' => null,
            'modelos' => [],
            'modelo' => $this->modelo,
            'modelo_efectivo' => null,
            'modelo_instalado' => false,
            'modelo_embeddings' => $this->modeloEmbeddings,
            'embeddings_instalado' => false,
            'mensaje' => '',
            'advertencias' => [],
        ];

        try {
            $version = $this->http(10)->get('/api/version');
            $tags = $this->http(10)->get('/api/tags');
        } catch (ConnectionException $e) {
            $estado['mensaje'] = OllamaException::noDisponible($this->url)->getMessage();

            return $estado;
        }

        if (! $tags->successful()) {
            $estado['mensaje'] = 'Ollama respondió con error HTTP '.$tags->status().'.';

            return $estado;
        }

        $estado['disponible'] = true;
        $estado['version'] = $version->json('version');
        $estado['modelos'] = collect($tags->json('models', []))
            ->map(fn ($m) => [
                'nombre' => $m['name'] ?? $m['model'] ?? '',
                'tamano' => isset($m['size']) ? round($m['size'] / 1073741824, 2).' GB' : '',
                'familia' => $m['details']['family'] ?? '',
                'parametros' => $m['details']['parameter_size'] ?? '',
                'embeddings' => $this->esModeloEmbeddings($m['name'] ?? ''),
            ])->filter(fn ($m) => $m['nombre'] !== '')->values()->all();

        $nombres = array_column($estado['modelos'], 'nombre');
        $estado['modelo_instalado'] = $this->estaInstalado($this->modelo, $nombres);
        $estado['embeddings_instalado'] = $this->estaInstalado($this->modeloEmbeddings, $nombres);
        $estado['modelo_efectivo'] = $estado['modelo_instalado'] ? $this->modelo : $this->modeloAlternativo($estado['modelos']);

        if (! $estado['modelo_instalado']) {
            if ($estado['modelo_efectivo'] && config('ollama.usar_modelo_alternativo')) {
                $estado['advertencias'][] = "El modelo \"{$this->modelo}\" no está descargado; se usará \"{$estado['modelo_efectivo']}\". Para usar el configurado ejecute: ollama pull {$this->modelo}";
            } else {
                $estado['disponible'] = false;
                $estado['mensaje'] = OllamaException::modeloNoEncontrado($this->modelo)->getMessage();
            }
        }
        if (! $estado['embeddings_instalado']) {
            $estado['advertencias'][] = "Búsqueda semántica desactivada: falta el modelo \"{$this->modeloEmbeddings}\" (ollama pull {$this->modeloEmbeddings}). Se usará búsqueda por palabras clave.";
        }
        if ($estado['disponible'] && $estado['mensaje'] === '') {
            $estado['mensaje'] = 'IA local lista con el modelo '.$estado['modelo_efectivo'].'.';
        }

        return $estado;
    }

    public function disponible(): bool
    {
        return (bool) ($this->estado()['disponible'] ?? false);
    }

    public function modelosInstalados(): array
    {
        return array_column($this->estado()['modelos'] ?? [], 'nombre');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    public function modeloEmbeddings(): string
    {
        return $this->modeloEmbeddings;
    }

    public function usarModelo(string $modelo): void
    {
        $this->modelo = $modelo;
        Cache::forget(self::CACHE_ESTADO);
    }

    public function caracteresContextoMaximo(): int
    {
        $ctx = (int) config('ollama.opciones.num_ctx', 8192);
        $prediccion = (int) config('ollama.opciones.num_predict', 1024);

        // ~3 caracteres por token en español; se reserva espacio para instrucciones y respuesta
        return max(2000, ($ctx - $prediccion - 600) * 3);
    }

    // ------------------------------------------------------------------ chat

    public function chat(array $mensajes, array $opciones = [], ?string $formato = null): string
    {
        $estado = $this->estado();
        if (! $estado['disponible']) {
            throw new OllamaException($estado['mensaje'] ?: OllamaException::noDisponible($this->url)->getMessage(), OllamaException::NO_DISPONIBLE);
        }
        $modelo = $estado['modelo_efectivo'] ?: $this->modelo;

        $payload = [
            'model' => $modelo,
            'messages' => array_values($mensajes),
            'stream' => false,
            'keep_alive' => config('ollama.keep_alive'),
            'options' => array_merge(config('ollama.opciones', []), $opciones),
        ];
        if ($formato === 'json') {
            $payload['format'] = 'json';
        }

        $respuesta = $this->enviar('/api/chat', $payload, (int) config('ollama.timeout'), $modelo);
        $contenido = (string) ($respuesta->json('message.content') ?? '');
        $contenido = $this->limpiarRazonamiento($contenido);

        if (trim($contenido) === '') {
            throw new OllamaException('El modelo devolvió una respuesta vacía. Intente nuevamente o reformule la pregunta.', OllamaException::RESPUESTA_INVALIDA);
        }

        return trim($contenido);
    }

    /** Chat que devuelve un arreglo decodificado de JSON (tolera texto alrededor del JSON). */
    public function chatJson(array $mensajes, array $opciones = []): array
    {
        $texto = $this->chat($mensajes, $opciones + ['temperature' => 0], 'json');

        return self::decodificarJson($texto);
    }

    public static function decodificarJson(string $texto): array
    {
        $texto = trim(preg_replace('/^```(?:json)?|```$/m', '', $texto) ?? $texto);
        $datos = json_decode($texto, true);
        if (is_array($datos)) {
            return $datos;
        }
        if (preg_match('/\{.*\}|\[.*\]/s', $texto, $m)) {
            $datos = json_decode($m[0], true);
            if (is_array($datos)) {
                return $datos;
            }
        }
        throw new OllamaException('La IA no devolvió un JSON válido.', OllamaException::RESPUESTA_INVALIDA);
    }

    // ------------------------------------------------------------------ embeddings

    public function embeddings(array $textos): array
    {
        $textos = array_values(array_map(fn ($t) => mb_substr((string) $t, 0, 6000), $textos));
        if (! $textos) {
            return [];
        }
        $estado = $this->estado();
        if (! ($estado['embeddings_instalado'] ?? false)) {
            throw OllamaException::modeloNoEncontrado($this->modeloEmbeddings);
        }

        $resultado = [];
        foreach (array_chunk($textos, 16) as $lote) {
            if ($this->soportaEmbedNuevo !== false) {
                try {
                    $r = $this->enviar('/api/embed', ['model' => $this->modeloEmbeddings, 'input' => $lote, 'keep_alive' => config('ollama.keep_alive')], 120, $this->modeloEmbeddings);
                    $vectores = $r->json('embeddings', []);
                    if (count($vectores) === count($lote)) {
                        $this->soportaEmbedNuevo = true;
                        array_push($resultado, ...$vectores);

                        continue;
                    }
                } catch (OllamaException $e) {
                    if ($e->codigo !== 'endpoint_no_existe') {
                        throw $e;
                    }
                }
                $this->soportaEmbedNuevo = false;
            }
            // Ollama < 0.3: un texto por petición
            foreach ($lote as $t) {
                $r = $this->enviar('/api/embeddings', ['model' => $this->modeloEmbeddings, 'prompt' => $t], 120, $this->modeloEmbeddings);
                $resultado[] = $r->json('embedding', []);
            }
        }

        return $resultado;
    }

    // ------------------------------------------------------------------ HTTP

    private function http(int $timeout): PendingRequest
    {
        return Http::baseUrl($this->url)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('ollama.timeout_conexion', 5))
            ->timeout($timeout);
    }

    private function enviar(string $ruta, array $payload, int $timeout, string $modelo): Response
    {
        $intentos = max(1, (int) config('ollama.reintentos', 2) + 1);
        $ultimo = null;

        for ($i = 1; $i <= $intentos; $i++) {
            try {
                $r = $this->http($timeout)->post($ruta, $payload);
            } catch (ConnectionException $e) {
                $ultimo = $e;
                $msg = $e->getMessage();
                if (str_contains($msg, 'cURL error 28') || stripos($msg, 'timed out') !== false) {
                    Log::warning('Ollama: tiempo agotado', ['ruta' => $ruta, 'modelo' => $modelo]);
                    throw OllamaException::tiempoAgotado($timeout);
                }
                Log::warning("Ollama: error de conexión (intento {$i}/{$intentos})", ['error' => $msg]);
                Cache::forget(self::CACHE_ESTADO);
                if ($i < $intentos) {
                    usleep(400_000 * $i);

                    continue;
                }
                throw OllamaException::noDisponible($this->url, $e);
            }

            if ($r->successful()) {
                return $r;
            }

            $error = (string) ($r->json('error') ?? $r->body());
            if ($r->status() === 404) {
                if (stripos($error, 'model') !== false && stripos($error, 'not found') !== false) {
                    Cache::forget(self::CACHE_ESTADO);
                    throw OllamaException::modeloNoEncontrado($modelo);
                }
                throw new OllamaException("La ruta {$ruta} no existe en esta versión de Ollama.", 'endpoint_no_existe');
            }
            if ($r->status() === 400 && stripos($error, 'does not support') !== false) {
                throw new OllamaException("El modelo \"{$modelo}\" no soporta esta operación ({$error}).", OllamaException::ERROR_SERVIDOR);
            }
            // 500/503: el modelo se está cargando o falta memoria → reintentar
            if ($r->serverError() && $i < $intentos) {
                Log::warning("Ollama: error {$r->status()} (intento {$i}/{$intentos})", ['error' => $error]);
                usleep(800_000 * $i);

                continue;
            }
            if (stripos($error, 'memory') !== false) {
                throw new OllamaException("Memoria insuficiente para el modelo \"{$modelo}\". Use un modelo más pequeño (por ejemplo llama3.2:1b o qwen2.5:1.5b).", OllamaException::ERROR_SERVIDOR);
            }
            throw new OllamaException('Ollama devolvió un error ('.$r->status().'): '.mb_substr($error, 0, 300), OllamaException::ERROR_SERVIDOR);
        }

        throw OllamaException::noDisponible($this->url, $ultimo);
    }

    // ------------------------------------------------------------------ utilidades

    private function limpiarRazonamiento(string $texto): string
    {
        $texto = preg_replace('/<think>.*?<\/think>/is', '', $texto) ?? $texto;

        return preg_replace('/^.*?<\/think>/is', '', $texto) ?? $texto;
    }

    private function esModeloEmbeddings(string $nombre): bool
    {
        return (bool) preg_match('/embed|bge|minilm|e5-|gte-|nomic|mxbai|arctic-embed|paraphrase/i', $nombre);
    }

    public static function normalizarNombre(string $nombre): string
    {
        $nombre = strtolower(trim($nombre));

        return str_contains($nombre, ':') ? $nombre : $nombre.':latest';
    }

    private function estaInstalado(string $modelo, array $instalados): bool
    {
        $buscado = self::normalizarNombre($modelo);
        foreach ($instalados as $n) {
            if (self::normalizarNombre($n) === $buscado) {
                return true;
            }
        }

        return false;
    }

    private function modeloAlternativo(array $modelos): ?string
    {
        $chat = array_values(array_filter($modelos, fn ($m) => ! $m['embeddings']));
        if (! $chat) {
            return null;
        }
        foreach (['llama3', 'qwen', 'mistral', 'gemma', 'phi', 'deepseek'] as $preferido) {
            foreach ($chat as $m) {
                if (str_contains(strtolower($m['nombre']), $preferido)) {
                    return $m['nombre'];
                }
            }
        }

        return $chat[0]['nombre'];
    }
}
