<?php

namespace App\Application\Services;

use App\Domain\Contracts\Repositories\PlantillaRepositoryInterface;
use App\Domain\Contracts\Repositories\TipoExpedienteRepositoryInterface;
use App\Domain\Contracts\Servicios\ClienteLlmInterface;
use App\Domain\Contracts\Servicios\ExtractorDocumentoInterface;
use App\Domain\Contracts\Servicios\GeneradorDocumentosInterface;
use App\Domain\Enums\FormatoArchivo;
use App\Domain\Enums\TipoCampo;
use App\Domain\Exceptions\ExpedienteException;
use App\Domain\Exceptions\OllamaException;
use App\Domain\Services\DetectorCamposImportantes;
use App\Domain\Services\MarcadorCampos;
use App\Domain\Services\NormalizadorTexto;
use App\Domain\ValueObjects\CampoDetectado;
use App\Domain\ValueObjects\DocumentoExtraido;
use App\Infrastructure\Persistence\Models\Expediente;
use App\Infrastructure\Persistence\Models\PlantillaFormato;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Aprende el FORMATO de cada tipo de expediente:
 *  1. Analiza un documento real (Word/PDF/Excel/TXT).
 *  2. Detecta los datos importantes (reglas + IA opcional) y los propone como espacios en blanco.
 *  3. El usuario revisa y confirma.
 *  4. Se guarda una plantilla DOCX que conserva el texto fijo y el diseño original.
 *  5. Con cada nueva muestra del mismo tipo el sistema compara y mejora (versión nueva).
 */
class AprendizajeFormatoService
{
    public function __construct(
        private readonly ExtractorDocumentoInterface $extractor,
        private readonly GeneradorDocumentosInterface $generador,
        private readonly PlantillaRepositoryInterface $plantillas,
        private readonly TipoExpedienteRepositoryInterface $tipos,
        private readonly ClienteLlmInterface $llm,
        private readonly DetectorCamposImportantes $detector,
        private readonly AprendizajeTiposService $aprendizajeTipos,
    ) {}

    // ------------------------------------------------------------------ 1-2. Análisis

    public function analizar(UploadedFile $archivo, ?int $tipoId, ?string $tipoNuevo, bool $usarIa): array
    {
        if (! $this->extractor->soporta($archivo->getClientOriginalName())) {
            throw new ExpedienteException('Formato no soportado. Use Word (.docx), PDF, Excel o TXT.');
        }
        $token = (string) Str::uuid();
        $ext = strtolower($archivo->getClientOriginalExtension());
        $ruta = $archivo->storeAs('formatos/originales', "{$token}.{$ext}", 'local');
        $documento = $this->extractor->extraer(Storage::disk('local')->path($ruta), $archivo->getClientOriginalName());
        $texto = $documento->texto();

        // tipo: elegido, nuevo, o sugerido por el aprendizaje
        $sugerencia = null;
        if ($tipoNuevo) {
            $tipoId = $this->tipos->buscarOCrear($tipoNuevo)->id;
        } elseif (! $tipoId) {
            $r = $this->aprendizajeTipos->clasificar($texto, $usarIa);
            $sugerencia = $r->tipoId ? ['tipo_id' => $r->tipoId, 'confianza' => $r->porcentaje(), 'metodo' => $r->metodo] : null;
            $tipoId = $r->tipoId;
        }

        $campos = $this->detector->detectar($texto);
        $avisos = [];
        $usoIa = false;
        if ($usarIa) {
            try {
                $extra = $this->detectarConIa($texto, $campos);
                $campos = array_merge($campos, $extra);
                $usoIa = true;
                if (! $extra) {
                    $avisos[] = 'La IA revisó el documento y no encontró datos adicionales a los detectados por las reglas.';
                }
            } catch (OllamaException $e) {
                $avisos[] = 'No se usó la IA: '.$e->getMessage().' Se usaron solo las reglas de detección.';
            }
        }

        $campos = $this->compararConFormatoAnterior($tipoId, $campos);

        $analisis = [
            'token' => $token,
            'archivo_ruta' => $ruta,
            'archivo_nombre' => $archivo->getClientOriginalName(),
            'formato' => $documento->formato->value,
            'parrafos' => $documento->parrafos,
            'tipo_id' => $tipoId,
            'sugerencia' => $sugerencia,
            'campos' => $campos,
            'uso_ia' => $usoIa,
            'avisos' => $avisos,
        ];
        Storage::disk('local')->put("formatos/analisis/{$token}.json", json_encode($analisis, JSON_UNESCAPED_UNICODE));

        return $analisis;
    }

