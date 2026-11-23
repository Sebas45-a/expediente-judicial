<?php

namespace App\Domain\Services;

use App\Domain\Enums\TipoCampo;
use App\Domain\ValueObjects\CampoDetectado;

/**
 * Detecta los datos variables más importantes de un expediente
 * (número de expediente, partes, fechas, DPI, montos, juzgado, direcciones…)
 * para convertirlos en espacios en blanco al aprender el formato.
 *
 * El texto fijo (fundamentos legales, fórmulas de estilo, encabezados) se conserva.
 */
final class DetectorCamposImportantes
{
    private const MESES = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre';

    private const NUM = 'un[oa]?|primero|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince'
        .'|diecis[eé]is|diecisiete|dieciocho|diecinueve|veinte|veinti[a-záéíóú]+|treinta|cuarenta|cincuenta|sesenta'
        .'|setenta|ochenta|noventa|cien(?:to)?';

    /** Rol procesal => [clave, etiqueta] */
    private const ROLES = [
        'yo' => ['demandante', 'Nombre del compareciente / demandante'],
        'promovido por' => ['demandante', 'Nombre del demandante'],
        'promovida por' => ['demandante', 'Nombre del demandante'],
        'interpuesto por' => ['solicitante', 'Nombre del solicitante'],
        'planteado por' => ['solicitante', 'Nombre del solicitante'],
        'a instancia de' => ['demandante', 'Nombre del demandante'],
        'contra' => ['demandado', 'Nombre del demandado'],
        'demandante' => ['demandante', 'Nombre del demandante'],
        'actor' => ['demandante', 'Nombre del demandante'],
        'actora' => ['demandante', 'Nombre del demandante'],
        'accionante' => ['demandante', 'Nombre del demandante'],
        'solicitante' => ['solicitante', 'Nombre del solicitante'],
        'ejecutante' => ['demandante', 'Nombre del demandante'],
        'postulante' => ['solicitante', 'Nombre del solicitante'],
        'interponente' => ['solicitante', 'Nombre del solicitante'],
        'demandado' => ['demandado', 'Nombre del demandado'],
        'demandada' => ['demandado', 'Nombre del demandado'],
        'ejecutado' => ['demandado', 'Nombre del demandado'],
        'ejecutada' => ['demandado', 'Nombre del demandado'],
        'sindicado' => ['sindicado', 'Nombre del sindicado'],
        'sindicada' => ['sindicado', 'Nombre del sindicado'],
        'procesado' => ['sindicado', 'Nombre del sindicado'],
        'procesada' => ['sindicado', 'Nombre del sindicado'],
        'imputado' => ['sindicado', 'Nombre del sindicado'],
        'imputada' => ['sindicado', 'Nombre del sindicado'],
        'acusado' => ['sindicado', 'Nombre del sindicado'],
        'acusada' => ['sindicado', 'Nombre del sindicado'],
        'agraviado' => ['agraviado', 'Nombre del agraviado'],
        'agraviada' => ['agraviado', 'Nombre del agraviado'],
        'victima' => ['agraviado', 'Nombre del agraviado'],
        'ofendido' => ['agraviado', 'Nombre del agraviado'],
        'ofendida' => ['agraviado', 'Nombre del agraviado'],
        'querellante' => ['querellante', 'Nombre del querellante'],
        'denunciante' => ['denunciante', 'Nombre del denunciante'],
        'juez' => ['juez', 'Nombre del juez'],
        'jueza' => ['juez', 'Nombre del juez'],
        'magistrado' => ['magistrado', 'Nombre del magistrado'],
        'magistrada' => ['magistrado', 'Nombre del magistrado'],
        'secretario' => ['secretario', 'Nombre del secretario'],
        'secretaria' => ['secretario', 'Nombre del secretario'],
        'notificador' => ['notificador', 'Nombre del notificador'],
        'fiscal' => ['fiscal', 'Nombre del fiscal'],
        'defensor' => ['abogado', 'Nombre del abogado'],
        'defensora' => ['abogado', 'Nombre del abogado'],
        'abogado' => ['abogado', 'Nombre del abogado'],
        'abogada' => ['abogado', 'Nombre del abogado'],
        'licenciado' => ['abogado', 'Nombre del abogado'],
        'licenciada' => ['abogado', 'Nombre del abogado'],
        'lic' => ['abogado', 'Nombre del abogado'],
        'licda' => ['abogado', 'Nombre del abogado'],
        'notario' => ['notario', 'Nombre del notario'],
        'notaria' => ['notario', 'Nombre del notario'],
        'testigo' => ['testigo', 'Nombre del testigo'],
        'perito' => ['perito', 'Nombre del perito'],
        'senor' => ['persona', 'Nombre de la persona'],
        'senora' => ['persona', 'Nombre de la persona'],
        'sr' => ['persona', 'Nombre de la persona'],
        'sra' => ['persona', 'Nombre de la persona'],
        'ciudadano' => ['persona', 'Nombre de la persona'],
        'ciudadana' => ['persona', 'Nombre de la persona'],
        'nombre' => ['persona', 'Nombre de la persona'],
        'nombres' => ['persona', 'Nombre de la persona'],
    ];

