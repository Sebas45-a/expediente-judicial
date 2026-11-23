<?php

namespace App\Http\Controllers;

use App\Application\Services\AprendizajeTiposService;
use App\Application\Services\TipoExpedienteService;
use App\Domain\Contracts\Repositories\PlantillaRepositoryInterface;
use App\Infrastructure\Persistence\Models\TipoExpediente;
use Illuminate\Http\Request;

class TipoExpedienteController extends Controller
{
    public function __construct(
        private readonly TipoExpedienteService $tipos,
        private readonly AprendizajeTiposService $aprendizaje,
    ) {}

    public function index()
    {
        return view('tipos.index', ['tipos' => $this->tipos->listar()]);
    }

    public function show(TipoExpediente $tipo, PlantillaRepositoryInterface $plantillas)
    {
        return view('tipos.show', [
            'tipo' => $tipo->loadCount('expedientes'),
            'ejemplos' => $tipo->expedientes()->latest()->limit(10)->get(),
            'plantillas' => $plantillas->todas($tipo->id),
            'otros' => $this->tipos->todos()->where('id', '!=', $tipo->id),
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate($this->reglas());
        $tipo = $this->tipos->crear($datos);

        return redirect()->route('tipos.show', $tipo)->with('ok', 'Tipo de expediente creado.');
    }

    public function update(Request $request, TipoExpediente $tipo)
    {
        $this->tipos->actualizar($tipo, $request->validate($this->reglas()));

        return back()->with('ok', 'Tipo actualizado.');
    }

    public function destroy(TipoExpediente $tipo)
    {
        $this->tipos->eliminar($tipo);

        return redirect()->route('tipos.index')->with('ok', 'Tipo eliminado. Sus expedientes quedaron sin clasificar.');
    }

    public function fusionar(Request $request, TipoExpediente $tipo)
    {
        $request->validate(['destino_id' => ['required', 'integer', 'exists:tipos_expediente,id']]);
        $destino = $this->tipos->fusionar($tipo, (int) $request->input('destino_id'));

        return redirect()->route('tipos.show', $destino)->with('ok', "Se fusionó \"{$tipo->nombre}\" dentro de \"{$destino->nombre}\".");
    }

    public function entrenar()
    {
        $r = $this->aprendizaje->entrenar();
        $precision = $r['precision'] !== null ? " Precisión estimada: {$r['precision']}%." : ' (Se necesitan al menos 6 ejemplos de 2 tipos para estimar precisión.)';

        return back()->with('ok', "Aprendizaje actualizado con {$r['muestras']} expedientes de {$r['tipos']} tipos.{$precision}");
    }

    public function reclasificar(Request $request)
    {
        $r = $this->aprendizaje->reclasificarPendientes($request->boolean('usar_ia'));

        return back()->with('ok', "Se revisaron {$r['revisados']} expedientes sin tipo y se clasificaron {$r['asignados']}.");
    }

    public function probar(Request $request)
    {
        $request->validate(['texto' => ['required', 'string', 'min:5', 'max:20000']]);
        $r = $this->aprendizaje->probar($request->input('texto'), $request->boolean('usar_ia'));

        return $request->expectsJson() ? response()->json(['ok' => true] + $r) : back()->withInput()->with('prueba', $r);
    }

    private function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'materia' => ['nullable', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
