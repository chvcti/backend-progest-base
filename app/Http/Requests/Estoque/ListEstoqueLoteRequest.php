<?php

namespace App\Http\Requests\Estoque;

use App\Http\Requests\BaseFormRequest;

class ListEstoqueLoteRequest extends BaseFormRequest
{
    /**
     * Regras de validação para listar lotes de um estoque.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'estoque_id' => 'required|exists:estoque,id',
        ];
    }

    public function messages()
    {
        return [
            'estoque_id.required' => 'O identificador do estoque é obrigatório.',
            'estoque_id.exists'   => 'Registro de estoque não encontrado.',
        ];
    }
}
