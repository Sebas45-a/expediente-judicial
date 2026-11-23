<?php

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\Repositories\ConsultaIaRepositoryInterface;
use App\Infrastructure\Persistence\Models\ConsultaIa;
use Illuminate\Support\Collection;

class EloquentConsultaIaRepository implements ConsultaIaRepositoryInterface
{
    public function registrar(array $datos): void
    {
        ConsultaIa::create($datos);
    }

    public function historial(?int $expedienteId = null, int $limite = 20): Collection
    {
        return ConsultaIa::with('expediente:id,numero_expediente,titulo')
            ->when($expedienteId, fn ($q) => $q->where('expediente_id', $expedienteId))
            ->latest()->limit($limite)->get()->reverse()->values();
    }

    public function estadisticas(): array
    {
        return [
            'total' => ConsultaIa::count(),
            'exitosas' => ConsultaIa::where('exito', true)->count(),
            'promedio_ms' => (int) ConsultaIa::where('exito', true)->avg('duracion_ms'),
        ];
    }
}