    /** Palabras en minúscula que pueden formar parte del nombre de un juzgado. */
    private const PALABRAS_JUZGADO = [
        'de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'en', 'para', 'con', 'contra', 'sede', 'turno', 'paz',
        'primera', 'segunda', 'tercera', 'cuarta', 'quinta', 'sexta', 'séptima', 'octava', 'novena', 'décima',
        'primero', 'segundo', 'tercero', 'cuarto', 'quinto', 'sexto', 'séptimo', 'octavo', 'noveno', 'décimo',
        'instancia', 'civil', 'penal', 'mercantil', 'familia', 'trabajo', 'previsión', 'social', 'narcoactividad',
        'delitos', 'ambiente', 'niñez', 'adolescencia', 'adolescentes', 'conflicto', 'ley', 'penal,', 'femicidio',
        'violencia', 'mujer', 'formas', 'económico', 'coactivo', 'cuentas', 'contencioso', 'administrativo',
        'constitucional', 'amparo', 'apelaciones', 'sentencia', 'ejecución', 'departamento', 'municipio',
        'distrito', 'materia', 'mayor', 'menor', 'cuantía', 'pluripersonal', 'unipersonal', 'riesgo', 'alto',
        'extinción', 'dominio', 'tránsito', 'regional', 'mixta', 'mixto', 'sala', 'corte', 'tribunal', 'juzgado',
    ];

    private const SECCIONES = [
        'considerando', 'por tanto', 'resultando', 'resulta', 'hechos', 'fundamento', 'fundamentos', 'peticion',
        'peticiones', 'leyes aplicables', 'citas de ley', 'pruebas', 'medios de prueba', 'antecedentes',
        'resuelve', 'notifiquese', 'parte resolutiva', 'objeto', 'asunto', 'relacion de hechos', 'i', 'ii', 'iii',
    ];

    /**
     * @return CampoDetectado[]
     */
    public function detectar(string $texto): array
    {
        $texto = NormalizadorTexto::limpiar($texto);
        if ($texto === '') {
            return [];
        }

        /** @var array<int, array{valor:string, tipo:TipoCampo, clave:?string, etiqueta:?string, pos:int, conf:float}> $hallazgos */
        $hallazgos = [];
        $this->numerosExpediente($texto, $hallazgos);
        $this->juzgados($texto, $hallazgos);
        $this->nombresPorRol($texto, $hallazgos);
        $this->fechas($texto, $hallazgos);
        $this->horas($texto, $hallazgos);
        $this->identificaciones($texto, $hallazgos);
        $this->montos($texto, $hallazgos);
        $this->direcciones($texto, $hallazgos);
        $this->contactos($texto, $hallazgos);
        $this->etiquetaValor($texto, $hallazgos);

        return $this->consolidar($texto, $hallazgos);
    }

    // ------------------------------------------------------------------ reglas

