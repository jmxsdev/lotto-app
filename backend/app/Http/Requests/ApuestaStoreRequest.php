<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApuestaStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $animales = [
            'delfin', 'ballena', 'carnero', 'toro', 'ciempies', 'alacran',
            'leon', 'caiman', 'tigre', 'raton', 'aguila', 'burro', 'gato',
            'loro', 'mono', 'paloma', 'zorro', 'lapa', 'venado', 'gallo',
            'pavo', 'cochino', 'gallina', 'camello', 'cebra', 'iguana', 'vaca',
            'perico', 'perro', 'zamuro', 'elefante', 'pavo_real', 'pescado',
            'ardilla', 'mariposa', 'abeja', 'jirafa', 'conejo',
        ];

        return [
            'juego_id' => 'required|exists:juegos,id',
            'combinacion' => 'required|array|min:1',
            'combinacion.animal' => ['required', 'string', 'max:50', Rule::in($animales)],
            'combinacion.numero' => 'nullable|integer|min:0|max:36',
            'amount_bs' => 'required|numeric|min:0',
            'amount_usd' => 'required|numeric|min:0',
            'sorteo_hora' => 'required|date_format:Y-m-d H:i:s',
            
            // Validación personalizada de monto total
            'total_calc' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'combinacion.animal.in' => 'El animal seleccionado no es válido.',
            'amount_bs.min' => 'El monto en bolívares debe ser mayor o igual a 0.',
            'amount_usd.min' => 'El monto en dólares debe ser mayor o igual a 0.',
            'sorteo_hora.date_format' => 'La hora del sorteo debe tener el formato YYYY-MM-DD HH:MM:SS.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $bs = $this->input('amount_bs');
            $usd = $this->input('amount_usd');
            
            if ($bs == 0 && $usd == 0) {
                $validator->errors()->add('monto', 'Debe ingresar un monto en Bolívares (BS) o Dólares (USD).');
            }
        });
    }
}
