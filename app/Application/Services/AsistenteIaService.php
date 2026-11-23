<?php

namespace App\Application\Services;

use App\Domain\Contracts\Repositories\ConfiguracionRepositoryInterface;
use App\Domain\Contracts\Repositories\ConsultaIaRepositoryInterface;
use App\Domain\Contracts\Repositories\ExpedienteRepositoryInterface;
use App\Domain\Contracts\Repositories\FragmentoRepositoryInterface;
use App\Domain\Contracts\Servicios\ClienteLlmInterface;
use App\Domain\Exceptions\ExpedienteException;
use App\Domain\Exceptions\OllamaException;
use App\Domain\Services\FragmentadorTexto;
use App\Domain\Services\NormalizadorTexto;
use App\Domain\ValueObjects\RespuestaIa;
use App\Infrastructure\Persistence\Models\Expediente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Asistente jurídico local: responde SOLO con base en el contenido de los expedientes (RAG).
 */
class AsistenteIaService
{
    private const SISTEMA = <<<'TXT'
Eres un asistente jurídico del Organismo/Poder Judicial. Respondes en español, con lenguaje formal y claro.
Reglas obligatorias:
1. Usa ÚNICAMENTE la información del CONTEXTO proporcionado. No uses conocimiento externo para hechos del caso.
2. Si el dato no aparece en el contexto, responde exactamente: "No consta en el expediente." y sugiere qué documento revisar.
3. No inventes nombres, fechas, números ni artículos de ley.
4. Cuando cites un dato, indica de qué expediente o fragmento proviene (por ejemplo: [Exp. 01043-2024-00321]).
5. Sé conciso: usa viñetas cuando enumeres varios elementos.
TXT;

    public function __construct(
        private readonly ClienteLlmInterface $llm,
        private readonly ExpedienteRepositoryInterface $expedientes,
        private readonly FragmentoRepositoryInterface $fragmentos,
        private readonly ConsultaIaRepositoryInterface $consultas,
        private readonly ConfiguracionRepositoryInterface $config,
        private readonly FragmentadorTexto $fragmentador,
    ) {}

    public function estado(bool $forzar = false): array
    {
        return $this->llm->estado($forzar) + [
            'fragmentos_indexados' => $this->fragmentos->total(),
            'consultas' => $this->consultas->estadisticas(),
        ];
    }

    public function cambiarModelo(string $modelo): void
    {
        $instalados = array_map(fn ($m) => strtolower($m), $this->llm->modelosInstalados());
        $buscado = strtolower($modelo);
        if (! in_array($buscado, $instalados, true) && ! in_array($buscado.':latest', $instalados, true)) {
            throw new ExpedienteException("El modelo {$modelo} no está instalado en Ollama. Ejecute: ollama pull {$modelo}");
        }
        $this->config->guardar('ollama.modelo', $modelo);
        $this->llm->usarModelo($modelo);
    }

    /** Prueba rápida de extremo a extremo. */
    public function probar(): RespuestaIa
    {
        $inicio = microtime(true);
        try {
            $texto = $this->llm->chat([
                ['role' => 'user', 'content' => 'Responde solamente con la palabra: FUNCIONANDO'],
            ], ['num_predict' => 20, 'temperature' => 0]);

            return new RespuestaIa(true, $texto, $this->llm->estado()['modelo_efectivo'] ?? $this->llm->modelo(), (int) ((microtime(true) - $inicio) * 1000));
        } catch (OllamaException $e) {
            return RespuestaIa::fallo($e->getMessage(), $this->llm->modelo());
        }
    }

    // ------------------------------------------------------------------ indexación

