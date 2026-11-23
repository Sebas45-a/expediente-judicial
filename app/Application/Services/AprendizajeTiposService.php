<?php

namespace App\Application\Services;

use App\Domain\Contracts\Repositories\ConfiguracionRepositoryInterface;
use App\Domain\Contracts\Repositories\ExpedienteRepositoryInterface;
use App\Domain\Contracts\Repositories\TipoExpedienteRepositoryInterface;
use App\Domain\Contracts\Servicios\ClienteLlmInterface;
use App\Domain\Exceptions\OllamaException;
use App\Domain\Services\ClasificadorNaiveBayes;
use App\Domain\Services\NormalizadorTexto;
use App\Domain\ValueObjects\ResultadoClasificacion;
use App\Infrastructure\Persistence\Models\Expediente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Aprende cada tipo de expediente a partir de los ejemplos (manuales, Excel o documentos)
 * y clasifica automáticamente los nuevos. Si el aprendizaje no está seguro, puede consultar a Ollama.
 */
class AprendizajeTiposService
{
    private const CLAVE_MODELO = 'clasificador.modelo';

    private const CLAVE_DESACTUALIZADO = 'clasificador.desactualizado';

    public function __construct(
        private readonly ExpedienteRepositoryInterface $expedientes,
        private readonly TipoExpedienteRepositoryInterface $tipos,
        private readonly ConfiguracionRepositoryInterface $config,
        private readonly ClienteLlmInterface $llm,
        private readonly ClasificadorNaiveBayes $clasificador,
    ) {}

    public function marcarDesactualizado(): void
    {
        $this->config->guardar(self::CLAVE_DESACTUALIZADO, true);
    }

    /**
     * Reentrena el modelo con todos los expedientes clasificados y actualiza el perfil de cada tipo.
     */
    public function entrenar(): array
    {
        $registros = $this->expedientes->paraEntrenamiento();
        $muestras = $registros->map(fn (Expediente $e) => [(int) $e->tipo_expediente_id, $e->textoParaAnalisis()])->values()->all();

        $modelo = $this->clasificador->entrenar($muestras);
        $precision = $this->clasificador->evaluar($muestras);
        $this->config->guardar(self::CLAVE_MODELO, $modelo);
        $this->config->guardar(self::CLAVE_DESACTUALIZADO, false);

        $porTipo = $registros->groupBy('tipo_expediente_id');
        foreach ($this->tipos->todos() as $tipo) {
            /** @var Collection $grupo */
            $grupo = $porTipo->get($tipo->id, collect());
            $info = $modelo['tipos'][$tipo->id] ?? null;
            $this->tipos->actualizar($tipo, [
                'total_ejemplos' => $grupo->count(),
                'palabras_clave' => $info ? $this->clasificador->palabrasClave($modelo, $tipo->id, 15) : [],
                'perfil' => $info ? [
                    'documentos' => $info['docs'],
                    'terminos' => $info['tokens'],
                    'frecuentes' => array_slice($info['frec'], 0, 25, true),
                ] : null,
                'campos_frecuentes' => $this->camposFrecuentes($grupo),
                'precision_entrenamiento' => $precision,
                'entrenado_en' => now(),
            ]);
        }

        return [
            'muestras' => count($muestras),
            'tipos' => count($modelo['tipos']),
            'vocabulario' => $modelo['vocab'],
            'precision' => $precision,
        ];
    }

    /** Qué datos suele tener cada tipo (columnas de Excel, juzgados, estados). */
    private function camposFrecuentes(Collection $grupo): array
    {
        if ($grupo->isEmpty()) {
            return [];
        }
        $n = $grupo->count();
        $columnas = [];
        foreach ($grupo as $e) {
            foreach (array_keys(array_filter((array) $e->datos_extra, fn ($v) => $v !== null && $v !== '')) as $k) {
                $columnas[$k] = ($columnas[$k] ?? 0) + 1;
            }
        }
        arsort($columnas);

        return [
            'columnas' => array_map(fn ($c) => round($c / $n * 100), array_slice($columnas, 0, 12, true)),
            'juzgados' => $grupo->pluck('juzgado')->filter()->countBy()->sortDesc()->take(5)->all(),
            'estados' => $grupo->map(fn ($e) => $e->estado?->etiqueta())->filter()->countBy()->sortDesc()->all(),
        ];
    }

