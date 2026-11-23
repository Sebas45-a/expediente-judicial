<?php

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlantillaFormato extends Model
{
    protected $table = 'plantillas_formato';

    protected $fillable = [
        'tipo_expediente_id', 'nombre', 'version', 'formato_origen', 'archivo_original_ruta',
        'archivo_original_nombre', 'archivo_plantilla_ruta', 'contenido_plantilla',
        'muestras_analizadas', 'activa', 'uso_ia',
    ];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'uso_ia' => 'boolean'];
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoExpediente::class, 'tipo_expediente_id');
    }

    public function campos(): HasMany
    {
        return $this->hasMany(CampoPlantilla::class)->orderBy('orden');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoGenerado::class);
    }
}
