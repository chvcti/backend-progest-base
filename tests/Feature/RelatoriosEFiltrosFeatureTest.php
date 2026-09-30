<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Setores;
use App\Models\Polo;
use App\Models\Fornecedor;
use App\Models\Produto;
use App\Models\Movimentacao;
use App\Models\ItemMovimentacao;
use App\Models\Entrada;
use App\Models\ItensEntrada;
use Database\Seeders\RegimeContratacaoSeeder;
use Database\Seeders\PolosESetoresDemoSeeder;
use Database\Seeders\AdminInicialSeeder;
use Database\Seeders\CatalogoProdutosOficialSeeder;
use Database\Seeders\FornecedoresSeeder;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

class RelatoriosEFiltrosFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $solicitanteClinica;
    protected Setores $caf;
    protected Setores $farmaciaDispensacao;
    protected Setores $clinicaMedica;
    protected Fornecedor $fornecedorCristalia;
    protected Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RegimeContratacaoSeeder::class,
            PolosESetoresDemoSeeder::class,
            AdminInicialSeeder::class,
            CatalogoProdutosOficialSeeder::class,
            FornecedoresSeeder::class,
        ]);

        $this->superAdmin = User::where('email', 'adminti@gmail.com')->firstOrFail();
        $this->clinicaMedica = Setores::where('nome', 'CLÍNICA MÉDICA')->firstOrFail();

        $regimeId = \Illuminate\Support\Facades\DB::table('regime_contratacao')->value('id') ?? 1;
        $this->solicitanteClinica = User::create([
            'name'                  => 'SOLICITANTE CLINICA TESTE',
            'email'                 => 'solicitante.clinica@progest.teste',
            'password'              => bcrypt('Admin123'),
            'cpf'                   => '55555555551',
            'telefone'              => '77999990051',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        \Illuminate\Support\Facades\DB::table('usuario_setor')->insert([
            'usuario_id' => $this->solicitanteClinica->id,
            'setor_id'   => $this->clinicaMedica->id,
            'perfil'     => 'solicitante',
        ]);

        $this->caf = Setores::where('nome', 'CENTRAL DE ABASTECIMENTO FARMACÊUTICO (CAF)')->firstOrFail();
        $this->farmaciaDispensacao = Setores::where('nome', 'FARMÁCIA DE DISPENSAÇÃO')->firstOrFail();
        $this->clinicaMedica = Setores::where('nome', 'CLÍNICA MÉDICA')->firstOrFail();

        $this->fornecedorCristalia = Fornecedor::where('razao_social_nome', 'LIKE', '%Cristália%')->first()
            ?? Fornecedor::firstOrFail();

        $this->produto = Produto::firstOrFail();

        // Criar dados base para teste dos relatórios
        $this->criarMassaDeDadosParaRelatorios();
    }

    private function criarMassaDeDadosParaRelatorios(): void
    {
        $hoje = Carbon::now();
        $ontem = Carbon::now()->subDay();
        $cincoDiasAtras = Carbon::now()->subDays(5);

        // 1. Entrada por NF
        $entrada = Entrada::create([
            'fornecedor_id' => $this->fornecedorCristalia->id,
            'setor_id' => $this->caf->id,
            'nota_fiscal' => 'NF-998877',
            'data_emissao' => $ontem->toDateString(),
            'valor_total' => 1500.00,
            'created_at' => $ontem,
            'updated_at' => $ontem,
        ]);

        ItensEntrada::create([
            'entrada_id' => $entrada->id,
            'produto_id' => $this->produto->id,
            'quantidade' => 100,
            'valor_unitario' => 15.00,
            'lote' => 'LOTE-CRISTALIA-01',
            'data_vencimento' => Carbon::now()->addYear()->toDateString(),
        ]);

        // 2. Movimentação Tipo Saída/Solicitação ('S')
        $movSaida = Movimentacao::create([
            'usuario_id' => $this->solicitanteClinica->id,
            'aprovador_usuario_id' => $this->superAdmin->id,
            'setor_origem_id' => $this->farmaciaDispensacao->id,
            'setor_destino_id' => $this->clinicaMedica->id,
            'tipo' => 'S',
            'status_solicitacao' => 'A',
            'data_hora' => $ontem,
            'observacao' => 'Reposição Clínica Médica',
            'created_at' => $ontem,
            'updated_at' => $ontem,
        ]);

        ItemMovimentacao::create([
            'movimentacao_id' => $movSaida->id,
            'produto_id' => $this->produto->id,
            'quantidade_solicitada' => 20,
            'quantidade_liberada' => 20,
            'created_at' => $ontem,
            'updated_at' => $ontem,
        ]);

        // 3. Movimentação Tipo Devolução ('D')
        $movDevolucao = Movimentacao::create([
            'usuario_id' => $this->solicitanteClinica->id,
            'aprovador_usuario_id' => $this->superAdmin->id,
            'setor_origem_id' => $this->clinicaMedica->id,
            'setor_destino_id' => $this->farmaciaDispensacao->id,
            'tipo' => 'D',
            'status_solicitacao' => 'A',
            'data_hora' => $hoje,
            'observacao' => 'Devolução de sobra',
            'created_at' => $hoje,
            'updated_at' => $hoje,
        ]);

        ItemMovimentacao::create([
            'movimentacao_id' => $movDevolucao->id,
            'produto_id' => $this->produto->id,
            'quantidade_solicitada' => 5,
            'quantidade_liberada' => 5,
            'created_at' => $hoje,
            'updated_at' => $hoje,
        ]);

        // 4. Movimentação antiga (fora de range para testar datas)
        $movAntiga = Movimentacao::create([
            'usuario_id' => $this->superAdmin->id,
            'aprovador_usuario_id' => $this->superAdmin->id,
            'setor_origem_id' => $this->caf->id,
            'setor_destino_id' => $this->farmaciaDispensacao->id,
            'tipo' => 'T',
            'status_solicitacao' => 'A',
            'data_hora' => $cincoDiasAtras,
            'observacao' => 'Abastecimento da semana passada',
            'created_at' => $cincoDiasAtras,
            'updated_at' => $cincoDiasAtras,
        ]);

        ItemMovimentacao::create([
            'movimentacao_id' => $movAntiga->id,
            'produto_id' => $this->produto->id,
            'quantidade_solicitada' => 50,
            'quantidade_liberada' => 50,
            'created_at' => $cincoDiasAtras,
            'updated_at' => $cincoDiasAtras,
        ]);
    }

    // =========================================================================
    // 1. RELATÓRIO DE MOVIMENTAÇÕES E FILTROS
    // =========================================================================

    public function test_relatorio_movimentacoes_sem_filtros_retorna_lista(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $res = $this->postJson('/api/relatorios/movimentacoes/list', [
            'filters' => []
        ]);

        $res->assertStatus(200)
            ->assertJson(['status' => true])
            ->assertJsonStructure(['data' => [
                '*' => ['id', 'tipo', 'status_solicitacao', 'itens']
            ]]);

        $this->assertGreaterThanOrEqual(3, count($res->json('data')));
    }

    public function test_relatorio_movimentacoes_filtra_por_tipo_devolucao(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $res = $this->postJson('/api/relatorios/movimentacoes/list', [
            'filters' => [
                'tipo' => 'D', // Apenas Devoluções
            ]
        ]);

        $res->assertStatus(200)
            ->assertJson(['status' => true]);

        $dados = $res->json('data');
        $this->assertNotEmpty($dados);

        foreach ($dados as $mov) {
            $this->assertEquals('D', $mov['tipo'], 'Todas as movimentações devem ser do tipo Devolução (D)');
        }
    }

    public function test_relatorio_movimentacoes_filtra_por_periodo(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $hoje = Carbon::now()->toDateString();
        $doisDiasAtras = Carbon::now()->subDays(2)->toDateString();

        $res = $this->postJson('/api/relatorios/movimentacoes/list', [
            'filters' => [
                'date_from' => $doisDiasAtras,
                'date_to' => $hoje,
            ]
        ]);

        $res->assertStatus(200)
            ->assertJson(['status' => true]);

        $dados = $res->json('data');
        // Deve incluir as movimentações de hoje e ontem, mas excluir a de 5 dias atrás
        $this->assertNotEmpty($dados);
        foreach ($dados as $mov) {
            $dataCriacao = Carbon::parse($mov['created_at'])->toDateString();
            $this->assertGreaterThanOrEqual($doisDiasAtras, $dataCriacao);
            $this->assertLessThanOrEqual($hoje, $dataCriacao);
        }
    }

    public function test_relatorio_movimentacoes_rejeita_data_final_menor_que_inicial(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $res = $this->postJson('/api/relatorios/movimentacoes/list', [
            'filters' => [
                'date_from' => '2026-09-03',
                'date_to' => '2026-09-01', // Data final anterior à inicial
            ]
        ]);

        $res->assertStatus(422)
            ->assertJson(['status' => false, 'validacao' => true])
            ->assertJsonStructure(['erros' => ['filters.date_to']]);
    }

    // =========================================================================
    // 2. RELATÓRIO DE ENTRADAS (NOTAS FISCAIS) E FILTROS
    // =========================================================================

    public function test_relatorio_entradas_filtra_por_fornecedor_e_setor(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $res = $this->postJson('/api/relatorios/entradas/list', [
            'filters' => [
                'fornecedor_id' => $this->fornecedorCristalia->id,
                'setor_id' => $this->caf->id,
                'nota_fiscal' => '998877',
            ]
        ]);

        $res->assertStatus(200)
            ->assertJson(['status' => true]);

        $dados = $res->json('data');
        $this->assertNotEmpty($dados);
        $this->assertEquals($this->fornecedorCristalia->id, $dados[0]['fornecedor_id']);
        $this->assertStringContainsString('998877', $dados[0]['nota_fiscal']);
    }

    // =========================================================================
    // 3. RELATÓRIO DE ESTOQUE
    // =========================================================================

    public function test_relatorio_estoque_lista_saldos_com_sucesso(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $res = $this->postJson('/api/relatorios/estoque/list', [
            'filters' => [
                'setor_id' => $this->caf->id,
            ]
        ]);

        $res->assertStatus(200)
            ->assertJson(['status' => true]);
    }

    // =========================================================================
    // 4. RELATÓRIO DE SAÍDAS CONSOLIDADAS POR DATA
    // =========================================================================

    public function test_relatorio_saidas_por_data_agrupa_quantidades_consumidas(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $hoje = Carbon::now()->toDateString();
        $doisDiasAtras = Carbon::now()->subDays(2)->toDateString();

        $res = $this->postJson('/api/relatorios/saidas-por-data/list', [
            'filters' => [
                'date_from' => $doisDiasAtras,
                'date_to' => $hoje,
            ]
        ]);

        $res->assertStatus(200)
            ->assertJson(['status' => true]);

        $dados = $res->json('data');
        $this->assertIsArray($dados);
    }
}
