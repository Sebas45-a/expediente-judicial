<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreguntaIaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pregunta' => ['required', 'string', 'min:2', 'max:2000'],
            'expediente_id' => ['nullable', 'integer', 'exists:expedientes,id'],
        ];
    }
}
