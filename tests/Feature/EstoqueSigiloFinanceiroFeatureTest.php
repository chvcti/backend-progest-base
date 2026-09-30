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
use Database\Seeders\RegimeContratacaoSeeder;
use Database\Seeders\PolosESetoresDemoSeeder;
use Database\Seeders\AdminInicialSeeder;
use Database\Seeders\CatalogoProdutosOficialSeeder;

class EstoqueSigiloFinanceiroFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $adminComum;
    protected User $almoxarife;
    protected User $solicitante;
    protected Setores $cafSetor;
    protected Setores $farmaciaDispensacao;
    protected Setores $setorSemEstoque;
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

        // 2. Resgata superAdmin
        $this->superAdmin = User::where('email', 'adminti@gmail.com')->firstOrFail();

        // 3. Resgata setores-chave
        $this->cafSetor            = Setores::where('nome', 'CENTRAL DE ABASTECIMENTO FARMACÊUTICO (CAF)')->firstOrFail();
        $this->farmaciaDispensacao = Setores::where('nome', 'FARMÁCIA DE DISPENSAÇÃO')->firstOrFail();
        $this->setorSemEstoque     = Setores::where('nome', 'CLÍNICA MÉDICA')->where('estoque', false)->firstOrFail();

        // 4. Cria usuários de teste isolados
        $regimeId = \Illuminate\Support\Facades\DB::table('regime_contratacao')->value('id') ?? 1;

        $this->adminComum = User::create([
            'name'                  => 'ADMIN COMUM TESTE',
            'email'                 => 'admin.comum@progest.teste',
            'password'              => bcrypt('Admin123'),
            'cpf'                   => '44444444441',
            'telefone'              => '77999990011',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        \Illuminate\Support\Facades\DB::table('usuario_setor')->insert([
            'usuario_id' => $this->adminComum->id,
            'setor_id'   => $this->cafSetor->id,
            'perfil'     => 'admin',
        ]);

        $this->almoxarife = User::create([
            'name'                  => 'ALMOXARIFE TESTE',
            'email'                 => 'almoxarife.teste@progest.teste',
            'password'              => bcrypt('Admin123'),
            'cpf'                   => '44444444442',
            'telefone'              => '77999990012',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        \Illuminate\Support\Facades\DB::table('usuario_setor')->insert([
            ['usuario_id' => $this->almoxarife->id, 'setor_id' => $this->cafSetor->id, 'perfil' => 'almoxarife'],
            ['usuario_id' => $this->almoxarife->id, 'setor_id' => $this->farmaciaDispensacao->id, 'perfil' => 'almoxarife'],
        ]);

        $this->solicitante = User::create([
            'name'                  => 'SOLICITANTE TESTE',
            'email'                 => 'solicitante.teste@progest.teste',
            'password'              => bcrypt('Admin123'),
            'cpf'                   => '44444444443',
            'telefone'              => '77999990013',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        \Illuminate\Support\Facades\DB::table('usuario_setor')->insert([
            ['usuario_id' => $this->solicitante->id, 'setor_id' => $this->cafSetor->id, 'perfil' => 'solicitante'],
            ['usuario_id' => $this->solicitante->id, 'setor_id' => $this->setorSemEstoque->id, 'perfil' => 'solicitante'],
        ]);

        // 5. Produto de referência
        $this->produto = Produto::firstOrFail();
    }

    /**
     * Helper para popular estoque e lote com valor financeiro em um setor
     */
    private function prepararEstoqueComValor(Setores $setor, float $quantidade = 100, float $valorUnitario = 12.50): array
    {
        $estoque = Estoque::updateOrCreate(
            ['setor_id' => $setor->id, 'produto_id' => $this->produto->id],
            [
                'quantidade_atual'       => $quantidade,
                'quantidade_minima'      => 10,
                'status_disponibilidade' => 'D',
            ]
        );

        $lote = EstoqueLote::create([
            'setor_id'              => $setor->id,
            'produto_id'            => $this->produto->id,
            'lote'                  => 'LOTE-TESTE-VALOR-' . $setor->id,
            'quantidade_disponivel' => $quantidade,
            'valor_unitario'        => $valorUnitario,
            'data_vencimento'       => now()->addMonths(6)->toDateString(),
            'data_fabricacao'       => now()->subMonths(1)->toDateString(),
        ]);

        return [$estoque, $lote];
    }

    // =========================================================================
    // 1. VISUALIZAÇÃO NA CAF POR ADMIN / ALMOXARIFE / SUPER ADMIN
    // =========================================================================

    /**
     * Super Admin consultando a CAF visualiza pode_ver_valores: true,
     * valor_total_patrimonio não nulo e itens com valor_total e preco_medio calculados.
     */
    public function test_super_admin_visualiza_valores_financeiros_na_caf()
    {
        $this->prepararEstoqueComValor($this->cafSetor, 100, 10.00);

        Sanctum::actingAs($this->superAdmin);

        $response = $this->getJson("/api/estoque/setor/{$this->cafSetor->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('status', true)
                 ->assertJsonPath('data.resumo.pode_ver_valores', true);

        $valorPatrimonio = $response->json('data.resumo.valor_total_patrimonio');
        $this->assertNotNull($valorPatrimonio);
        $this->assertGreaterThan(0, (float) $valorPatrimonio);

        // Verifica que os itens listados possuem valor_total e preco_medio calculados
        $itens = $response->json('data.estoque');
        $this->assertNotEmpty($itens);

        $itemProduto = collect($itens)->firstWhere('produto.id', $this->produto->id);
        $this->assertNotNull($itemProduto);
        $this->assertEquals(1000.00, (float) $itemProduto['valor_total']);
        $this->assertEquals(10.00, (float) $itemProduto['preco_medio']);
    }

    /**
     * Usuário com perfil 'almoxarife' vinculado à CAF visualiza valores financeiros da CAF com sucesso.
     */
    public function test_almoxarife_da_caf_visualiza_valores_financeiros_na_caf()
    {
        $this->prepararEstoqueComValor($this->cafSetor, 50, 20.00);

        Sanctum::actingAs($this->almoxarife);

        $response = $this->getJson("/api/estoque/setor/{$this->cafSetor->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('status', true)
                 ->assertJsonPath('data.resumo.pode_ver_valores', true);

        $this->assertNotNull($response->json('data.resumo.valor_total_patrimonio'));

        $itens = $response->json('data.estoque');
        $itemProduto = collect($itens)->firstWhere('produto.id', $this->produto->id);
        $this->assertNotNull($itemProduto);
        $this->assertEquals(1000.00, (float) $itemProduto['valor_total']);
        $this->assertEquals(20.00, (float) $itemProduto['preco_medio']);
    }

    /**
     * Usuário com perfil 'admin' vinculado à CAF visualiza valores financeiros da CAF com sucesso.
     */
    public function test_admin_da_caf_visualiza_valores_financeiros_na_caf()
    {
        $this->prepararEstoqueComValor($this->cafSetor, 40, 15.00);

        Sanctum::actingAs($this->adminComum);

        $response = $this->getJson("/api/estoque/setor/{$this->cafSetor->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('status', true)
                 ->assertJsonPath('data.resumo.pode_ver_valores', true);

        $this->assertNotNull($response->json('data.resumo.valor_total_patrimonio'));
    }

    // =========================================================================
    // 2. BLOQUEIO EM SETORES CONSUMIDORES / FARMÁCIAS SATÉLITES
    // =========================================================================

    /**
     * Ao consultar setor que NÃO é a CAF (ex: Farmácia de Dispensação),
     * pode_ver_valores DEVE vir false, valor_total_patrimonio DEVE vir estritamente null,
     * e os itens listados DEVEM conter valor_total: null e preco_medio: null.
     */
    public function test_bloqueio_de_valores_financeiros_em_setores_satelites_e_consumidores()
    {
        $this->prepararEstoqueComValor($this->farmaciaDispensacao, 80, 25.00);

        // Almoxarife do setor acessando sua própria Farmácia de Dispensação
        Sanctum::actingAs($this->almoxarife);

        $response = $this->getJson("/api/estoque/setor/{$this->farmaciaDispensacao->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('status', true)
                 ->assertJsonPath('data.resumo.pode_ver_valores', false);

        // O patrimônio DEVE ser estritamente null
        $this->assertNull($response->json('data.resumo.valor_total_patrimonio'));

        // Itens de estoque não devem expor custos
        $itens = $response->json('data.estoque');
        $itemProduto = collect($itens)->firstWhere('produto.id', $this->produto->id);
        $this->assertNotNull($itemProduto);
        $this->assertNull($itemProduto['valor_total']);
        $this->assertNull($itemProduto['preco_medio']);
    }

    /**
     * Consultar setor sem estoque próprio (estoque = false) retorna aviso apropriado.
     */
    public function test_setor_sem_estoque_retorna_aviso_sem_dados()
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->getJson("/api/estoque/setor/{$this->setorSemEstoque->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'Este setor não possui controle de estoque.');
    }

    // =========================================================================
    // 3. BLOQUEIO PARA SOLICITANTE
    // =========================================================================

    /**
     * Usuário com perfil solicitante (jeansolicitante@gmail.com) NÃO PODE visualizar
     * valores financeiros em hipótese alguma, mesmo ao acessar a CAF.
     */
    public function test_solicitante_tem_valores_financeiros_bloqueados_mesmo_na_caf()
    {
        $this->prepararEstoqueComValor($this->cafSetor, 60, 30.00);

        Sanctum::actingAs($this->solicitante);

        $response = $this->getJson("/api/estoque/setor/{$this->cafSetor->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('status', true)
                 ->assertJsonPath('data.resumo.pode_ver_valores', false);

        // O patrimônio deve ser null
        $this->assertNull($response->json('data.resumo.valor_total_patrimonio'));

        // Os custos monetários dos produtos devem ser null
        $itens = $response->json('data.estoque');
        $itemProduto = collect($itens)->firstWhere('produto.id', $this->produto->id);
        $this->assertNotNull($itemProduto);
        $this->assertNull($itemProduto['valor_total']);
        $this->assertNull($itemProduto['preco_medio']);
    }

    // =========================================================================
    // 4. BLOQUEIO SEM AUTENTICAÇÃO
    // =========================================================================

    /**
     * Acesso anônimo sem token Sanctum DEVE ser barrado com status HTTP 401 Unauthorized.
     */
    public function test_acesso_sem_autenticacao_retorna_unauthorized()
    {
        $response = $this->getJson("/api/estoque/setor/{$this->cafSetor->id}");

        $response->assertStatus(401);
    }
}
