<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Las respuestas de la IA local, el análisis de documentos y las importaciones grandes
 * pueden tardar más que el límite por defecto de PHP (30 s en Apache/XAMPP).
 */
class ExtenderTiempoEjecucion
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('POST') || $request->isMethod('PUT')) {
            @set_time_limit(max(300, (int) config('ollama.timeout', 180) + 60));
            @ini_set('memory_limit', '512M');
        }

        return $next($request);
    }
}
