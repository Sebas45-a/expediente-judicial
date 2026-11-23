<?php

namespace App\Http\Controllers;

use App\Application\Services\AsistenteIaService;
use App\Domain\Contracts\Repositories\ExpedienteRepositoryInterface;
use App\Domain\ValueObjects\RespuestaIa;
use App\Http\Requests\PreguntaIaRequest;
use App\Infrastructure\Persistence\Models\Expediente;
use Illuminate\Http\Request;

class AsistenteIaController extends Controller
{
    public function __construct(private readonly AsistenteIaService $ia) {}

    public function index(Request $request, ExpedienteRepositoryInterface $expedientes)
    {
        $id = $request->integer('expediente') ?: null;

        return view('asistente.index', [
            'estado' => $this->ia->estado($request->boolean('forzar')),
            'expediente' => $id ? $expedientes->buscar($id) : null,
            'expedientes' => $expedientes->recientes(200),
            'historial' => $this->ia->historial($id),
        ]);
    }

    public function estado(Request $request)
    {
        return response()->json($this->ia->estado($request->boolean('forzar')));
    }

    public function preguntar(PreguntaIaRequest $request)
    {
        @set_time_limit(0);

        return $this->json($this->ia->preguntar($request->input('pregunta'), $request->integer('expediente_id') ?: null));
    }

    public function resumir(Expediente $expediente)
    {
        @set_time_limit(0);

        return $this->json($this->ia->resumir($expediente->id));
    }

    public function probar()
    {
        return $this->json($this->ia->probar());
    }

    public function modelo(Request $request)
    {
        $request->validate(['modelo' => ['required', 'string', 'max:150']]);
        $this->ia->cambiarModelo($request->input('modelo'));

        return back()->with('ok', 'Modelo cambiado a '.$request->input('modelo').'.');
    }

    private function json(RespuestaIa $r)
    {
        return response()->json([
            'ok' => $r->exito,
            'respuesta' => $r->texto,
            'error' => $r->error,
            'modelo' => $r->modelo,
            'segundos' => round($r->duracionMs / 1000, 1),
            'fragmentos' => $r->fragmentos,
        ], $r->exito ? 200 : 503);
    }
}
