<?php

namespace App\Application\Services;

use App\Domain\Contracts\Repositories\ExpedienteRepositoryInterface;
use App\Domain\Contracts\Repositories\TipoExpedienteRepositoryInterface;
use App\Domain\Contracts\Servicios\ExtractorDocumentoInterface;
use App\Domain\Contracts\Servicios\LectorHojaCalculoInterface;
use App\Domain\Enums\OrigenExpediente;
use App\Domain\Services\DetectorCamposImportantes;
use App\Infrastructure\Persistence\Models\Expediente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExpedienteService
{
    public function __construct(
        private readonly ExpedienteRepositoryInterface $expedientes,
        private readonly TipoExpedienteRepositoryInterface $tipos,
        private readonly ExtractorDocumentoInterface $extractor,
        private readonly LectorHojaCalculoInterface $hojas,
        private readonly AprendizajeTiposService $aprendizaje,
        private readonly DetectorCamposImportantes $detector,
    ) {}

    public function listar(array $filtros): LengthAwarePaginator
    {
        return $this->expedientes->paginar($filtros);
    }

    public function obtener(int $id): Expediente
    {
        return $this->expedientes->obtener($id);
    }

    /**
     * @param  array  $datos  validados por el FormRequest
     */
    public function crear(array $datos, ?UploadedFile $archivo = null, bool $autoClasificar = true, bool $usarIa = false): Expediente
    {
        $datos = $this->prepararTipo($datos);
        $datos['origen'] ??= OrigenExpediente::Manual->value;

        if ($archivo) {
            $datos = array_merge($datos, $this->procesarArchivo($archivo));
            $datos['origen'] = OrigenExpediente::Documento->value;
            $datos = $this->completarDesdeDocumento($datos);
        }

        if (empty($datos['tipo_expediente_id']) && $autoClasificar) {
            $texto = implode("\n", array_filter([$datos['titulo'] ?? '', $datos['descripcion'] ?? '', $datos['juzgado'] ?? '', $datos['contenido_texto'] ?? '']));
            $r = $this->aprendizaje->clasificar($texto, $usarIa);
            if ($r->esConfiable((float) config('ollama.umbral_clasificacion', 0.6))) {
                $datos['tipo_expediente_id'] = $r->tipoId;
                $datos['clasificado_por'] = $r->metodo;
                $datos['clasificacion_confianza'] = $r->porcentaje();
            }
        }

        $expediente = $this->expedientes->crear($datos);
        if (($datos['clasificado_por'] ?? null) === 'manual') {
            $this->aprendizaje->marcarDesactualizado();
        }

        return $expediente;
    }

    public function actualizar(Expediente $expediente, array $datos, ?UploadedFile $archivo = null): Expediente
    {
        $tipoAnterior = $expediente->tipo_expediente_id;
        $datos = $this->prepararTipo($datos);
        if ($archivo) {
            $this->borrarArchivo($expediente);
            $datos = array_merge($datos, $this->procesarArchivo($archivo));
        }
        $datos['indexado_en'] = null; // obliga a reindexar para la IA
        $expediente = $this->expedientes->actualizar($expediente, $datos);
        if ($tipoAnterior !== $expediente->tipo_expediente_id || $archivo) {
            $this->aprendizaje->marcarDesactualizado();
        }

        return $expediente;
    }

    public function eliminar(Expediente $expediente): void
    {
        $this->borrarArchivo($expediente);
        $this->expedientes->eliminar($expediente);
        $this->aprendizaje->marcarDesactualizado();
    }

    /** Un tipo elegido a mano es una lección para el aprendizaje. */
    private function prepararTipo(array $datos): array
    {
        if (! empty($datos['tipo_nuevo'])) {
            $datos['tipo_expediente_id'] = $this->tipos->buscarOCrear($datos['tipo_nuevo'])->id;
        }
        unset($datos['tipo_nuevo']);
        if (! empty($datos['tipo_expediente_id'])) {
            $datos['clasificado_por'] = 'manual';
            $datos['clasificacion_confianza'] = 100;
        }

        return $datos;
    }

    private function procesarArchivo(UploadedFile $archivo): array
    {
        $ext = strtolower($archivo->getClientOriginalExtension());
        $ruta = $archivo->storeAs('expedientes', now()->format('Y/m/').Str::uuid().'.'.$ext, 'local');
        $doc = $this->extractor->extraer(Storage::disk('local')->path($ruta), $archivo->getClientOriginalName());

        return [
            'archivo_ruta' => $ruta,
            'archivo_nombre' => $archivo->getClientOriginalName(),
            'formato_archivo' => $doc->formato->value,
            'contenido_texto' => $doc->texto(),
        ];
    }

    /** Si el usuario no escribió número, juzgado o partes, se toman del documento. */
    private function completarDesdeDocumento(array $datos): array
    {
        $campos = collect($this->detector->detectar((string) ($datos['contenido_texto'] ?? '')));
        $primero = fn (array $claves) => $campos->first(fn ($c) => in_array($c->clave, $claves, true))?->valor;

        $datos['numero_expediente'] = ($datos['numero_expediente'] ?? null) ?: $primero(['numero_expediente']);
        if ($datos['numero_expediente'] && $this->expedientes->porNumero($datos['numero_expediente'])) {
            $datos['numero_expediente'] = null; // ya existe: no se duplica
        }
        $datos['juzgado'] = ($datos['juzgado'] ?? null) ?: $primero(['juzgado']);
        $datos['demandante'] = ($datos['demandante'] ?? null) ?: $primero(['demandante', 'solicitante', 'agraviado', 'querellante', 'denunciante']);
        $datos['demandado'] = ($datos['demandado'] ?? null) ?: $primero(['demandado', 'sindicado']);

        return $datos;
    }

    private function borrarArchivo(Expediente $e): void
    {
        if ($e->archivo_ruta) {
            Storage::disk('local')->delete($e->archivo_ruta);
        }
    }

    public function exportarExcel(array $filtros): string
    {
        $lista = $this->expedientes->todosParaExportar($filtros);
        $extras = $lista->flatMap(fn ($e) => array_keys((array) $e->datos_extra))->unique()->values()->all();
        $encabezados = ['Número de expediente', 'Tipo de expediente', 'Título', 'Juzgado', 'Demandante', 'Demandado', 'Fecha de inicio', 'Estado', 'Descripción', 'Origen', ...$extras];
        $filas = $lista->map(fn (Expediente $e) => [
            $e->numero_expediente, $e->tipo?->nombre, $e->titulo, $e->juzgado, $e->demandante, $e->demandado,
            $e->fecha_inicio?->format('Y-m-d'), $e->estado?->etiqueta(), $e->descripcion, $e->origen?->etiqueta(),
            ...array_map(fn ($k) => $e->datos_extra[$k] ?? null, $extras),
        ])->all();
        $ruta = Storage::disk('local')->path('exportaciones/expedientes_'.now()->format('Ymd_His').'.xlsx');
        $this->hojas->escribir($ruta, $encabezados, $filas, 'Expedientes');

        return $ruta;
    }

    public function rutaArchivo(Expediente $e): ?string
    {
        return $e->archivo_ruta && Storage::disk('local')->exists($e->archivo_ruta) ? Storage::disk('local')->path($e->archivo_ruta) : null;
    }
}
