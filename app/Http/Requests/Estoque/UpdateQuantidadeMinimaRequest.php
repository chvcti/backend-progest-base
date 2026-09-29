<?php

namespace App\Http\Requests\Estoque;

use App\Http\Requests\BaseFormRequest;

class UpdateQuantidadeMinimaRequest extends BaseFormRequest
{
    /**
     * Regras de validação para atualizar quantidade mínima de estoque.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'quantidade_minima' => 'required|integer|min:0',
        ];
    }

    public function messages()
    {
        return [
            'quantidade_minima.required' => 'A quantidade mínima é obrigatória.',
            'quantidade_minima.integer'  => 'A quantidade mínima deve ser um número inteiro.',
            'quantidade_minima.min'      => 'A quantidade mínima não pode ser negativa.',
        ];
    }
}
