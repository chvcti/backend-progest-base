<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
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
use Laravel\Sanctum\Sanctum;

class DevolucaoFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $solicitanteClinica;
    protected User $almoxarifeFarmacia;
    protected User $adminCaf;
    protected Setores $clinicaMedica;
    protected Setores $farmaciaDispensacao;
    protected Setores $caf;
    protected Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RegimeContratacaoSeeder::class,
            PolosESetoresDemoSeeder::class,
            AdminInicialSeeder::class,
            CatalogoProdutosOficialSeeder::class,
        ]);

        $this->adminCaf = User::where('email', 'adminti@gmail.com')->firstOrFail();
        $this->clinicaMedica = Setores::where('nome', 'CLÍNICA MÉDICA')->firstOrFail();
        $this->farmaciaDispensacao = Setores::where('nome', 'FARMÁCIA DE DISPENSAÇÃO')->firstOrFail();
        $this->caf = Setores::where('nome', 'CENTRAL DE ABASTECIMENTO FARMACÊUTICO (CAF)')->firstOrFail();

        $regimeId = \Illuminate\Support\Facades\DB::table('regime_contratacao')->value('id') ?? 1;

        $this->solicitanteClinica = User::create([
            'name'                  => 'SOLICITANTE CLINICA',
            'email'                 => 'solicitante.dev@progest.teste',
            'password'              => bcrypt('Admin123'),
            'cpf'                   => '77777777771',
            'telefone'              => '77999990071',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        \Illuminate\Support\Facades\DB::table('usuario_setor')->insert([
            'usuario_id' => $this->solicitanteClinica->id,
            'setor_id'   => $this->clinicaMedica->id,
            'perfil'     => 'solicitante',
        ]);

        $this->almoxarifeFarmacia = User::create([
            'name'                  => 'ALMOXARIFE FARMACIA',
            'email'                 => 'almoxarife.dev@progest.teste',
            'password'              => bcrypt('Admin123'),
            'cpf'                   => '77777777772',
            'telefone'              => '77999990072',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        \Illuminate\Support\Facades\DB::table('usuario_setor')->insert([
            ['usuario_id' => $this->almoxarifeFarmacia->id, 'setor_id' => $this->farmaciaDispensacao->id, 'perfil' => 'almoxarife'],
            ['usuario_id' => $this->almoxarifeFarmacia->id, 'setor_id' => $this->caf->id, 'perfil' => 'almoxarife'],
        ]);

        $this->produto = Produto::firstOrFail();
    }

    /**
     * Cenário 1: Setor sem estoque (Clínica Médica) devolve para Farmácia de Dispensação.
     * Origem não tem estoque físico nem lotes.
     * Destino recebe o incremento sem falha de estoque insuficiente na origem.
     */
    public function test_devolucao_origem_sem_estoque_para_distribuidor_com_estoque()
    {
        // 1. Garantir que a clínica médica tem estoque = false
        $this->assertFalse((bool) $this->clinicaMedica->estoque);

        // 2. Estado inicial do estoque na farmácia de dispensação
        $estoqueInicialDestino = Estoque::firstOrCreate(
            ['setor_id' => $this->farmaciaDispensacao->id, 'produto_id' => $this->produto->id],
            ['quantidade_atual' => 50, 'quantidade_minima' => 10, 'status_disponibilidade' => 'D']
        );
        $qtdInicial = (float) $estoqueInicialDestino->quantidade_atual;

        // 3. Solicitante cria a devolução (tipo 'D')
        Sanctum::actingAs($this->solicitanteClinica);
        $createResponse = $this->postJson('/api/movimentacao/create', [
            'usuario_id' => $this->solicitanteClinica->id,
            'setor_origem_id' => $this->clinicaMedica->id,
            'setor_destino_id' => $this->farmaciaDispensacao->id,
            'tipo' => 'D',
            'status_solicitacao' => 'P',
            'observacao' => 'Devolução de sobra de prescrição do paciente leito 12',
            'itens' => [
                [
                    'produto_id' => $this->produto->id,
                    'quantidade_solicitada' => 5,
                    'lote' => 'LOTE-DEV-001'
                ]
            ]
        ]);

        $createResponse->assertStatus(201)
            ->assertJson(['status' => true]);

        $movId = $createResponse->json('data.id');

        // 4. Almoxarife da Farmácia de Dispensação confere e aprova a devolução
        Sanctum::actingAs($this->almoxarifeFarmacia);
        $approveResponse = $this->postJson("/api/movimentacao/{$movId}/process", [
            'action' => 'approve',
            'itens' => [
                [
                    'id' => ItemMovimentacao::where('movimentacao_id', $movId)->first()->id,
                    'quantidade_liberada' => 5
                ]
            ]
        ]);

        $approveResponse->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'status_solicitacao' => 'A'
                ]
            ]);

        // 5. Verificar que o estoque da farmácia aumentou em 5 unidades
        $estoqueFinalDestino = Estoque::where('setor_id', $this->farmaciaDispensacao->id)
            ->where('produto_id', $this->produto->id)
            ->first();

        $this->assertEquals($qtdInicial + 5, (float) $estoqueFinalDestino->quantidade_atual);

        // 6. Verificar que foi criado ou incrementado o lote no destino
        $loteDestino = EstoqueLote::where('setor_id', $this->farmaciaDispensacao->id)
            ->where('produto_id', $this->produto->id)
            ->where('lote', 'LOTE-DEV-001')
            ->first();

        $this->assertNotNull($loteDestino);
        $this->assertEquals(5, (float) $loteDestino->quantidade_disponivel);

        // 7. Verificar que na clínica médica NÃO foi criado estoque fantasma
        $estoqueClinica = Estoque::where('setor_id', $this->clinicaMedica->id)
            ->where('produto_id', $this->produto->id)
            ->first();
        $this->assertNull($estoqueClinica);
    }

    /**
     * Cenário 2: Setor COM estoque devolve para a CAF.
     * Origem deve ter seu estoque deduzido e destino incrementado.
     */
    public function test_devolucao_origem_com_estoque_para_caf()
    {
        // 1. Configurar saldo inicial na Farmácia de Dispensação (origem com estoque = true)
        $estoqueOrigem = Estoque::updateOrCreate(
            ['setor_id' => $this->farmaciaDispensacao->id, 'produto_id' => $this->produto->id],
            ['quantidade_atual' => 40, 'quantidade_minima' => 5, 'status_disponibilidade' => 'D']
        );

        EstoqueLote::updateOrCreate(
            ['setor_id' => $this->farmaciaDispensacao->id, 'produto_id' => $this->produto->id, 'lote' => 'LOTE-SAT-40'],
            ['quantidade_disponivel' => 40, 'data_vencimento' => now()->addMonths(6)->toDateString()]
        );

        // 2. Configurar saldo inicial na CAF (destino)
        $estoqueCaf = Estoque::updateOrCreate(
            ['setor_id' => $this->caf->id, 'produto_id' => $this->produto->id],
            ['quantidade_atual' => 100, 'quantidade_minima' => 20, 'status_disponibilidade' => 'D']
        );

        // 3. Almoxarife cria pedido de devolução da Farmácia para a CAF
        Sanctum::actingAs($this->almoxarifeFarmacia);
        $createResponse = $this->postJson('/api/movimentacao/create', [
            'usuario_id' => $this->almoxarifeFarmacia->id,
            'setor_origem_id' => $this->farmaciaDispensacao->id,
            'setor_destino_id' => $this->caf->id,
            'tipo' => 'D',
            'status_solicitacao' => 'P',
            'observacao' => 'Devolução de excesso de estoque para a CAF',
            'itens' => [
                [
                    'produto_id' => $this->produto->id,
                    'quantidade_solicitada' => 15
                ]
            ]
        ]);

        $createResponse->assertStatus(201);
        $movId = $createResponse->json('data.id');

        // 4. Admin da CAF aprova a devolução
        Sanctum::actingAs($this->adminCaf);
        $approveResponse = $this->postJson("/api/movimentacao/{$movId}/process", [
            'action' => 'approve'
        ]);

        $approveResponse->assertStatus(200);

        // 5. Validar estoques: Origem deduzida (40 - 15 = 25) e Destino somado (100 + 15 = 115)
        $this->assertEquals(25, (float) Estoque::where('setor_id', $this->farmaciaDispensacao->id)->where('produto_id', $this->produto->id)->value('quantidade_atual'));
        $this->assertEquals(115, (float) Estoque::where('setor_id', $this->caf->id)->where('produto_id', $this->produto->id)->value('quantidade_atual'));
    }

    /**
     * Cenário 3: Bloqueio de devolução para setor sem estoque físico.
     */
    public function test_bloqueia_devolucao_para_setor_sem_estoque()
    {
        $outroSetorConsumidor = Setores::where('estoque', false)
            ->where('id', '!=', $this->clinicaMedica->id)
            ->firstOrFail();

        Sanctum::actingAs($this->solicitanteClinica);
        $response = $this->postJson('/api/movimentacao/create', [
            'usuario_id' => $this->solicitanteClinica->id,
            'setor_origem_id' => $this->clinicaMedica->id,
            'setor_destino_id' => $outroSetorConsumidor->id,
            'tipo' => 'D',
            'status_solicitacao' => 'P',
            'itens' => [
                [
                    'produto_id' => $this->produto->id,
                    'quantidade_solicitada' => 2
                ]
            ]
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => false,
                'message' => 'Devoluções só podem ser enviadas para setores com controle de estoque ativo (farmácias/almoxarifados).'
            ]);
    }

    /**
     * Cenário 4: Solicitante do setor de origem NÃO pode autoaprovar sua própria devolução.
     * Quem deve aprovar é o almoxarife do setor de destino (receptor).
     */
    public function test_solicitante_da_origem_nao_pode_autoaprovar_devolucao()
    {
        Sanctum::actingAs($this->solicitanteClinica);
        $createResponse = $this->postJson('/api/movimentacao/create', [
            'usuario_id' => $this->solicitanteClinica->id,
            'setor_origem_id' => $this->clinicaMedica->id,
            'setor_destino_id' => $this->farmaciaDispensacao->id,
            'tipo' => 'D',
            'status_solicitacao' => 'P',
            'itens' => [
                [
                    'produto_id' => $this->produto->id,
                    'quantidade_solicitada' => 1
                ]
            ]
        ]);

        $movId = $createResponse->json('data.id');

        // Solicitante tenta aprovar
        $tryApprove = $this->postJson("/api/movimentacao/{$movId}/process", [
            'action' => 'approve'
        ]);

        $tryApprove->assertStatus(403)
            ->assertJson([
                'status' => false
            ]);
    }

    /**
     * Cenário 5: Solicitante do setor de origem cancela sua devolução pendente -> Status 'X'.
     */
    public function test_solicitante_da_origem_pode_cancelar_devolucao_pendente()
    {
        Sanctum::actingAs($this->solicitanteClinica);
        $createResponse = $this->postJson('/api/movimentacao/create', [
            'usuario_id' => $this->solicitanteClinica->id,
            'setor_origem_id' => $this->clinicaMedica->id,
            'setor_destino_id' => $this->farmaciaDispensacao->id,
            'tipo' => 'D',
            'status_solicitacao' => 'P',
            'itens' => [
                [
                    'produto_id' => $this->produto->id,
                    'quantidade_solicitada' => 3
                ]
            ]
        ]);

        $movId = $createResponse->json('data.id');

        // Solicitante cancela
        $cancelResponse = $this->postJson("/api/movimentacao/{$movId}/process", [
            'action' => 'cancel'
        ]);

        $cancelResponse->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'status_solicitacao' => 'X'
                ]
            ]);

        $this->assertEquals('X', Movimentacao::find($movId)->status_solicitacao);
    }
}
