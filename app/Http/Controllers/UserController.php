<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\RegimeContratacao;
use App\Models\Setores;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\UserRequest;

class UserController extends Controller
{
    public function store(Request $request)
    {
        $dadosValidados = method_exists($request, 'validated') 
            ? ($request->validated()['user'] ?? $request->validated()) 
            : ($request->input('user') ?? $request->all());
        $dadosBrutos    = $request->all();

        // Padronização e captura da senha (suporta 'password' ou 'senha', no formato plano ou dentro de 'user')
        $rawPassword = $request->input('password')
            ?? $request->input('senha')
            ?? $request->input('user.password')
            ?? $request->input('user.senha')
            ?? ($dadosValidados['password'] ?? ($dadosValidados['senha'] ?? null))
            ?? ($dadosBrutos['user']['password'] ?? ($dadosBrutos['user']['senha'] ?? ($dadosBrutos['password'] ?? ($dadosBrutos['senha'] ?? null))));

        DB::beginTransaction();
        try {
            $user                  = new User;
            $user->status          = $dadosValidados['status'] ?? $request->input('status', 'A');
            $user->name            = mb_strtoupper($dadosValidados['name'] ?? $request->input('name'));
            $user->email           = mb_strtolower($dadosValidados['email'] ?? $request->input('email'));
            $user->telefone        = isset($dadosValidados['telefone']) 
                ? preg_replace('/\D/', '', $dadosValidados['telefone']) 
                : ($request->filled('telefone') ? preg_replace('/\D/', '', $request->input('telefone')) : null);
            $user->data_nascimento = $dadosValidados['data_nascimento'] ?? $request->input('data_nascimento') ?? null;
            $user->cpf             = preg_replace('/\D/', '', $dadosValidados['cpf'] ?? $request->input('cpf') ?? '');
            $user->regime_contratacao_id = $dadosValidados['regime_contratacao_id'] ?? $request->input('regime_contratacao_id') ?? null;

            // Garantia de persistência de senha devidamente criptografada com Hash::make
            if (!empty($rawPassword)) {
                $user->password = Hash::make($rawPassword);
            }

            $user->save();

            $this->sincronizarSetores($user, $dadosBrutos);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao salvar usuário e setores: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro ao salvar usuário.'], 500);
        }

        $user = User::with(['setores' => function ($q) {
            $q->select('setores.id', 'setores.polo_id', 'setores.nome', 'setores.descricao', 'setores.status', 'setores.estoque', 'setores.tipo');
        }])->find($user->id);

        return response()->json(['status' => true, 'data' => $user]);
    }

    public function add(UserRequest $request)
    {
        return $this->store($request);
    }

    public function update(UserRequest $request)
    {
        $dadosValidados = $request->validated()['user'] ?? $request->validated();
        $dadosBrutos    = $request->all();
        $id             = $dadosValidados['id'] ?? null;

        $user = User::find($id);
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Usuário não encontrado.'], 404);
        }

        // Não permite que ninguém edite o adminti exceto ele mesmo (se necessário)
        if ($user->email === 'adminti@gmail.com') {
            $currentUser = \Illuminate\Support\Facades\Auth::user();
            if (!$currentUser || $currentUser->email !== 'adminti@gmail.com') {
                return response()->json(['status' => false, 'message' => 'O super admin não pode ser alterado por outros usuários.'], 403);
            }
        }

        // Padronização e captura da senha no update (suporta 'password' ou 'senha')
        $rawPassword = $request->input('password')
            ?? $request->input('senha')
            ?? $request->input('user.password')
            ?? $request->input('user.senha')
            ?? ($dadosValidados['password'] ?? ($dadosValidados['senha'] ?? null))
            ?? ($dadosBrutos['user']['password'] ?? ($dadosBrutos['user']['senha'] ?? null));

