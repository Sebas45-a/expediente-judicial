<?php

namespace App\Domain\Contracts\Repositories;

use App\Infrastructure\Persistence\Models\TipoExpediente;
use Illuminate\Support\Collection;

interface TipoExpedienteRepositoryInterface
{
    public function todos(): Collection;

    public function conConteos(): Collection;

    public function buscar(int $id): ?TipoExpediente;

    public function obtener(int $id): TipoExpediente;

    /** Busca por nombre normalizado (sin tildes/mayúsculas) y lo crea si no existe. */
    public function buscarOCrear(string $nombre, ?string $materia = null): TipoExpediente;

    public function buscarPorNombre(string $nombre): ?TipoExpediente;

    public function crear(array $datos): TipoExpediente;

    public function actualizar(TipoExpediente $tipo, array $datos): TipoExpediente;

    public function eliminar(TipoExpediente $tipo): void;

    /** Mueve los expedientes y plantillas de $origen a $destino y elimina $origen. */
    public function fusionar(TipoExpediente $origen, TipoExpediente $destino): void;
}
