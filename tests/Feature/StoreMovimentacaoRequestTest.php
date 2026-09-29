<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Http\Requests\StoreMovimentacaoRequest;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class StoreMovimentacaoRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_deve_converter_campos_camel_case_para_snake_case_e_aplicar_auth_id()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $request = new StoreMovimentacaoRequest();
        $request->merge([
            'itens' => [
                [
                    'produtoId' => 99,
                    'quantidade' => 15
                ]
            ]
        ]);

        // Evocar o método protected prepareForValidation
        $method = new \ReflectionMethod(StoreMovimentacaoRequest::class, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        $data = $request->all();

        $this->assertEquals($user->id, $data['usuario_id']);
        $this->assertEquals(99, $data['itens'][0]['produto_id']);
        $this->assertEquals(15, $data['itens'][0]['quantidade_solicitada']);
        
        $this->assertArrayHasKey('produtoId', $data['itens'][0]);
    }

    public function test_deve_falhar_validacao_se_origem_e_destino_forem_iguais()
    {
        $request = new StoreMovimentacaoRequest();
        
        $rules = $request->rules();
        $validator = Validator::make([
            'usuario_id' => 1,
            'tipo' => 'T',
            'status_solicitacao' => 'P',
            'setor_origem_id' => 5,
            'setor_destino_id' => 5, // Igual a origem
            'itens' => [
                ['produto_id' => 1, 'quantidade_solicitada' => 10]
            ]
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('setor_destino_id'));
    }
}
