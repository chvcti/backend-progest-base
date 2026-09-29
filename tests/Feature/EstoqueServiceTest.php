<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Services\EstoqueService;
use App\Models\Estoque;
use App\Models\User;

class EstoqueServiceTest extends TestCase
{
    use RefreshDatabase;

    protected EstoqueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EstoqueService();
    }

    public function test_deve_incrementar_saldo_e_registrar_ledger_na_entrada()
    {
        $user = User::factory()->create();
        $estoque = Estoque::factory()->create(['quantidade_atual' => 10]);

        $this->service->registrarMovimentacaoContabil(
            $estoque->id,
            5,
            'entrada',
            $user->id,
            null,
            'Teste entrada'
        );

        $this->assertDatabaseHas('estoque', [
            'id' => $estoque->id,
            'quantidade_atual' => 15,
        ]);

        $this->assertDatabaseHas('estoque_ledger', [
            'estoque_id' => $estoque->id,
            'tipo_operacao' => 'entrada',
            'quantidade_movimentada' => 5,
            'saldo_anterior' => 10,
            'saldo_novo' => 15,
            'usuario_id' => $user->id
        ]);
    }

    public function test_deve_deduzir_saldo_e_registrar_ledger_na_saida()
    {
        $user = User::factory()->create();
        $estoque = Estoque::factory()->create(['quantidade_atual' => 10]);

        $this->service->registrarMovimentacaoContabil(
            $estoque->id,
            -3,
            'saida',
            $user->id,
            null,
            'Teste saída'
        );

        $this->assertDatabaseHas('estoque', [
            'id' => $estoque->id,
            'quantidade_atual' => 7,
        ]);

        $this->assertDatabaseHas('estoque_ledger', [
            'estoque_id' => $estoque->id,
            'tipo_operacao' => 'saida',
            'quantidade_movimentada' => -3,
            'saldo_anterior' => 10,
            'saldo_novo' => 7,
        ]);
    }

    public function test_nao_deve_permitir_saldo_negativo()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Operação negada: Saldo de estoque insuficiente para o produto.');

        $user = User::factory()->create();
        $estoque = Estoque::factory()->create(['quantidade_atual' => 5]);

        $this->service->registrarMovimentacaoContabil(
            $estoque->id,
            -10, // Tenta sacar mais do que o disponível
            'saida',
            $user->id,
            null,
            'Teste falha negativo'
        );
    }
}
