<?php

namespace App\Domain\Contracts\Repositories;

interface ConfiguracionRepositoryInterface
{
    public function obtener(string $clave, mixed $porDefecto = null): mixed;

    public function guardar(string $clave, mixed $valor): void;
}
