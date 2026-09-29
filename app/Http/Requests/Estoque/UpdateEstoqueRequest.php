<?php

namespace App\Http\Requests\Estoque;

use App\Http\Requests\BaseFormRequest;

class UpdateEstoqueRequest extends BaseFormRequest
{
    protected function prepareForValidation()
    {
        if ($this->has('estoque') && is_array($this->input('estoque'))) {
            $this->merge($this->input('estoque'));
        } elseif ($this->has('fornecedor') && is_array($this->input('fornecedor'))) {
            $this->merge($this->input('fornecedor'));
        }

        if (!$this->has('id') && $this->route('id')) {
            $this->merge(['id' => $this->route('id')]);
        }
    }

    public function rules()
    {
        return [
            'id'                => 'sometimes|required|exists:estoque,id',
            'produto_id'        => 'sometimes|required|exists:produtos,id',
            'setor_id'          => 'sometimes|required|exists:setores,id',
            'quantidade'        => 'nullable|numeric|min:0',
            'quantidade_minima' => 'nullable|integer|min:0',
            'status'            => 'nullable|string|in:A,I,D',
        ];
    }

    public function messages()
    {
        return [
            'produto_id.exists' => 'Produto não encontrado.',
            'setor_id.exists'   => 'Setor não encontrado.',
        ];
    }
}
