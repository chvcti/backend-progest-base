<?php

namespace App\Http\Requests\Movimentacao;

use App\Http\Requests\BaseFormRequest;

class ProcessMovimentacaoRequest extends BaseFormRequest
{
    /**
     * Mapeia status legado para action caso action não venha explícita.
     */
    protected function prepareForValidation()
    {
        if (!$this->has('action') && $this->has('status')) {
            $statusMap = [
                'A' => 'approve',
                'R' => 'reject',
                'P' => 'submit',
                'X' => 'cancel',
            ];
            $status = $this->input('status');
            $this->merge(['action' => $statusMap[$status] ?? null]);
        }
    }

    /**
     * Regras de validação para processamento da movimentação.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'action'               => 'required|in:approve,reject,submit,cancel',
            'status'               => 'nullable|string|in:A,R,P,X',
            'aprovador_usuario_id' => 'nullable|integer|exists:users,id',
            'usuario_id'           => 'nullable|integer|exists:users,id',
            'itens'                => 'nullable|array',
            'itens.*.id'           => 'sometimes|integer',
            'itens.*.quantidade_liberada' => 'sometimes|numeric|min:0',
            'motivo_rejeicao'      => 'nullable|string|max:500',
            'justificativa'        => 'nullable|string|max:500',
        ];
    }

    public function messages()
    {
        return [
            'action.required' => 'A ação a ser executada é obrigatória.',
            'action.in'       => 'Ação inválida. Valores aceitos: approve, reject, submit, cancel.',
        ];
    }
}
