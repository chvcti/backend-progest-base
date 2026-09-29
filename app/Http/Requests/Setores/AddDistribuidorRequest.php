<?php

namespace App\Http\Requests\Setores;

use App\Http\Requests\BaseFormRequest;

class AddDistribuidorRequest extends BaseFormRequest
{
    /**
     * Regras de validação para associar distribuidor a setor.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'setor_solicitante_id'  => 'required|exists:setores,id',
            'setor_distribuidor_id' => 'required|exists:setores,id|different:setor_solicitante_id',
            'prioridade'            => 'nullable|integer|min:1',
            'tipo'                  => 'nullable|in:Medicamento,Material,Ambos',
        ];
    }

    public function messages()
    {
        return [
            'setor_solicitante_id.required'  => 'O setor solicitante é obrigatório.',
            'setor_solicitante_id.exists'    => 'Setor solicitante não encontrado.',
            'setor_distribuidor_id.required' => 'O setor distribuidor é obrigatório.',
            'setor_distribuidor_id.exists'   => 'Setor distribuidor não encontrado.',
            'setor_distribuidor_id.different'=> 'O setor distribuidor não pode ser igual ao setor solicitante.',
        ];
    }
}
