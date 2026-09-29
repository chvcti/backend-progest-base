<?php

namespace App\Http\Controllers;

use App\Models\Estoque;
use App\Models\Movimentacao;
use App\Models\ItemMovimentacao;
use App\Models\Setores;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Retorna os indicadores e métricas consolidadas do dashboard
     * calculados via agregação direta no banco de dados.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function metrics(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $setorId = $request->input('setor_id') ?? $request->query('setor_id');

            // Se o setor_id não for informado, busca o primeiro setor vinculado ao usuário
            if (!$setorId && $user) {
                $primeiroSetor = $user->setores()->first();
                $setorId = $primeiroSetor?->id;
            }

            $setor = $setorId ? Setores::find($setorId) : null;
            $temEstoque = $setor ? (bool) $setor->estoque : true;

            $totalItens = 0;
            $abaixoMinimo = 0;
            $alerts = [];

            if ($setorId && $temEstoque) {
                $estoqueBase = Estoque::where('setor_id', $setorId);
                $totalItens = (clone $estoqueBase)->count();
                $abaixoMinimo = (clone $estoqueBase)
                    ->whereColumn('quantidade_atual', '<=', 'quantidade_minima')
                    ->count();

                // Top 5 produtos com estoque em nível crítico (abaixo ou no limite mínimo)
                $alerts = Estoque::with([
                    'produto:id,nome,codigo_simpas,codigo_barras,unidade_medida_id',
                    'produto.unidadeMedida:id,nome'
                ])
                    ->where('setor_id', $setorId)
                    ->whereColumn('quantidade_atual', '<=', 'quantidade_minima')
                    ->orderBy('quantidade_atual', 'asc')
                    ->limit(5)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'quantidade_atual' => (int) $item->quantidade_atual,
                            'quantidade_minima' => (int) $item->quantidade_minima,
                            'produto' => $item->produto ? [
                                'id' => $item->produto->id,
                                'nome' => $item->produto->nome,
                                'codigo_simpas' => $item->produto->codigo_simpas,
                                'codigo_barras' => $item->produto->codigo_barras,
                                'unidade_medida' => $item->produto->unidadeMedida ? [
                                    'id' => $item->produto->unidadeMedida->id,
                                    'nome' => $item->produto->unidadeMedida->nome,
                                    'sigla' => $item->produto->unidadeMedida->nome,
                                ] : null,
                            ] : null,
                        ];
                    });
            }

            // Pendentes de Entrada (Onde este setor é o destino e status é 'P')
            $pendentesEntrada = Movimentacao::where('status_solicitacao', 'P')
                ->when($setorId, function ($q, $setorId) {
                    $q->where('setor_destino_id', $setorId);
                })
                ->count();

            // Pendentes de Saída (Onde este setor é a origem e status é 'P')
            $pendentesSaida = Movimentacao::where('status_solicitacao', 'P')
                ->when($setorId, function ($q, $setorId) {
                    $q->where('setor_origem_id', $setorId);
                })
                ->count();

            // Métricas específicas do mês atual (para setores consumidores)
            $now = now();
            $startOfMonth = $now->copy()->startOfMonth();
            $endOfMonth = $now->copy()->endOfMonth();

            $pedidosEntreguesMes = 0;
            $itensSolicitadosMes = 0;

            if ($setorId) {
                $movsMesQuery = Movimentacao::where('setor_destino_id', $setorId)
                    ->whereBetween('created_at', [$startOfMonth, $endOfMonth]);

                $pedidosEntreguesMes = (clone $movsMesQuery)
                    ->where(function ($q) {
                        $q->whereIn('status_solicitacao', ['C', 'E'])
                          ->orWhereNull('status_solicitacao');
                    })
                    ->count();

                $itensSolicitadosMes = ItemMovimentacao::whereHas('movimentacao', function ($q) use ($setorId, $startOfMonth, $endOfMonth) {
                    $q->where('setor_destino_id', $setorId)
                      ->whereBetween('created_at', [$startOfMonth, $endOfMonth]);
                })->count();
            }

            // Solicitações recentes (Top 5)
            $recentQuery = Movimentacao::with([
                'setorOrigem:id,nome',
                'setorDestino:id,nome',
                'itens:id,movimentacao_id,produto_id,quantidade_solicitada,quantidade_liberada'
            ]);

            if ($setor && !$temEstoque) {
                // Setor consumidor: histórico das requisições em que é o destinatário
                $recentQuery->where('setor_destino_id', $setorId);
            } else {
                // Setor com estoque: solicitações pendentes associadas ao setor
                $recentQuery->where('status_solicitacao', 'P');
                if ($setorId) {
                    $recentQuery->where(function ($q) use ($setorId) {
                        $q->where('setor_origem_id', $setorId)
                          ->orWhere('setor_destino_id', $setorId);
                    });
                }
            }

            $recentRequests = $recentQuery
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($req) {
                    return [
                        'id' => $req->id,
                        'setor_origem_id' => $req->setor_origem_id,
                        'setor_destino_id' => $req->setor_destino_id,
                        'status_solicitacao' => $req->status_solicitacao,
                        'created_at' => $req->created_at ? $req->created_at->toISOString() : null,
                        'setor_origem' => $req->setorOrigem ? ['id' => $req->setorOrigem->id, 'nome' => $req->setorOrigem->nome] : null,
                        'setor_destino' => $req->setorDestino ? ['id' => $req->setorDestino->id, 'nome' => $req->setorDestino->nome] : null,
                        'itens' => $req->itens,
                        'itens_count' => $req->itens ? $req->itens->count() : 0,
                    ];
                });

            return response()->json([
                'status' => true,
                'data' => [
                    'stats' => [
                        'totalItens' => $totalItens,
                        'abaixoMinimo' => $abaixoMinimo,
                        'pendentesEntrada' => $pendentesEntrada,
                        'pendentesSaida' => $pendentesSaida,
                        'pedidosEntreguesMes' => $pedidosEntreguesMes,
                        'itensSolicitadosMes' => $itensSolicitadosMes,
                    ],
                    'alerts' => $alerts,
                    'recentRequests' => $recentRequests,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Erro ao carregar indicadores do dashboard: ' . $e->getMessage()
            ], 500);
        }
    }
}
