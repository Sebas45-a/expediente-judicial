<?php

namespace App\Http\Requests;

use App\Domain\Enums\EstadoExpediente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpedienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('expediente')?->id ?? $this->route('expediente');

        return [
            'numero_expediente' => ['nullable', 'string', 'max:100', Rule::unique('expedientes', 'numero_expediente')->ignore($id)],
            'titulo' => [$this->hasFile('archivo') ? 'nullable' : 'required', 'string', 'max:250'],
            'tipo_expediente_id' => ['nullable', 'integer', 'exists:tipos_expediente,id'],
            'tipo_nuevo' => ['nullable', 'string', 'max:150'],
            'juzgado' => ['nullable', 'string', 'max:250'],
            'demandante' => ['nullable', 'string', 'max:250'],
            'demandado' => ['nullable', 'string', 'max:250'],
            'fecha_inicio' => ['nullable', 'date'],
            'estado' => ['required', Rule::enum(EstadoExpediente::class)],
            'descripcion' => ['nullable', 'string', 'max:10000'],
            'contenido_texto' => ['nullable', 'string'],
            'archivo' => ['nullable', 'file', 'max:30720', 'mimes:docx,pdf,xlsx,xls,csv,txt'],
        ];
    }

    public function attributes(): array
    {
        return [
            'numero_expediente' => 'número de expediente',
            'titulo' => 'título',
            'tipo_expediente_id' => 'tipo de expediente',
            'fecha_inicio' => 'fecha de inicio',
        ];
    }

    public function messages(): array
    {
        return [
            'numero_expediente.unique' => 'Ya existe un expediente con ese número.',
            'archivo.mimes' => 'El archivo debe ser Word (.docx), PDF, Excel (.xlsx/.xls/.csv) o texto (.txt).',
            'archivo.max' => 'El archivo no debe superar 30 MB.',
        ];
    }

    /** Datos listos para el servicio (sin el archivo). */
    public function datos(): array
    {
        $d = collect($this->validated())->except('archivo')->all();
        if (empty($d['titulo']) && $this->hasFile('archivo')) {
            $d['titulo'] = pathinfo($this->file('archivo')->getClientOriginalName(), PATHINFO_FILENAME);
        }

        return $d;
    }
}
