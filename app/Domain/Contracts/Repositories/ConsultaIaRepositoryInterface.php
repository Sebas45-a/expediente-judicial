<?php

namespace App\Domain\Contracts\Repositories;

use Illuminate\Support\Collection;

interface ConsultaIaRepositoryInterface
{
    public function registrar(array $datos): void;

    public function historial(?int $expedienteId = null, int $limite = 20): Collection;

    public function estadisticas(): array;
}
