<?php

use App\Domain\Exceptions\ExpedienteException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [\App\Http\Middleware\ExtenderTiempoEjecucion::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Los errores de dominio (incluidos los de Ollama) se muestran como mensajes claros, nunca como pantalla de error.
        $exceptions->render(function (ExpedienteException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        });
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $msg = 'El archivo supera el tamaño máximo permitido por PHP (upload_max_filesize / post_max_size).';

            return $request->expectsJson() ? response()->json(['ok' => false, 'error' => $msg], 413) : back()->with('error', $msg);
        });
    })->create();

/*
|--------------------------------------------------------------------------
| Modo aplicación de escritorio
|--------------------------------------------------------------------------
| El programa instalado (carpeta desktop/) define EXPEDIENTE_DATOS con una carpeta
| del perfil del usuario (p. ej. %APPDATA%\Expediente Judicial). Ahí viven el .env,
| la base de datos, los archivos subidos y los registros, de modo que la carpeta de
| instalación puede ser de solo lectura y las actualizaciones no borran nada.
*/
if ($datos = getenv('EXPEDIENTE_DATOS')) {
    $app->useStoragePath($datos.DIRECTORY_SEPARATOR.'storage');
    $app->useEnvironmentPath($datos);
}

    // En Windows las rutas absolutas empiezan con la unidad (C:\, D:\…); Laravel solo reconoce / y \.
 if (preg_match('/^[A-Za-z]:[\\\\\/]/', $datos)) {
        $app->addAbsoluteCachePathPrefix(substr($datos, 0, 3));
}

return $app;
