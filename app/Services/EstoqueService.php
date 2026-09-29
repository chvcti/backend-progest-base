<?php

namespace App\Services;

use App\Models\Estoque;
use Illuminate\Support\Facades\DB;
use Exception;

class EstoqueService
{
    /**
     * Registra uma movimentação contábil no estoque com Auditoria (Ledger) de forma atômica.
     * Esta é a ÚNICA forma autorizada de alterar saldos fisicamente.
     *
     * @param int $estoqueId
     * @param float $quantidade Variação (+ para entrada, - para saída/baixa)
     * @param string $tipoOperacao 'entrada', 'saida', 'transferencia', 'ajuste', 'estorno'
     * @param int $usuarioId Usuário realizando a operação
     * @param int|null $movimentacaoId Vínculo com a solicitação/pedido
     * @param string|null $justificativa Obrigatório para ajustes/estornos manuais
     * @return Estoque Registro do estoque atualizado
     * @throws Exception Se saldo ficar negativo
     */
    public function registrarMovimentacaoContabil(
        int $estoqueId,
        float $quantidade,
        string $tipoOperacao,
        int $usuarioId,
        ?int $movimentacaoId = null,
        ?string $justificativa = null
    ): Estoque {
        // Validação de negócio (ajustes manuais necessitam de justificativa)
        if (in_array($tipoOperacao, ['ajuste', 'estorno']) && empty($justificativa)) {
            throw new Exception("Justificativa é obrigatória para operações de {$tipoOperacao}.");
        }

        return DB::transaction(function () use ($estoqueId, $quantidade, $tipoOperacao, $usuarioId, $movimentacaoId, $justificativa) {
            
            // 1. Bloqueio Pessimista: Protege contra concorrência/Deadlocks no saldo atômico
            $estoque = Estoque::lockForUpdate()->findOrFail($estoqueId);

            $saldoAnterior = (float) $estoque->quantidade_atual;
            $saldoNovo = $saldoAnterior + $quantidade;

            // 2. Validação: Proteção de Saldo Negativo (Fundamental em HealthTech)
            if ($saldoNovo < 0) {
                // Em casos hiper-específicos, poderia permitir. Mas a regra geral de negócio bloqueia.
                throw new Exception("Operação negada: Saldo de estoque insuficiente para o produto. (Saldo atual: {$saldoAnterior}, Tentativa: {$quantidade})");
            }

            // 3. Efetivar Atualização do Estoque (UPDATE)
            $estoque->quantidade_atual = $saldoNovo;
            $estoque->status_disponibilidade = $saldoNovo > 0 ? 'D' : 'I';
            $estoque->save();

            // 4. Registro no Ledger Imutável (INSERT - Audit Trail)
            DB::table('estoque_ledger')->insert([
                'estoque_id'             => $estoque->id,
                'produto_id'             => $estoque->produto_id,
                'usuario_id'             => $usuarioId,
                'tipo_operacao'          => $tipoOperacao,
                'quantidade_movimentada' => $quantidade,
                'saldo_anterior'         => $saldoAnterior,
                'saldo_novo'             => $saldoNovo,
                'movimentacao_id'        => $movimentacaoId,
                'justificativa'          => $justificativa,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            return $estoque;
        });
    }
}
