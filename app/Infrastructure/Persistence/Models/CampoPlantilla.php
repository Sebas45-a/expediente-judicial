<?php

namespace App\Infrastructure\Persistence\Models;

use App\Domain\Enums\TipoCampo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampoPlantilla extends Model
{
    protected $table = 'campos_plantilla';

    protected $fillable = [
        'plantilla_formato_id', 'clave', 'etiqueta', 'tipo_dato', 'valor_ejemplo',
        'ocurrencias', 'obligatorio', 'fuente', 'orden',
    ];

    protected function casts(): array
    {
        return ['obligatorio' => 'boolean', 'tipo_dato' => TipoCampo::class];
    }

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaFormato::class, 'plantilla_formato_id');
    }
}
