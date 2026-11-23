<?php

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\Repositories\ImportacionRepositoryInterface;
use App\Infrastructure\Persistence\Models\ImportacionExcel;
use Illuminate\Support\Collection;

class EloquentImportacionRepository implements ImportacionRepositoryInterface
{
    public function crear(array $datos): ImportacionExcel
    {
        return ImportacionExcel::create($datos);
    }

    public function obtener(int $id): ImportacionExcel
    {
        return ImportacionExcel::findOrFail($id);
    }

    public function actualizar(ImportacionExcel $importacion, array $datos): ImportacionExcel
    {
        $importacion->fill($datos)->save();

        return $importacion;
    }

    public function recientes(int $n = 15): Collection
    {
        return ImportacionExcel::latest()->limit($n)->get();
    }
}
