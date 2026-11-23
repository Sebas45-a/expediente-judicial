<?php

namespace App\Domain\Services;

use App\Domain\ValueObjects\CampoDetectado;

/**
 * Reemplaza en texto plano los valores detectados por marcadores ({{clave}}, ${clave}, líneas en blanco…).
 */
final class MarcadorCampos
{
    public const LINEA_BLANCO = '______________________';

    /**
     * @param  CampoDetectado[]  $campos
     * @param  callable(CampoDetectado): string  $marcador
     */
    public static function marcar(string $texto, array $campos, callable $marcador): string
    {
        usort($campos, fn ($a, $b) => mb_strlen($b->valor) <=> mb_strlen($a->valor));
        // primero se usan fichas temporales para que un reemplazo no altere a otro
        $fichas = [];
        foreach (array_values($campos) as $i => $c) {
            $ficha = "\u{E000}{$i}\u{E001}";
            $nuevo = preg_replace(DetectorCamposImportantes::patronValor($c->valor), $ficha, $texto);
            if ($nuevo !== null && $nuevo !== $texto) {
                $texto = $nuevo;
                $fichas[$ficha] = $marcador($c);
            }
        }

        return strtr($texto, $fichas);
    }

    /** Sustituye {{clave}} por su valor o por una línea en blanco. */
    public static function aplicarValores(string $plantilla, array $valores): string
    {
        return preg_replace_callback('/\{\{([a-z0-9_]+)\}\}/', function ($m) use ($valores) {
            $v = trim((string) ($valores[$m[1]] ?? ''));

            return $v !== '' ? $v : self::LINEA_BLANCO;
        }, $plantilla) ?? $plantilla;
    }
}
