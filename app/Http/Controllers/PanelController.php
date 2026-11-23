<?php

namespace App\Http\Controllers;

use App\Application\Services\AsistenteIaService;
use App\Application\Services\ImportacionExcelService;
use App\Application\Services\TipoExpedienteService;
use App\Domain\Contracts\Repositories\ExpedienteRepositoryInterface;
use App\Domain\Contracts\Repositories\PlantillaRepositoryInterface;

class PanelController extends Controller
{
    public function index(
        ExpedienteRepositoryInterface $expedientes,
        TipoExpedienteService $tipos,
        PlantillaRepositoryInterface $plantillas,
        ImportacionExcelService $importaciones,
        AsistenteIaService $ia,
    ) {
        return view('panel', [
            'stats' => $expedientes->estadisticas(),
            'recientes' => $expedientes->recientes(),
            'tipos' => $tipos->listar()->take(8),
            'formatos' => $plantillas->todas()->where('activa', true)->count(),
            'importaciones' => $importaciones->historial()->take(5),
            'ia' => $ia->estado(),
        ]);
    }
}