    private function agregar(array &$h, string $valor, TipoCampo $tipo, int $pos, ?string $clave = null, ?string $etiqueta = null, float $conf = 0.9): void
    {
        $valor = trim($valor, " \t\n,;:-–—\"'“”()");
        // quitar punto final que no sea abreviatura
        if (str_ends_with($valor, '.') && ! preg_match('/\b[A-Za-z]{1,3}\.$/u', $valor)) {
            $valor = rtrim($valor, '.');
        }
        if (mb_strlen($valor) < 2 || mb_strlen($valor) > 180) {
            return;
        }
        $h[] = ['valor' => $valor, 'tipo' => $tipo, 'clave' => $clave, 'etiqueta' => $etiqueta, 'pos' => $pos, 'conf' => $conf];
    }

    private function todos(string $patron, string $texto): array
    {
        $ok = @preg_match_all($patron, $texto, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return $ok ? $m : [];
    }

    private function numerosExpediente(string $texto, array &$h): void
    {
        $patrones = [
            '/(?i:expediente|exp\.|causa|proceso|carpeta\s+judicial|juicio|amparo|caso)\s*(?i:n[o°º]\.?|n[uú]mero|num\.?)?\s*[:.\-]?\s*([A-Z0-9]{1,8}(?:\s?[\-\/\.]\s?[A-Z0-9]{1,8}){1,5})/u',
            '/\b((?:[A-Z]{1,4}\d{0,3}-)?\d{3,5}-\d{4}-\d{1,6}(?:-[A-Z0-9]{1,4})?)\b/u',
            '/\b(MP\d{3}-\d{4}-\d{2,8})\b/u',
        ];
        foreach ($patrones as $p) {
            foreach ($this->todos($p, $texto) as $m) {
                $valor = $m[1][0];
                if (! preg_match('/\d/', $valor) || preg_match('/^\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}$/', $valor)) {
                    continue; // es una fecha, no un número de expediente
                }
                $this->agregar($h, $valor, TipoCampo::NumeroExpediente, $m[1][1], 'numero_expediente', 'Número de expediente', 0.95);
            }
        }
    }

    private function juzgados(string $texto, array &$h): void
    {
        $p = '/\b((?i:juzgado|tribunal|sala|corte\s+suprema|corte\s+de\s+constitucionalidad)\b[^\n.;:()"]{3,160})/u';
        foreach ($this->todos($p, $texto) as $m) {
            $palabras = preg_split('/\s+/u', trim($m[1][0])) ?: [];
            $aceptadas = [array_shift($palabras)];
            foreach ($palabras as $w) {
                $limpia = trim($w, ',');
                $min = mb_strtolower($limpia);
                $esMayus = $limpia !== '' && mb_strtoupper(mb_substr($limpia, 0, 1)) === mb_substr($limpia, 0, 1) && ! is_numeric($limpia);
                if ($esMayus || in_array($min, self::PALABRAS_JUZGADO, true)) {
                    $aceptadas[] = $limpia;
                    if (str_ends_with($w, ',')) {
                        break;
                    }

                    continue;
                }
                break;
            }
            while ($aceptadas && in_array(mb_strtolower(end($aceptadas)), ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'en', 'para', 'con'], true)) {
                array_pop($aceptadas);
            }
            if (count($aceptadas) >= 3) {
                $this->agregar($h, implode(' ', $aceptadas), TipoCampo::Juzgado, $m[1][1], 'juzgado', 'Juzgado / Tribunal', 0.85);
            }
        }
    }

