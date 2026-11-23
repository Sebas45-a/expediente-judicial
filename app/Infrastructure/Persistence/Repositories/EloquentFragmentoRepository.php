<?php

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\Repositories\FragmentoRepositoryInterface;
use App\Infrastructure\Persistence\Models\FragmentoExpediente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentFragmentoRepository implements FragmentoRepositoryInterface
{
    public function reemplazar(int $expedienteId, array $fragmentos, ?string $modelo): void
    {
        DB::transaction(function () use ($expedienteId, $fragmentos, $modelo) {
            FragmentoExpediente::where('expediente_id', $expedienteId)->delete();
            $ahora = now();
            $filas = [];
            foreach (array_values($fragmentos) as $i => $f) {
                $filas[] = [
                    'expediente_id' => $expedienteId,
                    'orden' => $i,
                    'contenido' => $f['contenido'],
                    'embedding' => $f['embedding'] ? json_encode($f['embedding']) : null,
                    'modelo_embedding' => $f['embedding'] ? $modelo : null,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
            foreach (array_chunk($filas, 100) as $lote) {
                FragmentoExpediente::insert($lote);
            }
        });
    }

    public function deExpediente(?int $expedienteId = null, int $limite = 4000): Collection
    {
        return FragmentoExpediente::query()
            ->when($expedienteId, fn ($q) => $q->where('expediente_id', $expedienteId))
            ->whereHas('expediente')
            ->orderBy('expediente_id')->orderBy('orden')
            ->limit($limite)
            ->get(['id', 'expediente_id', 'orden', 'contenido', 'embedding', 'modelo_embedding'])
            ->map(function ($f) {
                $f->embedding = $f->embedding ? json_decode($f->embedding, true) : null;

                return $f;
            });
    }

    public function eliminarDe(int $expedienteId): void
    {
        FragmentoExpediente::where('expediente_id', $expedienteId)->delete();
    }

    public function total(): int
    {
        return FragmentoExpediente::count();
    }
}
