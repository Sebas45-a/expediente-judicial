<?php

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultaIa extends Model
{
    protected $table = 'consultas_ia';

    protected $fillable = ['expediente_id', 'pregunta', 'respuesta', 'modelo', 'duracion_ms', 'fragmentos_usados', 'exito', 'error'];

    protected function casts(): array
    {
        return ['fragmentos_usados' => 'array', 'exito' => 'boolean'];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }
}
