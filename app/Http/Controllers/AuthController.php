<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\TipoVinculo;
use App\Models\Setores;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();
        $user = User::with(['setores', 'polosAdministrados'])->where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Credenciais inválidas'
            ], 401);
        }

        // Verifica se a conta do usuário está ativa
        if (isset($user->status) && $user->status !== 'A') {
            return response()->json([
                'status'  => false,
                'message' => 'Conta inativa. Entre em contato com a administração do sistema.'
            ], 403);
        }

        // Revoga tokens anteriores para prevenir acúmulo de sessões fantasmas
        $user->tokens()->delete();

        $token = $user->createToken('progest-auth-token')->plainTextToken;
        return response()->json(['user' => $user, 'token' => $token], 200);
    }

    public function register(RegisterRequest $request)
    {
        return response()->json([
            'status'  => false,
            'message' => 'O auto-cadastro público está desativado por motivos de segurança institucional. Contate o administrador.'
        ], 403);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json([
            'status'  => 'success',
            'message' => 'Sessão encerrada com sucesso',
        ], 200);
    }
}