<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Setores;
use App\Models\Polo;
use App\Models\Produto;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class PermissoesProdutosEntradaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Configurar o ambiente inicial de polo e setor CAF
        $this->polo = Polo::create(['nome' => 'Polo Teste', 'status' => 'A']);
        $this->caf = Setores::create([
            'nome' => 'CAF',
            'tipo' => 'Ambos',
            'estoque' => true,
            'polo_id' => $this->polo->id,
            'status' => 'A'
        ]);
        
        $this->clinica = Setores::create([
            'nome' => 'Clinica',
            'tipo' => 'Ambos',
            'estoque' => false,
            'polo_id' => $this->polo->id,
            'status' => 'A'
        ]);

        // Usuário superadmin
        $this->superAdmin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'adminti@gmail.com',
        ]);

        // Usuário admin de setor
        $this->adminSetor = User::factory()->create([
            'name' => 'Admin Setor',
            'email' => 'adminsetor@teste.com',
        ]);
        DB::table('usuario_setor')->insert([
            'usuario_id' => $this->adminSetor->id,
            'setor_id' => $this->caf->id,
            'perfil' => 'admin'
        ]);

        // Usuário almoxarife de setor (não admin)
        $this->almoxarife = User::factory()->create([
            'name' => 'Almoxarife',
            'email' => 'almoxarife@teste.com',
        ]);
        DB::table('usuario_setor')->insert([
            'usuario_id' => $this->almoxarife->id,
            'setor_id' => $this->caf->id,
            'perfil' => 'almoxarife'
        ]);

        $this->grupo = \App\Models\GrupoProduto::create(['nome' => 'Medicamentos', 'tipo' => 'Medicamento', 'status' => 'A']);
        $this->unidade = \App\Models\UnidadeMedida::create(['nome' => 'Caixa', 'sigla' => 'CX', 'status' => 'A']);

        // Produto base
        $this->produto = Produto::create([
            'nome' => 'Produto Teste',
            'grupo_produto_id' => $this->grupo->id,
            'unidade_medida_id' => $this->unidade->id,
            'status' => 'A'
        ]);
    }

    public function test_superadmin_can_manage_products()
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        $response = $this->postJson('/api/produtos/add', [
            'produto' => [
                'nome' => 'Novo Produto 1',
                'grupo_produto_id' => $this->grupo->id,
                'unidade_medida_id' => $this->unidade->id,
                'status' => 'A'
            ]
        ]);
        $response->assertStatus(201);

        $responseDelete = $this->deleteJson('/api/produtos/delete/' . $this->produto->id);
        $responseDelete->assertStatus(200);
    }

    public function test_admin_setor_caf_can_manage_products()
    {
        Sanctum::actingAs($this->adminSetor, ['*']);

        $response = $this->postJson('/api/produtos/add', [
            'produto' => [
                'nome' => 'Novo Produto 2',
                'grupo_produto_id' => $this->grupo->id,
                'unidade_medida_id' => $this->unidade->id,
                'status' => 'A'
            ]
        ]);
        $response->assertStatus(201);
    }

    public function test_almoxarife_cannot_manage_products()
    {
        Sanctum::actingAs($this->almoxarife, ['*']);

        $response = $this->postJson('/api/produtos/add', [
            'produto' => [
                'nome' => 'Novo Produto 3',
                'grupo_produto_id' => $this->grupo->id,
                'unidade_medida_id' => $this->unidade->id,
                'status' => 'A'
            ]
        ]);
        $response->assertStatus(403)
                 ->assertJson(['message' => 'Acesso negado. Apenas administradores podem cadastrar ou alterar produtos.']);
    }

    public function test_almoxarife_cannot_register_entrada()
    {
        Sanctum::actingAs($this->almoxarife, ['*']);

        // Almoxarife deve ser bloqueado com 403
        $response = $this->postJson('/api/entrada/add', [
            'setor_id' => $this->caf->id,
            'nota_fiscal' => '123',
            'itens' => []
        ]);
        
        $response->assertStatus(403)
                 ->assertJson(['message' => 'Acesso negado. Apenas administradores podem registrar ou alterar entradas.']);
    }

    public function test_admin_setor_caf_can_attempt_register_entrada()
    {
        Sanctum::actingAs($this->adminSetor, ['*']);

        $response = $this->postJson('/api/entrada/add', [
            'setor_id' => $this->caf->id,
            'nota_fiscal' => '123',
            'itens' => []
        ]);
        
        // Admin não deve receber 403 (vai receber 422 de validação pelos campos vazios)
        $this->assertNotEquals(403, $response->status());
    }

    public function test_admin_setor_can_list_users()
    {
        Sanctum::actingAs($this->adminSetor, ['*']);

        $response = $this->getJson('/api/usuario-setor/listar-por-usuario?usuario_id=' . $this->almoxarife->id);
        
        // Deve conseguir listar os setores do almoxarife pois o adminSetor é admin de um setor
        $response->assertStatus(200);
    }
    
    public function test_almoxarife_cannot_list_users()
    {
        Sanctum::actingAs($this->almoxarife, ['*']);

        $response = $this->getJson('/api/usuario-setor/listar-por-usuario?usuario_id=' . $this->adminSetor->id);
        
        // Um almoxarife não pode listar as vinculações de outro usuário
        $response->assertStatus(403);
    }
}
