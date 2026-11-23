<?php

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\Repositories\TipoExpedienteRepositoryInterface;
use App\Domain\Services\NormalizadorTexto;
use App\Infrastructure\Persistence\Models\Expediente;
use App\Infrastructure\Persistence\Models\PlantillaFormato;
use App\Infrastructure\Persistence\Models\TipoExpediente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentTipoExpedienteRepository implements TipoExpedienteRepositoryInterface
{
    private const COLORES = ['#1f4e79', '#7b2d26', '#2e6b3f', '#6b4c9a', '#b8860b', '#0f6e6e', '#8a3b6f', '#4a5a6a', '#a0522d', '#2f4f8f'];

    public function todos(): Collection
    {
        return TipoExpediente::orderBy('nombre')->get();
    }

    public function conConteos(): Collection
    {
        return TipoExpediente::withCount(['expedientes', 'plantillas'])->orderByDesc('expedientes_count')->orderBy('nombre')->get();
    }

    public function buscar(int $id): ?TipoExpediente
    {
        return TipoExpediente::find($id);
    }

    public function obtener(int $id): TipoExpediente
    {
        return TipoExpediente::findOrFail($id);
    }

    public function buscarPorNombre(string $nombre): ?TipoExpediente
    {
        $slug = NormalizadorTexto::slug($nombre, '-');

        return $slug === '' ? null : TipoExpediente::where('slug', $slug)->first();
    }

    public function buscarOCrear(string $nombre, ?string $materia = null): TipoExpediente
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? $nombre);
        if ($existente = $this->buscarPorNombre($nombre)) {
            return $existente;
        }

        return $this->crear([
            'nombre' => mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE, 'UTF-8'),
            'materia' => $materia,
        ]);
    }

    public function crear(array $datos): TipoExpediente
    {
        $datos['slug'] = NormalizadorTexto::slug($datos['nombre'], '-');
        $datos['color'] ??= self::COLORES[TipoExpediente::count() % count(self::COLORES)];

        return TipoExpediente::create($datos);
    }

    public function actualizar(TipoExpediente $tipo, array $datos): TipoExpediente
    {
        if (isset($datos['nombre'])) {
            $datos['slug'] = NormalizadorTexto::slug($datos['nombre'], '-');
        }
        $tipo->fill($datos)->save();

        return $tipo->refresh();
    }

    public function eliminar(TipoExpediente $tipo): void
    {
        $tipo->delete();
    }

    public function fusionar(TipoExpediente $origen, TipoExpediente $destino): void
    {
        DB::transaction(function () use ($origen, $destino) {
            Expediente::withTrashed()->where('tipo_expediente_id', $origen->id)->update(['tipo_expediente_id' => $destino->id]);
            PlantillaFormato::where('tipo_expediente_id', $origen->id)->update(['tipo_expediente_id' => $destino->id, 'activa' => false]);
            $origen->delete();
        });
    }
}
