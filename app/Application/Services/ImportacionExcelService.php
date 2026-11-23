<?php

namespace App\Application\Services;

use App\Domain\Contracts\Repositories\ExpedienteRepositoryInterface;
use App\Domain\Contracts\Repositories\ImportacionRepositoryInterface;
use App\Domain\Contracts\Repositories\TipoExpedienteRepositoryInterface;
use App\Domain\Contracts\Servicios\LectorHojaCalculoInterface;
use App\Domain\Enums\EstadoExpediente;
use App\Domain\Enums\OrigenExpediente;
use App\Domain\Exceptions\ExpedienteException;
use App\Domain\Services\NormalizadorTexto;
use App\Infrastructure\Persistence\Models\ImportacionExcel;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportacionExcelService
{
    /** Campo del sistema => etiqueta */
    public const CAMPOS = [
        'numero_expediente' => 'Número de expediente',
        'titulo' => 'Título / Asunto',
        'tipo' => 'Tipo de expediente',
        'juzgado' => 'Juzgado / Tribunal',
        'demandante' => 'Demandante / Actor / Agraviado',
        'demandado' => 'Demandado / Sindicado',
        'fecha_inicio' => 'Fecha de inicio',
        'estado' => 'Estado',
        'descripcion' => 'Descripción / Resumen / Hechos',
        'contenido_texto' => 'Contenido completo',
    ];

    /** Sinónimos frecuentes en los Excel de juzgados (normalizados, sin tildes). */
    private const SINONIMOS = [
        'numero_expediente' => ['numero expediente', 'no expediente', 'no de causa', 'nro de causa', 'num de causa', 'no de proceso', 'no proceso', 'no de caso', 'numero de caso', 'no de carpeta', 'no de juicio', 'nro expediente', 'expediente no', 'causa no', 'no exp', 'exp no', 'no de expediente', 'num expediente', 'nro expediente', 'expediente', 'numero de expediente', 'numero de causa', 'no causa', 'causa', 'numero de proceso', 'proceso no', 'carpeta judicial', 'caso', 'correlativo', 'numero', 'no', 'exp'],
        'titulo' => ['titulo', 'asunto', 'caratula', 'nombre del proceso', 'nombre', 'juicio', 'objeto', 'referencia'],
        'tipo' => ['tipo', 'tipo expediente', 'tipo de expediente', 'tipo de proceso', 'tipo proceso', 'tipo de juicio', 'tipo juicio', 'clase', 'clase de juicio', 'clase de proceso', 'categoria', 'delito', 'tipo de caso', 'materia'],
        'juzgado' => ['juzgado', 'tribunal', 'organo jurisdiccional', 'sede', 'dependencia', 'sala', 'oficina', 'judicatura', 'despacho'],
        'demandante' => ['demandante', 'actor', 'actora', 'parte actora', 'agraviado', 'agraviada', 'victima', 'solicitante', 'denunciante', 'querellante', 'accionante', 'ofendido', 'ejecutante'],
        'demandado' => ['demandado', 'demandada', 'parte demandada', 'sindicado', 'sindicada', 'imputado', 'procesado', 'acusado', 'ejecutado', 'requerido'],
        'fecha_inicio' => ['fecha', 'fecha inicio', 'fecha de inicio', 'fecha ingreso', 'fecha de ingreso', 'fecha presentacion', 'fecha de presentacion', 'fecha recepcion', 'fecha de recepcion', 'inicio', 'ingreso', 'fecha de la demanda'],
        'estado' => ['estado', 'situacion', 'estatus', 'status', 'etapa', 'fase', 'estado actual', 'estado procesal'],
        'descripcion' => ['descripcion', 'resumen', 'hechos', 'observaciones', 'observacion', 'detalle', 'motivo', 'notas', 'comentario', 'comentarios', 'sintesis'],
        'contenido_texto' => ['contenido', 'texto', 'texto completo', 'contenido completo', 'cuerpo'],
    ];

    /** Palabras demasiado genéricas para coincidencias parciales ("Estado civil" no es el estado del expediente). */
    private const GENERICOS = ['numero', 'nombre', 'fecha', 'tipo', 'clase', 'caso', 'sede', 'sala', 'inicio', 'ingreso', 'texto', 'estado', 'objeto', 'oficina', 'notas', 'materia', 'delito', 'referencia', 'juicio', 'cuerpo', 'contenido', 'actor', 'causa', 'etapa', 'fase'];

    public function __construct(
        private readonly LectorHojaCalculoInterface $lector,
        private readonly ImportacionRepositoryInterface $importaciones,
        private readonly ExpedienteRepositoryInterface $expedientes,
        private readonly TipoExpedienteRepositoryInterface $tipos,
        private readonly AprendizajeTiposService $aprendizaje,
    ) {}

    /** Guarda el archivo, lee una muestra y propone el mapeo de columnas. */
    public function previsualizar(UploadedFile $archivo, ?string $hoja = null): array
    {
        $ruta = $archivo->storeAs('importaciones', now()->format('Ymd_His').'_'.Str::random(6).'.'.strtolower($archivo->getClientOriginalExtension()), 'local');
        $absoluta = Storage::disk('local')->path($ruta);

        $datos = $this->lector->leer($absoluta, $hoja, 15);
        if ($datos['total'] === 0) {
            Storage::disk('local')->delete($ruta);
            throw new ExpedienteException('El archivo no contiene filas de datos debajo de los encabezados.');
        }

        $importacion = $this->importaciones->crear([
            'archivo_nombre' => $archivo->getClientOriginalName(),
            'archivo_ruta' => $ruta,
            'hoja' => $datos['hoja'],
            'encabezados' => $datos['encabezados'],
            'mapeo' => $this->sugerirMapeo($datos['encabezados']),
            'total_filas' => $datos['total'],
            'estado' => 'pendiente',
        ]);

        return $this->datosPrevisualizacion($importacion, $datos);
    }

    public function datosPrevisualizacion(ImportacionExcel $importacion, ?array $datos = null): array
    {
        $datos ??= $this->lector->leer(Storage::disk('local')->path($importacion->archivo_ruta), $importacion->hoja, 15);

        return [
            'importacion' => $importacion,
            'encabezados' => $datos['encabezados'],
            'muestra' => $datos['filas'],
            'total' => $datos['total'],
            'mapeo' => $importacion->mapeo ?? [],
            'hojas' => rescue(fn () => $this->lector->hojas(Storage::disk('local')->path($importacion->archivo_ruta)), [], false),
        ];
    }

    /** @return array<string, string> campo => encabezado */
    public function sugerirMapeo(array $encabezados): array
    {
        $norm = [];
        foreach ($encabezados as $h) {
            $norm[$h] = NormalizadorTexto::normalizar($h);
        }
        $mapeo = [];
        $usados = [];
        // 1) coincidencia exacta  2) el encabezado contiene el sinónimo (sinónimos largos primero)
        foreach ([true, false] as $exacta) {
            foreach (self::SINONIMOS as $campo => $sinonimos) {
                if (isset($mapeo[$campo])) {
                    continue;
                }
                usort($sinonimos, fn ($a, $b) => strlen($b) <=> strlen($a));
                foreach ($sinonimos as $s) {
                    foreach ($norm as $original => $n) {
                        if (isset($usados[$original])) {
                            continue;
                        }
                        $coincide = $exacta ? $n === $s : (strlen($s) >= 4 && ! in_array($s, self::GENERICOS, true) && preg_match('/\b'.preg_quote($s, '/').'\b/', $n));
                        if ($coincide) {
                            $mapeo[$campo] = $original;
                            $usados[$original] = true;
                            break 2;
                        }
                    }
                }
            }
        }

        return $mapeo;
    }

    /**
     * Ejecuta la importación completa.
     *
     * @param  array<string, string|null>  $mapeo  campo => encabezado
     * @param  array{duplicados?: string, tipo_defecto?: ?int, clasificar?: bool, usar_ia?: bool, crear_tipos?: bool}  $opciones
     */
    public function importar(ImportacionExcel $importacion, array $mapeo, array $opciones = []): ImportacionExcel
    {
        @set_time_limit(0);
        $mapeo = array_filter($mapeo, fn ($v) => $v !== null && $v !== '' && in_array($v, $importacion->encabezados ?? [], true));
        $opciones += ['duplicados' => 'actualizar', 'tipo_defecto' => null, 'clasificar' => true, 'usar_ia' => false, 'crear_tipos' => true];

        $datos = $this->lector->leer(Storage::disk('local')->path($importacion->archivo_ruta), $importacion->hoja);
        $columnasMapeadas = array_values($mapeo);
        $resultado = ['importadas' => 0, 'actualizadas' => 0, 'omitidas' => 0, 'errores' => []];
        $tipoDefecto = $opciones['tipo_defecto'] ? $this->tipos->buscar((int) $opciones['tipo_defecto']) : null;

        foreach (array_chunk($datos['filas'], 200) as $lote) {
            DB::transaction(function () use ($lote, $mapeo, $columnasMapeadas, $opciones, $tipoDefecto, $importacion, &$resultado) {
                foreach ($lote as $fila) {
                    try {
                        $this->procesarFila($fila, $mapeo, $columnasMapeadas, $opciones, $tipoDefecto, $importacion, $resultado);
                    } catch (\Throwable $e) {
                        $resultado['omitidas']++;
                        if (count($resultado['errores']) < 200) {
                            $resultado['errores'][] = ['fila' => $fila['__fila'] ?? '?', 'error' => $e instanceof ExpedienteException ? $e->getMessage() : 'Error inesperado: '.Str::limit($e->getMessage(), 160)];
                        }
                    }
                }
            });
        }

        // Aprendizaje: reentrena con los nuevos ejemplos y clasifica los que llegaron sin tipo
        $this->aprendizaje->entrenar();
        $clasificacion = $opciones['clasificar']
            ? $this->aprendizaje->reclasificarPendientes((bool) $opciones['usar_ia'], $importacion->id)
            : ['revisados' => 0, 'asignados' => 0];
        if ($clasificacion['asignados'] > 0) {
            $this->aprendizaje->entrenar();
        }

        return $this->importaciones->actualizar($importacion, [
            'mapeo' => $mapeo,
            'total_filas' => $datos['total'],
            'importadas' => $resultado['importadas'],
            'actualizadas' => $resultado['actualizadas'],
            'omitidas' => $resultado['omitidas'],
            'errores' => array_merge($resultado['errores'], [['fila' => '—', 'error' => "Clasificación automática: {$clasificacion['asignados']} de {$clasificacion['revisados']} expedientes sin tipo fueron clasificados.", 'info' => true]]),
            'estado' => $resultado['importadas'] + $resultado['actualizadas'] === 0 ? 'fallida' : ($resultado['errores'] ? 'con_errores' : 'completada'),
        ]);
    }

    private function procesarFila(array $fila, array $mapeo, array $columnasMapeadas, array $opciones, $tipoDefecto, ImportacionExcel $importacion, array &$resultado): void
    {
        $valor = fn (string $campo) => isset($mapeo[$campo]) ? $this->texto($fila[$mapeo[$campo]] ?? null) : null;

        $numero = $valor('numero_expediente');
        $numero = $numero !== null ? mb_substr($numero, 0, 100) : null;
        $tipoTexto = $valor('tipo');

        $tipo = null;
        $clasificadoPor = null;
        if ($tipoTexto) {
            $tipo = $opciones['crear_tipos'] ? $this->tipos->buscarOCrear($tipoTexto) : $this->tipos->buscarPorNombre($tipoTexto);
            $clasificadoPor = $tipo ? 'excel' : null;
        }
        if (! $tipo && $tipoDefecto) {
            $tipo = $tipoDefecto;
            $clasificadoPor = 'manual';
        }

        $extra = [];
        foreach ($fila as $col => $v) {
            if ($col === '__fila' || in_array($col, $columnasMapeadas, true)) {
                continue;
            }
            $t = $this->texto($v);
            if ($t !== null) {
                $extra[$col] = $t;
            }
        }

        $titulo = $valor('titulo') ?? trim(($tipo?->nombre ?? 'Expediente').' '.($numero ?? ''));
        if ($titulo === 'Expediente') {
            $titulo = 'Expediente fila '.($fila['__fila'] ?? '');
        }

        $datos = [
            'numero_expediente' => $numero,
            'titulo' => Str::limit($titulo, 250, ''),
            'tipo_expediente_id' => $tipo?->id,
            'juzgado' => $this->limite($valor('juzgado')),
            'demandante' => $this->limite($valor('demandante')),
            'demandado' => $this->limite($valor('demandado')),
            'fecha_inicio' => isset($mapeo['fecha_inicio']) ? self::parsearFecha($fila[$mapeo['fecha_inicio']] ?? null) : null,
            'estado' => EstadoExpediente::desdeTexto($valor('estado'))->value,
            'descripcion' => $valor('descripcion'),
            'contenido_texto' => $valor('contenido_texto'),
            'datos_extra' => $extra ?: null,
            'origen' => OrigenExpediente::Excel->value,
            'importacion_excel_id' => $importacion->id,
            'clasificado_por' => $clasificadoPor,
            'clasificacion_confianza' => $tipo ? 100 : null,
        ];

        $existente = $numero ? $this->expedientes->porNumero($numero) : null;
        if ($existente) {
            if ($opciones['duplicados'] === 'omitir') {
                $resultado['omitidas']++;
                $resultado['errores'][] = ['fila' => $fila['__fila'] ?? '?', 'error' => "El expediente {$numero} ya existe (omitido)."];

                return;
            }
            if ($existente->trashed()) {
                $existente->restore();
            }
            // no borrar datos existentes con celdas vacías
            $datos = array_filter($datos, fn ($v) => $v !== null);
            $datos['datos_extra'] = array_merge((array) $existente->datos_extra, $extra) ?: null;
            $this->expedientes->actualizar($existente, $datos);
            $resultado['actualizadas']++;

            return;
        }

        $this->expedientes->crear($datos);
        $resultado['importadas']++;
    }

    private function texto(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (int) $v;
        }
        $t = trim((string) $v);

        return $t === '' || in_array(mb_strtolower($t), ['n/a', 'na', '-', '--', 'null', 'ninguno'], true) ? null : $t;
    }

    private function limite(?string $v): ?string
    {
        return $v === null ? null : Str::limit($v, 250, '');
    }

    /** Acepta 2024-03-15, 15/03/2024, 15-03-24, número de serie de Excel y "15 de marzo de 2024". */
    public static function parsearFecha(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_numeric($v) && $v > 20000 && $v < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->format('Y-m-d');
        }
        $t = trim((string) $v);
        $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
        if (preg_match('/(\d{1,2})\s+de\s+([a-z]+)\s+(?:de|del)\s+(\d{4})/i', NormalizadorTexto::sinTildes(mb_strtolower($t)), $m) && isset($meses[$m[2]])) {
            return checkdate($meses[$m[2]], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $meses[$m[2]], $m[1]) : null;
        }
        if (preg_match('/^(\d{4})[\-\/.](\d{1,2})[\-\/.](\d{1,2})/', $t, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{2,4})/', $t, $m)) {
            $anio = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            [$d, $mes] = [(int) $m[1], (int) $m[2]];
            if ($mes > 12 && $d <= 12) {
                [$d, $mes] = [$mes, $d]; // formato mm/dd/aaaa
            }

            return checkdate($mes, $d, $anio) ? sprintf('%04d-%02d-%02d', $anio, $mes, $d) : null;
        }

        return null;
    }

    /** Genera un Excel de ejemplo con las columnas reconocidas. */
    public function plantillaEjemplo(): string
    {
        $ruta = Storage::disk('local')->path('exportaciones/plantilla_importacion_expedientes.xlsx');
        $this->lector->escribir($ruta, [
            'Número de expediente', 'Tipo de expediente', 'Título', 'Juzgado', 'Demandante', 'Demandado',
            'Fecha de inicio', 'Estado', 'Descripción', 'Cuantía', 'Abogado',
        ], [
            ['01043-2024-00321', 'Juicio Ordinario Civil', 'Daños y perjuicios', 'Juzgado Primero de Primera Instancia Civil', 'Carlos Méndez López', 'María Ruiz Paz', '2024-03-15', 'En trámite', 'Reclamo por incumplimiento de contrato de arrendamiento', 'Q.5,000.00', 'Lic. José Pérez'],
            ['C-01077-2024-00045', 'Pensión Alimenticia', 'Fijación de pensión alimenticia', 'Juzgado Segundo de Familia', 'Ana Gómez', 'Luis Torres', '2024-04-02', 'En audiencia', 'Solicitud de pensión para dos menores', 'Q.1,500.00', 'Licda. Rosa Díaz'],
        ], 'Expedientes');

        return $ruta;
    }

    public function historial(): \Illuminate\Support\Collection
    {
        return $this->importaciones->recientes();
    }

    public function obtener(int $id): ImportacionExcel
    {
        return $this->importaciones->obtener($id);
    }
}
