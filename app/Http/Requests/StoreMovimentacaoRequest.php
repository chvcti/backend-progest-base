<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMovimentacaoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        // A autorização baseada em perfil ou permissão pode ser adicionada aqui.
        // O controle de acesso ao setor já costuma estar sendo validado no Controller (ou pode ser migrado para cá).
        return auth()->check() || $this->has('usuario_id');
    }

    /**
     * Intercepta a requisição antes da validação e corrige/prepara os dados do payload.
     * Remove o débito técnico de normalização que estava no controller.
     */
    protected function prepareForValidation()
    {
        $data = $this->all();

        // 1. Normalizar o usuário atual caso não seja enviado no payload
        if (!isset($data['usuario_id']) && auth()->check()) {
            $data['usuario_id'] = auth()->id();
        }

        // 2. Normalizar os itens: conversão do padrão camelCase do frontend para o padrão snake_case do banco
        if (isset($data['itens']) && is_array($data['itens'])) {
            foreach ($data['itens'] as $key => $item) {
                // Converter alias de 'quantidade' para 'quantidade_solicitada'
                if (isset($item['quantidade']) && !isset($item['quantidade_solicitada'])) {
                    $data['itens'][$key]['quantidade_solicitada'] = $item['quantidade'];
                }

                // Converter 'produtoId' camelCase para 'produto_id'
                if (isset($item['produtoId']) && !isset($item['produto_id'])) {
                    $data['itens'][$key]['produto_id'] = $item['produtoId'];
                }
            }
        }

        // Atualiza a requisição com os dados normalizados
        $this->replace($data);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $status = $this->input('status_solicitacao', 'P');
        $isRascunho = ($status === 'C');
        $tipo = $this->input('tipo', '');

        // Regra dinâmica para a quantidade baseada no tipo de movimentação
        $qtdRule = ($tipo === 'D') 
            ? 'required_with:itens|integer|min:1' 
            : 'required_with:itens|numeric|min:0.0001';

        // Regras para o array de itens: rascunho aceita vazio, demais exigem mínimo 1 item.
        $itensRules = $isRascunho ? ['nullable', 'array'] : ['required', 'array', 'min:1'];

        return [
            'usuario_id'         => 'required|integer|exists:users,id',
            'tipo'               => 'required|in:T,D,S',
            'status_solicitacao' => 'nullable|in:P,C',
            
            // Regras estritas: Ambos setores precisam existir
            'setor_origem_id'    => 'required|integer|exists:setores,id',
            'setor_destino_id'   => 'required|integer|exists:setores,id|different:setor_origem_id',

            'observacao'         => 'nullable|string|max:1000',

            'itens'                         => $itensRules,
            'itens.*.produto_id'            => 'required_with:itens|integer|exists:produtos,id',
            'itens.*.quantidade_solicitada' => $qtdRule,
        ];
    }

    /**
     * Mensagens amigáveis de validação
     */
    public function messages()
    {
        return [
            'setor_destino_id.different' => 'O setor de destino não pode ser igual ao setor de origem.',
            'itens.min' => 'Ao menos um item deve ser selecionado para uma solicitação pendente.',
            'itens.*.produto_id.exists' => 'O produto informado não existe na base de dados.',
        ];
    }
}
