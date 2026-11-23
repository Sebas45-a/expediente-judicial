<?php

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\Repositories\ExpedienteRepositoryInterface;
use App\Domain\Enums\EstadoExpediente;
use App\Infrastructure\Persistence\Models\Expediente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EloquentExpedienteRepository implements ExpedienteRepositoryInterface
{
    private function filtrar(array $f): Builder
    {
        $q = Expediente::query()->with('tipo');

        if (! empty($f['buscar'])) {
            $b = '%'.trim($f['buscar']).'%';
            $q->where(function (Builder $w) use ($b) {
                $w->where('numero_expediente', 'like', $b)
                    ->orWhere('titulo', 'like', $b)
                    ->orWhere('demandante', 'like', $b)
                    ->orWhere('demandado', 'like', $b)
                    ->orWhere('juzgado', 'like', $b)
                    ->orWhere('descripcion', 'like', $b);
            });
        }
        if (! empty($f['tipo'])) {
            $f['tipo'] === 'sin' ? $q->whereNull('tipo_expediente_id') : $q->where('tipo_expediente_id', (int) $f['tipo']);
        }
        if (! empty($f['estado'])) {
            $q->where('estado', $f['estado']);
        }
        if (! empty($f['origen'])) {
            $q->where('origen', $f['origen']);
        }
        if (! empty($f['importacion'])) {
            $q->where('importacion_excel_id', (int) $f['importacion']);
        }
        if (! empty($f['desde'])) {
            $q->whereDate('fecha_inicio', '>=', $f['desde']);
        }
        if (! empty($f['hasta'])) {
            $q->whereDate('fecha_inicio', '<=', $f['hasta']);
        }

        $orden = in_array($f['orden'] ?? '', ['numero_expediente', 'titulo', 'fecha_inicio', 'created_at', 'estado'], true) ? $f['orden'] : 'created_at';
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $q->orderBy($orden, $dir)->orderByDesc('id');
    }

    public function paginar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        return $this->filtrar($filtros)->paginate($porPagina)->withQueryString();
    }

    public function buscar(int $id): ?Expediente
    {
        return Expediente::with('tipo')->find($id);
    }

    public function obtener(int $id): Expediente
    {
        return Expediente::with('tipo')->findOrFail($id);
    }

    public function porNumero(string $numero): ?Expediente
    {
        return Expediente::withTrashed()->where('numero_expediente', trim($numero))->first();
    }

    public function crear(array $datos): Expediente
    {
        return Expediente::create($datos);
    }

    public function actualizar(Expediente $expediente, array $datos): Expediente
    {
        $expediente->fill($datos)->save();

        return $expediente->refresh();
    }

    public function eliminar(Expediente $expediente): void
    {
        $expediente->fragmentos()->delete();
        $expediente->delete();
    }

    public function paraEntrenamiento(): Collection
    {
        return Expediente::query()
            ->whereNotNull('tipo_expediente_id')
            ->where(fn ($q) => $q->whereIn('clasificado_por', ['manual', 'excel', 'documento'])->orWhereNull('clasificado_por'))
            ->get(['id', 'tipo_expediente_id', 'titulo', 'descripcion', 'juzgado', 'contenido_texto', 'datos_extra', 'estado']);
    }

    public function sinClasificar(): Collection
    {
        return Expediente::whereNull('tipo_expediente_id')->get();
    }

    public function recientes(int $n = 8): Collection
    {
        return Expediente::with('tipo')->latest()->limit($n)->get();
    }

    public function pendientesDeIndexar(int $limite = 50): Collection
    {
        return Expediente::with('tipo')->whereNull('indexado_en')->limit($limite)->get();
    }

    public function estadisticas(): array
    {
        $porEstado = Expediente::query()->selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado')->all();
        $estados = [];
        foreach (EstadoExpediente::cases() as $e) {
            $estados[$e->value] = ['etiqueta' => $e->etiqueta(), 'color' => $e->color(), 'total' => (int) ($porEstado[$e->value] ?? 0)];
        }

        return [
            'total' => Expediente::count(),
            'sin_clasificar' => Expediente::whereNull('tipo_expediente_id')->count(),
            'importados_excel' => Expediente::where('origen', 'excel')->count(),
            'con_documento' => Expediente::whereNotNull('archivo_ruta')->count(),
            'indexados' => Expediente::whereNotNull('indexado_en')->count(),
            'por_estado' => $estados,
        ];
    }

    public function todosParaExportar(array $filtros = []): Collection
    {
        return $this->filtrar($filtros)->get();
    }
}