        DB::beginTransaction();
        try {
            $user->name            = mb_strtoupper($dadosValidados['name']);
            $user->email           = mb_strtolower($dadosValidados['email']);
            $user->telefone        = isset($dadosValidados['telefone']) ? preg_replace('/\D/', '', $dadosValidados['telefone']) : null;
            $user->data_nascimento = $dadosValidados['data_nascimento'] ?? null;
            $user->cpf             = preg_replace('/\D/', '', $dadosValidados['cpf']);
            $user->status          = $dadosValidados['status'] ?? $user->status;
            $user->regime_contratacao_id    = $dadosValidados['regime_contratacao_id'] ?? null;

            if (!empty($rawPassword)) {
                $user->password = Hash::make($rawPassword);
            }

            $user->save();

            $this->sincronizarSetores($user, $dadosBrutos);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao atualizar usuário e setores: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro ao atualizar usuário.'], 500);
        }

        $user = User::with(['setores' => function ($q) {
            $q->select('setores.id', 'setores.polo_id', 'setores.nome', 'setores.descricao', 'setores.status', 'setores.estoque', 'setores.tipo');
        }])->find($user->id);

        return response()->json(['status' => true, 'data' => $user]);
    }

    public function listAll(Request $request)
    {
        $query = User::query()->where('email', '!=', 'adminti@gmail.com');

        // Busca textual multi-campo (name, email, cpf, telefone)
        $search = $request->input('search');
        if (!empty($search)) {
            $termoBusca = '%' . $search . '%';
            $query->where(function ($q) use ($termoBusca) {
                $q->where('name', 'LIKE', $termoBusca)
                  ->orWhere('email', 'LIKE', $termoBusca)
                  ->orWhere('cpf', 'LIKE', $termoBusca)
                  ->orWhere('telefone', 'LIKE', $termoBusca);
            });
        }

        // Filtro por regime_contratacao_id
        $RegimeContratacao = $request->input('regime_contratacao_id');
        if (!empty($RegimeContratacao)) {
            $query->where('regime_contratacao_id', $RegimeContratacao);
        }

        // Filtro por status
        $status = $request->input('status');
        if (!empty($status)) {
            $query->where('status', $status);
        }

        // Filtros legados (compatibilidade com chamadas anteriores)
        $filters = $request->input('filters', []);
        foreach ($filters as $condition) {
            if (is_array($condition)) {
                foreach ($condition as $coluna => $valor) {
                    $colunasPermitidas = ['name', 'email', 'cpf', 'telefone', 'status', 'regime_contratacao_id'];
                    if (in_array($coluna, $colunasPermitidas)) {
                        $query->where($coluna, 'LIKE', '%' . $valor . '%');
                    }
                }
            }
        }

        // Ordenação dinâmica
        $sortBy  = $request->input('sort_by', 'name');
        $sortDir = $request->input('sort_dir', 'asc');
        $colunasOrdenacao = ['id', 'name', 'email', 'cpf', 'status', 'regime_contratacao_id'];
        if (in_array($sortBy, $colunasOrdenacao) && in_array(strtolower($sortDir), ['asc', 'desc'])) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('name', 'asc');
        }

        $users = $query->select(
            'id', 'name', 'email', 'cpf', 'telefone', 'data_nascimento', 'status', 'regime_contratacao_id'
        )->get();

        return response()->json(['status' => true, 'data' => $users]);
    }

    public function listData(Request $request)
    {
        $user = User::with(['setores' => function ($q) {
            $q->select('setores.id', 'setores.polo_id', 'setores.nome', 'setores.descricao', 'setores.status', 'setores.estoque', 'setores.tipo');
        }])->find($request->id);

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Usuário não encontrado']);
        }

        $RegimeContratacao = $user->regime_contratacao_id ? RegimeContratacao::find($user->regime_contratacao_id) : null;

        return response()->json([
            'status'       => true,
            'data'         => $user,
            'regime_contratacao_id' => $RegimeContratacao,
            'setores'      => $user->setores,
        ]);
    }

    public function delete($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Usuário não encontrado.'], 404);
        }
        if ($user->email === 'adminti@gmail.com') {
            return response()->json(['status' => false, 'message' => 'O usuário super admin não pode ser desativado.'], 403);
        }

        // Toggle: se ativo → inativa, se inativo → ativa
        $user->status = $user->status === 'A' ? 'I' : 'A';
        $user->save();

        $acao = $user->status === 'A' ? 'ativado' : 'desativado';
        return response()->json(['status' => true, 'message' => "Usuário {$acao} com sucesso.", 'data' => $user]);
    }

    public function countUsers()
    {
        $total = User::where('status', 'A')->where('email', '!=', 'adminti@gmail.com')->count();
        return response()->json(['count' => $total]);
    }

    /**
     * Sincroniza os setores e perfis do usuário.
     * Aceita os dados de setores dentro de 'setores' ou 'setores_ids' (snake_case).
     */
    private function sincronizarSetores(User $user, array $dados)
    {
        $setoresRecebidos = $dados['setores_ids'] ?? $dados['setores'] ?? [];
        if (empty($setoresRecebidos) && isset($dados['user']) && is_array($dados['user'])) {
            $setoresRecebidos = $dados['user']['setores_ids'] ?? $dados['user']['setores'] ?? [];
        }

        if (!is_array($setoresRecebidos) || empty($setoresRecebidos)) {
            return;
        }

        $dadosSync = [];
        foreach ($setoresRecebidos as $item) {
            $setorId = null;
            $perfil  = null;

            if (is_array($item)) {
                $setorId = $item['id'] ?? ($item['setor_id'] ?? null);
                $perfil  = $item['perfil'] ?? null;
            } elseif (is_object($item)) {
                $setorId = $item->id ?? ($item->setor_id ?? null);
                $perfil  = $item->perfil ?? null;
            } else {
                $setorId = $item;
            }

            if (!is_numeric($setorId) || $setorId <= 0) {
                continue;
            }

            $perfil            = $perfil ?? 'solicitante';
            $dadosSync[$setorId] = ['perfil' => $perfil];
        }

        if (!empty($dadosSync)) {
            $idsValidos = Setores::whereIn('id', array_keys($dadosSync))->get();
            $dadosFiltrados = [];
            
            $currentUser = \Illuminate\Support\Facades\Auth::user();
            $isSuperAdmin = $currentUser ? $currentUser->isSuperAdmin() : false;
            $isAdminCaf   = $currentUser ? $currentUser->isAdminCaf() : false;
            
            $polosAdmin = $currentUser ? $currentUser->polosAdministrados->pluck('id')->toArray() : [];
            $setoresAdmin = $currentUser ? $currentUser->setores()->wherePivot('perfil', 'admin')->pluck('setores.id')->toArray() : [];

            foreach ($idsValidos as $setorValido) {
                $idValido = $setorValido->id;
                $perfilDesejado = $dadosSync[$idValido]['perfil'];
                
                // Super Admin e Admin CAF têm poder total.
                if (!$isSuperAdmin && !$isAdminCaf) {
                    $isAdminPoloDesseSetor = in_array($setorValido->polo_id, $polosAdmin);
                    $isAdminDesseSetor = in_array($idValido, $setoresAdmin);
                    
                    if (!$isAdminPoloDesseSetor && !$isAdminDesseSetor) {
                        throw new \Exception("Você não tem permissão para vincular usuários ao setor: " . $setorValido->nome);
                    }

                    if ($perfilDesejado === 'admin') {
                        throw new \Exception("Apenas o Admin da CAF ou o Super Admin podem conceder o perfil de Administrador.");
                    }
                }

                if ($perfilDesejado === 'almoxarife' && !$setorValido->estoque) {
                    throw new \Exception("O perfil de almoxarife só pode ser concedido a setores com estoque.");
                }

                $dadosFiltrados[$idValido] = $dadosSync[$idValido];
            }
            $user->setores()->sync($dadosFiltrados);
        }
    }
}
