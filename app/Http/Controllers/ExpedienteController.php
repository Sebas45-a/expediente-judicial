<?php

namespace App\Http\Controllers;

use App\Application\Services\AsistenteIaService;
use App\Application\Services\ExpedienteService;
use App\Application\Services\TipoExpedienteService;
use App\Domain\Contracts\Repositories\PlantillaRepositoryInterface;
use App\Domain\Enums\EstadoExpediente;
use App\Http\Requests\ExpedienteRequest;
use App\Infrastructure\Persistence\Models\Expediente;
use Illuminate\Http\Request;

class ExpedienteController extends Controller
{
    public function __construct(
        private readonly ExpedienteService $servicio,
        private readonly TipoExpedienteService $tipos,
    ) {}

    public function index(Request $request)
    {
        $filtros = $request->only(['buscar', 'tipo', 'estado', 'origen', 'importacion', 'desde', 'hasta', 'orden', 'dir']);

        return view('expedientes.index', [
            'expedientes' => $this->servicio->listar($filtros),
            'tipos' => $this->tipos->todos(),
            'estados' => EstadoExpediente::opciones(),
            'filtros' => $filtros,
        ]);
    }

    public function create()
    {
        return view('expedientes.form', ['expediente' => new Expediente(['estado' => EstadoExpediente::EnTramite]), 'tipos' => $this->tipos->todos(), 'estados' => EstadoExpediente::opciones()]);
    }

    public function store(ExpedienteRequest $request)
    {
        $e = $this->servicio->crear($request->datos(), $request->file('archivo'), true, $request->boolean('usar_ia'));
        $msg = 'Expediente registrado.';
        if ($e->clasificado_por && $e->clasificado_por !== 'manual') {
            $msg .= " Tipo asignado automáticamente: {$e->tipo?->nombre} ({$e->clasificacion_confianza}% de confianza).";
        }

        return redirect()->route('expedientes.show', $e)->with('ok', $msg);
    }

    public function show(Expediente $expediente, AsistenteIaService $ia, PlantillaRepositoryInterface $plantillas)
    {
        $expediente->load('tipo', 'importacion');

        return view('expedientes.show', [
            'e' => $expediente,
            'historial' => $ia->historial($expediente->id),
            'formatos' => $expediente->tipo_expediente_id ? $plantillas->todas($expediente->tipo_expediente_id) : collect(),
        ]);
    }

    public function edit(Expediente $expediente)
    {
        return view('expedientes.form', ['expediente' => $expediente, 'tipos' => $this->tipos->todos(), 'estados' => EstadoExpediente::opciones()]);
    }

    public function update(ExpedienteRequest $request, Expediente $expediente)
    {
        $this->servicio->actualizar($expediente, $request->datos(), $request->file('archivo'));

        return redirect()->route('expedientes.show', $expediente)->with('ok', 'Expediente actualizado.');
    }

    public function destroy(Expediente $expediente)
    {
        $this->servicio->eliminar($expediente);

        return redirect()->route('expedientes.index')->with('ok', 'Expediente eliminado.');
    }

    public function archivo(Expediente $expediente)
    {
        $ruta = $this->servicio->rutaArchivo($expediente);
        abort_unless($ruta, 404, 'El expediente no tiene archivo adjunto.');

        return response()->download($ruta, $expediente->archivo_nombre);
    }

    public function exportar(Request $request)
    {
        $ruta = $this->servicio->exportarExcel($request->only(['buscar', 'tipo', 'estado', 'origen', 'importacion', 'desde', 'hasta']));

        return response()->download($ruta, basename($ruta))->deleteFileAfterSend();
    }

    public function indexar(Expediente $expediente, AsistenteIaService $ia)
    {
        $r = $ia->indexar($expediente);

        return back()->with('ok', "Expediente indexado para la IA: {$r['fragmentos']} fragmentos ".($r['semantico'] ? '(búsqueda semántica activada).' : '(búsqueda por palabras clave).'));
    }
}
