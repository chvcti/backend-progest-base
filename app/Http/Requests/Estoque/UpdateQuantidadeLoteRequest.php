<?php

namespace App\Http\Requests\Estoque;

use App\Http\Requests\BaseFormRequest;

class UpdateQuantidadeLoteRequest extends BaseFormRequest
{
    /**
     * Regras de validação para atualizar quantidade disponível de um lote.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id'                   => 'required|exists:estoque_lote,id',
            'quantidade_disponivel'=> 'required|numeric|min:0',
        ];
    }

    public function messages()
    {
        return [
            'id.required'                   => 'O identificador do lote é obrigatório.',
            'id.exists'                     => 'Lote de estoque não encontrado.',
            'quantidade_disponivel.required'=> 'A quantidade disponível é obrigatória.',
            'quantidade_disponivel.min'     => 'A quantidade disponível não pode ser negativa.',
        ];
    }
}
