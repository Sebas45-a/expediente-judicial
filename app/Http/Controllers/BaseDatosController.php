<?php

namespace App\Http\Controllers;

use App\Application\Services\BaseDatosService;
use Illuminate\Http\Request;

class BaseDatosController extends Controller
{
    public function __construct(private readonly BaseDatosService $bd) {}

    public function index()
    {
        return view('bd.index', ['info' => $this->bd->info(), 'migraciones' => $this->bd->migraciones()]);
    }

    public function tabla(Request $request, string $tabla)
    {
        return view('bd.tabla', $this->bd->explorar($tabla, $request->input('buscar'), max(1, $request->integer('pagina', 1))) + ['buscar' => $request->input('buscar')]);
    }

    public function respaldo()
    {
        $ruta = $this->bd->respaldo();

        return response()->download($ruta, basename($ruta));
    }

    public function optimizar()
    {
        return back()->with('ok', $this->bd->optimizar());
    }

    public function vaciarIndice()
    {
        $n = $this->bd->vaciarIndiceIa();

        return back()->with('ok', "Índice de la IA vaciado ({$n} fragmentos). Se regenerará automáticamente en la próxima consulta.");
    }
}
