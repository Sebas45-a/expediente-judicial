<?php

namespace App\Domain\Enums;

use App\Domain\Services\NormalizadorTexto;

enum EstadoExpediente: string
{
    case EnTramite = 'en_tramite';
    case EnAudiencia = 'en_audiencia';
    case Suspendido = 'suspendido';
    case Resuelto = 'resuelto';
    case Archivado = 'archivado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnTramite => 'En trámite',
            self::EnAudiencia => 'En audiencia',
            self::Suspendido => 'Suspendido',
            self::Resuelto => 'Resuelto',
            self::Archivado => 'Archivado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EnTramite => 'azul',
            self::EnAudiencia => 'morado',
            self::Suspendido => 'naranja',
            self::Resuelto => 'verde',
            self::Archivado => 'gris',
        };
    }

    /** Interpreta textos libres que vienen del Excel ("En Trámite", "SENTENCIADO", "archivo"...). */
    public static function desdeTexto(?string $texto): self
    {
        $t = NormalizadorTexto::normalizar((string) $texto);
        if ($t === '') {
            return self::EnTramite;
        }
        foreach (self::cases() as $caso) {
            if ($t === $caso->value || $t === NormalizadorTexto::normalizar($caso->etiqueta())) {
                return $caso;
            }
        }

        return match (true) {
            str_contains($t, 'archiv') => self::Archivado,
            str_contains($t, 'resuel') || str_contains($t, 'sentenc') || str_contains($t, 'conclu')
                || str_contains($t, 'finaliz') || str_contains($t, 'fenec') => self::Resuelto,
            str_contains($t, 'suspend') || str_contains($t, 'paraliz') => self::Suspendido,
            str_contains($t, 'audiencia') || str_contains($t, 'debate') => self::EnAudiencia,
            default => self::EnTramite,
        };
    }

    public static function opciones(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->etiqueta()])->all();
    }
}
