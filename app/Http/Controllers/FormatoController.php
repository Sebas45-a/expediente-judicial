<?php

namespace App\Http\Controllers;

use App\Application\Services\AprendizajeFormatoService;
use App\Application\Services\ExpedienteService;
use App\Application\Services\TipoExpedienteService;
use App\Domain\Contracts\Repositories\PlantillaRepositoryInterface;
use App\Domain\Enums\TipoCampo;
use App\Domain\Services\MarcadorCampos;
use App\Domain\ValueObjects\CampoDetectado;
use App\Http\Requests\AnalizarFormatoRequest;
use App\Infrastructure\Persistence\Models\PlantillaFormato;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FormatoController extends Controller
{
    public function __construct(
        private readonly AprendizajeFormatoService $servicio,
        private readonly PlantillaRepositoryInterface $plantillas,
        private readonly TipoExpedienteService $tipos,
    ) {}

    public function index(Request $request)
    {
        return view('formatos.index', [
            'plantillas' => $this->plantillas->todas($request->integer('tipo') ?: null),
            'tipos' => $this->tipos->todos(),
            'tipoFiltro' => $request->integer('tipo') ?: null,
        ]);
    }

    public function create(Request $request)
    {
        return view('formatos.create', ['tipos' => $this->tipos->todos(), 'tipoId' => $request->integer('tipo') ?: null]);
    }

    public function analizar(AnalizarFormatoRequest $request)
    {
        $a = $this->servicio->analizar(
            $request->file('documento'),
            $request->integer('tipo_expediente_id') ?: null,
            $request->input('tipo_nuevo') ?: null,
            $request->boolean('usar_ia'),
        );

        return redirect()->route('formatos.revisar', $a['token']);
    }

    public function revisar(string $token)
    {
        $a = $this->servicio->obtenerAnalisis($token);

        return view('formatos.revisar', [
            'a' => $a,
            'tipos' => $this->tipos->todos(),
            'tiposCampo' => TipoCampo::opciones(),
            'vistaHtml' => self::resaltar($a['parrafos'], $a['campos']),
        ]);
    }

    /** HTML del documento con cada dato detectado resaltado (data-campo = índice). */
    private static function resaltar(array $parrafos, array $campos): string
    {
        $objetos = array_map(fn ($c) => CampoDetectado::desdeArray($c), $campos);
        $indice = [];
        foreach ($objetos as $i => $c) {
            $indice[$c->clave] = $i;
        }
        $html = [];
        foreach ($parrafos as $p) {
            $marcado = MarcadorCampos::marcar($p, $objetos, fn (CampoDetectado $c) => "\u{E100}{$indice[$c->clave]}\u{E101}");
            $seguro = e($marcado);
            $seguro = preg_replace_callback('/\x{E100}(\d+)\x{E101}/u', fn ($m) => '<mark class="marca-campo" data-campo="'.$m[1].'">'.e($objetos[(int) $m[1]]->valor).'</mark>', $seguro);
            $html[] = $seguro;
        }

        return implode("\n", $html);
    }

    public function confirmar(Request $request, string $token)
    {
        $request->validate([
            'tipo_expediente_id' => ['required', 'integer', 'exists:tipos_expediente,id'],
            'nombre' => ['nullable', 'string', 'max:200'],
            'campos' => ['array'],
            'manuales' => ['array'],
        ], ['tipo_expediente_id.required' => 'Seleccione el tipo de expediente al que pertenece este formato.']);

        $manuales = collect($request->input('manuales', []))->filter(fn ($m) => trim($m['valor'] ?? '') !== '')->values()->all();
        $plantilla = $this->servicio->confirmar($token, (int) $request->input('tipo_expediente_id'), (string) $request->input('nombre'), $request->input('campos', []), $manuales);

        return redirect()->route('formatos.show', $plantilla)->with('ok', "Formato aprendido con {$plantilla->campos->count()} espacios en blanco (versión {$plantilla->version}).");
    }

    public function descartar(string $token)
    {
        $this->servicio->descartarAnalisis($token);

        return redirect()->route('formatos.index')->with('ok', 'Análisis descartado.');
    }

    public function show(PlantillaFormato $plantilla)
    {
        $plantilla->load('tipo', 'campos')->loadCount('documentos');

        return view('formatos.show', ['p' => $plantilla, 'tiposCampo' => TipoCampo::opciones(), 'vista' => AprendizajeFormatoService::vistaPrevia($plantilla)]);
    }

    public function actualizarCampos(Request $request, PlantillaFormato $plantilla)
    {
        $this->servicio->actualizarCampos($plantilla, (array) $request->input('campos', []));

        return back()->with('ok', 'Campos actualizados.');
    }

    public function activar(PlantillaFormato $plantilla)
    {
        $this->servicio->activar($plantilla);

        return back()->with('ok', 'Esta versión ahora es el formato activo del tipo.');
    }

    public function usar(Request $request, PlantillaFormato $plantilla, ExpedienteService $expedientes)
    {
        $plantilla->load('campos', 'tipo');
        $expediente = $request->integer('expediente') ? $expedientes->obtener($request->integer('expediente')) : null;
        $valores = $expediente ? $this->servicio->valoresDesdeExpediente($plantilla, $expediente) : [];

        return view('formatos.usar', [
            'p' => $plantilla,
            'expediente' => $expediente,
            'valores' => array_merge($valores, (array) $request->old('valores', [])),
            'vista' => AprendizajeFormatoService::vistaPrevia($plantilla, $valores),
            'expedientesTipo' => $plantilla->tipo?->expedientes()->latest()->limit(50)->get(['id', 'numero_expediente', 'titulo']) ?? collect(),
        ]);
    }

    public function generar(Request $request, PlantillaFormato $plantilla)
    {
        $plantilla->load('campos');
        $valores = (array) $request->input('valores', []);
        $faltan = $plantilla->campos->filter(fn ($c) => $c->obligatorio && trim((string) ($valores[$c->clave] ?? '')) === '' && ! $request->boolean('permitir_vacios'));
        if ($faltan->isNotEmpty()) {
            return back()->withInput()->with('error', 'Complete los campos obligatorios: '.$faltan->pluck('etiqueta')->implode(', ').'. (O marque "Permitir espacios vacíos").');
        }
        $ruta = $this->servicio->generar($plantilla, $valores, $request->integer('expediente_id') ?: null);

        return response()->download(Storage::disk('local')->path($ruta), basename($ruta));
    }

    public function sugerir(Request $request, PlantillaFormato $plantilla, ExpedienteService $expedientes)
    {
        $request->validate(['expediente_id' => ['required', 'integer', 'exists:expedientes,id']]);
        $plantilla->load('campos');
        $e = $expedientes->obtener((int) $request->input('expediente_id'));
        $base = $this->servicio->valoresDesdeExpediente($plantilla, $e);
        $ia = $this->servicio->sugerirConIa($plantilla, $e);

        return response()->json(['ok' => true, 'valores' => array_merge($ia, $base)]);
    }

    public function enBlanco(PlantillaFormato $plantilla)
    {
        $plantilla->load('campos');
        $ruta = $this->servicio->generar($plantilla, [], null, true);

        return response()->download(Storage::disk('local')->path($ruta), basename($ruta))->deleteFileAfterSend();
    }

    public function descargar(PlantillaFormato $plantilla, string $cual)
    {
        $ruta = $cual === 'original' ? $plantilla->archivo_original_ruta : $plantilla->archivo_plantilla_ruta;
        abort_unless($ruta && Storage::disk('local')->exists($ruta), 404);
        $nombre = $cual === 'original' ? $plantilla->archivo_original_nombre : 'plantilla-'.$plantilla->tipo->slug.'-v'.$plantilla->version.'.docx';

        return response()->download(Storage::disk('local')->path($ruta), $nombre);
    }

    public function destroy(PlantillaFormato $plantilla)
    {
        $this->servicio->eliminar($plantilla);

        return redirect()->route('formatos.index')->with('ok', 'Formato eliminado.');
    }
}
