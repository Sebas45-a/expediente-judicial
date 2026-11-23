<?php

namespace App\Domain\Enums;

enum FormatoArchivo: string
{
    case Docx = 'docx';
    case Pdf = 'pdf';
    case Xlsx = 'xlsx';
    case Xls = 'xls';
    case Csv = 'csv';
    case Txt = 'txt';

    public static function desdeNombre(string $nombreArchivo): ?self
    {
        return self::tryFrom(strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION)));
    }

    public function esHojaCalculo(): bool
    {
        return in_array($this, [self::Xlsx, self::Xls, self::Csv], true);
    }

    public static function extensionesDocumento(): array
    {
        return ['docx', 'pdf', 'xlsx', 'xls', 'csv', 'txt'];
    }
}
