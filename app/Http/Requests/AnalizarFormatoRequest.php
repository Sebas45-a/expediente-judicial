<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalizarFormatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'documento' => ['required', 'file', 'max:30720', 'mimes:docx,pdf,xlsx,xls,csv,txt'],
            'tipo_expediente_id' => ['nullable', 'integer', 'exists:tipos_expediente,id'],
            'tipo_nuevo' => ['nullable', 'string', 'max:150'],
            'usar_ia' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'documento.required' => 'Seleccione el documento de ejemplo.',
            'documento.mimes' => 'El documento debe ser Word (.docx), PDF, Excel o TXT. Los .doc antiguos deben guardarse como .docx.',
        ];
    }
}