    public function obtenerAnalisis(string $token): array
    {
        $ruta = 'formatos/analisis/'.basename($token).'.json';
        if (! Storage::disk('local')->exists($ruta)) {
            throw new ExpedienteException('El análisis expiró o no existe. Vuelva a cargar el documento.');
        }

        return json_decode(Storage::disk('local')->get($ruta), true);
    }

    /**
     * Pide a Ollama los datos variables que las reglas no detectaron.
     * Cada valor se verifica contra el texto: si la IA "inventa" algo, se descarta.
     *
     * @param  CampoDetectado[]  $yaDetectados
     * @return array<int, array> campos como arreglo
     */
    private function detectarConIa(string $texto, array $yaDetectados): array
    {
        $max = min(12000, $this->llm->caracteresContextoMaximo());
        $conocidos = array_map(fn (CampoDetectado $c) => $c->valor, $yaDetectados);
        $json = $this->llm->chatJson([
            ['role' => 'system', 'content' => 'Eres un analista de documentos judiciales. Respondes únicamente con JSON válido.'],
            ['role' => 'user', 'content' => <<<TXT
Del siguiente documento judicial identifica SOLO los datos variables importantes que cambian de un expediente a otro
(nombres de las partes, juzgado, número de expediente, fechas, montos, delitos, direcciones, números de documentos, etc.).
NO incluyas texto legal fijo, artículos de ley ni fórmulas de estilo.
Copia cada valor EXACTAMENTE como aparece en el documento (mismas mayúsculas y tildes).
Ya se detectaron estos valores, no los repitas: {$this->lista($conocidos)}

Responde con este JSON:
{"campos":[{"clave":"nombre_en_snake_case","etiqueta":"Etiqueta legible","tipo":"nombre|fecha|numero_expediente|juzgado|monto|direccion|dpi|texto","valor":"texto exacto"}]}

DOCUMENTO:
"""
{$this->recorte($texto, $max)}
"""
TXT],
        ], ['num_predict' => 1200]);

        $nuevos = [];
        foreach (array_slice((array) ($json['campos'] ?? []), 0, 25) as $c) {
            $valor = trim((string) ($c['valor'] ?? ''));
            if (mb_strlen($valor) < 2 || mb_strlen($valor) > 180 || in_array($valor, $conocidos, true)) {
                continue;
            }
            if (DetectorCamposImportantes::contarOcurrencias($texto, $valor) === 0) {
                continue; // la IA no copió el valor tal cual: se descarta para no corromper el formato
            }
            $clave = NormalizadorTexto::slug((string) ($c['clave'] ?? $c['etiqueta'] ?? 'dato')) ?: 'dato';
            $nuevos[] = (new CampoDetectado(
                Str::limit($clave, 60, ''),
                Str::limit((string) ($c['etiqueta'] ?? $clave), 140, ''),
                TipoCampo::desdeTexto($c['tipo'] ?? null),
                $valor,
                DetectorCamposImportantes::contarOcurrencias($texto, $valor),
                'ia',
                0.7,
            ));
            $conocidos[] = $valor;
        }

        return $nuevos;
    }

    private function lista(array $valores): string
    {
        return $valores ? '"'.implode('", "', array_slice($valores, 0, 40)).'"' : '(ninguno)';
    }

