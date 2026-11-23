<?php

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FragmentoExpediente extends Model
{
    protected $table = 'fragmentos_expediente';

    protected $fillable = ['expediente_id', 'orden', 'contenido', 'embedding', 'modelo_embedding'];

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }
}
