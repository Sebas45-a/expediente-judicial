<?php

namespace App\Domain\Contracts\Repositories;

use App\Infrastructure\Persistence\Models\Expediente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ExpedienteRepositoryInterface
{
    public function paginar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator;

    public function buscar(int $id): ?Expediente;

    public function obtener(int $id): Expediente;

    public function porNumero(string $numero): ?Expediente;

    public function crear(array $datos): Expediente;

    public function actualizar(Expediente $expediente, array $datos): Expediente;

    public function eliminar(Expediente $expediente): void;

    /** Expedientes con tipo asignado manualmente/por Excel (sirven para entrenar). */
    public function paraEntrenamiento(): Collection;

    public function sinClasificar(): Collection;

    public function recientes(int $n = 8): Collection;

    public function pendientesDeIndexar(int $limite = 50): Collection;

    public function estadisticas(): array;

    public function todosParaExportar(array $filtros = []): Collection;
}
