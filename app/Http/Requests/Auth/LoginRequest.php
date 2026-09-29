<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class LoginRequest extends BaseFormRequest
{
    /**
     * Regras de validação para autenticação de usuário.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email'    => 'required|string|email|max:255',
            'password' => 'required|string',
        ];
    }

    /**
     * Mensagens de validação personalizadas.
     */
    public function messages()
    {
        return [
            'email.required'    => 'O campo e-mail é obrigatório.',
            'email.email'       => 'Informe um endereço de e-mail válido.',
            'password.required' => 'O campo senha é obrigatório.',
        ];
    }
}
