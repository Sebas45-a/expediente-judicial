<?php

use App\Http\Controllers\AsistenteIaController;
use App\Http\Controllers\BaseDatosController;
use App\Http\Controllers\ExpedienteController;
use App\Http\Controllers\FormatoController;
use App\Http\Controllers\ImportacionController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\TipoExpedienteController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PanelController::class, 'index'])->name('panel');

// Expedientes
Route::get('expedientes/exportar', [ExpedienteController::class, 'exportar'])->name('expedientes.exportar');
Route::get('expedientes/{expediente}/archivo', [ExpedienteController::class, 'archivo'])->name('expedientes.archivo');
Route::post('expedientes/{expediente}/indexar', [ExpedienteController::class, 'indexar'])->name('expedientes.indexar');
Route::resource('expedientes', ExpedienteController::class);

// Importación desde Excel
Route::prefix('importar')->name('importar.')->group(function () {
    Route::get('/', [ImportacionController::class, 'index'])->name('index');
    Route::get('plantilla', [ImportacionController::class, 'plantilla'])->name('plantilla');
    Route::post('previsualizar', [ImportacionController::class, 'previsualizar'])->name('previsualizar');
    Route::get('{importacion}/mapeo', [ImportacionController::class, 'mapeo'])->name('mapeo');
    Route::post('{importacion}/ejecutar', [ImportacionController::class, 'ejecutar'])->name('ejecutar');
    Route::get('{importacion}', [ImportacionController::class, 'show'])->name('show');
});

// Aprendizaje de tipos
Route::prefix('tipos')->name('tipos.')->group(function () {
    Route::get('/', [TipoExpedienteController::class, 'index'])->name('index');
    Route::post('/', [TipoExpedienteController::class, 'store'])->name('store');
    Route::post('entrenar', [TipoExpedienteController::class, 'entrenar'])->name('entrenar');
    Route::post('reclasificar', [TipoExpedienteController::class, 'reclasificar'])->name('reclasificar');
    Route::post('probar', [TipoExpedienteController::class, 'probar'])->name('probar');
    Route::get('{tipo}', [TipoExpedienteController::class, 'show'])->name('show');
    Route::put('{tipo}', [TipoExpedienteController::class, 'update'])->name('update');
    Route::delete('{tipo}', [TipoExpedienteController::class, 'destroy'])->name('destroy');
    Route::post('{tipo}/fusionar', [TipoExpedienteController::class, 'fusionar'])->name('fusionar');
});

// Aprendizaje de formatos
Route::prefix('formatos')->name('formatos.')->group(function () {
    Route::get('/', [FormatoController::class, 'index'])->name('index');
    Route::get('aprender', [FormatoController::class, 'create'])->name('create');
    Route::post('analizar', [FormatoController::class, 'analizar'])->name('analizar');
    Route::get('revisar/{token}', [FormatoController::class, 'revisar'])->name('revisar');
    Route::post('revisar/{token}', [FormatoController::class, 'confirmar'])->name('confirmar');
    Route::delete('revisar/{token}', [FormatoController::class, 'descartar'])->name('descartar');
    Route::get('{plantilla}', [FormatoController::class, 'show'])->name('show');
    Route::put('{plantilla}/campos', [FormatoController::class, 'actualizarCampos'])->name('campos');
    Route::post('{plantilla}/activar', [FormatoController::class, 'activar'])->name('activar');
    Route::get('{plantilla}/usar', [FormatoController::class, 'usar'])->name('usar');
    Route::post('{plantilla}/generar', [FormatoController::class, 'generar'])->name('generar');
    Route::post('{plantilla}/sugerir', [FormatoController::class, 'sugerir'])->name('sugerir');
    Route::get('{plantilla}/en-blanco', [FormatoController::class, 'enBlanco'])->name('blanco');
    Route::get('{plantilla}/descargar/{cual}', [FormatoController::class, 'descargar'])->whereIn('cual', ['plantilla', 'original'])->name('descargar');
    Route::delete('{plantilla}', [FormatoController::class, 'destroy'])->name('destroy');
});

// Asistente IA (Ollama)
Route::prefix('asistente')->name('asistente.')->group(function () {
    Route::get('/', [AsistenteIaController::class, 'index'])->name('index');
    Route::get('estado', [AsistenteIaController::class, 'estado'])->name('estado');
    Route::post('preguntar', [AsistenteIaController::class, 'preguntar'])->name('preguntar');
    Route::post('resumir/{expediente}', [AsistenteIaController::class, 'resumir'])->name('resumir');
    Route::post('probar', [AsistenteIaController::class, 'probar'])->name('probar');
    Route::post('modelo', [AsistenteIaController::class, 'modelo'])->name('modelo');
});

// Base de datos
Route::prefix('base-datos')->name('bd.')->group(function () {
    Route::get('/', [BaseDatosController::class, 'index'])->name('index');
    Route::get('tabla/{tabla}', [BaseDatosController::class, 'tabla'])->name('tabla');
    Route::get('respaldo', [BaseDatosController::class, 'respaldo'])->name('respaldo');
    Route::post('optimizar', [BaseDatosController::class, 'optimizar'])->name('optimizar');
    Route::post('vaciar-indice', [BaseDatosController::class, 'vaciarIndice'])->name('vaciar');
});
