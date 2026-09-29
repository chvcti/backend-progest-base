<?php

namespace App\Http\Requests\Movimentacao;

use App\Http\Requests\BaseFormRequest;

class DevolverRequest extends BaseFormRequest
{
    /**
     * Regras de validação para devolução de itens (individual ou em lote).
     *
     * @return array
     */
    public function rules()
    {
        if ($this->has('itens')) {
            return [
                'motivo'                        => 'nullable|string|max:255',
                'itens'                         => 'required|array|min:1',
                'itens.*.item_movimentacao_id' => 'required|integer',
                'itens.*.quantidade_devolvendo' => 'required|numeric|min:0',
            ];
        }

        return [
            'item_movimentacao_id' => 'required|integer',
            'quantidade'           => 'required|numeric|min:1',
            'lote'                 => 'required|string|max:100',
            'motivo'               => 'nullable|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'item_movimentacao_id.required' => 'O identificador do item da movimentação é obrigatório.',
            'quantidade.required'           => 'A quantidade a devolver é obrigatória.',
            'quantidade.min'                => 'A quantidade a devolver deve ser de no mínimo 1.',
            'lote.required'                 => 'O número do lote é obrigatório.',
            'itens.required'                => 'A lista de itens para devolução é obrigatória.',
            'itens.min'                     => 'Informe ao menos um item para devolução.',
        ];
    }
}
