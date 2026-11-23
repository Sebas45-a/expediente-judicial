<?php

namespace App\Application\Services;

use App\Domain\Contracts\Repositories\TipoExpedienteRepositoryInterface;
use App\Domain\Exceptions\ExpedienteException;
use App\Infrastructure\Persistence\Models\TipoExpediente;
use Illuminate\Support\Collection;

class TipoExpedienteService
{
    public function __construct(
        private readonly TipoExpedienteRepositoryInterface $tipos,
        private readonly AprendizajeTiposService $aprendizaje,
    ) {}

    public function listar(): Collection
    {
        return $this->tipos->conConteos();
    }

    public function todos(): Collection
    {
        return $this->tipos->todos();
    }

    public function obtener(int $id): TipoExpediente
    {
        return $this->tipos->obtener($id);
    }

    public function crear(array $datos): TipoExpediente
    {
        if ($this->tipos->buscarPorNombre($datos['nombre'])) {
            throw new ExpedienteException('Ya existe un tipo con ese nombre.');
        }

        return $this->tipos->crear($datos);
    }

    public function actualizar(TipoExpediente $tipo, array $datos): TipoExpediente
    {
        $otro = $this->tipos->buscarPorNombre($datos['nombre'] ?? $tipo->nombre);
        if ($otro && $otro->id !== $tipo->id) {
            throw new ExpedienteException('Ya existe otro tipo con ese nombre. Use "Fusionar" para unirlos.');
        }

        return $this->tipos->actualizar($tipo, $datos);
    }

    public function eliminar(TipoExpediente $tipo): void
    {
        $this->tipos->eliminar($tipo); // los expedientes quedan "sin clasificar"
        $this->aprendizaje->marcarDesactualizado();
    }

    public function fusionar(TipoExpediente $origen, int $destinoId): TipoExpediente
    {
        if ($origen->id === $destinoId) {
            throw new ExpedienteException('Elija un tipo distinto para fusionar.');
        }
        $destino = $this->tipos->obtener($destinoId);
        $this->tipos->fusionar($origen, $destino);
        $this->aprendizaje->entrenar();

        return $destino;
    }
}
