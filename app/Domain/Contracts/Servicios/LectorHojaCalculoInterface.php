<?php

namespace App\Domain\Contracts\Servicios;

interface LectorHojaCalculoInterface
{
    /** @return string[] nombres de hojas */
    public function hojas(string $rutaAbsoluta): array;

    /**
     * Lee una hoja. Detecta automáticamente la fila de encabezados.
     *
     * @return array{encabezados: string[], filas: array<int, array<string, mixed>>, total: int, hoja: string}
     */
    public function leer(string $rutaAbsoluta, ?string $hoja = null, ?int $limite = null): array;

    /**
     * @param  string[]  $encabezados
     * @param  array<int, array<int, mixed>>  $filas
     */
    public function escribir(string $rutaAbsoluta, array $encabezados, array $filas, string $tituloHoja = 'Datos'): void;
}
