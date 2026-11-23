<?php

namespace App\Domain\Contracts\Repositories;

use App\Infrastructure\Persistence\Models\PlantillaFormato;
use Illuminate\Support\Collection;

interface PlantillaRepositoryInterface
{
    public function todas(?int $tipoId = null): Collection;

    public function obtener(int $id): PlantillaFormato;

    public function activaDeTipo(int $tipoId): ?PlantillaFormato;

    /** Crea plantilla + campos en una transacción y desactiva versiones anteriores del tipo. */
    public function crearConCampos(array $datos, array $campos): PlantillaFormato;

    public function actualizar(PlantillaFormato $plantilla, array $datos): PlantillaFormato;

    public function eliminar(PlantillaFormato $plantilla): void;

    /** Marca esta versión como la activa de su tipo. */
    public function activar(PlantillaFormato $plantilla): void;

    public function registrarDocumentoGenerado(PlantillaFormato $plantilla, array $valores, string $ruta, ?int $expedienteId): void;
}