    /**
     * Fragmenta el expediente y calcula embeddings (si el modelo de embeddings está instalado).
     *
     * @return array{fragmentos:int, semantico:bool}
     */
    public function indexar(Expediente $expediente): array
    {
        $texto = $expediente->ficha()."\n\n".($expediente->contenido_texto ?? '');
        $piezas = $this->fragmentador->fragmentar($texto);
        $vectores = [];
        $semantico = false;

        if ($piezas && ($this->llm->estado()['embeddings_instalado'] ?? false)) {
            try {
                // se antepone el número de expediente para que cada fragmento conserve su contexto
                $prefijo = 'Expediente '.($expediente->numero_expediente ?: $expediente->id).': ';
                $vectores = $this->llm->embeddings(array_map(fn ($p) => $prefijo.$p, $piezas));
                $semantico = count($vectores) === count($piezas);
            } catch (OllamaException $e) {
                Log::warning('No se generaron embeddings: '.$e->getMessage());
                $vectores = [];
            }
        }

        $this->fragmentos->reemplazar($expediente->id, array_map(fn ($p, $i) => [
            'contenido' => $p,
            'embedding' => $semantico ? ($vectores[$i] ?? null) : null,
        ], $piezas, array_keys($piezas)), $semantico ? $this->llm->modeloEmbeddings() : null);

        $this->expedientes->actualizar($expediente, ['indexado_en' => now()]);

        return ['fragmentos' => count($piezas), 'semantico' => $semantico];
    }

    private function necesitaIndexar(Expediente $e): bool
    {
        return $e->indexado_en === null || $e->updated_at?->gt($e->indexado_en->copy()->addSeconds(2));
    }

    // ------------------------------------------------------------------ preguntas

    public function preguntar(string $pregunta, ?int $expedienteId = null): RespuestaIa
    {
        $pregunta = trim($pregunta);
        if ($pregunta === '') {
            return RespuestaIa::fallo('Escriba una pregunta.');
        }
        $inicio = microtime(true);
        $expediente = $expedienteId ? $this->expedientes->obtener($expedienteId) : null;

        try {
            [$contexto, $usados] = $this->construirContexto($pregunta, $expediente);
            if (trim($contexto) === '') {
                throw new ExpedienteException('No hay expedientes con contenido para consultar. Registre o importe expedientes primero.');
            }

            $alcance = $expediente
                ? 'Expediente consultado: '.($expediente->numero_expediente ?: "ID {$expediente->id}").' — '.$expediente->titulo
                : 'Consulta general sobre todos los expedientes registrados.';

            $texto = $this->llm->chat([
                ['role' => 'system', 'content' => self::SISTEMA],
                ['role' => 'user', 'content' => "{$alcance}\n\n=== CONTEXTO ===\n{$contexto}\n=== FIN DEL CONTEXTO ===\n\nPregunta: {$pregunta}"],
            ]);

            $respuesta = new RespuestaIa(true, $texto, $this->llm->estado()['modelo_efectivo'] ?? $this->llm->modelo(), (int) ((microtime(true) - $inicio) * 1000), $usados);
        } catch (ExpedienteException $e) {
            $respuesta = RespuestaIa::fallo($e->getMessage(), $this->llm->modelo());
        } catch (\Throwable $e) {
            report($e);
            $respuesta = RespuestaIa::fallo('Ocurrió un error inesperado al consultar la IA. Revise storage/logs/laravel.log.', $this->llm->modelo());
        }

        $this->consultas->registrar([
            'expediente_id' => $expediente?->id,
            'pregunta' => $pregunta,
            'respuesta' => $respuesta->exito ? $respuesta->texto : null,
            'modelo' => $respuesta->modelo,
            'duracion_ms' => $respuesta->duracionMs,
            'fragmentos_usados' => $respuesta->fragmentos,
            'exito' => $respuesta->exito,
            'error' => $respuesta->error,
        ]);

        return $respuesta;
    }

    public function resumir(int $expedienteId): RespuestaIa
    {
        return $this->preguntar(
            'Elabora un resumen ejecutivo del expediente con: 1) partes procesales, 2) tipo de proceso y juzgado, '
            .'3) hechos principales, 4) pretensiones o peticiones, 5) estado actual y próximas actuaciones, 6) fechas clave.',
            $expedienteId
        );
    }

    public function historial(?int $expedienteId = null): Collection
    {
        return $this->consultas->historial($expedienteId);
    }

