<?php

namespace Tests\Feature;

use App\Models\Estoque;
use App\Models\EstoqueLote;
use App\Models\ItemMovimentacao;
use App\Models\Movimentacao;
use App\Models\Produto;
use App\Models\Setores;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DevolucaoPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        
        $this->setorDistribuidor = Setores::factory()->create();
        $this->setorConsumidor = Setores::factory()->create();

        DB::table('usuario_setor')->insert([
            ['usuario_id' => $this->user->id, 'setor_id' => $this->setorConsumidor->id, 'perfil' => 'solicitante'],
            ['usuario_id' => $this->user->id, 'setor_id' => $this->setorDistribuidor->id, 'perfil' => 'admin'],
        ]);

        $this->produto = Produto::factory()->create(['nome' => 'Produto Teste Devolução']);
        
        $this->movimentacao = Movimentacao::create([
            'usuario_id' => $this->user->id,
            'aprovador_usuario_id' => $this->user->id,
            'setor_origem_id' => $this->setorDistribuidor->id,
            'setor_destino_id' => $this->setorConsumidor->id,
            'tipo' => 'S', // Saída/Pedido
            'status_solicitacao' => 'A', // Atendido
            'data_hora' => now()
        ]);

        $this->itemMovimentacao = ItemMovimentacao::create([
            'movimentacao_id' => $this->movimentacao->id,
            'produto_id' => $this->produto->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada' => 10,
            'lote' => json_encode([
                ['lote' => 'LOTE-TESTE-1', 'quantidade' => 5],
                ['lote' => 'LOTE-TESTE-2', 'quantidade' => 5]
            ])
        ]);

        // Cria estoque no distribuidor (saldo que vai ser creditado ao devolver)
        Estoque::create([
            'produto_id' => $this->produto->id,
            'setor_id' => $this->setorDistribuidor->id,
            'quantidade_atual' => 50,
            'quantidade_minima' => 10,
            'status_disponibilidade' => 'D'
        ]);

        EstoqueLote::create([
            'produto_id' => $this->produto->id,
            'setor_id' => $this->setorDistribuidor->id,
            'lote' => 'LOTE-TESTE-1',
            'quantidade_disponivel' => 25,
            'data_vencimento' => now()->addYear()->toDateString()
        ]);
        
        EstoqueLote::create([
            'produto_id' => $this->produto->id,
            'setor_id' => $this->setorDistribuidor->id,
            'lote' => 'LOTE-TESTE-2',
            'quantidade_disponivel' => 25,
            'data_vencimento' => now()->addYear()->toDateString()
        ]);
    }

    public function test_rejeicao_quantidade_zero_ou_negativa()
    {
        $response = $this->actingAs($this->user)->postJson("/api/movimentacao/{$this->movimentacao->id}/devolver", [
            'item_movimentacao_id' => $this->itemMovimentacao->id,
            'lote' => 'LOTE-TESTE-1',
            'quantidade' => 0,
            'motivo' => 'Devolução'
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['quantidade']);
    }

    public function test_rejeicao_quantidade_maior_que_atendida_no_lote()
    {
        $response = $this->actingAs($this->user)->postJson("/api/movimentacao/{$this->movimentacao->id}/devolver", [
            'item_movimentacao_id' => $this->itemMovimentacao->id,
            'lote' => 'LOTE-TESTE-1',
            'quantidade' => 6, // Atendido neste lote foi 5
            'motivo' => 'Devolução excessiva'
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'Quantidade a devolver excede a quantidade atendida no pedido para este lote.');
    }

    public function test_sucesso_registro_devolucao_e_estorno_no_lote_e_estoque_geral()
    {
        $response = $this->actingAs($this->user)->postJson("/api/movimentacao/{$this->movimentacao->id}/devolver", [
            'item_movimentacao_id' => $this->itemMovimentacao->id,
            'lote' => 'LOTE-TESTE-1',
            'quantidade' => 3,
            'motivo' => 'Sobra'
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', true);

        // Confirma se o lote do distribuidor teve saldo incrementado (+3)
        $this->assertDatabaseHas('estoque_lote', [
            'produto_id' => $this->produto->id,
            'setor_id' => $this->setorDistribuidor->id,
            'lote' => 'LOTE-TESTE-1',
            'quantidade_disponivel' => 28 // era 25
        ]);

        // Confirma se o estoque geral do distribuidor teve saldo incrementado (+3)
        $this->assertDatabaseHas('estoque', [
            'produto_id' => $this->produto->id,
            'setor_id' => $this->setorDistribuidor->id,
            'quantidade_atual' => 53 // era 50
        ]);

        // Confirma criação do registro de devolução
        $this->assertDatabaseHas('devolucoes', [
            'movimentacao_id' => $this->movimentacao->id,
            'item_movimentacao_id' => $this->itemMovimentacao->id,
            'lote' => 'LOTE-TESTE-1',
            'quantidade' => 3,
            'usuario_id' => $this->user->id
        ]);
    }

    public function test_busca_movimentacao_por_lote_na_listagem()
    {
        $this->actingAs($this->user)->postJson("/api/movimentacao/{$this->movimentacao->id}/devolver", [
            'item_movimentacao_id' => $this->itemMovimentacao->id,
            'lote' => 'LOTE-TESTE-1',
            'quantidade' => 1,
            'motivo' => ''
        ]);

        // Lista do setor distribuidor buscando por um lote devolvido / atendido
        $response = $this->actingAs($this->user)->postJson("/api/movimentacao/listBySetor", [
            'setor_id' => $this->setorDistribuidor->id,
            'lote' => 'LOTE-TESTE-1'
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['tem_devolucao']);
        $this->assertEquals($this->movimentacao->id, $data[0]['id']);

        // Busca por um lote que não existe no item_movimentacao nem em devolucoes
        $responseNaoExiste = $this->actingAs($this->user)->postJson("/api/movimentacao/listBySetor", [
            'setor_id' => $this->setorDistribuidor->id,
            'lote' => 'LOTE-INEXISTENTE'
        ]);

        $responseNaoExiste->assertStatus(200);
        $dataNaoExiste = $responseNaoExiste->json('data');
        $this->assertCount(0, $dataNaoExiste);
    }

    public function test_bloqueio_edicao_quando_pedido_nao_for_rascunho()
    {
        // $this->movimentacao tem status 'A' (Atendido/Aprovado)
        $response = $this->actingAs($this->user)->postJson("/api/movimentacao/{$this->movimentacao->id}/update-rascunho", [
            'observacao' => 'Tentativa de editar pedido aprovado',
            'itens' => [
                [
                    'produto_id' => $this->produto->id,
                    'quantidade_solicitada' => 2
                ]
            ]
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'Apenas pedidos em rascunho podem ser editados.');
    }

    public function test_rejeicao_quantidade_decimal_em_devolucao()
    {
        $response = $this->actingAs($this->user)->postJson("/api/movimentacao/{$this->movimentacao->id}/devolver", [
            'item_movimentacao_id' => $this->itemMovimentacao->id,
            'lote' => 'LOTE-TESTE-1',
            'quantidade' => 1.5, // Decimal deve ser estritamente rejeitado
            'motivo' => 'Teste decimal'
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['quantidade']);
    }

    public function test_devolucao_expoe_numero_pedido_e_eager_loading_responsaveis()
    {
        $response = $this->actingAs($this->user)->postJson("/api/movimentacao/{$this->movimentacao->id}/devolver", [
            'item_movimentacao_id' => $this->itemMovimentacao->id,
            'lote' => 'LOTE-TESTE-1',
            'quantidade' => 2,
            'motivo' => 'Devolução inteira válida'
        ]);

        $response->assertStatus(200);

        // Verifica na listagem o eager loading de usuario e aprovador
        $resList = $this->actingAs($this->user)->postJson("/api/movimentacao/listBySetor", [
            'setor_id' => $this->setorDistribuidor->id
        ]);

        $resList->assertStatus(200);
        $item = $resList->json('data.0');
        $this->assertNotNull($item['usuario']);
        $this->assertEquals($this->user->id, $item['usuario']['id']);
        $this->assertNotNull($item['aprovador']);
        $this->assertEquals($this->user->id, $item['aprovador']['id']);

        // Verifica o show
        $resShow = $this->actingAs($this->user)->getJson("/api/movimentacao/{$this->movimentacao->id}");
        $resShow->assertStatus(200);
        $showData = $resShow->json('data');
        $this->assertNotNull($showData['usuario']);
        $this->assertNotNull($showData['aprovador']);
        $this->assertNotEmpty($showData['devolucoes']);
        $this->assertEquals($this->movimentacao->id, $showData['devolucoes'][0]['numero_pedido']);
        $this->assertEquals($this->movimentacao->id, $showData['devolucoes'][0]['pedido_origem_id']);
        $this->assertNotNull($showData['devolucoes'][0]['pedido']);
        $this->assertEquals($this->setorConsumidor->id, $showData['devolucoes'][0]['pedido']['setor_destino_id']);
        $this->assertIsInt($showData['devolucoes'][0]['quantidade']);
        $this->assertEquals(2, $showData['devolucoes'][0]['quantidade']);
    }

    public function test_filtro_rigido_distribuidores_para_setor()
    {
        $outroSetor = Setores::factory()->create(['estoque' => true]);

        // Consulta sem distribuidores cadastrados deve vir vazia
        $responseVazio = $this->actingAs($this->user)->postJson('/api/setores/listDistribuidoresParaSetor', [
            'setor_id' => $outroSetor->id
        ]);
        $responseVazio->assertStatus(200);
        $this->assertCount(0, $responseVazio->json('data'));

        // Vincula distribuidor
        DB::table('setor_distribuidor')->insert([
            'setor_solicitante_id' => $this->setorConsumidor->id,
            'setor_distribuidor_id' => $this->setorDistribuidor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/setores/listDistribuidoresParaSetor', [
            'setor_id' => $this->setorConsumidor->id
        ]);

        $response->assertStatus(200);
        $distribuidores = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($distribuidores));
        $this->assertEquals($this->setorDistribuidor->id, $distribuidores[0]['id']);
    }

    public function test_fifo_estrito_por_validade_e_id()
    {
        $produtoFifo = Produto::factory()->create();

        // Lote 1: Vence em 10 meses
        $lote1 = EstoqueLote::create([
            'produto_id' => $produtoFifo->id,
            'setor_id' => $this->setorDistribuidor->id,
            'lote' => 'FIFO-1',
            'quantidade_disponivel' => 5,
            'data_vencimento' => now()->addMonths(10)->toDateString()
        ]);

        // Lote 2: Vence em 2 meses (deve sair primeiro!)
        $lote2 = EstoqueLote::create([
            'produto_id' => $produtoFifo->id,
            'setor_id' => $this->setorDistribuidor->id,
            'lote' => 'FIFO-2',
            'quantidade_disponivel' => 4,
            'data_vencimento' => now()->addMonths(2)->toDateString()
        ]);

        Estoque::create([
            'produto_id' => $produtoFifo->id,
            'setor_id' => $this->setorDistribuidor->id,
            'quantidade_atual' => 9,
            'quantidade_minima' => 0,
            'status_disponibilidade' => 'D'
        ]);

        // Criar pedido pendente de 6 unidades
        $mov = Movimentacao::create([
            'usuario_id' => $this->user->id,
            'setor_origem_id' => $this->setorDistribuidor->id,
            'setor_destino_id' => $this->setorConsumidor->id,
            'tipo' => 'T',
            'data_hora' => now(),
            'status_solicitacao' => 'P'
        ]);

        ItemMovimentacao::create([
            'movimentacao_id' => $mov->id,
            'produto_id' => $produtoFifo->id,
            'quantidade_solicitada' => 6,
            'quantidade_liberada' => 0
        ]);

        // Aprovar movimentação (deve consumir 4 de FIFO-2 e 2 de FIFO-1)
        $resp = $this->actingAs($this->user)->postJson("/api/movimentacao/{$mov->id}/process", [
            'action' => 'approve',
            'itens' => [
                [
                    'id' => $mov->itens()->first()->id,
                    'quantidade_liberada' => 6
                ]
            ]
        ]);

        $resp->assertStatus(200);

        // FIFO-2 deve ter sido totalmente zerado (4 - 4 = 0) e nunca negativado
        $lote2->refresh();
        $this->assertEquals(0, intval($lote2->quantidade_disponivel));

        // FIFO-1 deve ter 3 restantes (5 - 2 = 3)
        $lote1->refresh();
        $this->assertEquals(3, intval($lote1->quantidade_disponivel));
    }

    public function test_item_atendido_expoe_lote_e_quantidade_devolvida_consolidada()
    {
        // 1. Registra devolução de 3 unidades para o item
        $this->actingAs($this->user)->postJson("/api/movimentacao/{$this->movimentacao->id}/devolver", [
            'item_movimentacao_id' => $this->itemMovimentacao->id,
            'lote' => 'LOTE-TESTE-1',
            'quantidade' => 3,
            'motivo' => 'Devolução teste'
        ])->assertStatus(200);

        // 2. Consulta via show
        $resShow = $this->actingAs($this->user)->getJson("/api/movimentacao/{$this->movimentacao->id}");
        $resShow->assertStatus(200);
        $data = $resShow->json('data');

        $this->assertEquals($this->movimentacao->id, $data['numero_pedido']);
        $this->assertNotEmpty($data['itens']);

        $itemPayload = $data['itens'][0];
        $this->assertEquals(3, $itemPayload['quantidade_devolvida']);
        $this->assertNotNull($itemPayload['numero_lote']);
        $this->assertStringContainsString('LOTE-TESTE-1', $itemPayload['numero_lote']);
        $this->assertIsArray($itemPayload['lotes_parsed']);

        // 3. Testa item em pedido sem devolução (deve retornar 0)
        $movSemDev = Movimentacao::create([
            'usuario_id' => $this->user->id,
            'aprovador_usuario_id' => $this->user->id,
            'setor_origem_id' => $this->setorDistribuidor->id,
            'setor_destino_id' => $this->setorConsumidor->id,
            'tipo' => 'S',
            'status_solicitacao' => 'A',
            'data_hora' => now()
        ]);
        $itemSemDev = ItemMovimentacao::create([
            'movimentacao_id' => $movSemDev->id,
            'produto_id' => $this->produto->id,
            'quantidade_solicitada' => 5,
            'quantidade_liberada' => 5,
            'lote' => 'LOTE-TESTE-SEM-DEV'
        ]);

        $resShowSemDev = $this->actingAs($this->user)->getJson("/api/movimentacao/{$movSemDev->id}");
        $resShowSemDev->assertStatus(200);
        $itemSemDevPayload = $resShowSemDev->json('data.itens.0');
        $this->assertEquals(0, $itemSemDevPayload['quantidade_devolvida']);
        $this->assertEquals('LOTE-TESTE-SEM-DEV', $itemSemDevPayload['numero_lote']);
    }
}
