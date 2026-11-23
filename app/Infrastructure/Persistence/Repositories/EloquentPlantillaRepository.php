<?php

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\Repositories\PlantillaRepositoryInterface;
use App\Infrastructure\Persistence\Models\DocumentoGenerado;
use App\Infrastructure\Persistence\Models\PlantillaFormato;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentPlantillaRepository implements PlantillaRepositoryInterface
{
    public function todas(?int $tipoId = null): Collection
    {
        return PlantillaFormato::with('tipo')->withCount(['campos', 'documentos'])
            ->when($tipoId, fn ($q) => $q->where('tipo_expediente_id', $tipoId))
            ->orderByDesc('activa')->orderByDesc('updated_at')->get();
    }

    public function obtener(int $id): PlantillaFormato
    {
        return PlantillaFormato::with(['tipo', 'campos'])->findOrFail($id);
    }

    public function activaDeTipo(int $tipoId): ?PlantillaFormato
    {
        return PlantillaFormato::with('campos')->where('tipo_expediente_id', $tipoId)->where('activa', true)->latest('version')->first();
    }

    public function crearConCampos(array $datos, array $campos): PlantillaFormato
    {
        return DB::transaction(function () use ($datos, $campos) {
            $version = (int) PlantillaFormato::where('tipo_expediente_id', $datos['tipo_expediente_id'])->max('version');
            PlantillaFormato::where('tipo_expediente_id', $datos['tipo_expediente_id'])->update(['activa' => false]);
            $plantilla = PlantillaFormato::create($datos + ['version' => $version + 1, 'activa' => true]);
            foreach (array_values($campos) as $i => $c) {
                $plantilla->campos()->create($c + ['orden' => $i]);
            }

            return $plantilla->load('campos', 'tipo');
        });
    }

    public function actualizar(PlantillaFormato $plantilla, array $datos): PlantillaFormato
    {
        $plantilla->fill($datos)->save();

        return $plantilla->refresh();
    }

    public function eliminar(PlantillaFormato $plantilla): void
    {
        $plantilla->delete();
    }

    public function activar(PlantillaFormato $plantilla): void
    {
        DB::transaction(function () use ($plantilla) {
            PlantillaFormato::where('tipo_expediente_id', $plantilla->tipo_expediente_id)->update(['activa' => false]);
            $plantilla->update(['activa' => true]);
        });
    }

    public function registrarDocumentoGenerado(PlantillaFormato $plantilla, array $valores, string $ruta, ?int $expedienteId): void
    {
        DocumentoGenerado::create([
            'plantilla_formato_id' => $plantilla->id,
            'expediente_id' => $expedienteId,
            'valores' => $valores,
            'archivo_ruta' => $ruta,
        ]);
    }
}
