<?php

namespace App\Domain\Services;

use App\Domain\ValueObjects\ResultadoClasificacion;

/**
 * Clasificador Naive Bayes multinomial (con suavizado de Laplace) para
 * aprender cada tipo de expediente a partir de los ejemplos registrados.
 *
 * Es lógica pura de dominio: no depende de Laravel ni de la base de datos.
 */
final class ClasificadorNaiveBayes
{
    /** Número máximo de términos que se guardan por tipo (mantiene el modelo liviano). */
    public const MAX_TERMINOS = 1500;

    /**
     * @param  array<int, array{0:int, 1:string}>  $muestras  [tipoId, texto]
     * @return array{tipos: array<int, array{docs:int, tokens:int, frec: array<string,int>}>, vocab:int, total_docs:int}
     */
    public function entrenar(array $muestras): array
    {
        $tipos = [];
        $vocab = [];
        foreach ($muestras as [$tipoId, $texto]) {
            $tokens = NormalizadorTexto::tokens($texto);
            $tipos[$tipoId] ??= ['docs' => 0, 'tokens' => 0, 'frec' => [], 'df' => []];
            $tipos[$tipoId]['docs']++;
            foreach (array_unique($tokens) as $t) {
                $tipos[$tipoId]['df'][$t] = ($tipos[$tipoId]['df'][$t] ?? 0) + 1;
            }
            foreach ($tokens as $t) {
                $tipos[$tipoId]['frec'][$t] = ($tipos[$tipoId]['frec'][$t] ?? 0) + 1;
                $tipos[$tipoId]['tokens']++;
                $vocab[$t] = true;
            }
        }
        foreach ($tipos as &$tipo) {
            arsort($tipo['frec']);
            $tipo['frec'] = array_slice($tipo['frec'], 0, self::MAX_TERMINOS, true);
            $tipo['df'] = array_intersect_key($tipo['df'], $tipo['frec']);
        }
        unset($tipo);

        return ['tipos' => $tipos, 'vocab' => max(1, count($vocab)), 'total_docs' => count($muestras)];
    }

    public function clasificar(array $modelo, string $texto): ResultadoClasificacion
    {
        $tipos = $modelo['tipos'] ?? [];
        if (count($tipos) === 0) {
            return ResultadoClasificacion::sinResultado();
        }
        $tokens = NormalizadorTexto::tokens($texto);
        if (! $tokens) {
            return ResultadoClasificacion::sinResultado();
        }

        // sólo cuentan términos conocidos por algún tipo
        $conocidos = array_filter($tokens, function ($t) use ($tipos) {
            foreach ($tipos as $tipo) {
                if (isset($tipo['frec'][$t])) {
                    return true;
                }
            }

            return false;
        });
        if (! $conocidos) {
            return ResultadoClasificacion::sinResultado();
        }

        $vocab = (int) ($modelo['vocab'] ?? 1);
        $totalDocs = max(1, (int) ($modelo['total_docs'] ?? 1));
        $puntajes = [];
        foreach ($tipos as $tipoId => $tipo) {
            $log = log(($tipo['docs'] + 1) / ($totalDocs + count($tipos)));
            $denominador = $tipo['tokens'] + $vocab;
            foreach ($conocidos as $t) {
                $log += log((($tipo['frec'][$t] ?? 0) + 1) / $denominador);
            }
            $puntajes[$tipoId] = $log;
        }

        // softmax estable
        $max = max($puntajes);
        $exp = array_map(fn ($p) => exp($p - $max), $puntajes);
        $suma = array_sum($exp) ?: 1;
        $prob = array_map(fn ($e) => $e / $suma, $exp);
        arsort($prob);

        $mejor = array_key_first($prob);
        // si sólo hay un tipo aprendido, la confianza depende de la cobertura del vocabulario
        $confianza = count($tipos) === 1
            ? min(0.95, count($conocidos) / max(1, count($tokens)))
            : $prob[$mejor];

        return new ResultadoClasificacion((int) $mejor, (float) $confianza, 'aprendizaje', array_slice($prob, 0, 5, true));
    }

    /**
     * Términos más característicos de un tipo frente a los demás.
     *
     * @return string[]
     */
    public function palabrasClave(array $modelo, int $tipoId, int $n = 12): array
    {
        $tipos = $modelo['tipos'] ?? [];
        if (! isset($tipos[$tipoId])) {
            return [];
        }
        $propio = $tipos[$tipoId];
        $otrosFrec = [];
        $otrosTokens = 0;
        foreach ($tipos as $id => $t) {
            if ($id === $tipoId) {
                continue;
            }
            $otrosTokens += $t['tokens'];
            foreach ($t['frec'] as $term => $c) {
                $otrosFrec[$term] = ($otrosFrec[$term] ?? 0) + $c;
            }
        }
        $puntaje = [];
        $minDocs = min(2, $propio['docs']);
        foreach ($propio['frec'] as $term => $c) {
            // debe aparecer en varios expedientes del tipo (no en uno solo) …
            if (($propio['df'][$term] ?? $c) < $minDocs) {
                continue;
            }
            $pPropio = ($c + 1) / ($propio['tokens'] + 1);
            $pOtros = (($otrosFrec[$term] ?? 0) + 1) / ($otrosTokens + 1);
            $razon = $pPropio / $pOtros;
            // … y ser claramente más frecuente que en los demás tipos
            if (count($tipos) > 1 && $razon < 1.8) {
                continue;
            }
            $puntaje[$term] = log($razon) * log(1 + ($propio['df'][$term] ?? $c));
        }
        arsort($puntaje);

        return array_slice(array_keys($puntaje), 0, $n);
    }

    /**
     * Precisión estimada: validación cruzada sencilla (k=5) si hay suficientes muestras.
     */
    public function evaluar(array $muestras): ?float
    {
        $n = count($muestras);
        $tiposDistintos = count(array_unique(array_column($muestras, 0)));
        if ($n < 6 || $tiposDistintos < 2) {
            return null;
        }
        $k = min(5, $n);
        $aciertos = 0;
        for ($f = 0; $f < $k; $f++) {
            $entreno = [];
            $prueba = [];
            foreach ($muestras as $i => $m) {
                if ($i % $k === $f) {
                    $prueba[] = $m;
                } else {
                    $entreno[] = $m;
                }
            }
            $modelo = $this->entrenar($entreno);
            foreach ($prueba as [$real, $texto]) {
                if ($this->clasificar($modelo, $texto)->tipoId === $real) {
                    $aciertos++;
                }
            }
        }

        return round($aciertos / $n * 100, 2);
    }
}
