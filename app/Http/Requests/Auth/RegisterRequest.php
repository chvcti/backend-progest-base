<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class RegisterRequest extends BaseFormRequest
{
    /**
     * Regras de validação para registro de novo usuário.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ];
    }

    /**
     * Mensagens de validação personalizadas.
     */
    public function messages()
    {
        return [
            'name.required'      => 'O campo nome é obrigatório.',
            'email.required'     => 'O campo e-mail é obrigatório.',
            'email.unique'       => 'Este e-mail já está em uso.',
            'password.required'  => 'O campo senha é obrigatório.',
            'password.min'       => 'A senha deve conter no mínimo 8 caracteres.',
            'password.confirmed' => 'A confirmação de senha não confere.',
        ];
    }
}
