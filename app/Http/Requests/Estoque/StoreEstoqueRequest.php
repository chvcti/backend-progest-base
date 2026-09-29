<?php

namespace App\Http\Requests\Estoque;

use App\Http\Requests\BaseFormRequest;

class StoreEstoqueRequest extends BaseFormRequest
{
    protected function prepareForValidation()
    {
        if ($this->has('estoque') && is_array($this->input('estoque'))) {
            $this->merge($this->input('estoque'));
        }
    }

    public function rules()
    {
        return [
            'produto_id'        => 'required|exists:produtos,id',
            'setor_id'          => 'required|exists:setores,id',
            'quantidade'        => 'nullable|numeric|min:0',
            'quantidade_minima' => 'nullable|integer|min:0',
        ];
    }

    public function messages()
    {
        return [
            'produto_id.required' => 'O produto é obrigatório.',
            'produto_id.exists'   => 'Produto não encontrado.',
            'setor_id.required'   => 'O setor é obrigatório.',
            'setor_id.exists'     => 'Setor não encontrado.',
        ];
    }
}
