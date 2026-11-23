<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArchivoExcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'max:51200', 'mimes:xlsx,xls,csv,txt'],
            'hoja' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'archivo.required' => 'Seleccione un archivo de Excel.',
            'archivo.mimes' => 'Solo se aceptan archivos .xlsx, .xls o .csv.',
            'archivo.max' => 'El archivo no debe superar 50 MB.',
        ];
    }
}
