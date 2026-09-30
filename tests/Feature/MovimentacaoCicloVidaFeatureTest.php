<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use App\Models\User;
use App\Models\Setores;
use App\Models\Produto;
use App\Models\Estoque;
use App\Models\EstoqueLote;
use App\Models\Movimentacao;
use App\Models\ItemMovimentacao;
use Database\Seeders\RegimeContratacaoSeeder;
use Database\Seeders\PolosESetoresDemoSeeder;
use Database\Seeders\AdminInicialSeeder;
use Database\Seeders\CatalogoProdutosOficialSeeder;
use Illuminate\Support\Facades\DB;

class MovimentacaoCicloVidaFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $solicitante;
    protected User $almoxarife;
    protected Setores $setorDistribuidor;
    protected Setores $setorConsumidor;
    protected Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Carrega os seeders oficiais requeridos
        $this->seed([
            RegimeContratacaoSeeder::class,
            PolosESetoresDemoSeeder::class,
            AdminInicialSeeder::class,
            CatalogoProdutosOficialSeeder::class,
        ]);

        // 2. Setores: Distribuidor (Farmácia com estoque) e Consumidor (Clínica sem estoque)
        $this->setorDistribuidor = Setores::where('nome', 'FARMÁCIA DE DISPENSAÇÃO')->where('estoque', true)->firstOrFail();
        $this->setorConsumidor   = Setores::where('nome', 'CLÍNICA MÉDICA')->where('estoque', false)->firstOrFail();

        // 3. Usuários padrão de teste
        $regimeId = \Illuminate\Support\Facades\DB::table('regime_contratacao')->value('id') ?? 1;

        $this->solicitante = User::create([
            'name'                  => 'SOLICITANTE TESTE',
            'email'                 => 'solicitante.mov@progest.teste',
            'password'              => bcrypt('Admin123'),
            'cpf'                   => '66666666661',
            'telefone'              => '77999990061',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        \Illuminate\Support\Facades\DB::table('usuario_setor')->insert([
            'usuario_id' => $this->solicitante->id,
            'setor_id'   => $this->setorConsumidor->id,
            'perfil'     => 'solicitante',
        ]);

        $this->almoxarife = User::create([
            'name'                  => 'ALMOXARIFE TESTE',
            'email'                 => 'almoxarife.mov@progest.teste',
            'password'              => bcrypt('Admin123'),
            'cpf'                   => '66666666662',
            'telefone'              => '77999990062',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        \Illuminate\Support\Facades\DB::table('usuario_setor')->insert([
            'usuario_id' => $this->almoxarife->id,
            'setor_id'   => $this->setorDistribuidor->id,
            'perfil'     => 'almoxarife',
        ]);

        // 4. Produto do catálogo oficial
        $this->produto = Produto::firstOrFail();
    }

    // =========================================================================
    // 1. RASCUNHO ('C') E SUBMISSÃO PARA PENDENTE ('P')
    // =========================================================================

    /**
     * O solicitante cria um pedido como rascunho ('C').
     * O rascunho NÃO pode alterar o estoque nem lotes.
     * Posteriormente, o solicitante promove o rascunho para Pendente ('P').
     */
    public function test_solicitante_cria_rascunho_sem_alterar_estoque_e_submete_para_pendente()
    {
        Sanctum::actingAs($this->solicitante);

        // Prepara estoque prévio no distribuidor para garantir referência
        $estoqueInicial = Estoque::updateOrCreate(
            ['setor_id' => $this->setorDistribuidor->id, 'produto_id' => $this->produto->id],
            ['quantidade_atual' => 50, 'quantidade_minima' => 10, 'status_disponibilidade' => 'D']
        );

        $payload = [
            'usuario_id'         => $this->solicitante->id,
            'setor_origem_id'    => $this->setorDistribuidor->id,
            'setor_destino_id'   => $this->setorConsumidor->id,
            'tipo'               => 'S',
            'status_solicitacao' => 'C', // Rascunho
            'observacao'         => 'Rascunho de reposição semanal',
            'itens'              => [
                [
                    'produto_id'            => $this->produto->id,
                    'quantidade_solicitada' => 15,
                ]
            ]
        ];

        // 1. Cria o rascunho via endpoint
        $response = $this->postJson('/api/movimentacao/create', $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('status', true)
                 ->assertJsonPath('data.status_solicitacao', 'C');

        $movId = $response->json('data.id');

        // Valida que foi gravado como rascunho 'C'
        $this->assertDatabaseHas('movimentacao', [
            'id'                 => $movId,
            'status_solicitacao' => 'C',
            'usuario_id'         => $this->solicitante->id,
        ]);

        // Valida que o estoque de origem PERMANECEU INTACTO
        $estoqueInicial->refresh();
        $this->assertEquals(50, (float) $estoqueInicial->quantidade_atual);

        // 2. Submete o rascunho para tornar Pendente ('P')
        $submitResponse = $this->postJson("/api/movimentacao/{$movId}/process", [
            'action' => 'submit'
        ]);

        $submitResponse->assertStatus(200)
                       ->assertJsonPath('status', true)
                       ->assertJsonPath('data.status_solicitacao', 'P');

        $this->assertDatabaseHas('movimentacao', [
            'id'                 => $movId,
            'status_solicitacao' => 'P',
        ]);

        // O estoque ainda não deve ser debitado no status 'P'
        $estoqueInicial->refresh();
        $this->assertEquals(50, (float) $estoqueInicial->quantidade_atual);
    }

    // =========================================================================
    // 2. CANCELAMENTO PELO SOLICITANTE (STATUS 'X')
    // =========================================================================

    /**
     * Solicitante cancela um pedido pendente próprio com action 'cancel'.
     * O status_solicitacao deve ir para 'X' (Cancelado).
     */
    public function test_solicitante_cancela_pedido_proprio_pendente_atualizando_para_status_x()
    {
        Sanctum::actingAs($this->solicitante);

        // Cria uma movimentação pendente ('P')
        $mov = Movimentacao::create([
            'usuario_id'         => $this->solicitante->id,
            'setor_origem_id'    => $this->setorDistribuidor->id,
            'setor_destino_id'   => $this->setorConsumidor->id,
            'tipo'               => 'S',
            'status_solicitacao' => 'P',
            'data_hora'          => now(),
            'observacao'         => 'Pedido a ser cancelado',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $mov->id,
            'produto_id'            => $this->produto->id,
            'quantidade_solicitada' => 5,
            'quantidade_liberada'   => 0,
        ]);

        // Cancela o pedido
        $response = $this->postJson("/api/movimentacao/{$mov->id}/process", [
            'action' => 'cancel'
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', true)
                 ->assertJsonPath('data.status_solicitacao', 'X');

        $this->assertDatabaseHas('movimentacao', [
            'id'                 => $mov->id,
            'status_solicitacao' => 'X',
        ]);
    }

    /**
     * Caso de borda: tentar cancelar um pedido que NÃO está pendente (ex: já aprovado 'A')
     * deve falhar com HTTP 422 e mensagem 'Apenas pendentes podem ser canceladas.'.
     */
    public function test_bloqueia_cancelamento_de_pedido_que_nao_esta_pendente()
    {
        Sanctum::actingAs($this->solicitante);

        // Cria uma movimentação já aprovada ('A')
        $mov = Movimentacao::create([
            'usuario_id'         => $this->solicitante->id,
            'aprovador_usuario_id' => $this->almoxarife->id,
            'setor_origem_id'    => $this->setorDistribuidor->id,
            'setor_destino_id'   => $this->setorConsumidor->id,
            'tipo'               => 'S',
            'status_solicitacao' => 'A', // Já aprovada
            'data_hora'          => now(),
        ]);

        $response = $this->postJson("/api/movimentacao/{$mov->id}/process", [
            'action' => 'cancel'
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'Apenas pendentes podem ser canceladas.');

        // O status permanece 'A'
        $this->assertDatabaseHas('movimentacao', [
            'id'                 => $mov->id,
            'status_solicitacao' => 'A',
        ]);
    }

    // =========================================================================
    // 3. APROVAÇÃO COM BAIXA FIFO AUTOMÁTICA (ALMOXARIFE)
    // =========================================================================

    /**
     * Almoxarife aprova pedido com baixa PEPS/FIFO automática:
     * - Lote A (vencimento mais próximo, qtd 10) deve ser totalmente zerado.
     * - Lote B (vencimento mais distante, qtd 20) deve ter baixa de 5 unidades, restando 15.
     * - Estoque de origem deduz 15 unidades (de 30 para 15).
     * - Status da movimentação é atualizado para 'A'.
     */
    public function test_aprovacao_com_baixa_fifo_automatica_pelo_almoxarife()
    {
        // 1. Limpa lotes prévios deste produto no distribuidor para garantir cenário controlado
        EstoqueLote::where('produto_id', $this->produto->id)
            ->where('setor_id', $this->setorDistribuidor->id)
            ->delete();

        // 2. Prepara saldo total de 30 no estoque do distribuidor
        $estoqueOrigem = Estoque::updateOrCreate(
            ['setor_id' => $this->setorDistribuidor->id, 'produto_id' => $this->produto->id],
            ['quantidade_atual' => 30, 'quantidade_minima' => 5, 'status_disponibilidade' => 'D']
        );

        // 3. Cria Lote A: vence em 30 dias (deve sair primeiro)
        $loteA = EstoqueLote::create([
            'setor_id'              => $this->setorDistribuidor->id,
            'produto_id'            => $this->produto->id,
            'lote'                  => 'LOTE-A-VENC-PROXIMO',
            'quantidade_disponivel' => 10,
            'data_vencimento'       => now()->addDays(30)->toDateString(),
            'data_fabricacao'       => now()->subMonths(2)->toDateString(),
            'valor_unitario'        => 5.50,
        ]);

        // 4. Cria Lote B: vence em 90 dias (deve sair depois)
        $loteB = EstoqueLote::create([
            'setor_id'              => $this->setorDistribuidor->id,
            'produto_id'            => $this->produto->id,
            'lote'                  => 'LOTE-B-VENC-DISTANTE',
            'quantidade_disponivel' => 20,
            'data_vencimento'       => now()->addDays(90)->toDateString(),
            'data_fabricacao'       => now()->subMonths(1)->toDateString(),
            'valor_unitario'        => 5.50,
        ]);

        // 5. Cria movimentação pendente solicitando 15 unidades
        $mov = Movimentacao::create([
            'usuario_id'         => $this->solicitante->id,
            'setor_origem_id'    => $this->setorDistribuidor->id,
            'setor_destino_id'   => $this->setorConsumidor->id,
            'tipo'               => 'T',
            'status_solicitacao' => 'P',
            'data_hora'          => now(),
        ]);

        $item = ItemMovimentacao::create([
            'movimentacao_id'       => $mov->id,
            'produto_id'            => $this->produto->id,
            'quantidade_solicitada' => 15,
            'quantidade_liberada'   => 0,
        ]);

        // 6. Autentica como Arthur Almoxarife (tem perfil 'almoxarife' no setor distribuidor)
        Sanctum::actingAs($this->almoxarife);

        $payload = [
            'action' => 'approve',
            'itens'  => [
                [
                    'id'                  => $item->id,
                    'quantidade_liberada' => 15,
                ]
            ]
        ];

        $response = $this->postJson("/api/movimentacao/{$mov->id}/process", $payload);

        $response->assertStatus(200)
                 ->assertJsonPath('status', true)
                 ->assertJsonPath('data.status_solicitacao', 'A');

        // 7. VALIDAÇÕES DA BAIXA FIFO:
        // Lote A (vencimento mais próximo): 10 consumidos -> resta 0
        $loteA->refresh();
        $this->assertEquals(0, (float) $loteA->quantidade_disponivel);

        // Lote B (vencimento mais distante): 5 consumidos -> resta 15
        $loteB->refresh();
        $this->assertEquals(15, (float) $loteB->quantidade_disponivel);

        // Estoque de origem deduziu 15 unidades (30 - 15 = 15)
        $estoqueOrigem->refresh();
        $this->assertEquals(15, (float) $estoqueOrigem->quantidade_atual);

        // Status da movimentação no banco atualizado para 'A'
        $this->assertDatabaseHas('movimentacao', [
            'id'                 => $mov->id,
            'status_solicitacao' => 'A',
            'aprovador_usuario_id' => $this->almoxarife->id,
        ]);
    }

    // =========================================================================
    // 4. REJEIÇÃO COM ROLLBACK POR SALDO INSUFICIENTE
    // =========================================================================

    /**
     * Tentar aprovar quantidade maior do que a soma dos lotes disponíveis
     * deve disparar rollback da transação e retornar erro HTTP 422.
     */
    public function test_rejeicao_com_rollback_quando_saldo_em_lote_insuficiente()
    {
        // 1. Prepara apenas 8 unidades disponíveis no lote e estoque
        EstoqueLote::where('produto_id', $this->produto->id)
            ->where('setor_id', $this->setorDistribuidor->id)
            ->delete();

        $estoqueOrigem = Estoque::updateOrCreate(
            ['setor_id' => $this->setorDistribuidor->id, 'produto_id' => $this->produto->id],
            ['quantidade_atual' => 8, 'quantidade_minima' => 5, 'status_disponibilidade' => 'D']
        );

        $lote = EstoqueLote::create([
            'setor_id'              => $this->setorDistribuidor->id,
            'produto_id'            => $this->produto->id,
            'lote'                  => 'LOTE-INSUFICIENTE',
            'quantidade_disponivel' => 8,
            'data_vencimento'       => now()->addDays(60)->toDateString(),
            'data_fabricacao'       => now()->subMonths(1)->toDateString(),
            'valor_unitario'        => 3.00,
        ]);

        // 2. Pedido solicitando 25 unidades
        $mov = Movimentacao::create([
            'usuario_id'         => $this->solicitante->id,
            'setor_origem_id'    => $this->setorDistribuidor->id,
            'setor_destino_id'   => $this->setorConsumidor->id,
            'tipo'               => 'T',
            'status_solicitacao' => 'P',
            'data_hora'          => now(),
        ]);

        $item = ItemMovimentacao::create([
            'movimentacao_id'       => $mov->id,
            'produto_id'            => $this->produto->id,
            'quantidade_solicitada' => 25,
            'quantidade_liberada'   => 0,
        ]);

        Sanctum::actingAs($this->almoxarife);

        // 3. Tenta aprovar 25 unidades (saldo é apenas 8)
        $payload = [
            'action' => 'approve',
            'itens'  => [
                [
                    'id'                  => $item->id,
                    'quantidade_liberada' => 25,
                ]
            ]
        ];

        $response = $this->postJson("/api/movimentacao/{$mov->id}/process", $payload);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false);

        // 4. Confirma o ROLLBACK: o lote continua com 8 e o estoque com 8
        $lote->refresh();
        $this->assertEquals(8, (float) $lote->quantidade_disponivel);

        $estoqueOrigem->refresh();
        $this->assertEquals(8, (float) $estoqueOrigem->quantidade_atual);

        // A movimentação continua pendente 'P'
        $this->assertDatabaseHas('movimentacao', [
            'id'                 => $mov->id,
            'status_solicitacao' => 'P',
        ]);
    }
}