    private function nombresPorRol(string $texto, array &$h): void
    {
        $roles = implode('|', array_map(fn ($r) => str_replace(' ', '\\s+', preg_quote($r, '/')), [
            'yo', 'promovido por', 'promovida por', 'interpuesto por', 'planteado por', 'a instancia de', 'contra', 'demandante', 'actora', 'actor', 'accionante', 'solicitante', 'ejecutante', 'postulante', 'interponente',
            'demandada', 'demandado', 'ejecutada', 'ejecutado', 'sindicada', 'sindicado', 'procesada', 'procesado',
            'imputada', 'imputado', 'acusada', 'acusado', 'agraviada', 'agraviado', 'víctima', 'victima', 'ofendida',
            'ofendido', 'querellante', 'denunciante', 'jueza', 'juez', 'magistrada', 'magistrado', 'secretaria',
            'secretario', 'notificador', 'fiscal', 'defensora', 'defensor', 'abogada', 'abogado', 'licenciada',
            'licenciado', 'licda', 'lic', 'notaria', 'notario', 'testigo', 'perito', 'señora', 'señor', 'sra', 'sr',
            'ciudadana', 'ciudadano', 'nombres', 'nombre',
        ]));
        $palabra = '(?:[A-ZÁÉÍÓÚÑ][a-záéíóúñü]+|[A-ZÁÉÍÓÚÑ]{2,})';
        $conector = '(?:\s+(?:de\s+la|de\s+los|del|de|y|DE\s+LA|DEL|DE|Y))?';
        $p = '/(?<![\p{L}])((?i:'.$roles.'))\.?(?:\s+(?i:de\s+nombre|llamad[oa]|se\s+llama|es))?\s*[:,]?\s*(?i:(?:el|la|del|de\s+la|al|a\s+la)\s+)?(?i:(?:señor(?:a)?|sr\.?|sra\.?|licenciad[oa]|lic\.|licda\.|abogad[oa])\s+)?('
            .$palabra.'(?:'.$conector.'\s+'.$palabra.'){1,5})/u';

        foreach ($this->todos($p, $texto) as $m) {
            $rol = NormalizadorTexto::normalizar($m[1][0]);
            $nombre = $m[2][0];
            // Evita capturar frases como "Juez Primero de Primera Instancia"
            if (preg_match('/^(?i:juzgado|tribunal|primer[oa]?|segund[oa]|tercer[oa]?|de\s|del\s|presente|instancia|ministerio|organismo|sala|en|el|la)\b/u', $nombre)) {
                continue;
            }
            $nombre = preg_replace('/\s+(?:de|del|y|DE|DEL|Y)$/u', '', $nombre) ?? $nombre;
            [$clave, $etiqueta] = self::ROLES[$rol] ?? ['persona', 'Nombre de la persona'];
            $this->agregar($h, $nombre, TipoCampo::Nombre, $m[2][1], $clave, $etiqueta, $clave === 'persona' ? 0.75 : 0.9);
        }
    }

    private function fechas(string $texto, array &$h): void
    {
        $num = self::NUM;
        $patrones = [
            '/\b((?:\d{1,2}|'.$num.')(?:\s+y\s+(?:'.$num.'))?\s+de\s+(?:'.self::MESES.')\s+(?:de|del)\s+(?:a[ñn]o\s+)?(?:\d{4}|dos\s+mil(?:\s+(?:'.$num.'|y))*))/iu',
            '/\b(\d{1,2}[\/\-.]\d{1,2}[\/\-.](?:\d{4}|\d{2}))\b/u',
            '/\b(\d{4}-\d{2}-\d{2})\b/u',
        ];
        foreach ($patrones as $p) {
            foreach ($this->todos($p, $texto) as $m) {
                $this->agregar($h, $m[1][0], TipoCampo::Fecha, $m[1][1], null, null, 0.9);
            }
        }
    }

    private function horas(string $texto, array &$h): void
    {
        $num = self::NUM;
        $patrones = [
            '/\b(\d{1,2}[:h]\d{2}\s*(?:horas|hrs\.?|a\.\s?m\.|p\.\s?m\.|am|pm))/iu',
            '/\b((?:las|la)\s+(?:'.$num.'|\d{1,2})(?:\s+y\s+(?:'.$num.'))?\s+horas(?:\s+con\s+(?:'.$num.'|\d{1,2})(?:\s+y\s+(?:'.$num.'))?\s+minutos)?)/iu',
        ];
        foreach ($patrones as $p) {
            foreach ($this->todos($p, $texto) as $m) {
                $this->agregar($h, $m[1][0], TipoCampo::Hora, $m[1][1], null, null, 0.8);
            }
        }
    }

