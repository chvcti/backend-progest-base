<?php

namespace App\Http\Requests\Setores;

use App\Http\Requests\BaseFormRequest;

class StoreSetorRequest extends BaseFormRequest
{
    /**
     * Prepara os dados normalizando os wrappers legados 'Setores' ou 'setores'.
     */
    protected function prepareForValidation()
    {
        $data = $this->all();
        $setoresData = $data['Setores'] ?? $data['setores'] ?? null;
        if ($setoresData && is_array($setoresData)) {
            $this->merge($setoresData);
        }
    }

    /**
     * Regras de validação para criação de setor.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'polo_id'        => 'required|exists:polos,id',
            'nome'           => 'required|string|max:255',
            'descricao'      => 'nullable|string|max:1000',
            'status'         => 'sometimes|string|in:A,I',
            'estoque'        => 'sometimes|boolean',
            'tipo'           => 'sometimes|in:Medicamento,Material,Ambos',
            'distribuidor'   => 'nullable|array',
            'distribuidor.setor_distribuidor_id' => 'required_with:distribuidor|exists:setores,id',
            'distribuidores' => 'nullable|array',
            'distribuidores.*.distribuidor_id'       => 'sometimes|exists:setores,id',
            'distribuidores.*.setor_distribuidor_id' => 'sometimes|exists:setores,id',
            'distribuidores.*.prioridade'            => 'sometimes|integer|min:1',
            'distribuidores.*.tipo'                  => 'sometimes|in:Medicamento,Material,Ambos',
        ];
    }

    public function messages()
    {
        return [
            'polo_id.required' => 'O polo é obrigatório.',
            'polo_id.exists'   => 'Polo selecionado não existe.',
            'nome.required'    => 'O nome do setor é obrigatório.',
            'tipo.in'          => 'Tipo inválido. Deve ser Medicamento, Material ou Ambos.',
        ];
    }
}