    private function recorte(string $texto, int $max): string
    {
        return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max)."\n[...]" : $texto;
    }

    /**
     * Aprendizaje entre muestras: si un dato tiene EXACTAMENTE el mismo valor que en el formato anterior
     * del mismo tipo, probablemente es texto fijo (p.ej. el nombre del juzgado de siempre) → se desmarca.
     *
     * @param  CampoDetectado[]  $campos
     */
    private function compararConFormatoAnterior(?int $tipoId, array $campos): array
    {
        $anterior = $tipoId ? $this->plantillas->activaDeTipo($tipoId) : null;
        $previos = $anterior ? $anterior->campos->keyBy('clave') : collect();
        $valoresPrevios = $anterior ? $anterior->campos->pluck('valor_ejemplo')->map(fn ($v) => mb_strtolower((string) $v))->all() : [];

        return array_map(function ($c) use ($previos, $valoresPrevios) {
            $arr = $c instanceof CampoDetectado ? $c->toArray() : $c;
            $repetido = in_array(mb_strtolower($arr['valor']), $valoresPrevios, true);
            $arr['incluir'] = ! $repetido && $arr['confianza'] >= 0.7;
            $arr['nota'] = $repetido ? 'Tiene el mismo valor que en la muestra anterior: probablemente es texto fijo.' : null;
            if ($previo = $previos->get($arr['clave'])) {
                $arr['etiqueta'] = $previo->etiqueta; // conserva las etiquetas que el usuario ya corrigió
                $arr['conocido'] = true;
            }

            return $arr;
        }, $campos);
    }

    // ------------------------------------------------------------------ 3-4. Confirmación

    /**
     * @param  array<int, array{clave?:string, etiqueta?:string, tipo?:string, valor?:string, incluir?:mixed}>  $seleccion
     */
    public function confirmar(string $token, int $tipoId, string $nombre, array $seleccion, array $manuales = []): PlantillaFormato
    {
        $analisis = $this->obtenerAnalisis($token);
        $tipo = $this->tipos->obtener($tipoId);
        $documento = new DocumentoExtraido(FormatoArchivo::from($analisis['formato']), $analisis['parrafos']);
        $texto = $documento->texto();

        $campos = [];
        $claves = [];
        $agregar = function (array $c, string $fuente) use (&$campos, &$claves, $texto) {
            $valor = trim((string) ($c['valor'] ?? ''));
            if ($valor === '' || DetectorCamposImportantes::contarOcurrencias($texto, $valor) === 0) {
                return;
            }
            $etiqueta = trim((string) ($c['etiqueta'] ?? '')) ?: 'Dato';
            $base = NormalizadorTexto::slug((string) ($c['clave'] ?? '')) ?: NormalizadorTexto::slug($etiqueta) ?: 'dato';
            $base = Str::limit($base, 55, '');
            $clave = $base;
            $n = 2;
            while (isset($claves[$clave])) {
                $clave = $base.'_'.$n++;
            }
            $claves[$clave] = true;
            $campos[] = new CampoDetectado($clave, Str::limit($etiqueta, 140, ''), TipoCampo::desdeTexto($c['tipo'] ?? null), $valor,
                DetectorCamposImportantes::contarOcurrencias($texto, $valor), $c['fuente'] ?? $fuente);
        };

        foreach ($seleccion as $c) {
            if (! empty($c['incluir'])) {
                $agregar($c, 'regla');
            }
        }
        foreach ($manuales as $m) {
            $agregar($m + ['tipo' => 'texto'], 'manual');
        }
        if (! $campos) {
            throw new ExpedienteException('Seleccione al menos un dato para dejar en blanco.');
        }

        $anterior = $this->plantillas->activaDeTipo($tipoId);
        $rutaPlantilla = 'formatos/plantillas/'.$tipo->slug.'-'.Str::random(8).'.docx';
        $this->generador->construirPlantilla(
            Storage::disk('local')->path($analisis['archivo_ruta']),
            $documento,
            $campos,
            Storage::disk('local')->path($rutaPlantilla)
        );

        $contenido = implode("\n", array_map(
            fn ($p) => MarcadorCampos::marcar($p, $campos, fn (CampoDetectado $c) => '{{'.$c->clave.'}}'),
            $documento->parrafos
        ));

        usort($campos, fn ($a, $b) => $a->tipo->prioridad() <=> $b->tipo->prioridad());
        $plantilla = $this->plantillas->crearConCampos([
            'tipo_expediente_id' => $tipoId,
            'nombre' => trim($nombre) ?: 'Formato de '.$tipo->nombre,
            'formato_origen' => $analisis['formato'],
            'archivo_original_ruta' => $analisis['archivo_ruta'],
            'archivo_original_nombre' => $analisis['archivo_nombre'],
            'archivo_plantilla_ruta' => $rutaPlantilla,
            'contenido_plantilla' => $contenido,
            'muestras_analizadas' => ($anterior?->muestras_analizadas ?? 0) + 1,
            'uso_ia' => (bool) ($analisis['uso_ia'] ?? false),
        ], array_map(fn (CampoDetectado $c) => [
            'clave' => $c->clave,
            'etiqueta' => $c->etiqueta,
            'tipo_dato' => $c->tipo->value,
            'valor_ejemplo' => $c->valor,
            'ocurrencias' => $c->ocurrencias,
            'obligatorio' => in_array($c->tipo, [TipoCampo::NumeroExpediente, TipoCampo::Nombre, TipoCampo::Fecha, TipoCampo::Juzgado], true),
            'fuente' => $c->fuente,
        ], $campos));

        Storage::disk('local')->delete("formatos/analisis/{$token}.json");

        return $plantilla;
    }

    public function descartarAnalisis(string $token): void
    {
        try {
            $a = $this->obtenerAnalisis($token);
            Storage::disk('local')->delete([$a['archivo_ruta'], 'formatos/analisis/'.basename($token).'.json']);
        } catch (ExpedienteException) {
        }
    }

    // ------------------------------------------------------------------ 5. Uso del formato

    /** Valores sugeridos a partir de los datos registrados de un expediente. */
    public function valoresDesdeExpediente(PlantillaFormato $plantilla, Expediente $e): array
    {
        $extra = collect((array) $e->datos_extra)->mapWithKeys(fn ($v, $k) => [NormalizadorTexto::slug($k) => $v]);
        $alias = [
            'monto' => ['monto', 'cuantia', 'valor', 'importe', 'pension'],
            'abogado' => ['abogado', 'abogado_director', 'licenciado', 'procurador'],
            'dpi' => ['dpi', 'cui', 'dpi_demandante'],
            'telefono' => ['telefono', 'celular'],
            'direccion' => ['direccion', 'domicilio'],
        ];
        $valores = [];
        foreach ($plantilla->campos as $campo) {
            $clave = preg_replace('/_\d+$/', '', $campo->clave);
            $desdeExtra = $extra->get($campo->clave) ?? $extra->get($clave)
                ?? collect($alias[$clave] ?? [])->map(fn ($a) => $extra->get($a))->filter()->first();
            $valor = match (true) {
                $clave === 'numero_expediente' => $e->numero_expediente,
                $clave === 'juzgado' => $e->juzgado,
                in_array($clave, ['demandante', 'solicitante', 'agraviado', 'querellante', 'denunciante'], true) => $e->demandante,
                in_array($clave, ['demandado', 'sindicado'], true) => $e->demandado,
                $campo->clave === 'fecha' && $e->fecha_inicio !== null => self::fechaEnLetras($e->fecha_inicio),
                default => $desdeExtra,
            };
            if ($valor !== null && $campo->tipo_dato === TipoCampo::Nombre) {
                // el formato ya trae "Lic." / "señor" antes del espacio: se evita "Lic. Lic. José"
                $valor = preg_replace('/^(?:lic(?:da)?\.?|licenciad[oa]|abogad[oa]|sr(?:a)?\.?|señor(?:a)?|dr(?:a)?\.?)\s+/iu', '', trim((string) $valor));
            }
            $valores[$campo->clave] = $valor;
        }

        return array_filter($valores, fn ($v) => $v !== null && $v !== '');
    }

    /** La IA lee el expediente y propone los valores de cada espacio en blanco (solo si constan). */
    public function sugerirConIa(PlantillaFormato $plantilla, Expediente $e): array
    {
        $campos = $plantilla->campos->map(fn ($c) => "- {$c->clave}: {$c->etiqueta} (tipo {$c->tipo_dato->value}, ejemplo: \"".Str::limit((string) $c->valor_ejemplo, 60).'")')->implode("\n");
        $contexto = $this->recorte($e->ficha()."\n\n".($e->contenido_texto ?? ''), min(10000, $this->llm->caracteresContextoMaximo()));

        $json = $this->llm->chatJson([
            ['role' => 'system', 'content' => 'Completas formularios judiciales con datos de un expediente. Respondes solo con JSON.'],
            ['role' => 'user', 'content' => "Campos a completar:\n{$campos}\n\nExpediente:\n\"\"\"\n{$contexto}\n\"\"\"\n\n"
                .'Devuelve un JSON {"clave": "valor"} con un valor para cada clave usando SOLO datos del expediente. '
                .'Si un dato no consta, deja la cadena vacía "". No inventes.'],
        ], ['num_predict' => 900]);

        $validas = $plantilla->campos->pluck('clave')->all();

        return collect($json)->only($validas)->map(fn ($v) => is_scalar($v) ? trim((string) $v) : '')->filter()->all();
    }

    /** @return string ruta relativa del DOCX generado */
    public function generar(PlantillaFormato $plantilla, array $valores, ?int $expedienteId = null, bool $enBlanco = false): string
    {
        $claves = $plantilla->campos->pluck('clave')->all();
        $valores = $enBlanco ? [] : collect($valores)->only($claves)->map(fn ($v) => trim((string) $v))->all();
        $nombre = NormalizadorTexto::slug($plantilla->nombre, '-') ?: 'documento';
        $ruta = 'formatos/generados/'.$nombre.($enBlanco ? '-en-blanco' : '').'-'.now()->format('Ymd-His').'-'.Str::random(4).'.docx';

        $this->generador->generar(Storage::disk('local')->path($plantilla->archivo_plantilla_ruta), $valores, Storage::disk('local')->path($ruta));
        if (! $enBlanco) {
            $this->plantillas->registrarDocumentoGenerado($plantilla, $valores, $ruta, $expedienteId);
        }

        return $ruta;
    }

    /** Texto con los valores aplicados; los espacios vacíos se muestran como líneas. */
    public static function vistaPrevia(PlantillaFormato $plantilla, array $valores = []): string
    {
        return MarcadorCampos::aplicarValores($plantilla->contenido_plantilla, $valores);
    }

    public function actualizarCampos(PlantillaFormato $plantilla, array $datos): void
    {
        foreach ($plantilla->campos as $campo) {
            if (! isset($datos[$campo->id])) {
                continue;
            }
            $d = $datos[$campo->id];
            $campo->update([
                'etiqueta' => Str::limit(trim((string) ($d['etiqueta'] ?? $campo->etiqueta)) ?: $campo->etiqueta, 140, ''),
                'tipo_dato' => TipoCampo::desdeTexto($d['tipo_dato'] ?? $campo->tipo_dato->value)->value,
                'obligatorio' => ! empty($d['obligatorio']),
                'orden' => (int) ($d['orden'] ?? $campo->orden),
            ]);
        }
    }

    public function activar(PlantillaFormato $plantilla): void
    {
        $this->plantillas->activar($plantilla);
    }

    public function eliminar(PlantillaFormato $plantilla): void
    {
        $archivos = array_filter([$plantilla->archivo_plantilla_ruta, $plantilla->archivo_original_ruta]);
        $generados = $plantilla->documentos()->pluck('archivo_ruta')->all();
        $this->plantillas->eliminar($plantilla);
        Storage::disk('local')->delete([...$archivos, ...$generados]);
    }

    public static function fechaEnLetras(\DateTimeInterface $fecha): string
    {
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return (int) $fecha->format('j').' de '.$meses[(int) $fecha->format('n')].' de '.$fecha->format('Y');
    }
}