    private function identificaciones(string $texto, array &$h): void
    {
        foreach ($this->todos('/(?<!\d)(\d{4}\s?\d{5}\s?\d{4})(?!\d)/u', $texto) as $m) {
            $this->agregar($h, $m[1][0], TipoCampo::Dpi, $m[1][1], null, null, 0.9);
        }
        foreach ($this->todos('/\b(?i:NIT)\s*(?i:no\.?|n[uú]mero)?\s*[:.]?\s*(\d{3,10}-?[\dkK])\b/u', $texto) as $m) {
            $this->agregar($h, $m[1][0], TipoCampo::Nit, $m[1][1], null, null, 0.9);
        }
        foreach ($this->todos('/\b(?i:colegiad[oa](?:\s+activ[oa])?)\s*(?i:no\.?|n[uú]mero)?\s*[:.]?\s*(\d{3,6})\b/u', $texto) as $m) {
            $this->agregar($h, $m[1][0], TipoCampo::Colegiado, $m[1][1], null, null, 0.9);
        }
        $civil = 'solter[oa]|casad[oa]|divorciad[oa]|viud[oa]|unid[oa]\s+de\s+hecho';
        $p = '/a[ñn]os\s+de\s+edad,\s*('.$civil.'),\s*([a-záéíóúñ]+),\s*([a-záéíóúñ ]{3,50}?),\s*(?:de\s+este\s+domicilio|con\s+domicilio|del\s+domicilio|vecin[oa]|quien|me\s+identifico|se\s+identifica)/iu';
        foreach ($this->todos($p, $texto) as $m) {
            $this->agregar($h, $m[1][0], TipoCampo::Texto, $m[1][1], 'estado_civil', 'Estado civil', 0.8);
            $this->agregar($h, $m[2][0], TipoCampo::Texto, $m[2][1], 'nacionalidad', 'Nacionalidad', 0.75);
            $this->agregar($h, $m[3][0], TipoCampo::Texto, $m[3][1], 'profesion', 'Profesión u oficio', 0.8);
        }
        $num = self::NUM;
        foreach ($this->todos('/\b((?:\d{1,3}|(?:'.$num.')(?:\s+y\s+(?:'.$num.'))?)\s+a[ñn]os\s+de\s+edad)/iu', $texto) as $m) {
            $this->agregar($h, $m[1][0], TipoCampo::Edad, $m[1][1], null, null, 0.85);
        }
    }

    private function montos(string $texto, array &$h): void
    {
        foreach ($this->todos('/(?<![\p{L}\d])((?:Q|US\$|\$|L)\s?\.?\s?\d{1,3}(?:[,\s]\d{3})*(?:\.\d{1,2})?|(?:Q|US\$|\$)\s?\.?\s?\d+(?:\.\d{1,2})?)(?!\d)/u', $texto) as $m) {
            $this->agregar($h, $m[1][0], TipoCampo::Monto, $m[1][1], null, null, 0.9);
        }
        foreach ($this->todos('/(?i:cantidad|suma|monto|importe)\s+(?i:de|total\s+de)\s+((?:[A-ZÁÉÍÓÚÑa-záéíóúñ]+\s+){1,9}(?i:quetzales|d[oó]lares|lempiras|pesos)(?:\s+(?i:con)\s+[\d\/]+(?:\s+(?i:centavos))?)?(?:\s+(?i:exactos))?)/u', $texto) as $m) {
            $this->agregar($h, $m[1][0], TipoCampo::Monto, $m[1][1], 'monto_letras', 'Monto en letras', 0.85);
        }
        foreach ($this->todos('/(?<![\p{L}])((?:(?:[A-ZÁÉÍÓÚÑ]{2,}|Y)\s+){1,8}(?:QUETZALES|D[OÓ]LARES|LEMPIRAS|PESOS)(?:\s+(?:CON|EXACTOS)(?:\s+[\d\/]+)?(?:\s+CENTAVOS)?)?)/u', $texto) as $m) {
            $this->agregar($h, $m[1][0], TipoCampo::Monto, $m[1][1], 'monto_letras', 'Monto en letras', 0.85);
        }
    }

