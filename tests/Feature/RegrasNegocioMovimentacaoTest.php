<?php

namespace Tests\Feature;

use App\Models\Devolucao;
use App\Models\Estoque;
use App\Models\EstoqueLote;
use App\Models\ItemMovimentacao;
use App\Models\Movimentacao;
use App\Models\Polo;
use App\Models\Produto;
use App\Models\Setores;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegrasNegocioMovimentacaoTest extends TestCase
{
    use DatabaseTransactions;

    protected $polo;
    protected $setorDistribuidor;
    protected $setorSolicitante;
    protected $almoxarife;
    protected $solicitante;
    protected $admin;
    protected $produtoA;
    protected $produtoB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->polo = Polo::factory()->create(['nome' => 'Polo Testes Regras']);

        // Setor Distribuidor com controle de estoque físico
        $this->setorDistribuidor = Setores::factory()->create([
            'polo_id' => $this->polo->id,
            'nome'    => 'Farmácia Central Teste',
            'estoque' => true,
            'tipo'    => 'Medicamento',
            'status'  => 'A',
        ]);

        // Setor Solicitante assistencial sem estoque físico
        $this->setorSolicitante = Setores::factory()->create([
            'polo_id' => $this->polo->id,
            'nome'    => 'Enfermaria Geral Teste',
            'estoque' => false,
            'tipo'    => 'Medicamento',
            'status'  => 'A',
        ]);

        // Configuração de distribuidor autorizado
        DB::table('setor_distribuidor')->insert([
            'setor_solicitante_id'  => $this->setorSolicitante->id,
            'setor_distribuidor_id' => $this->setorDistribuidor->id,
        ]);

        // Usuário Almoxarife vinculado ao Distribuidor
        $this->almoxarife = User::factory()->create([
            'email' => 'almoxarife_regra_' . uniqid() . '@teste.com',
        ]);
        DB::table('usuario_setor')->insert([
            'usuario_id' => $this->almoxarife->id,
            'setor_id'   => $this->setorDistribuidor->id,
            'perfil'     => 'almoxarife',
        ]);

        // Usuário Solicitante vinculado à Enfermaria
        $this->solicitante = User::factory()->create([
            'email' => 'solicitante_regra_' . uniqid() . '@teste.com',
        ]);
        DB::table('usuario_setor')->insert([
            'usuario_id' => $this->solicitante->id,
            'setor_id'   => $this->setorSolicitante->id,
            'perfil'     => 'solicitante',
        ]);

        // Usuário Super Admin
        $this->admin = User::where('email', 'adminti@gmail.com')->first();
        if (!$this->admin) {
            $this->admin = User::factory()->create(['email' => 'adminti@gmail.com']);
        }

        // Produtos para teste
        $this->produtoA = Produto::factory()->create(['nome' => 'Amoxicilina 500mg Regra Teste']);
        $this->produtoB = Produto::factory()->create(['nome' => 'Dipirona 500mg Regra Teste']);
    }

    // =========================================================================
    // 1. TRAVA DE TETO DE SOLICITAÇÃO (TRAVA 1)
    // =========================================================================

    /**
     * Trava 1 (Transferência): Impede aprovar quantidade maior do que a solicitada pelo requisitante.
     */
    public function test_trava_teto_solicitacao_bloqueia_aprovacao_acima_do_pedido_em_transferencia()
    {
        $mov = Movimentacao::create([
            'usuario_id'         => $this->solicitante->id,
            'setor_origem_id'    => $this->setorDistribuidor->id,
            'setor_destino_id'   => $this->setorSolicitante->id,
            'tipo'               => 'T',
            'status_solicitacao' => 'P',
            'data_hora'          => now(),
        ]);

        $item = ItemMovimentacao::create([
            'movimentacao_id'       => $mov->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada'   => 0,
        ]);

        // Estoque com saldo suficiente para 50 (a recusa deve ser por teto de solicitação)
        Estoque::create([
            'produto_id'             => $this->produtoA->id,
            'setor_id'               => $this->setorDistribuidor->id,
            'quantidade_atual'       => 50,
            'quantidade_minima'      => 0,
            'status_disponibilidade' => 'D',
        ]);
        EstoqueLote::create([
            'produto_id'            => $this->produtoA->id,
            'setor_id'              => $this->setorDistribuidor->id,
            'lote'                  => 'LOTE-TETO-01',
            'quantidade_disponivel' => 50,
            'data_vencimento'       => now()->addMonths(12)->toDateString(),
        ]);

        // Tentar aprovar 15 quando foram solicitadas apenas 10
        $response = $this->actingAs($this->almoxarife)->postJson("/api/movimentacao/{$mov->id}/process", [
            'action' => 'approve',
            'itens'  => [
                ['id' => $item->id, 'quantidade_liberada' => 15],
            ],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false);

        $this->assertStringContainsString('não pode ser maior do que a quantidade solicitada', $response->json('message'));
        $this->assertStringContainsString('(15)', $response->json('message'));
        $this->assertStringContainsString('(10)', $response->json('message'));
    }

    /**
     * Trava 1 (Devolução): Impede aprovar quantidade maior do que a solicitada em devolução.
     */
    public function test_trava_teto_solicitacao_bloqueia_aprovacao_acima_do_pedido_em_devolucao()
    {
        $movDev = Movimentacao::create([
            'usuario_id'         => $this->solicitante->id,
            'setor_origem_id'    => $this->setorSolicitante->id,
            'setor_destino_id'   => $this->setorDistribuidor->id,
            'tipo'               => 'D',
            'status_solicitacao' => 'P',
            'data_hora'          => now(),
        ]);

        $itemDev = ItemMovimentacao::create([
            'movimentacao_id'       => $movDev->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 5,
            'quantidade_devolvendo' => 5,
            'quantidade_liberada'   => 0,
        ]);

        // Almoxarife do setor receptor tenta aprovar 8 un quando foram solicitadas 5 un de devolução
        $response = $this->actingAs($this->almoxarife)->postJson("/api/movimentacao/{$movDev->id}/process", [
            'action' => 'approve',
            'itens'  => [
                ['id' => $itemDev->id, 'quantidade_liberada' => 8],
            ],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false);

        $this->assertStringContainsString('não pode ser maior do que a quantidade solicitada', $response->json('message'));
        $this->assertStringContainsString('(8)', $response->json('message'));
        $this->assertStringContainsString('(5)', $response->json('message'));
    }

    // =========================================================================
    // 2. ATENDIMENTO PARCIAL E FLEXIBILIZAÇÃO DE QUANTIDADE ZERO (0)
    // =========================================================================

    /**
     * Cenário A: Item Zerado Permitido quando há atendimento de outro item.
     */
    public function test_atendimento_parcial_permite_item_com_quantidade_zero()
    {
        $mov = Movimentacao::create([
            'usuario_id'         => $this->solicitante->id,
            'setor_origem_id'    => $this->setorDistribuidor->id,
            'setor_destino_id'   => $this->setorSolicitante->id,
            'tipo'               => 'T',
            'status_solicitacao' => 'P',
            'data_hora'          => now(),
        ]);

        $itemA = ItemMovimentacao::create([
            'movimentacao_id'       => $mov->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada'   => 0,
        ]);

        $itemB = ItemMovimentacao::create([
            'movimentacao_id'       => $mov->id,
            'produto_id'            => $this->produtoB->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada'   => 0,
        ]);

        // Produto A sem saldo em estoque (0)
        Estoque::updateOrCreate(
            [
                'produto_id' => $this->produtoA->id,
                'setor_id'   => $this->setorDistribuidor->id,
            ],
            [
                'quantidade_atual'       => 0,
                'quantidade_minima'      => 0,
                'status_disponibilidade' => 'I',
            ]
        );

        // Produto B com saldo suficiente (30)
        Estoque::updateOrCreate(
            [
                'produto_id' => $this->produtoB->id,
                'setor_id'   => $this->setorDistribuidor->id,
            ],
            [
                'quantidade_atual'       => 30,
                'quantidade_minima'      => 0,
                'status_disponibilidade' => 'D',
            ]
        );
        EstoqueLote::create([
            'produto_id'            => $this->produtoB->id,
            'setor_id'              => $this->setorDistribuidor->id,
            'lote'                  => 'LOTE-PARCIAL-B',
            'quantidade_disponivel' => 30,
            'data_vencimento'       => now()->addMonths(18)->toDateString(),
        ]);

        // Aprovar liberando 0 para Item A e 10 para Item B
        $response = $this->actingAs($this->almoxarife)->postJson("/api/movimentacao/{$mov->id}/process", [
            'action' => 'approve',
            'itens'  => [
                ['id' => $itemA->id, 'quantidade_liberada' => 0],
                ['id' => $itemB->id, 'quantidade_liberada' => 10],
            ],
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', true);

        // Item A gravou 0 sem falhas
        $itemA->refresh();
        $this->assertEquals(0, (float) $itemA->quantidade_liberada);

        // Item B gravou 10 e deduziu estoque
        $itemB->refresh();
        $this->assertEquals(10, (float) $itemB->quantidade_liberada);

        $mov->refresh();
        $this->assertEquals('A', $mov->status_solicitacao);

        $this->assertDatabaseHas('estoque', [
            'produto_id'       => $this->produtoB->id,
            'setor_id'         => $this->setorDistribuidor->id,
            'quantidade_atual' => 20, // 30 - 10 = 20
        ]);
    }

    /**
     * Cenário B: Bloqueio de aprovação quando todos os itens recebem quantidade 0.
     */
    public function test_bloqueia_aprovacao_quando_todos_os_itens_recebem_quantidade_zero()
    {
        $mov = Movimentacao::create([
            'usuario_id'         => $this->solicitante->id,
            'setor_origem_id'    => $this->setorDistribuidor->id,
            'setor_destino_id'   => $this->setorSolicitante->id,
            'tipo'               => 'T',
            'status_solicitacao' => 'P',
            'data_hora'          => now(),
        ]);

        $itemA = ItemMovimentacao::create([
            'movimentacao_id'       => $mov->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada'   => 0,
        ]);

        $itemB = ItemMovimentacao::create([
            'movimentacao_id'       => $mov->id,
            'produto_id'            => $this->produtoB->id,
            'quantidade_solicitada' => 5,
            'quantidade_liberada'   => 0,
        ]);

        $response = $this->actingAs($this->almoxarife)->postJson("/api/movimentacao/{$mov->id}/process", [
            'action' => 'approve',
            'itens'  => [
                ['id' => $itemA->id, 'quantidade_liberada' => 0],
                ['id' => $itemB->id, 'quantidade_liberada' => 0],
            ],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'Não é possível aprovar uma movimentação com todos os itens zerados. Rejeite a solicitação se não houver atendimento.');
    }

    /**
     * Cenário C: Bloqueio de aprovação com valor negativo.
     */
    public function test_bloqueia_aprovacao_com_quantidade_negativa()
    {
        $mov = Movimentacao::create([
            'usuario_id'         => $this->solicitante->id,
            'setor_origem_id'    => $this->setorDistribuidor->id,
            'setor_destino_id'   => $this->setorSolicitante->id,
            'tipo'               => 'T',
            'status_solicitacao' => 'P',
            'data_hora'          => now(),
        ]);

        $item = ItemMovimentacao::create([
            'movimentacao_id'       => $mov->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada'   => 0,
        ]);

        $response = $this->actingAs($this->almoxarife)->postJson("/api/movimentacao/{$mov->id}/process", [
            'action' => 'approve',
            'itens'  => [
                ['id' => $item->id, 'quantidade_liberada' => -2],
            ],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'A quantidade aprovada não pode ser negativa.');
    }

    // =========================================================================
    // 3. TETO DE DEVOLUÇÕES ACUMULADAS
    // =========================================================================

    /**
     * Cenário A: Tentar devolver quantidade superior ao saldo atendido do pedido original.
     */
    public function test_teto_devolucao_bloqueia_excesso_em_relacao_ao_saldo_atendido()
    {
        $pedidoOriginal = Movimentacao::create([
            'usuario_id'           => $this->solicitante->id,
            'aprovador_usuario_id' => $this->almoxarife->id,
            'setor_origem_id'      => $this->setorDistribuidor->id,
            'setor_destino_id'     => $this->setorSolicitante->id,
            'tipo'                 => 'S',
            'status_solicitacao'   => 'A',
            'data_hora'            => now(),
        ]);

        $itemOriginal = ItemMovimentacao::create([
            'movimentacao_id'       => $pedidoOriginal->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada'   => 10,
            'lote'                  => 'LOTE-ORIG-10',
        ]);

        // Tentar devolver 15 quando foram atendidas 10
        $response = $this->actingAs($this->solicitante)->postJson("/api/movimentacao/{$pedidoOriginal->id}/devolver", [
            'itens' => [
                [
                    'item_movimentacao_id'  => $itemOriginal->id,
                    'quantidade_devolvendo' => 15,
                ],
            ],
            'motivo' => 'Devolução excessiva',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false);

        $this->assertStringContainsString('não pode superar o saldo atendido disponível', $response->json('message'));
        $this->assertStringContainsString('(15)', $response->json('message'));
        $this->assertStringContainsString('(10)', $response->json('message'));
    }

    /**
     * Cenário B: Pedido de 50 un. 1ª devolução de 20 un aprovada. 2ª devolução de 35 un bloqueada (saldo é 30 un).
     */
    public function test_teto_devolucoes_consecutivas_abate_saldo_remanescente()
    {
        $pedidoOriginal = Movimentacao::create([
            'usuario_id'           => $this->solicitante->id,
            'aprovador_usuario_id' => $this->almoxarife->id,
            'setor_origem_id'      => $this->setorDistribuidor->id,
            'setor_destino_id'     => $this->setorSolicitante->id,
            'tipo'                 => 'S',
            'status_solicitacao'   => 'A',
            'data_hora'            => now(),
        ]);

        $itemOriginal = ItemMovimentacao::create([
            'movimentacao_id'       => $pedidoOriginal->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 50,
            'quantidade_liberada'   => 50,
            'lote'                  => 'LOTE-ORIG-50',
        ]);

        // 1. Primeira devolução de 20 unidades
        $resDev1 = $this->actingAs($this->solicitante)->postJson("/api/movimentacao/{$pedidoOriginal->id}/devolver", [
            'itens' => [
                [
                    'item_movimentacao_id'  => $itemOriginal->id,
                    'quantidade_devolvendo' => 20,
                ],
            ],
            'motivo' => 'Primeira devolução parcial',
        ]);
        $resDev1->assertStatus(200);

        // Recupera a movimentação de devolução criada e aprova
        $movDev1 = Movimentacao::where('tipo', 'D')
            ->where('observacao', 'like', '%pedido #' . $pedidoOriginal->id . '%')
            ->first();
        $this->assertNotNull($movDev1);

        $itemDev1 = $movDev1->itens()->first();
        $resAprov = $this->actingAs($this->almoxarife)->postJson("/api/movimentacao/{$movDev1->id}/process", [
            'action' => 'approve',
            'itens'  => [
                ['id' => $itemDev1->id, 'quantidade_liberada' => 20],
            ],
        ]);
        $resAprov->assertStatus(200);

        // 2. Segunda devolução: tentar devolver 35 unidades (saldo remanescente é 50 - 20 = 30)
        $resDev2 = $this->actingAs($this->solicitante)->postJson("/api/movimentacao/{$pedidoOriginal->id}/devolver", [
            'itens' => [
                [
                    'item_movimentacao_id'  => $itemOriginal->id,
                    'quantidade_devolvendo' => 35,
                ],
            ],
            'motivo' => 'Segunda devolução que estoura o saldo',
        ]);

        $resDev2->assertStatus(422)
                ->assertJsonPath('status', false);

        $this->assertStringContainsString('não pode superar o saldo atendido disponível', $resDev2->json('message'));
        $this->assertStringContainsString('(35)', $resDev2->json('message'));
        $this->assertStringContainsString('(30)', $resDev2->json('message'));
    }

    /**
     * Cenário C: Ao aprovar devolução com quantidade aceita, quantidade_solicitada e quantidade_devolvendo
     * permanecem intactas (quantidade aceita gravada apenas em quantidade_liberada).
     */
    public function test_aprovacao_devolucao_preserva_quantidade_solicitada_e_devolvendo_do_item()
    {
        $pedidoOriginal = Movimentacao::create([
            'usuario_id'           => $this->solicitante->id,
            'aprovador_usuario_id' => $this->almoxarife->id,
            'setor_origem_id'      => $this->setorDistribuidor->id,
            'setor_destino_id'     => $this->setorSolicitante->id,
            'tipo'                 => 'S',
            'status_solicitacao'   => 'A',
            'data_hora'            => now(),
        ]);

        $itemOriginal = ItemMovimentacao::create([
            'movimentacao_id'       => $pedidoOriginal->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 30,
            'quantidade_liberada'   => 30,
            'lote'                  => 'LOTE-HIST-30',
        ]);

        // Solicitante abre devolução de 20 unidades
        $this->actingAs($this->solicitante)->postJson("/api/movimentacao/{$pedidoOriginal->id}/devolver", [
            'itens' => [
                [
                    'item_movimentacao_id'  => $itemOriginal->id,
                    'quantidade_devolvendo' => 20,
                ],
            ],
            'motivo' => 'Devolução para teste de preservação histórica',
        ])->assertStatus(200);

        $movDev = Movimentacao::where('tipo', 'D')
            ->where('observacao', 'like', '%pedido #' . $pedidoOriginal->id . '%')
            ->first();
        $this->assertNotNull($movDev);

        $itemDev = $movDev->itens()->first();
        $this->assertEquals(20, (float) $itemDev->quantidade_solicitada);
        $this->assertEquals(20, (float) $itemDev->quantidade_devolvendo);
        $this->assertEquals(0, (float) $itemDev->quantidade_liberada);

        // Almoxarife aceita parcialmente apenas 14 unidades
        $resAprov = $this->actingAs($this->almoxarife)->postJson("/api/movimentacao/{$movDev->id}/process", [
            'action' => 'approve',
            'itens'  => [
                ['id' => $itemDev->id, 'quantidade_liberada' => 14],
            ],
        ]);
        $resAprov->assertStatus(200);

        // Verifica integridade dos campos no banco de dados
        $itemDev->refresh();
        $this->assertEquals(20, (float) $itemDev->quantidade_solicitada, 'quantidade_solicitada deve permanecer intacta.');
        $this->assertEquals(20, (float) $itemDev->quantidade_devolvendo, 'quantidade_devolvendo deve permanecer intacta.');
        $this->assertEquals(14, (float) $itemDev->quantidade_liberada, 'quantidade aceita deve constar apenas em quantidade_liberada.');
    }

    // =========================================================================
    // 4. RELATÓRIO DE SAÍDAS E DEDUÇÃO LÍQUIDA
    // =========================================================================

    /**
     * Testa listSaidasPorData garantindo que transferências ('T') sejam contabilizadas nas saídas
     * e devoluções aprovadas ('D') abatam da saída bruta (quantidade_liquida = saida_bruta - devolvida).
     */
    public function test_relatorio_saidas_por_data_contempla_transferencias_e_abate_devolucoes()
    {
        $hoje = now()->format('Y-m-d');

        // 1. Movimentação de Transferência ('T') aprovada: 30 unidades
        $movTransf = Movimentacao::create([
            'usuario_id'           => $this->solicitante->id,
            'aprovador_usuario_id' => $this->almoxarife->id,
            'setor_origem_id'      => $this->setorDistribuidor->id,
            'setor_destino_id'     => $this->setorSolicitante->id,
            'tipo'                 => 'T',
            'status_solicitacao'   => 'A',
            'data_hora'            => now(),
        ]);
        ItemMovimentacao::create([
            'movimentacao_id'       => $movTransf->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 30,
            'quantidade_liberada'   => 30,
            'lote'                  => 'LOTE-REL-T',
        ]);

        // 2. Movimentação de Saída Direta ('S') aprovada: 20 unidades
        $movSaida = Movimentacao::create([
            'usuario_id'           => $this->solicitante->id,
            'aprovador_usuario_id' => $this->almoxarife->id,
            'setor_origem_id'      => $this->setorDistribuidor->id,
            'setor_destino_id'     => $this->setorSolicitante->id,
            'tipo'                 => 'S',
            'status_solicitacao'   => 'A',
            'data_hora'            => now(),
        ]);
        ItemMovimentacao::create([
            'movimentacao_id'       => $movSaida->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 20,
            'quantidade_liberada'   => 20,
            'lote'                  => 'LOTE-REL-S',
        ]);

        // Saída bruta esperada = 30 ('T') + 20 ('S') = 50 unidades

        // 3. Movimentação de Devolução ('D') aprovada: 15 unidades
        $movDev = Movimentacao::create([
            'usuario_id'           => $this->solicitante->id,
            'aprovador_usuario_id' => $this->almoxarife->id,
            'setor_origem_id'      => $this->setorSolicitante->id,
            'setor_destino_id'     => $this->setorDistribuidor->id,
            'tipo'                 => 'D',
            'status_solicitacao'   => 'A',
            'data_hora'            => now(),
        ]);
        ItemMovimentacao::create([
            'movimentacao_id'       => $movDev->id,
            'produto_id'            => $this->produtoA->id,
            'quantidade_solicitada' => 15,
            'quantidade_devolvendo' => 15,
            'quantidade_liberada'   => 15,
            'lote'                  => 'LOTE-REL-D',
        ]);

        // Consultar relatório de saídas por data
        $response = $this->actingAs($this->admin)->postJson('/api/relatorios/saidas-por-data/list', [
            'filters' => [
                'date_from'  => $hoje,
                'date_to'    => $hoje,
                'produto_id' => $this->produtoA->id,
            ],
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', true);

        $data = $response->json('data');
        $this->assertNotEmpty($data, 'O relatório deve retornar dados para a data informada.');

        $grupoData = collect($data)->firstWhere('data', $hoje);
        $this->assertNotNull($grupoData, "O grupo de data {$hoje} deve existir.");

        $produtoItem = collect($grupoData['produtos'])->firstWhere('produto.id', $this->produtoA->id);
        $this->assertNotNull($produtoItem, "O produto {$this->produtoA->nome} deve estar presente no relatório.");

        // Asserções das quantidades
        $this->assertEquals(50, $produtoItem['quantidade_saida_bruta'], 'Saída bruta deve somar transferências T (30) e saídas S (20).');
        $this->assertEquals(15, $produtoItem['quantidade_devolvida'], 'Quantidade devolvida deve contabilizar a devolução D (15).');
        $this->assertEquals(35, $produtoItem['quantidade_liquida'], 'Quantidade líquida deve ser 50 - 15 = 35.');
        $this->assertEquals(35, $produtoItem['quantidade_total'], 'Quantidade total do produto deve ser o valor líquido 35.');

        // Verifica se a transferência 'T' aparece nas movimentações detalhadas
        $movimentacoes = collect($produtoItem['movimentacoes']);
        $transferencia = $movimentacoes->firstWhere('tipo', 'T');
        $this->assertNotNull($transferencia, 'A transferência setorial deve constar nas movimentações detalhadas.');
        $this->assertEquals('Transferência Setorial', $transferencia['tipo_descricao']);
        $this->assertEquals(30, $transferencia['quantidade']);

        $saidaDireta = $movimentacoes->firstWhere('tipo', 'S');
        $this->assertNotNull($saidaDireta, 'A saída direta deve constar nas movimentações detalhadas.');
        $this->assertEquals('Saída Direta', $saidaDireta['tipo_descricao']);
        $this->assertEquals(20, $saidaDireta['quantidade']);
    }
}
