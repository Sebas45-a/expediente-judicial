<?php

namespace App\Http\Controllers;

use App\Application\Services\ImportacionExcelService;
use App\Application\Services\TipoExpedienteService;
use App\Http\Requests\ArchivoExcelRequest;
use App\Infrastructure\Persistence\Models\ImportacionExcel;
use Illuminate\Http\Request;

class ImportacionController extends Controller
{
    public function __construct(private readonly ImportacionExcelService $servicio) {}

    public function index()
    {
        return view('importar.index', ['historial' => $this->servicio->historial(), 'campos' => ImportacionExcelService::CAMPOS]);
    }

    public function plantilla()
    {
        return response()->download($this->servicio->plantillaEjemplo(), 'plantilla_importacion_expedientes.xlsx');
    }

    public function previsualizar(ArchivoExcelRequest $request)
    {
        $datos = $this->servicio->previsualizar($request->file('archivo'), $request->input('hoja'));

        return redirect()->route('importar.mapeo', $datos['importacion']);
    }

    public function mapeo(ImportacionExcel $importacion, TipoExpedienteService $tipos)
    {
        if ($importacion->estado !== 'pendiente') {
            return redirect()->route('importar.show', $importacion);
        }

        return view('importar.mapeo', $this->servicio->datosPrevisualizacion($importacion) + [
            'campos' => ImportacionExcelService::CAMPOS,
            'tipos' => $tipos->todos(),
        ]);
    }

    public function ejecutar(Request $request, ImportacionExcel $importacion)
    {
        $request->validate([
            'mapeo' => ['array'],
            'duplicados' => ['required', 'in:actualizar,omitir'],
            'tipo_defecto' => ['nullable', 'integer', 'exists:tipos_expediente,id'],
        ]);
        $mapeo = array_filter((array) $request->input('mapeo', []));
        if (! isset($mapeo['numero_expediente']) && ! isset($mapeo['titulo']) && ! isset($mapeo['descripcion'])) {
            return back()->withInput()->with('error', 'Asigne al menos la columna del número de expediente, del título o de la descripción.');
        }

        $resultado = $this->servicio->importar($importacion, $mapeo, [
            'duplicados' => $request->input('duplicados'),
            'tipo_defecto' => $request->input('tipo_defecto'),
            'clasificar' => $request->boolean('clasificar'),
            'usar_ia' => $request->boolean('usar_ia'),
            'crear_tipos' => $request->boolean('crear_tipos'),
        ]);

        return redirect()->route('importar.show', $resultado)->with('ok', "Importación terminada: {$resultado->importadas} nuevos, {$resultado->actualizadas} actualizados, {$resultado->omitidas} omitidos.");
    }

    public function show(ImportacionExcel $importacion)
    {
        $importacion->loadCount('expedientes');
        $porTipo = $importacion->expedientes()->with('tipo')->get()->groupBy(fn ($e) => $e->tipo?->nombre ?? 'Sin clasificar')->map->count()->sortDesc();

        return view('importar.show', ['imp' => $importacion, 'porTipo' => $porTipo]);
    }
}