    private function modelo(): array
    {
        $modelo = $this->config->obtener(self::CLAVE_MODELO);
        if (! $modelo || $this->config->obtener(self::CLAVE_DESACTUALIZADO, true)) {
            $this->entrenar();
            $modelo = $this->config->obtener(self::CLAVE_MODELO);
        }
        // json_decode convierte claves numéricas en int, pero se asegura
        $modelo['tipos'] = collect($modelo['tipos'] ?? [])->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all();

        return $modelo;
    }

    public function clasificar(string $texto, bool $usarIa = false): ResultadoClasificacion
    {
        $resultado = $this->clasificador->clasificar($this->modelo(), $texto);
        $umbral = (float) config('ollama.umbral_clasificacion', 0.6);

        if (! $resultado->esConfiable($umbral) && $usarIa) {
            $ia = $this->clasificarConIa($texto);
            if ($ia && $ia->confianza >= $resultado->confianza) {
                return $ia;
            }
        }

        return $resultado;
    }

    /** Pide a Ollama elegir entre los tipos existentes. Nunca inventa tipos nuevos. */
    public function clasificarConIa(string $texto): ?ResultadoClasificacion
    {
        $tipos = $this->tipos->todos();
        if ($tipos->isEmpty() || ! $this->llm->disponible()) {
            return null;
        }
        $lista = $tipos->map(fn ($t) => '- '.$t->nombre.($t->descripcion ? " ({$t->descripcion})" : ''))->implode("\n");
        try {
            $json = $this->llm->chatJson([
                ['role' => 'system', 'content' => 'Eres un clasificador de expedientes judiciales. Responde solo con JSON.'],
                ['role' => 'user', 'content' => "Clasifica el expediente en UNO de estos tipos (usa el nombre exacto):\n{$lista}\n\n"
                    ."Expediente:\n\"\"\"\n".NormalizadorTexto::recortar($texto, 3500)."\n\"\"\"\n\n"
                    .'Responde con este JSON: {"tipo": "nombre exacto del tipo", "confianza": número entre 0 y 1}'],
            ], ['num_predict' => 120]);
        } catch (OllamaException $e) {
            Log::info('Clasificación IA no disponible: '.$e->getMessage());

            return null;
        }

        $nombre = NormalizadorTexto::normalizar((string) ($json['tipo'] ?? ''));
        $tipo = $tipos->first(fn ($t) => NormalizadorTexto::normalizar($t->nombre) === $nombre)
            ?? $tipos->first(fn ($t) => $nombre !== '' && (str_contains(NormalizadorTexto::normalizar($t->nombre), $nombre) || str_contains($nombre, NormalizadorTexto::normalizar($t->nombre))));
        if (! $tipo) {
            return null;
        }
        $confianza = max(0.0, min(1.0, (float) ($json['confianza'] ?? 0.6)));

        return new ResultadoClasificacion($tipo->id, $confianza, 'ia');
    }

    /**
     * Clasifica los expedientes sin tipo. Solo asigna cuando la confianza supera el umbral.
     */
    public function reclasificarPendientes(bool $usarIa = false, ?int $importacionId = null): array
    {
        $pendientes = $this->expedientes->sinClasificar();
        if ($importacionId) {
            $pendientes = $pendientes->where('importacion_excel_id', $importacionId);
        }
        $umbral = (float) config('ollama.umbral_clasificacion', 0.6);
        $asignados = 0;
        foreach ($pendientes as $e) {
            $r = $this->clasificar($e->textoParaAnalisis(), $usarIa);
            if ($r->esConfiable($umbral)) {
                $this->expedientes->actualizar($e, [
                    'tipo_expediente_id' => $r->tipoId,
                    'clasificado_por' => $r->metodo,
                    'clasificacion_confianza' => $r->porcentaje(),
                ]);
                $asignados++;
            }
        }

        return ['revisados' => $pendientes->count(), 'asignados' => $asignados];
    }

    /** Resultado legible para la pantalla "Probar clasificador". */
    public function probar(string $texto, bool $usarIa): array
    {
        $r = $this->clasificar($texto, $usarIa);
        $nombres = $this->tipos->todos()->pluck('nombre', 'id');

        return [
            'tipo' => $r->tipoId ? $nombres[$r->tipoId] ?? '—' : null,
            'confianza' => $r->porcentaje(),
            'metodo' => $r->metodo,
            'confiable' => $r->esConfiable((float) config('ollama.umbral_clasificacion', 0.6)),
            'alternativas' => collect($r->alternativas)->map(fn ($p, $id) => ['tipo' => $nombres[$id] ?? $id, 'probabilidad' => round($p * 100, 1)])->values()->all(),
        ];
    }
}
