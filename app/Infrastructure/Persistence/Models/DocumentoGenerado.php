<?php

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoGenerado extends Model
{
    protected $table = 'documentos_generados';

    protected $fillable = ['plantilla_formato_id', 'expediente_id', 'valores', 'archivo_ruta'];

    protected function casts(): array
    {
        return ['valores' => 'array'];
    }

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaFormato::class, 'plantilla_formato_id');
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }
}
