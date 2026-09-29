<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\User;
use App\Models\Setores;
use App\Models\Polo;
use App\Models\Produto;
use App\Models\GrupoProduto;
use App\Models\UnidadeMedida;
use App\Models\Estoque;
use App\Models\Movimentacao;
use App\Models\ItemMovimentacao;
use Laravel\Sanctum\Sanctum;

class DashboardControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;
    protected $setorComEstoque;
    protected $setorSemEstoque;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $polo = Polo::factory()->create();

        $this->setorComEstoque = Setores::create([
            'polo_id' => $polo->id,
            'nome' => 'Farmácia Central Teste',
            'estoque' => true,
            'tipo' => 'Medicamento',
            'status' => 'A'
        ]);

        $this->setorSemEstoque = Setores::create([
            'polo_id' => $polo->id,
            'nome' => 'UBS Teste Consumidor',
            'estoque' => false,
            'tipo' => 'Medicamento',
            'status' => 'A'
        ]);

        $grupo = GrupoProduto::firstOrCreate(
            ['nome' => 'Medicamentos Teste', 'tipo' => 'Medicamento'],
            ['status' => 'A']
        );

        $unidade = UnidadeMedida::firstOrCreate(
            ['nome' => 'Caixa Teste'],
            ['status' => 'A']
        );

        $produto = Produto::create([
            'nome' => 'Amoxicilina 500mg Teste',
            'marca' => 'Genérico Teste',
            'grupo_produto_id' => $grupo->id,
            'unidade_medida_id' => $unidade->id,
            'status' => 'A'
        ]);

        // Estoque abaixo do mínimo
        Estoque::create([
            'setor_id' => $this->setorComEstoque->id,
            'produto_id' => $produto->id,
            'quantidade_atual' => 5,
            'quantidade_minima' => 20,
            'status_disponibilidade' => 'D'
        ]);

        // Movimentação pendente de entrada para o setor sem estoque vindo do setor com estoque
        $mov = Movimentacao::create([
            'usuario_id' => $this->user->id,
            'setor_origem_id' => $this->setorComEstoque->id,
            'setor_destino_id' => $this->setorSemEstoque->id,
            'tipo' => 'S',
            'status_solicitacao' => 'P',
            'data_hora' => now()
        ]);

        ItemMovimentacao::create([
            'movimentacao_id' => $mov->id,
            'produto_id' => $produto->id,
            'quantidade_solicitada' => 10,
            'quantidade_atendida' => 0
        ]);
    }

    public function test_retorna_metricas_com_sucesso_para_setor_com_estoque()
    {
        $response = $this->getJson('/api/dashboard/metrics?setor_id=' . $this->setorComEstoque->id);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'stats' => [
                        'totalItens' => 1,
                        'abaixoMinimo' => 1,
                        'pendentesEntrada' => 0,
                        'pendentesSaida' => 1,
                    ]
                ]
            ]);

        $this->assertNotEmpty($response->json('data.alerts'));
        $this->assertEquals(5, $response->json('data.alerts.0.quantidade_atual'));
    }

    public function test_retorna_metricas_para_setor_consumidor()
    {
        $response = $this->getJson('/api/dashboard/metrics?setor_id=' . $this->setorSemEstoque->id);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'stats' => [
                        'totalItens' => 0,
                        'abaixoMinimo' => 0,
                        'pendentesEntrada' => 1,
                        'pendentesSaida' => 0,
                    ]
                ]
            ]);

        $this->assertEmpty($response->json('data.alerts'));
    }
}