    private function direcciones(string $texto, array &$h): void
    {
        $p = '/(?i:domicilio(?:\s+procesal)?|direcci[oó]n|residente\s+en|reside\s+en|con\s+sede\s+en|ubicad[oa]\s+en|recibir\s+notificaciones\s+en|lugar\s+para\s+recibir\s+notificaciones(?:\s+(?:la|el|en))?|notificad[oa]\s+en|residencia\s+en|vive\s+en)\s*(?i:(?:es|el|la|en|ubicad[oa]\s+en|:|,)\s*){0,3}([^\n;]{6,140}?)(?=\.\s+[A-ZÁÉÍÓÚÑ]|\.\s*$|\.\s*\n|;|\n|,\s+(?i:quien|donde|para|y\s+(?:que|el|la))\b|$)/um';
        foreach ($this->todos($p, $texto) as $m) {
            $v = trim($m[1][0]);
            if (preg_match('/\d|zona|avenida|calle|aldea|colonia|km|kil[oó]metro|barrio|municipio|departamento|casa/iu', $v)) {
                $this->agregar($h, $v, TipoCampo::Direccion, $m[1][1], null, null, 0.8);
            }
        }
    }

    private function contactos(string $texto, array &$h): void
    {
        foreach ($this->todos('/\b([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})\b/u', $texto) as $m) {
            $this->agregar($h, $m[1][0], TipoCampo::Correo, $m[1][1], null, null, 0.95);
        }
        foreach ($this->todos('/\b(?i:tel[eé]fono(?:s)?|tel\.|celular|cel\.|m[oó]vil)\s*(?i:no\.?|n[uú]mero)?\s*[:.]?\s*((?:\+?502\s?)?\d{4}[\s\-]?\d{4})\b/u', $texto) as $m) {
            $this->agregar($h, $m[1][0], TipoCampo::Telefono, $m[1][1], null, null, 0.9);
        }
    }

    /** Líneas tipo formulario: "Fecha de audiencia: 12/03/2024", "Nombre del actor: Juan Pérez". */
    private function etiquetaValor(string $texto, array &$h): void
    {
        foreach ($this->todos('/^[ \t]*([A-ZÁÉÍÓÚÑ][A-Za-zÁÉÍÓÚÑáéíóúñ.\/ ]{2,45}?)\s*:\s*([^\n:]{2,90})$/mu', $texto) as $m) {
            $etiqueta = trim($m[1][0]);
            $valor = trim($m[2][0]);
            $norm = NormalizadorTexto::normalizar($etiqueta);
            if (in_array($norm, self::SECCIONES, true) || str_word_count(NormalizadorTexto::sinTildes($etiqueta)) > 6) {
                continue;
            }
            if (mb_strlen($valor) > 90 || preg_match('/^(?:que|se|el|la|los|las|con|de)\s/iu', $valor)) {
                continue; // es redacción, no un dato
            }
            $clave = NormalizadorTexto::slug($etiqueta);
            if ($clave === '' || strlen($clave) > 60) {
                continue;
            }
            $tipo = $this->inferirTipo($norm, $valor);
            $this->agregar($h, $valor, $tipo, $m[2][1], $clave, $etiqueta, 0.75);
        }
    }

    private function inferirTipo(string $etiquetaNorm, string $valor): TipoCampo
    {
        return match (true) {
            str_contains($etiquetaNorm, 'fecha') => TipoCampo::Fecha,
            str_contains($etiquetaNorm, 'hora') => TipoCampo::Hora,
            str_contains($etiquetaNorm, 'expediente') || str_contains($etiquetaNorm, 'causa') => TipoCampo::NumeroExpediente,
            str_contains($etiquetaNorm, 'dpi') || str_contains($etiquetaNorm, 'cui') => TipoCampo::Dpi,
            str_contains($etiquetaNorm, 'nit') => TipoCampo::Nit,
            str_contains($etiquetaNorm, 'monto') || str_contains($etiquetaNorm, 'cuantia') || str_contains($etiquetaNorm, 'valor') => TipoCampo::Monto,
            str_contains($etiquetaNorm, 'juzgado') || str_contains($etiquetaNorm, 'tribunal') => TipoCampo::Juzgado,
            str_contains($etiquetaNorm, 'direccion') || str_contains($etiquetaNorm, 'domicilio') => TipoCampo::Direccion,
            str_contains($etiquetaNorm, 'correo') || str_contains($etiquetaNorm, 'email') => TipoCampo::Correo,
            str_contains($etiquetaNorm, 'telefono') || str_contains($etiquetaNorm, 'celular') => TipoCampo::Telefono,
            str_contains($etiquetaNorm, 'nombre') || str_contains($etiquetaNorm, 'actor') || str_contains($etiquetaNorm, 'demandad')
                || str_contains($etiquetaNorm, 'juez') || str_contains($etiquetaNorm, 'sindicad') => TipoCampo::Nombre,
            (bool) preg_match('/^\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}$/', $valor) => TipoCampo::Fecha,
            default => TipoCampo::Texto,
        };
    }

