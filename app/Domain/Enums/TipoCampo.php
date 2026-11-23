<?php

namespace App\Domain\Enums;

/**
 * Tipos de datos "importantes" que el sistema deja en blanco al aprender un formato.
 */
enum TipoCampo: string
{
    case NumeroExpediente = 'numero_expediente';
    case Fecha = 'fecha';
    case Hora = 'hora';
    case Nombre = 'nombre';
    case Dpi = 'dpi';
    case Nit = 'nit';
    case Monto = 'monto';
    case Juzgado = 'juzgado';
    case Direccion = 'direccion';
    case Correo = 'correo';
    case Telefono = 'telefono';
    case Colegiado = 'colegiado';
    case Edad = 'edad';
    case Texto = 'texto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::NumeroExpediente => 'Número de expediente',
            self::Fecha => 'Fecha',
            self::Hora => 'Hora',
            self::Nombre => 'Nombre de persona',
            self::Dpi => 'DPI / CUI',
            self::Nit => 'NIT',
            self::Monto => 'Monto',
            self::Juzgado => 'Juzgado / Tribunal',
            self::Direccion => 'Dirección',
            self::Correo => 'Correo electrónico',
            self::Telefono => 'Teléfono',
            self::Colegiado => 'Número de colegiado',
            self::Edad => 'Edad',
            self::Texto => 'Texto',
        };
    }

    /** Tipo de input HTML para el formulario de llenado. */
    public function inputHtml(): string
    {
        return match ($this) {
            self::Correo => 'email',
            self::Edad => 'number',
            default => 'text',
        };
    }

    /** Prioridad: los más importantes se muestran primero. */
    public function prioridad(): int
    {
        return match ($this) {
            self::NumeroExpediente => 1,
            self::Juzgado => 2,
            self::Nombre => 3,
            self::Fecha => 4,
            self::Dpi => 5,
            self::Monto => 6,
            self::Direccion => 7,
            default => 9,
        };
    }

    public static function desdeTexto(?string $valor): self
    {
        return self::tryFrom((string) $valor) ?? self::Texto;
    }

    public static function opciones(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->etiqueta()])->all();
    }
}
