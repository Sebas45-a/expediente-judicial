<?php

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoExpediente extends Model
{
    protected $table = 'tipos_expediente';

    protected $fillable = [
        'nombre', 'slug', 'materia', 'descripcion', 'color', 'perfil', 'palabras_clave',
        'campos_frecuentes', 'total_ejemplos', 'precision_entrenamiento', 'entrenado_en',
    ];

    protected $hidden = ['perfil'];

    protected function casts(): array
    {
        return [
            'perfil' => 'array',
            'palabras_clave' => 'array',
            'campos_frecuentes' => 'array',
            'entrenado_en' => 'datetime',
            'precision_entrenamiento' => 'float',
        ];
    }

    public function expedientes(): HasMany
    {
        return $this->hasMany(Expediente::class, 'tipo_expediente_id');
    }

    public function plantillas(): HasMany
    {
        return $this->hasMany(PlantillaFormato::class, 'tipo_expediente_id');
    }
}
