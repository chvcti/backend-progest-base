<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\Fornecedor;

class FornecedorControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = \App\Models\User::where('email', 'adminti@gmail.com')->first() 
            ?? \App\Models\User::factory()->create(['email' => 'adminti@gmail.com']);
        \Laravel\Sanctum\Sanctum::actingAs($admin);
    }

    private function generateValidCnpj($i = 0)
    {
        $cnpjs = ['80177977000118', '96329064000100', '01648071000168', '95186076000160'];
        return $cnpjs[$i % count($cnpjs)];
    }

    private function generateValidCpf($i = 0)
    {
        $cpfs = ['88852395105', '06893242867', '61043370900', '64843097950'];
        return $cpfs[$i % count($cpfs)];
    }

    public function test_create_fornecedor_juridico_successfully()
    {
        $payload = [
            'fornecedor' => [
                'razao_social_nome' => 'Empresa Teste LTDA',
                'tipo_pessoa' => 'J',
                'cnpj' => $this->generateValidCnpj(0),
                'status' => 'A'
            ]
        ];

        $response = $this->postJson('/api/fornecedores/add', $payload);

        $response->assertStatus(201)
                 ->assertJson(['status' => true]);

        $this->assertDatabaseHas('fornecedores', [
            'razao_social_nome' => 'EMPRESA TESTE LTDA',
            'cnpj' => $this->generateValidCnpj(0)
        ]);
    }

    public function test_create_fornecedor_fisico_successfully()
    {
        $payload = [
            'fornecedor' => [
                'razao_social_nome' => 'João Teste',
                'tipo_pessoa' => 'F',
                'cpf' => $this->generateValidCpf(0),
                'status' => 'A'
            ]
        ];

        $response = $this->postJson('/api/fornecedores/add', $payload);

        $response->assertStatus(201)
                 ->assertJson(['status' => true]);

        $this->assertDatabaseHas('fornecedores', [
            'razao_social_nome' => 'JOÃO TESTE',
            'cpf' => $this->generateValidCpf(0)
        ]);
    }

    public function test_update_fornecedor_successfully()
    {
        $fornecedor = Fornecedor::create([
            'razao_social_nome' => 'EMPRESA ANTIGA',
            'tipo_pessoa' => 'J',
            'cnpj' => $this->generateValidCnpj(1),
            'status' => 'A'
        ]);

        $payload = [
            'fornecedor' => [
                'id' => $fornecedor->id,
                'razao_social_nome' => 'Empresa Atualizada',
                'tipo_pessoa' => 'J',
                'cnpj' => $this->generateValidCnpj(2),
                'status' => 'A'
            ]
        ];

        $response = $this->postJson('/api/fornecedores/update', $payload);

        $response->assertStatus(200)
                 ->assertJson(['status' => true]);

        $this->assertDatabaseHas('fornecedores', [
            'id' => $fornecedor->id,
            'razao_social_nome' => 'EMPRESA ATUALIZADA'
        ]);
    }

    public function test_list_fornecedores()
    {
        Fornecedor::create([
            'razao_social_nome' => 'Empresa A',
            'tipo_pessoa' => 'J',
            'cnpj' => $this->generateValidCnpj(1),
            'status' => 'A'
        ]);

        $response = $this->postJson('/api/fornecedores/list');

        $response->assertStatus(200)
                 ->assertJsonStructure(['status', 'data']);
    }

    public function test_toggle_status_fornecedor()
    {
        $fornecedor = Fornecedor::create([
            'razao_social_nome' => 'Empresa Status',
            'tipo_pessoa' => 'J',
            'cnpj' => $this->generateValidCnpj(3),
            'status' => 'A'
        ]);

        $response = $this->postJson('/api/fornecedores/toggleStatus', ['id' => $fornecedor->id]);

        $response->assertStatus(200)
                 ->assertJson(['status' => true]);

        $this->assertDatabaseHas('fornecedores', [
            'id' => $fornecedor->id,
            'status' => 'I'
        ]);
    }
}
