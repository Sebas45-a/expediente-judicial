<?php

namespace App\Infrastructure\Persistence\Models;

use App\Domain\Enums\EstadoExpediente;
use App\Domain\Enums\OrigenExpediente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expediente extends Model
{
    use SoftDeletes;

    protected $table = 'expedientes';

    protected $fillable = [
        'numero_expediente', 'titulo', 'tipo_expediente_id', 'juzgado', 'demandante', 'demandado',
        'fecha_inicio', 'estado', 'descripcion', 'contenido_texto', 'datos_extra', 'origen',
        'archivo_ruta', 'archivo_nombre', 'formato_archivo', 'importacion_excel_id',
        'clasificado_por', 'clasificacion_confianza', 'indexado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'datos_extra' => 'array',
            'estado' => EstadoExpediente::class,
            'origen' => OrigenExpediente::class,
            'indexado_en' => 'datetime',
            'clasificacion_confianza' => 'float',
        ];
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoExpediente::class, 'tipo_expediente_id');
    }

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(ImportacionExcel::class, 'importacion_excel_id');
    }

    public function fragmentos(): HasMany
    {
        return $this->hasMany(FragmentoExpediente::class);
    }

    public function consultas(): HasMany
    {
        return $this->hasMany(ConsultaIa::class);
    }

    /** Texto completo usado para aprendizaje e IA: metadatos + contenido. */
    public function textoParaAnalisis(): string
    {
        $partes = [
            $this->titulo,
            $this->descripcion,
            $this->juzgado,
            $this->contenido_texto,
        ];
        // solo los valores: los nombres de columnas son iguales en todos los tipos y meten ruido
        foreach ((array) $this->datos_extra as $v) {
            if (is_string($v) && trim($v) !== '' && ! is_numeric(str_replace([',', '.', 'Q', ' '], '', $v))) {
                $partes[] = $v;
            }
        }

        return trim(implode("\n", array_filter($partes, fn ($p) => is_string($p) && trim($p) !== '')));
    }

    /** Ficha estructurada que se antepone al contexto de la IA. */
    public function ficha(): string
    {
        $l = [
            'Número de expediente: '.($this->numero_expediente ?: 'No registrado'),
            'Título: '.$this->titulo,
            'Tipo: '.($this->tipo?->nombre ?? 'Sin clasificar'),
            'Estado: '.($this->estado?->etiqueta() ?? ''),
        ];
        if ($this->juzgado) {
            $l[] = 'Juzgado: '.$this->juzgado;
        }
        if ($this->demandante) {
            $l[] = 'Demandante / Actor / Agraviado: '.$this->demandante;
        }
        if ($this->demandado) {
            $l[] = 'Demandado / Sindicado: '.$this->demandado;
        }
        if ($this->fecha_inicio) {
            $l[] = 'Fecha de inicio: '.$this->fecha_inicio->format('d/m/Y');
        }
        if ($this->descripcion) {
            $l[] = 'Descripción: '.$this->descripcion;
        }
        foreach ((array) $this->datos_extra as $k => $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                $l[] = "{$k}: {$v}";
            }
        }

        return implode("\n", $l);
    }
}
