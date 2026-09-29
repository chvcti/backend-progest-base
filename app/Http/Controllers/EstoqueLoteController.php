<?php

namespace App\Http\Controllers;

use App\Models\EstoqueLote;
use App\Models\Estoque;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\Estoque\UpdateQuantidadeLoteRequest;
use App\Http\Requests\Estoque\ListEstoqueLoteRequest;

class EstoqueLoteController extends Controller
{
    /**
     * Listar lotes de um estoque específico pelo ID do estoque
     */
    public function list(ListEstoqueLoteRequest $request)
    {
        try {
            $validated = $request->validated();
            $estoqueId = $validated['estoque_id'];

            // Buscar o estoque para pegar produto_id e setor_id
            $estoque = Estoque::with('setor')->find($estoqueId);
            if (!$estoque) {
                return response()->json([
                    'status' => false,
                    'message' => 'Registro de estoque não encontrado.'
                ], 404);
            }

            $user = auth()->user();
            $podeVerValores = $user && $estoque->setor && $user->podeVerValoresFinanceiros($estoque->setor);

            $lotes = EstoqueLote::with([
                'setor:id,nome,tipo',
                'produto:id,nome,marca,grupo_produto_id,unidade_medida_id',
                'produto.grupoProduto:id,nome,tipo',
                'produto.unidadeMedida:id,nome',
            ])
                ->where('produto_id', $estoque->produto_id)
                ->where('setor_id', $estoque->setor_id)
                ->orderBy('data_vencimento', 'asc')
                ->get()
                ->map(function ($lote) use ($podeVerValores) {
                    $item = $lote->toArray();
                    if ($podeVerValores) {
                        $vUnit = $lote->valor_unitario ? (float) $lote->valor_unitario : null;
                        $item['valor_unitario'] = $vUnit;
                        $item['valor_total_lote'] = $vUnit !== null ? round($vUnit * (float) $lote->quantidade_disponivel, 2) : null;
                    } else {
                        $item['valor_unitario'] = null;
                        $item['valor_total_lote'] = null;
                    }
                    return $item;
                });

            $valorTotalProduto = $podeVerValores 
                ? round($lotes->sum('valor_total_lote'), 2)
                : null;

            return response()->json([
                'status' => true,
                'data' => $lotes,
                'permissoes' => [
                    'pode_ver_valores' => $podeVerValores,
                ],
                'resumo_financeiro' => [
                    'valor_total_produto' => $valorTotalProduto,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar lotes do estoque: ' . $e->getMessage(), [
                'payload' => $request->all(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao listar lotes do estoque.'
            ], 500);
        }
    }

    /**
     * Atualizar quantidade disponível de um lote específico
     */
    public function updateQuantidade(UpdateQuantidadeLoteRequest $request)
    {
        try {
            $data = $request->validated();

            // Lote e saldo agregado precisam mudar juntos, senão ficam divergentes.
            $resultado = DB::transaction(function () use ($data) {
                $lote = EstoqueLote::where('id', $data['id'])->lockForUpdate()->first();
                $quantidadeAnterior = $lote->quantidade_disponivel;
                $diferenca = $data['quantidade_disponivel'] - $quantidadeAnterior;

                $estoque = Estoque::where('produto_id', $lote->produto_id)
                    ->where('setor_id', $lote->setor_id)
                    ->lockForUpdate()
                    ->first();

                // Zerar o saldo agregado à força mascararia uma inconsistência:
                // é melhor recusar o ajuste e deixar o operador corrigir a origem.
                if ($estoque && ($estoque->quantidade_atual + $diferenca) < 0) {
                    return [
                        'erro' => 'O ajuste deixaria o estoque do produto negativo ('
                            . $estoque->quantidade_atual . ' ' . ($diferenca >= 0 ? '+' : '') . $diferenca
                            . '). Revise a quantidade informada.',
                    ];
                }

                $lote->quantidade_disponivel = $data['quantidade_disponivel'];
                $lote->save();

                if ($estoque) {
                    $estoque->quantidade_atual += $diferenca;
                    $estoque->status_disponibilidade = $estoque->quantidade_atual > 0 ? 'D' : 'I';
                    $estoque->save();
                }

                return ['lote' => $lote];
            });

            if (isset($resultado['erro'])) {
                return response()->json(['status' => false, 'message' => $resultado['erro']], 422);
            }

            $lote = $resultado['lote'];
            $lote->load(['setor', 'produto.grupoProduto', 'produto.unidadeMedida']);

            return response()->json([
                'status' => true,
                'message' => 'Quantidade do lote atualizada com sucesso.',
                'data' => $lote,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar quantidade do lote: ' . $e->getMessage(), [
                'payload' => $request->all(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao atualizar quantidade do lote.'
            ], 500);
        }
    }
}
