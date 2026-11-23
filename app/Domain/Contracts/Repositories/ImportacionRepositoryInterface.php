<?php

namespace App\Domain\Contracts\Repositories;

use App\Infrastructure\Persistence\Models\ImportacionExcel;
use Illuminate\Support\Collection;

interface ImportacionRepositoryInterface
{
    public function crear(array $datos): ImportacionExcel;

    public function obtener(int $id): ImportacionExcel;

    public function actualizar(ImportacionExcel $importacion, array $datos): ImportacionExcel;

    public function recientes(int $n = 15): Collection;
}
