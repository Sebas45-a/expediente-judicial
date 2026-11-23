<?php

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\Repositories\ConfiguracionRepositoryInterface;
use App\Infrastructure\Persistence\Models\Configuracion;

class EloquentConfiguracionRepository implements ConfiguracionRepositoryInterface
{
    private array $cache = [];

    public function obtener(string $clave, mixed $porDefecto = null): mixed
    {
        if (array_key_exists($clave, $this->cache)) {
            return $this->cache[$clave] ?? $porDefecto;
        }
        try {
            $valor = Configuracion::find($clave)?->valor;
        } catch (\Throwable) {
            $valor = null; // la tabla aún no existe (antes de migrar)
        }
        $this->cache[$clave] = $valor === null ? null : json_decode($valor, true);

        return $this->cache[$clave] ?? $porDefecto;
    }

    public function guardar(string $clave, mixed $valor): void
    {
        Configuracion::updateOrCreate(['clave' => $clave], ['valor' => json_encode($valor)]);
        $this->cache[$clave] = $valor;
    }
}
