<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/** Excepción base del dominio: su mensaje siempre es apto para mostrar al usuario. */
class ExpedienteException extends RuntimeException {}
