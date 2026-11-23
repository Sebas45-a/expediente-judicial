<?php

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportacionExcel extends Model
{
    protected $table = 'importaciones_excel';

    protected $fillable = [
        'archivo_nombre', 'archivo_ruta', 'hoja', 'encabezados', 'mapeo', 'total_filas',
        'importadas', 'actualizadas', 'omitidas', 'errores', 'estado',
    ];

    protected function casts(): array
    {
        return ['encabezados' => 'array', 'mapeo' => 'array', 'errores' => 'array'];
    }

    public function expedientes(): HasMany
    {
        return $this->hasMany(Expediente::class, 'importacion_excel_id');
    }
}
