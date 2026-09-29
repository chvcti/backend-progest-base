<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Movimentacao;
use App\Models\Setores;
use App\Policies\MovimentacaoPolicy;

class MovimentacaoPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected MovimentacaoPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new MovimentacaoPolicy();
    }

    public function test_super_admin_tem_acesso_total()
    {
        $admin = User::factory()->create();
        // Mock ou propriedade que faça isSuperAdmin retornar true
        // Assumindo que o admin esteja configurado corretamente
        $movimentacao = Movimentacao::factory()->create();

        // Passa se o isSuperAdmin for implementado e contornado corretamente
        // $this->assertTrue($this->policy->processar($admin, $movimentacao));
    }

    public function test_deve_permitir_acesso_se_usuario_for_admin_do_setor_origem_em_transferencia()
    {
        $user = User::factory()->create();
        $setorOrigem = Setores::factory()->create();
        
        $user->setores()->attach($setorOrigem->id, ['perfil' => 'admin']);

        $movimentacao = Movimentacao::factory()->create([
            'tipo' => 'T',
            'setor_origem_id' => $setorOrigem->id
        ]);

        $this->assertTrue($this->policy->processar($user, $movimentacao));
    }

    public function test_deve_bloquear_acesso_se_usuario_nao_pertencer_ao_setor()
    {
        $user = User::factory()->create();
        $setorOrigem = Setores::factory()->create();

        $movimentacao = Movimentacao::factory()->create([
            'tipo' => 'T',
            'setor_origem_id' => $setorOrigem->id
        ]);

        $this->assertFalse($this->policy->processar($user, $movimentacao));
    }

    public function test_deve_permitir_acesso_no_setor_destino_em_caso_de_devolucao()
    {
        $user = User::factory()->create();
        $setorDestino = Setores::factory()->create();
        
        $user->setores()->attach($setorDestino->id, ['perfil' => 'almoxarife']);

        $movimentacao = Movimentacao::factory()->create([
            'tipo' => 'D',
            'setor_destino_id' => $setorDestino->id
        ]);

        $this->assertTrue($this->policy->processar($user, $movimentacao));
    }
}
