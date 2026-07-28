<?php

namespace App\Http\Requests;

use App\Models\Juego;
use App\Services\JuegoPluginManager;
use Illuminate\Foundation\Http\FormRequest;

class ApuestaStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'juego_id' => 'required|exists:juegos,id',
            'amount_bs' => 'required|numeric|min:0',
            'amount_usd' => 'required|numeric|min:0',
            'sorteo_hora' => 'sometimes|date_format:Y-m-d H:i:s|after_or_equal:now',
        ];

        $juego = Juego::find($this->juego_id);
        if ($juego) {
            $plugin = app(JuegoPluginManager::class)->getPlugin($juego);
            if ($plugin) {
                $rules = array_merge($rules, $plugin->getValidationRules());
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'amount_bs.min' => 'El monto en bolívares debe ser mayor o igual a 0.',
            'amount_usd.min' => 'El monto en dólares debe ser mayor o igual a 0.',
            'sorteo_hora.date_format' => 'La hora del sorteo debe tener el formato YYYY-MM-DD HH:MM:SS.',
        ];

        $juego = Juego::find($this->juego_id);
        if ($juego) {
            $plugin = app(JuegoPluginManager::class)->getPlugin($juego);
            if ($plugin) {
                $messages = array_merge($messages, $plugin->getValidationMessages());
            }
        }

        return $messages;
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
