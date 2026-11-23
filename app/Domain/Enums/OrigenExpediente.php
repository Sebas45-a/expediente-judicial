<?php

namespace App\Domain\Enums;

enum OrigenExpediente: string
{
    case Manual = 'manual';
    case Excel = 'excel';
    case Documento = 'documento';
    case Plantilla = 'plantilla';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Manual => 'Registro manual',
            self::Excel => 'Importado de Excel',
            self::Documento => 'Cargado desde documento',
            self::Plantilla => 'Generado con formato',
        };
    }
}
