<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Setores;
use App\Models\Polo;
use App\Models\Fornecedor;
use App\Models\Produto;
use App\Models\GrupoProduto;
use App\Models\UnidadeMedida;
use App\Models\RegimeContratacao;
use Database\Seeders\RegimeContratacaoSeeder;
use Database\Seeders\PolosESetoresDemoSeeder;
use Database\Seeders\AdminInicialSeeder;
use Database\Seeders\CatalogoProdutosOficialSeeder;
use Database\Seeders\FornecedoresSeeder;
use Laravel\Sanctum\Sanctum;

class CadastrosCrudFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $solicitanteComum;
    protected Polo $poloHgvc;
    protected GrupoProduto $grupoMedicamento;
    protected UnidadeMedida $unidadeAmpola;
    protected RegimeContratacao $regimeEstatutario;

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
        $this->unidadeAmpola = UnidadeMedida::firstOrFail();
        $this->regimeEstatutario = RegimeContratacao::firstOrFail();

        $this->solicitanteComum = User::create([
            'name'                  => 'SOLICITANTE TESTE',
            'email'                 => 'solicitante.teste@progest.teste',
            'password'              => bcrypt('Admin123'),
            'cpf'                   => $this->gerarCpfValido(),
            'telefone'              => '77999990003',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $this->regimeEstatutario->id,
        ]);

        $this->poloHgvc = Polo::where('sigla', 'HGVC')->first() ?? Polo::firstOrFail();
        $this->grupoMedicamento = GrupoProduto::firstOrCreate(
            ['nome' => 'MEDICAMENTOS GERAIS'],
            ['tipo' => 'Medicamento', 'status' => 'A']
        );
    }

    private function gerarCpfValido(): string
    {
        $n = [];
        for ($i = 0; $i < 9; $i++) {
            $n[$i] = rand(0, 9);
        }
        $soma = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma += $n[$i] * (10 - $i);
        }
        $resto = $soma % 11;
        $n[9] = ($resto < 2) ? 0 : 11 - $resto;

        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += $n[$i] * (11 - $i);
        }
        $resto = $soma % 11;
        $n[10] = ($resto < 2) ? 0 : 11 - $resto;

        return implode('', $n);
    }

    private function gerarCnpjValido(): string
    {
        $n = [rand(1, 9), rand(0, 9), rand(0, 9), rand(0, 9), rand(0, 9), rand(0, 9), rand(0, 9), rand(0, 9), 0, 0, 0, 1];
        $pesos1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        for ($i = 0; $i < 12; $i++) {
            $soma += $n[$i] * $pesos1[$i];
        }
        $resto = $soma % 11;
        $n[12] = ($resto < 2) ? 0 : 11 - $resto;

        $pesos2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        for ($i = 0; $i < 13; $i++) {
            $soma += $n[$i] * $pesos2[$i];
        }
        $resto = $soma % 11;
        $n[13] = ($resto < 2) ? 0 : 11 - $resto;

        return implode('', $n);
    }

    // =========================================================================
    // 1. TESTES DE PRODUTOS
    // =========================================================================

    public function test_super_admin_pode_cadastrar_editar_e_inativar_produto(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // 1. Cadastro
        $resAdd = $this->postJson('/api/produtos/add', [
            'produto' => [
                'nome' => 'DIPIRONA SODICA 500MG/ML 2ML TESTE',
                'marca' => 'FARMACEUTICA TESTE',
                'codigo_simpas' => 'SIMP' . rand(1000, 9999),
                'codigo_barras' => '789' . rand(1000000000, 9999999999),
                'grupo_produto_id' => $this->grupoMedicamento->id,
                'unidade_medida_id' => $this->unidadeAmpola->id,
                'status' => 'A',
            ]
        ]);

        $resAdd->assertStatus(201)
               ->assertJson(['status' => true]);

        $produtoId = $resAdd->json('data.id');
        $this->assertDatabaseHas('produtos', ['id' => $produtoId, 'status' => 'A']);

        // 2. Edição
        $resUpdate = $this->postJson('/api/produtos/update', [
            'produto' => [
                'id' => $produtoId,
                'nome' => 'DIPIRONA SODICA 500MG/ML 2ML TESTE ALTERADO',
                'marca' => 'FARMACEUTICA ALTERADA',
                'grupo_produto_id' => $this->grupoMedicamento->id,
                'unidade_medida_id' => $this->unidadeAmpola->id,
                'status' => 'A',
            ]
        ]);

        $resUpdate->assertStatus(200)
                  ->assertJson(['status' => true]);

        $this->assertDatabaseHas('produtos', [
            'id' => $produtoId,
            'nome' => 'DIPIRONA SODICA 500MG/ML 2ML TESTE ALTERADO',
        ]);

        // 3. Inativação (toggle via delete)
        $resDelete = $this->postJson("/api/produtos/delete/{$produtoId}");
        $resDelete->assertStatus(200)
                  ->assertJson(['status' => true]);

        $this->assertDatabaseHas('produtos', ['id' => $produtoId, 'status' => 'I']);
    }

    public function test_solicitante_comum_bloqueado_de_cadastrar_produto(): void
    {
        Sanctum::actingAs($this->solicitanteComum, ['*']);

        $res = $this->postJson('/api/produtos/add', [
            'produto' => [
                'nome' => 'PRODUTO BLOQUEADO',
                'grupo_produto_id' => $this->grupoMedicamento->id,
                'unidade_medida_id' => $this->unidadeAmpola->id,
            ]
        ]);

        $res->assertStatus(403);
    }

    // =========================================================================
    // 2. TESTES DE FORNECEDORES
    // =========================================================================

    public function test_super_admin_pode_cadastrar_editar_e_inativar_fornecedor(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $cnpj = $this->gerarCnpjValido();

        // 1. Cadastro
        $resAdd = $this->postJson('/api/fornecedores/add', [
            'fornecedor' => [
                'tipo_pessoa' => 'J',
                'razao_social_nome' => 'DISTRIBUIDORA MEDICA HOSPITALAR LTDA TESTE',
                'cnpj' => $cnpj,
                'status' => 'A',
            ]
        ]);

        $resAdd->assertStatus(201)
               ->assertJson(['status' => true]);

        $fornecedorId = $resAdd->json('data.id');
        $this->assertDatabaseHas('fornecedores', ['id' => $fornecedorId, 'status' => 'A']);

        // 2. Edição
        $resUpdate = $this->postJson('/api/fornecedores/update', [
            'fornecedor' => [
                'id' => $fornecedorId,
                'tipo_pessoa' => 'J',
                'razao_social_nome' => 'DISTRIBUIDORA MEDICA HOSPITALAR NOME ATUALIZADO',
                'cnpj' => $cnpj,
                'status' => 'A',
            ]
        ]);

        $resUpdate->assertStatus(200)
                  ->assertJson(['status' => true]);

        $this->assertDatabaseHas('fornecedores', [
            'id' => $fornecedorId,
            'razao_social_nome' => 'DISTRIBUIDORA MEDICA HOSPITALAR NOME ATUALIZADO',
        ]);

        // 3. Inativação (toggle via delete)
        $resDelete = $this->postJson("/api/fornecedores/delete/{$fornecedorId}");
        $resDelete->assertStatus(200)
                  ->assertJson(['status' => true]);

        $this->assertDatabaseHas('fornecedores', ['id' => $fornecedorId, 'status' => 'I']);
    }

    public function test_fornecedor_com_cnpj_invalido_falha_validacao(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $res = $this->postJson('/api/fornecedores/add', [
            'fornecedor' => [
                'tipo_pessoa' => 'J',
                'razao_social_nome' => 'FORNECEDOR INVALIDO LTDA',
                'cnpj' => '11111111111111', // CNPJ com dígitos inválidos
                'status' => 'A',
            ]
        ]);

        $res->assertStatus(422)
            ->assertJson(['status' => false, 'validacao' => true])
            ->assertJsonStructure(['erros' => ['fornecedor.cnpj']]);
    }

    // =========================================================================
    // 3. TESTES DE SETORES
    // =========================================================================

    public function test_super_admin_pode_cadastrar_editar_e_inativar_setor(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // 1. Cadastro
        $nomeSetor = 'ENFERMARIA PEDIATRICA TESTE ' . rand(100, 999);
        $resAdd = $this->postJson('/api/setores/add', [
            'setores' => [
                'polo_id' => $this->poloHgvc->id,
                'nome' => $nomeSetor,
                'descricao' => 'Ala pediátrica de testes automatizados',
                'estoque' => false,
                'tipo' => 'Ambos',
                'status' => 'A',
            ]
        ]);

        $resAdd->assertStatus(200)
               ->assertJson(['status' => true]);

        $setorId = $resAdd->json('data.id');
        $this->assertDatabaseHas('setores', ['id' => $setorId, 'status' => 'A']);

        // 2. Edição
        $resUpdate = $this->postJson('/api/setores/update', [
            'setores' => [
                'id' => $setorId,
                'polo_id' => $this->poloHgvc->id,
                'nome' => $nomeSetor . ' ALTERADA',
                'descricao' => 'Descrição modificada',
                'estoque' => false,
                'tipo' => 'Medicamento',
                'status' => 'A',
            ]
        ]);

        $resUpdate->assertStatus(200)
                  ->assertJson(['status' => true]);

        $this->assertDatabaseHas('setores', [
            'id' => $setorId,
            'nome' => $nomeSetor . ' ALTERADA',
            'tipo' => 'Medicamento',
        ]);

        // 3. Inativação (toggleStatus)
        $resToggle = $this->postJson('/api/setores/toggleStatus', ['id' => $setorId]);
        $resToggle->assertStatus(200)
                  ->assertJson(['status' => true]);

        $this->assertDatabaseHas('setores', ['id' => $setorId, 'status' => 'I']);
    }

    // =========================================================================
    // 4. TESTES DE POLOS
    // =========================================================================

    public function test_super_admin_pode_cadastrar_editar_e_inativar_polo(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $sigla = 'TEST' . rand(10, 99);
        $nomePolo = 'POLO HOSPITALAR REGIONAL ' . $sigla;

        // 1. Cadastro
        $resAdd = $this->postJson('/api/polo/add', [
            'nome' => $nomePolo,
            'sigla' => $sigla,
            'status' => 'A',
        ]);

        $resAdd->assertStatus(201)
               ->assertJson(['status' => true]);

        $poloId = $resAdd->json('data.id');
        $this->assertDatabaseHas('polos', ['id' => $poloId, 'status' => 'A']);

        // 2. Edição
        $resUpdate = $this->postJson('/api/polo/update', [
            'id' => $poloId,
            'nome' => $nomePolo . ' ATUALIZADO',
            'sigla' => $sigla,
            'status' => 'A',
        ]);

        $resUpdate->assertStatus(200)
                  ->assertJson(['status' => true]);

        $this->assertDatabaseHas('polos', [
            'id' => $poloId,
            'nome' => $nomePolo . ' ATUALIZADO',
        ]);

        // 3. Inativação (toggleStatus)
        $resToggle = $this->postJson('/api/polo/toggleStatus', ['id' => $poloId]);
        $resToggle->assertStatus(200)
                  ->assertJson(['status' => true]);

        $this->assertDatabaseHas('polos', ['id' => $poloId, 'status' => 'I']);
    }

    // =========================================================================
    // 5. TESTES DE USUÁRIOS
    // =========================================================================

    public function test_super_admin_pode_cadastrar_editar_e_inativar_usuario(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $cpf = $this->gerarCpfValido();
        $email = 'enfermeiro.teste' . rand(1000, 9999) . '@progest.com';

        // 1. Cadastro
        $resAdd = $this->postJson('/api/user/add', [
            'user' => [
                'name' => 'ENFERMEIRO TESTE AUTOMATIZADO',
                'email' => $email,
                'cpf' => $cpf,
                'data_nascimento' => '1990-05-15',
                'telefone' => '77999887766',
                'regime_contratacao_id' => $this->regimeEstatutario->id,
                'status' => 'A',
                'password' => 'SenhaForte123',
            ],
            'setores' => [
                ['setor_id' => $this->poloHgvc->setores()->first()->id, 'perfil' => 'solicitante']
            ]
        ]);

        $resAdd->assertStatus(200)
               ->assertJson(['status' => true]);

        $userId = $resAdd->json('data.id');
        $this->assertDatabaseHas('users', ['id' => $userId, 'email' => $email, 'status' => 'A']);

        // 2. Edição
        $resUpdate = $this->postJson('/api/user/update', [
            'user' => [
                'id' => $userId,
                'name' => 'ENFERMEIRO TESTE NOME MODIFICADO',
                'email' => $email,
                'cpf' => $cpf,
                'data_nascimento' => '1990-05-15',
                'telefone' => '77988884444',
                'regime_contratacao_id' => $this->regimeEstatutario->id,
                'status' => 'A',
            ]
        ]);

        $resUpdate->assertStatus(200)
                  ->assertJson(['status' => true]);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'name' => 'ENFERMEIRO TESTE NOME MODIFICADO',
        ]);

        // 3. Inativação (delete)
        $resDelete = $this->postJson("/api/user/delete/{$userId}");
        $resDelete->assertStatus(200)
                  ->assertJson(['status' => true]);

        $this->assertDatabaseHas('users', ['id' => $userId, 'status' => 'I']);
    }
}
