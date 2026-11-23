<?php

namespace App\Domain\Services;

/**
 * Divide textos largos en fragmentos solapados para la búsqueda semántica (RAG).
 */
final class FragmentadorTexto
{
    public function __construct(
        private readonly int $tamano = 1000,
        private readonly int $solape = 150,
    ) {}

    /** @return string[] */
    public function fragmentar(string $texto): array
    {
        $texto = NormalizadorTexto::limpiar($texto);
        if ($texto === '') {
            return [];
        }

        $parrafos = preg_split("/\n+/u", $texto) ?: [];
        $piezas = [];
        foreach ($parrafos as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            // párrafos gigantes se cortan por oraciones
            if (mb_strlen($p) > $this->tamano) {
                foreach (preg_split('/(?<=[.;:])\s+/u', $p) ?: [$p] as $oracion) {
                    while (mb_strlen($oracion) > $this->tamano) {
                        $piezas[] = mb_substr($oracion, 0, $this->tamano);
                        $oracion = mb_substr($oracion, $this->tamano - $this->solape);
                    }
                    $piezas[] = $oracion;
                }
            } else {
                $piezas[] = $p;
            }
        }

        $fragmentos = [];
        $actual = '';
        foreach ($piezas as $pieza) {
            if ($actual !== '' && mb_strlen($actual) + mb_strlen($pieza) + 1 > $this->tamano) {
                $fragmentos[] = $actual;
                $cola = mb_substr($actual, -$this->solape);
                $espacio = mb_strpos($cola, ' ');
                $actual = ($espacio !== false ? mb_substr($cola, $espacio + 1) : $cola)."\n".$pieza;
            } else {
                $actual = $actual === '' ? $pieza : $actual."\n".$pieza;
            }
        }
        if (trim($actual) !== '') {
            $fragmentos[] = $actual;
        }

        return $fragmentos;
    }
}
