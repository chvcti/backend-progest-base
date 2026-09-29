<?php

namespace App\Http\Requests;

class UpdateEntradaRequest extends BaseFormRequest
{
    /**
     * Determina se o usuário está autorizado a fazer esta requisição.
     * A autorização fina é tratada via Middleware/ACL.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Prepara os dados antes da validação para suportar ID via rota ou body.
     */
    protected function prepareForValidation()
    {
        if (!$this->has('id') && $this->route('id')) {
            $this->merge(['id' => $this->route('id')]);
        }
    }

    /**
     * Regras de validação para atualização de entrada.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => 'required|exists:entrada,id',
            'nota_fiscal' => 'required|string|max:255',
            'setor_id' => 'required|exists:setores,id',
            'fornecedor_id' => 'required|exists:fornecedores,id',
            'itens' => 'required|array|min:1',
            'itens.*.produto_id' => 'required|exists:produtos,id',
            'itens.*.quantidade' => 'required|integer|min:1',
            'itens.*.valor_unitario' => 'nullable|numeric|min:0',
            'itens.*.lote' => 'required|string|max:50',
            'itens.*.data_vencimento' => 'required|date|after:today',
            'itens.*.data_fabricacao' => 'nullable|date|before_or_equal:today',
        ];
    }

    /**
     * Mensagens de erro personalizadas.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'id.required' => 'O ID da entrada é obrigatório.',
            'id.exists' => 'Entrada não encontrada.',
            'nota_fiscal.required' => 'A nota fiscal é obrigatória.',
            'setor_id.required' => 'O setor é obrigatório.',
            'setor_id.exists' => 'Setor não encontrado.',
            'fornecedor_id.required' => 'O fornecedor é obrigatório.',
            'fornecedor_id.exists' => 'Fornecedor não encontrado.',
            'itens.required' => 'Informe ao menos um item para a entrada.',
            'itens.array' => 'A lista de itens deve ser um array.',
            'itens.min' => 'Informe ao menos um item para a entrada.',
            'itens.*.produto_id.required' => 'Produto é obrigatório em todos os itens.',
            'itens.*.produto_id.exists' => 'Produto informado não foi encontrado.',
            'itens.*.quantidade.required' => 'Quantidade é obrigatória em todos os itens.',
            'itens.*.quantidade.integer' => 'Quantidade deve ser um número inteiro.',
            'itens.*.quantidade.min' => 'Quantidade deve ser ao menos 1.',
            'itens.*.lote.required' => 'O lote é obrigatório em todos os itens.',
            'itens.*.lote.max' => 'O lote deve ter no máximo 50 caracteres.',
            'itens.*.data_vencimento.required' => 'A data de vencimento é obrigatória.',
            'itens.*.data_vencimento.date' => 'A data de vencimento deve ser uma data válida.',
            'itens.*.data_vencimento.after' => 'A data de vencimento deve ser posterior à data atual.',
            'itens.*.data_fabricacao.date' => 'A data de fabricação deve ser uma data válida.',
            'itens.*.data_fabricacao.before_or_equal' => 'A data de fabricação não pode ser futura.',
        ];
    }
}
