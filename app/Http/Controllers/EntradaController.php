<?php

namespace App\Http\Controllers;

use App\Models\Entrada;
use App\Models\Estoque;
use App\Models\EstoqueLote;
use App\Models\ItensEntrada;
use App\Models\Produto;
use App\Models\Setores;
use App\Http\Requests\StoreEntradaRequest;
use App\Http\Requests\UpdateEntradaRequest;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EntradaController extends Controller
{
    /**
     * Um produto só entra num setor cujo tipo aceite o tipo do seu grupo.
     * Setores do tipo 'Ambos' recebem medicamentos e materiais.
     */
    private function produtoCompativelComSetor($produto, $setor): bool
    {
        if (!$produto || !$produto->grupoProduto) {
            return false;
        }

        return $setor->tipo === 'Ambos'
            || $produto->grupoProduto->tipo === $setor->tipo;
    }

    /**
     * Registrar uma nova entrada de produtos no estoque do setor.
     */
    public function add(StoreEntradaRequest $request)
    {
        $data = $request->validated();

        $setor = Setores::find($data['setor_id']);

        $user = auth()->user();
        $podeRegistrar = $user && (
            $user->isSuperAdmin()
            || $user->isAdmin()
            || $user->setores()
                ->where('setores.id', $setor->id)
                ->whereIn('usuario_setor.perfil', ['admin', 'almoxarife'])
                ->exists()
        );

        if (!$podeRegistrar) {
            return response()->json([
                'status' => false,
                'message' => 'Acesso negado. Apenas administradores ou almoxarifes vinculados ao setor distribuidor podem registrar ou alterar entradas.'
            ], 403);
        }

        if (!$setor->estoque) {
            return response()->json([
                'status' => false,
                'message' => 'O setor selecionado não possui controle de estoque.'
            ], 400);
        }

        // Apenas distribuidores centrais (ou setores com papel distribuidor) podem receber NF de fornecedores externos.
        $ehDistribuidorCentral = (str_contains(strtoupper($setor->nome), 'CAF') || str_contains(strtoupper($setor->nome), 'ALMOXARIFADO') || str_contains(strtoupper($setor->nome), 'CENTRAL'));
        $ehDistribuidor = DB::table('setor_distribuidor')->where('setor_distribuidor_id', $setor->id)->exists();

        if (!$ehDistribuidorCentral && !$ehDistribuidor) {
            return response()->json([
                'status' => false,
                'message' => 'O setor informado não é um distribuidor autorizado para recebimento de notas fiscais externas de fornecedores.'
            ], 422);
        }

        try {
            $entrada = DB::transaction(function () use ($data, $setor) {
                $entrada = Entrada::create([
                    'nota_fiscal' => mb_strtoupper(trim($data['nota_fiscal'])),
                    'setor_id' => $setor->id,
                    'fornecedor_id' => $data['fornecedor_id'],
                ]);

                foreach ($data['itens'] as $item) {
                    $produto = Produto::with('grupoProduto')->find($item['produto_id']);

                    if (!$produto) {
                        throw new \RuntimeException('Produto não encontrado.');
                    }

                    if (!$this->produtoCompativelComSetor($produto, $setor)) {
                        throw new \RuntimeException('Produto "' . $produto->nome . '" não é compatível com o tipo do setor.');
                    }

                    $itemEntrada = ItensEntrada::create([
                        'entrada_id' => $entrada->id,
                        'produto_id' => $produto->id,
                        'quantidade' => $item['quantidade'],
                        'valor_unitario' => isset($item['valor_unitario']) && $item['valor_unitario'] !== '' && $item['valor_unitario'] !== null
                            ? round((float) $item['valor_unitario'], 4)
                            : null,
                        'lote' => mb_strtoupper(trim($item['lote'])),
                        'data_vencimento' => $item['data_vencimento'],
                        'data_fabricacao' => $item['data_fabricacao'] ?? null,
                    ]);

                    // Evitando Lost Update na Entrada
                    $estoqueBase = Estoque::firstOrCreate(
                        ['produto_id' => $produto->id, 'setor_id' => $setor->id],
                        ['quantidade_atual' => 0, 'quantidade_minima' => 0, 'status_disponibilidade' => 'D']
                    );

                    // Tranca a linha para ninguém ler enquanto somamos
                    $estoqueTravado = Estoque::where('id', $estoqueBase->id)->lockForUpdate()->first();
                    $estoqueTravado->quantidade_atual += $itemEntrada->quantidade;
                    $estoqueTravado->status_disponibilidade = 'D';
                    $estoqueTravado->save();    

                    // Atualizar ou criar registro de estoque por lote
                    $estoqueLote = EstoqueLote::firstOrCreate(
                        [
                            'setor_id' => $setor->id,
                            'produto_id' => $produto->id,
                            'lote' => mb_strtoupper(trim($item['lote'])),
                        ],
                        [
                            'quantidade_disponivel' => 0,
                            'valor_unitario' => $itemEntrada->valor_unitario,
                            'data_vencimento' => $item['data_vencimento'],
                            'data_fabricacao' => $item['data_fabricacao'] ?? null,
                        ]
                    );

                    $estoqueLote->quantidade_disponivel += $itemEntrada->quantidade;
                    if ($itemEntrada->valor_unitario !== null) {
                        $estoqueLote->valor_unitario = $itemEntrada->valor_unitario;
                    }
                    $estoqueLote->save();
                }

                return $entrada;
            });

            $entrada->load(['setor', 'fornecedor', 'itens.produto']);

            return response()->json([
                'status' => true,
                'message' => 'Entrada registrada com sucesso.',
                'data' => $entrada,
            ], 201);
        } catch (\RuntimeException $e) {
            Log::warning('Falha de validação na criação de entrada: ' . $e->getMessage(), [
                'payload' => $data,
            ]);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Erro ao registrar entrada: ' . $e->getMessage(), [
                'payload' => $data,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao registrar entrada.'
            ], 500);
        }
    }

    public function store(StoreEntradaRequest $request)
    {
        return $this->add($request);
    }

    /**
     * Listar entradas com seus itens e detalhes dos produtos
     */
    public function list(Request $request)
    {
        try {
            $data = $request->all();
            $filters = $data['filters'] ?? [];
            $perPage = $data['per_page'] ?? 15;

            $query = Entrada::with([
                // 'codigo_unidade' não existe na tabela 'Setores' (migration), removido para evitar SQL error
                'setor:id,nome,tipo',
                'fornecedor:id,razao_social_nome,tipo_pessoa,status',
                'itens.produto:id,nome,marca,grupo_produto_id,unidade_medida_id,status',
                'itens.produto.grupoProduto:id,nome,tipo',
                'itens.produto.unidadeMedida:id,nome',
            ])->orderByDesc('created_at');

            if (!empty($filters)) {
                foreach ($filters as $key => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    switch ($key) {
                        case 'nota_fiscal':
                            $query->where('nota_fiscal', 'like', '%' . trim($value) . '%');
                            break;
                        case 'setor_id':
                            $query->where('setor_id', $value);
                            break;
                        case 'fornecedor_id':
                            $query->where('fornecedor_id', $value);
                            break;
                    }
                }
            }

            /** @var LengthAwarePaginator $entradas */
            $entradas = $query->paginate($perPage);

            $entradas->getCollection()->transform(function (Entrada $entrada) {
                return [
                    'id' => $entrada->id,
                    'nota_fiscal' => $entrada->nota_fiscal,
                    'created_at' => $entrada->created_at,
                    'setor' => $entrada->setor,
                    'fornecedor' => $entrada->fornecedor,
                    'itens' => $entrada->itens->map(function (ItensEntrada $item) {
                        return [
                            'id' => $item->id,
                            'quantidade' => $item->quantidade,
                            'lote' => $item->lote,
                            'data_vencimento' => $item->data_vencimento,
                            'data_fabricacao' => $item->data_fabricacao,
                            'produto' => [
                                'id' => $item->produto->id,
                                'nome' => $item->produto->nome,
                                'marca' => $item->produto->marca,
                                'status' => $item->produto->status,
                                'grupo_produto' => $item->produto->grupoProduto,
                                'unidade_medida' => $item->produto->unidadeMedida,
                            ],
                        ];
                    })->values(),
                ];
            });

            return response()->json([
                'status' => true,
                'data' => $entradas,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar entradas: ' . $e->getMessage(), [
                'payload' => $request->all(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao listar entradas.'
            ], 500);
        }
    }

    /**
     * Atualizar uma entrada existente e seus itens ajustando o estoque
     */
    public function update(UpdateEntradaRequest $request)
    {
        $data = $request->validated();

        $entrada = Entrada::with(['itens'])->find($data['id']);
        $setor = Setores::find($data['setor_id']);

        $user = auth()->user();
        $podeAlterar = $user && (
            $user->isSuperAdmin()
            || $user->isAdmin()
            || $user->setores()
                ->where('setores.id', $setor->id)
                ->whereIn('usuario_setor.perfil', ['admin', 'almoxarife'])
                ->exists()
        );

        if (!$podeAlterar) {
            return response()->json([
                'status' => false,
                'message' => 'Acesso negado. Apenas administradores ou almoxarifes vinculados ao setor podem registrar ou alterar entradas.'
            ], 403);
        }

        if (!$setor->estoque) {
            return response()->json([
                'status' => false,
                'message' => 'O setor selecionado não possui controle de estoque.'
            ], 400);
        }

        try {
            $entradaAtualizada = DB::transaction(function () use ($data, $entrada, $setor) {
                // Reverter estoque dos itens atuais (tanto estoque geral quanto lotes)
                foreach ($entrada->itens as $itemExistente) {
                    // Reverter estoque geral
                    $estoque = Estoque::where('produto_id', $itemExistente->produto_id)
                        ->where('setor_id', $entrada->setor_id)
                        ->first();

                    if ($estoque) {
                        $estoque->quantidade_atual -= $itemExistente->quantidade;
                        if ($estoque->quantidade_atual < 0) {
                            $estoque->quantidade_atual = 0;
                        }
                        $estoque->status_disponibilidade = $estoque->quantidade_atual > 0 ? 'D' : 'I';
                        $estoque->save();
                    }

                    // Reverter estoque de lote
                    if ($itemExistente->lote) {
                        $estoqueLote = EstoqueLote::where('setor_id', $entrada->setor_id)
                            ->where('produto_id', $itemExistente->produto_id)
                            ->where('lote', $itemExistente->lote)
                            ->first();

                        if ($estoqueLote) {
                            $estoqueLote->quantidade_disponivel -= $itemExistente->quantidade;
                            if ($estoqueLote->quantidade_disponivel < 0) {
                                $estoqueLote->quantidade_disponivel = 0;
                            }
                            $estoqueLote->save();
                        }
                    }
                }

                // Atualiza dados da entrada
                $entrada->update([
                    'nota_fiscal' => mb_strtoupper(trim($data['nota_fiscal'])),
                    'setor_id' => $setor->id,
                    'fornecedor_id' => $data['fornecedor_id'],
                ]);

                // Remove itens antigos
                ItensEntrada::where('entrada_id', $entrada->id)->delete();

                // Cadastra novos itens e atualiza estoque
                foreach ($data['itens'] as $item) {
                    $produto = Produto::with('grupoProduto')->find($item['produto_id']);

                    if (!$this->produtoCompativelComSetor($produto, $setor)) {
                        $nomeProduto = $produto->nome ?? ('ID ' . $item['produto_id']);
                        throw new \RuntimeException('Produto "' . $nomeProduto . '" não é compatível com o tipo do setor.');
                    }

                    $itemEntrada = ItensEntrada::create([
                        'entrada_id' => $entrada->id,
                        'produto_id' => $produto->id,
                        'quantidade' => $item['quantidade'],
                        'lote' => mb_strtoupper(trim($item['lote'])),
                        'data_vencimento' => $item['data_vencimento'],
                        'data_fabricacao' => $item['data_fabricacao'] ?? null,
                    ]);

                    // Atualizar estoque geral
                    $estoque = Estoque::firstOrCreate(
                        [
                            'produto_id' => $produto->id,
                            'setor_id' => $setor->id,
                        ],
                        [
                            'quantidade_atual' => 0,
                            'quantidade_minima' => 0,
                            'status_disponibilidade' => 'D',
                        ]
                    );

                    $estoque->quantidade_atual += $itemEntrada->quantidade;
                    $estoque->status_disponibilidade = $estoque->quantidade_atual > 0 ? 'D' : 'I';
                    $estoque->save();

                    // Atualizar ou criar estoque de lote
                    $estoqueLote = EstoqueLote::firstOrCreate(
                        [
                            'setor_id' => $setor->id,
                            'produto_id' => $produto->id,
                            'lote' => mb_strtoupper(trim($item['lote'])),
                        ],
                        [
                            'quantidade_disponivel' => 0,
                            'data_vencimento' => $item['data_vencimento'],
                            'data_fabricacao' => $item['data_fabricacao'] ?? null,
                        ]
                    );

                    $estoqueLote->quantidade_disponivel += $itemEntrada->quantidade;
                    $estoqueLote->save();
                }

                return $entrada;
            });

            $entradaAtualizada->load(['setor', 'fornecedor', 'itens.produto']);

            return response()->json([
                'status' => true,
                'message' => 'Entrada atualizada com sucesso.',
                'data' => $entradaAtualizada,
            ]);
        } catch (\RuntimeException $e) {
            Log::warning('Falha de validação na atualização de entrada: ' . $e->getMessage(), [
                'payload' => $data,
            ]);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar entrada: ' . $e->getMessage(), [
                'payload' => $data,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao atualizar entrada.'
            ], 500);
        }
    }

    /**
     * Remover uma entrada e reverter o estoque relacionado
     */
    public function delete(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            return response()->json([
                'status' => false,
                'message' => 'Acesso negado. Apenas administradores podem registrar ou alterar entradas.'
            ], 403);
        }

        $data = $request->all();

        $validator = Validator::make($data, [
            'id' => 'required|exists:entrada,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'validacao' => true,
                'erros' => $validator->errors()
            ], 422);
        }

        try {
            DB::transaction(function () use ($data) {
                $entrada = Entrada::with('itens')->find($data['id']);

                foreach ($entrada->itens as $item) {
                    $estoque = Estoque::where('produto_id', $item->produto_id)
                        ->where('setor_id', $entrada->setor_id)
                        ->first();

                    if ($estoque) {
                        $estoque->quantidade_atual -= $item->quantidade;
                        if ($estoque->quantidade_atual < 0) {
                            $estoque->quantidade_atual = 0;
                        }
                        $estoque->status_disponibilidade = $estoque->quantidade_atual > 0 ? 'D' : 'I';
                        $estoque->save();
                    }
                }

                ItensEntrada::where('entrada_id', $entrada->id)->delete();
                $entrada->delete();
            });

            return response()->json([
                'status' => true,
                'message' => 'Entrada removida com sucesso.'
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao remover entrada: ' . $e->getMessage(), [
                'payload' => $data,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao remover entrada.'
            ], 500);
        }
    }
}
