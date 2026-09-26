<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Movimentacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MovimentacaoAprovacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_approval_with_zero_or_negative_quantity()
    {
        $user = User::factory()->create();
        $movimentacao = Movimentacao::factory()->create(['status_solicitacao' => 'P']);

        DB::table('usuario_setor')->insert([
            'usuario_id' => $user->id,
            'setor_id' => $movimentacao->setor_origem_id,
            'perfil' => 'admin'
        ]);

        $produto = \App\Models\Produto::factory()->create();
        $item = \App\Models\ItemMovimentacao::create([
            'movimentacao_id' => $movimentacao->id,
            'produto_id' => $produto->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada' => 0
        ]);

        // 1. Testa quantidade negativa
        $responseNeg = $this->actingAs($user)->postJson("/api/movimentacao/{$movimentacao->id}/process", [
            'action' => 'approve',
            'itens' => [
                ['id' => $item->id, 'quantidade_liberada' => -1]
            ]
        ]);

        $responseNeg->assertStatus(422)
                 ->assertJsonFragment([
                     'status' => false,
                     'message' => 'A quantidade aprovada não pode ser negativa.'
                 ]);

        // 2. Testa todos os itens zerados
        $responseZero = $this->actingAs($user)->postJson("/api/movimentacao/{$movimentacao->id}/process", [
            'action' => 'approve',
            'itens' => [
                ['id' => $item->id, 'quantidade_liberada' => 0]
            ]
        ]);

        $responseZero->assertStatus(422)
                 ->assertJsonFragment([
                     'status' => false,
                     'message' => 'Não é possível aprovar uma movimentação com todos os itens zerados. Rejeite a solicitação se não houver atendimento.'
                 ]);
    }

    public function test_assigns_auth_id_to_aprovado_por_on_approval()
    {
        $user = User::factory()->create();
        
        // Simular aprovação bem-sucedida, mockando dependências do banco
        // Considerando que as factories estão devidamente configuradas ou usando stubs
        $movimentacao = Movimentacao::factory()->create(['status_solicitacao' => 'P']);
        
        DB::table('usuario_setor')->insert([
            'usuario_id' => $user->id,
            'setor_id' => $movimentacao->setor_origem_id,
            'perfil' => 'admin'
        ]);

        $produto = \App\Models\Produto::factory()->create();
        $item = \App\Models\ItemMovimentacao::create([
            'movimentacao_id' => $movimentacao->id,
            'produto_id' => $produto->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada' => 0
        ]);
        \App\Models\Estoque::updateOrCreate([
            'setor_id' => $movimentacao->setor_origem_id,
            'produto_id' => $produto->id,
        ], [
            'quantidade_atual' => 50,
            'quantidade_minima' => 10,
            'status_disponibilidade' => 'D'
        ]);
        \App\Models\EstoqueLote::create([
            'setor_id' => $movimentacao->setor_origem_id,
            'produto_id' => $produto->id,
            'lote' => 'LOTE-APROV-1',
            'quantidade_disponivel' => 50,
            'data_vencimento' => now()->addYear()->toDateString()
        ]);
        


        $response = $this->actingAs($user)->postJson("/api/movimentacao/{$movimentacao->id}/process", [
            'action' => 'approve',
            'itens' => [
                ['id' => $item->id, 'quantidade_liberada' => 10]
            ]
        ]);

        // Ignorando erros de estoque de factory ausente, o importante é validar se quando aprovado ele salva.
        // Se a transação for concluída (ou mockada para testar o setter), o status vira A e o auth_id entra.
        // Dado o contexto do controller que foi alterado para $mov->aprovador_usuario_id = auth()->id();
        
        // Assert que o banco tem o aprovador correto (caso o setup estivesse completo para a transação passar)
        $this->assertDatabaseHas('movimentacao', [
            'id' => $movimentacao->id,
            'aprovador_usuario_id' => $user->id
        ]);
    }
}