    /**
     * @return array{0:string, 1:array} contexto y referencias usadas
     */
    private function construirContexto(string $pregunta, ?Expediente $expediente): array
    {
        $max = $this->llm->caracteresContextoMaximo();

        // Expediente pequeño: se envía completo (más preciso que fragmentos)
        if ($expediente) {
            $completo = $expediente->ficha()."\n\n".($expediente->contenido_texto ?? '');
            if (mb_strlen($completo) <= $max) {
                return [$completo, [['expediente_id' => $expediente->id, 'fragmento' => 'completo']]];
            }
            if ($this->necesitaIndexar($expediente)) {
                $this->indexar($expediente);
            }
        } else {
            $this->indexarPendientes(50);
        }

        $candidatos = $this->fragmentos->deExpediente($expediente?->id);
        if ($candidatos->isEmpty()) {
            return [$expediente ? mb_substr($expediente->ficha(), 0, $max) : '', []];
        }

        $ordenados = $this->rankear($pregunta, $candidatos);
        $k = (int) config('ollama.fragmentos_contexto', 6);
        $numeros = [];
        $partes = [];
        $usados = [];
        $largo = 0;

        if ($expediente) {
            $ficha = $expediente->ficha();
            $partes[] = "[Ficha del expediente]\n{$ficha}";
            $largo += mb_strlen($ficha);
        }
        foreach ($ordenados as $f) {
            if (count($usados) >= $k) {
                break;
            }
            if (! isset($numeros[$f->expediente_id])) {
                $numeros[$f->expediente_id] = $this->expedientes->buscar($f->expediente_id)?->numero_expediente ?: "ID {$f->expediente_id}";
            }
            $bloque = "[Exp. {$numeros[$f->expediente_id]} · fragmento ".($f->orden + 1)."]\n".$f->contenido;
            if ($largo + mb_strlen($bloque) > $max) {
                break;
            }
            $partes[] = $bloque;
            $largo += mb_strlen($bloque);
            $usados[] = ['expediente_id' => $f->expediente_id, 'fragmento' => $f->orden + 1, 'puntaje' => round($f->puntaje, 3)];
        }

        return [implode("\n\n---\n\n", $partes), $usados];
    }

    private function indexarPendientes(int $limite): void
    {
        foreach ($this->expedientes->pendientesDeIndexar($limite) as $e) {
            $this->indexar($e);
        }
    }

    /** Ordena por similitud coseno (semántica) o, si no hay embeddings, por coincidencia de palabras (BM25 simplificado). */
    private function rankear(string $pregunta, Collection $candidatos): Collection
    {
        $modeloEmb = $this->llm->modeloEmbeddings();
        $conVector = $candidatos->filter(fn ($f) => is_array($f->embedding) && $f->modelo_embedding === $modeloEmb);

        if ($conVector->count() > 0 && ($this->llm->estado()['embeddings_instalado'] ?? false)) {
            try {
                $q = $this->llm->embeddings([$pregunta])[0] ?? null;
                if ($q) {
                    $tokensPregunta = array_unique(NormalizadorTexto::tokens($pregunta));

                    return $candidatos->map(function ($f) use ($q, $tokensPregunta) {
                        $sem = is_array($f->embedding) ? self::coseno($q, $f->embedding) : 0;
                        $f->puntaje = $sem * 0.8 + self::coincidencia($tokensPregunta, $f->contenido) * 0.2;

                        return $f;
                    })->sortByDesc('puntaje')->values();
                }
            } catch (OllamaException $e) {
                Log::info('Búsqueda semántica no disponible, se usa búsqueda por palabras: '.$e->getMessage());
            }
        }

        $tokens = array_unique(NormalizadorTexto::tokens($pregunta));

        return $candidatos->map(function ($f) use ($tokens) {
            $f->puntaje = self::coincidencia($tokens, $f->contenido);

            return $f;
        })->sortBy([['puntaje', 'desc'], ['orden', 'asc']])->values();
    }

    public static function coseno(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = $na = $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }

        return $na > 0 && $nb > 0 ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    public static function coincidencia(array $tokensPregunta, string $contenido): float
    {
        if (! $tokensPregunta) {
            return 0.0;
        }
        $frec = array_count_values(NormalizadorTexto::tokens($contenido));
        $largo = max(1, array_sum($frec));
        $puntaje = 0.0;
        foreach ($tokensPregunta as $t) {
            $tf = $frec[$t] ?? 0;
            if ($tf > 0) {
                $puntaje += ($tf * 2.2) / ($tf + 1.2 * (0.25 + 0.75 * $largo / 150));
            }
        }

        return $puntaje / count($tokensPregunta);
    }
}
