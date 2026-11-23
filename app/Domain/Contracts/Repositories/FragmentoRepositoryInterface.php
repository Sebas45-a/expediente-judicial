<?php

namespace App\Domain\Contracts\Repositories;

use Illuminate\Support\Collection;

interface FragmentoRepositoryInterface
{
    /**
     * @param  array<int, array{contenido: string, embedding: ?array}>  $fragmentos
     */
    public function reemplazar(int $expedienteId, array $fragmentos, ?string $modelo): void;

    /** @return Collection<int, object{id:int, expediente_id:int, orden:int, contenido:string, embedding:?array}> */
    public function deExpediente(?int $expedienteId = null, int $limite = 4000): Collection;

    public function eliminarDe(int $expedienteId): void;

    public function total(): int;
}
