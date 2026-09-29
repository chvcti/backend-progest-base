<?php

namespace App\Http\Requests\Estoque;

use App\Http\Requests\BaseFormRequest;

class UpdateStatusRequest extends BaseFormRequest
{
    /**
     * Regras de validação para atualizar status de disponibilidade do estoque.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'status_disponibilidade' => 'required|in:D,I',
        ];
    }

    public function messages()
    {
        return [
            'status_disponibilidade.required' => 'O status de disponibilidade é obrigatório.',
            'status_disponibilidade.in'       => 'Status de disponibilidade inválido. Deve ser D (Disponível) ou I (Indisponível).',
        ];
    }
}
