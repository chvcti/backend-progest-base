<?php

namespace App\Http\Requests\Movimentacao;

use App\Http\Requests\BaseFormRequest;

class ConsumoInternoRequest extends BaseFormRequest
{
    /**
     * Regras de validação para registro de consumo interno.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'produto_id' => 'required|integer|exists:produtos,id',
            'lote'       => 'required|string|max:100',
            'setor_id'   => 'required|integer|exists:setores,id',
            'quantidade' => 'required|numeric|gt:0',
            'observacao' => 'nullable|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'produto_id.required' => 'O produto é obrigatório.',
            'produto_id.exists'   => 'Produto não encontrado.',
            'lote.required'       => 'O lote é obrigatório.',
            'setor_id.required'   => 'O setor é obrigatório.',
            'setor_id.exists'     => 'Setor não encontrado.',
            'quantidade.required' => 'A quantidade é obrigatória.',
            'quantidade.gt'       => 'A quantidade deve ser maior que zero.',
        ];
    }
}