    // ------------------------------------------------------------------ consolidación

    /**
     * Elimina duplicados y solapamientos, asigna claves únicas y cuenta ocurrencias.
     *
     * @return CampoDetectado[]
     */
    private function consolidar(string $texto, array $hallazgos): array
    {
        // 1) los más largos y confiables primero para resolver solapamientos
        usort($hallazgos, fn ($a, $b) => [$b['conf'], mb_strlen($b['valor'])] <=> [$a['conf'], mb_strlen($a['valor'])]);

        $tramos = [];
        $porValor = [];
        foreach ($hallazgos as $hz) {
            $inicio = $hz['pos'];
            $fin = $inicio + strlen($hz['valor']);
            $llave = mb_strtolower($hz['valor']);

            if (isset($porValor[$llave])) {
                // mismo valor: conservar la versión con rol (clave explícita)
                if ($porValor[$llave]['clave'] === null && $hz['clave'] !== null) {
                    $porValor[$llave]['clave'] = $hz['clave'];
                    $porValor[$llave]['etiqueta'] = $hz['etiqueta'];
                }

                continue;
            }
            $solapa = false;
            foreach ($tramos as [$i, $f]) {
                if ($inicio < $f && $fin > $i) {
                    $solapa = true;
                    break;
                }
            }
            // un valor contenido dentro de otro ya aceptado tampoco se agrega
            foreach (array_keys($porValor) as $otro) {
                $ambosConLetras = preg_match('/\p{L}{3}/u', $otro) && preg_match('/\p{L}{3}/u', $llave);
                if ($ambosConLetras && (str_contains($otro, $llave) || str_contains($llave, $otro))) {
                    $solapa = true;
                    break;
                }
            }
            if ($solapa) {
                continue;
            }
            $tramos[] = [$inicio, $fin];
            $porValor[$llave] = $hz;
        }

        // 2) orden de aparición en el documento
        $lista = array_values($porValor);
        usort($lista, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        // 3) claves únicas
        $usadas = [];
        $campos = [];
        foreach ($lista as $hz) {
            $base = $hz['clave'] ?? $hz['tipo']->value;
            $etiqueta = $hz['etiqueta'] ?? $hz['tipo']->etiqueta();
            $usadas[$base] = ($usadas[$base] ?? 0) + 1;
            $clave = $usadas[$base] === 1 ? $base : $base.'_'.$usadas[$base];
            if ($usadas[$base] > 1) {
                $etiqueta .= ' ('.$usadas[$base].')';
            }
            $ocurrencias = max(1, self::contarOcurrencias($texto, $hz['valor']));
            $campos[] = new CampoDetectado($clave, $etiqueta, $hz['tipo'], $hz['valor'], $ocurrencias, 'regla', $hz['conf']);
        }

        return $campos;
    }

    /** Cuenta apariciones exactas respetando límites de palabra (no cuenta "12345" dentro de "1234567"). */
    public static function contarOcurrencias(string $texto, string $valor): int
    {
        return (int) preg_match_all(self::patronValor($valor), $texto);
    }

    /** Patrón regex que encuentra el valor como palabra completa. */
    public static function patronValor(string $valor): string
    {
        $partes = preg_split('/\s+/u', trim($valor)) ?: [$valor];
        $cuerpo = implode('[\s\x{00A0}]+', array_map(fn ($p) => preg_quote($p, '/'), $partes));

        return '/(?<![\p{L}\p{N}])'.$cuerpo.'(?![\p{L}\p{N}])/u';
    }
}
