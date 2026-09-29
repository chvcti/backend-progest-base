<?php

namespace App\Http\Requests\Movimentacao;

use App\Http\Requests\BaseFormRequest;

class UpdateRascunhoRequest extends BaseFormRequest
{
    /**
     * Regras de validação para edição de rascunho de pedido/movimentação.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'setor_origem_id'               => 'nullable|integer|exists:setores,id',
            'observacao'                    => 'nullable|string',
            'status_solicitacao'            => 'nullable|in:C,P',
            'itens'                         => 'required|array|min:1',
            'itens.*.produto_id'            => 'required|integer|exists:produtos,id',
            'itens.*.quantidade_solicitada' => 'required|numeric|min:0.0001',
        ];
    }

    public function messages()
    {
        return [
            'setor_origem_id.exists'                => 'Setor de origem não encontrado.',
            'itens.required'                        => 'O pedido deve conter pelo menos um item.',
            'itens.min'                             => 'O pedido deve conter pelo menos um item.',
            'itens.*.produto_id.required'           => 'O produto é obrigatório em cada item.',
            'itens.*.produto_id.exists'             => 'Produto não encontrado.',
            'itens.*.quantidade_solicitada.required' => 'A quantidade solicitada é obrigatória.',
            'itens.*.quantidade_solicitada.min'     => 'A quantidade solicitada deve ser maior que zero.',
        ];
    }
}
