<?php

namespace App\Domain\Services;

/**
 * Utilidades de texto en español sin dependencias externas.
 */
final class NormalizadorTexto
{
    private const STOPWORDS = [
        'que', 'los', 'las', 'del', 'por', 'con', 'una', 'uno', 'unos', 'unas', 'para', 'como', 'mas', 'pero',
        'sus', 'este', 'esta', 'esto', 'estos', 'estas', 'ese', 'esa', 'eso', 'esos', 'esas', 'aquel', 'son',
        'fue', 'han', 'hay', 'ser', 'sido', 'sin', 'sobre', 'entre', 'cuando', 'muy', 'tambien', 'hasta',
        'desde', 'donde', 'quien', 'quienes', 'cual', 'cuales', 'todo', 'todos', 'toda', 'todas', 'otro', 'otra',
        'otros', 'otras', 'les', 'nos', 'ante', 'bajo', 'cada', 'dicho', 'dicha', 'dichos', 'dichas', 'mismo',
        'misma', 'segun', 'tiene', 'tienen', 'haber', 'esta', 'estan', 'estaba', 'sera', 'seran', 'porque',
        'asi', 'ademas', 'solo', 'siendo', 'tanto', 'tal', 'tales', 'del', 'al', 'lo', 'le', 'se', 'su', 'en',
        'el', 'la', 'de', 'y', 'o', 'a', 'un', 'no', 'si', 'ya', 'mi', 'tu', 'es', 'era', 'sea', 'dos', 'mil',
        'numero', 'nan', 'null', 'none',
        // genéricos de cualquier expediente (no distinguen un tipo de otro)
        'lic', 'licda', 'licenciado', 'licenciada', 'abogado', 'abogada', 'juzgado', 'tribunal', 'instancia',
        'expediente', 'primero', 'segundo', 'tercero', 'cuarto', 'quinto', 'sexto', 'primera', 'tercera',
        'guatemala', 'departamento', 'ciudad', 'zona', 'senor', 'senora',
    ];

    public static function sinTildes(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);
    }

    /** minúsculas, sin tildes, sin signos, espacios simples. */
    public static function normalizar(string $texto): string
    {
        $t = mb_strtolower(self::sinTildes($texto), 'UTF-8');
        $t = preg_replace('/[^a-z0-9]+/u', ' ', $t) ?? '';

        return trim(preg_replace('/\s+/', ' ', $t) ?? '');
    }

    /** @return string[] */
    public static function tokens(string $texto): array
    {
        $tokens = [];
        foreach (explode(' ', self::normalizar($texto)) as $tk) {
            if (strlen($tk) < 3 || ctype_digit($tk) || in_array($tk, self::STOPWORDS, true)) {
                continue;
            }
            // raíz muy simple: quita plurales para agrupar "demandas"/"demanda"
            if (strlen($tk) > 5 && str_ends_with($tk, 'es')) {
                $tk = substr($tk, 0, -2);
            } elseif (strlen($tk) > 4 && str_ends_with($tk, 's')) {
                $tk = substr($tk, 0, -1);
            }
            $tokens[] = $tk;
        }

        return $tokens;
    }

    public static function slug(string $texto, string $sep = '_'): string
    {
        $s = self::normalizar($texto);

        return trim(str_replace(' ', $sep, $s), $sep);
    }

    /** Recorta un texto respetando palabras completas. */
    public static function recortar(string $texto, int $max): string
    {
        $texto = trim($texto);
        if (mb_strlen($texto) <= $max) {
            return $texto;
        }
        $corte = mb_substr($texto, 0, $max);
        $ultimo = mb_strrpos($corte, ' ');

        return ($ultimo !== false && $ultimo > $max * 0.6 ? mb_substr($corte, 0, $ultimo) : $corte).'…';
    }

    /** Elimina caracteres de control y normaliza saltos de línea. */
    public static function limpiar(string $texto): string
    {
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        }
        $texto = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $texto);
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto) ?? $texto;
        $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;

        return trim(preg_replace("/\n{3,}/", "\n\n", $texto) ?? $texto);
    }
}
