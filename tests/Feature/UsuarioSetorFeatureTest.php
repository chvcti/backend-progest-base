<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use App\Models\User;
use App\Models\Setores;
use Database\Seeders\RegimeContratacaoSeeder;
use Database\Seeders\PolosESetoresDemoSeeder;
use Database\Seeders\AdminInicialSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsuarioSetorFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $adminComum;
    protected User $solicitante;
    protected User $almoxarife;
    protected Setores $cafSetor;
    protected Setores $setorSemEstoque;
    protected Setores $setorComEstoque;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Carrega os seeders oficiais da base de teste
        $this->seed([
            RegimeContratacaoSeeder::class,
            PolosESetoresDemoSeeder::class,
            AdminInicialSeeder::class,
        ]);

        // 2. Resgata superAdmin
        $this->superAdmin  = User::where('email', 'adminti@gmail.com')->firstOrFail();

        // 3. Resgata setores-chave configurados pelo PolosESetoresDemoSeeder
        $this->cafSetor = Setores::where('nome', 'CENTRAL DE ABASTECIMENTO FARMACÊUTICO (CAF)')->firstOrFail();
        $this->setorSemEstoque = Setores::where('nome', 'CLÍNICA MÉDICA')->where('estoque', false)->firstOrFail();
        $this->setorComEstoque = Setores::where('nome', 'FARMÁCIA DE DISPENSAÇÃO')->where('estoque', true)->firstOrFail();

        // 4. Cria usuários padrão do teste
        $regimeId = DB::table('regime_contratacao')->value('id') ?? 1;

        $this->adminComum = User::create([
            'name'                  => 'ADMIN TESTE',
            'email'                 => 'admin.teste@progest.teste',
            'password'              => Hash::make('Admin123'),
            'cpf'                   => '33333333331',
            'telefone'              => '77999990031',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        DB::table('usuario_setor')->insert([
            'usuario_id' => $this->adminComum->id,
            'setor_id'   => $this->cafSetor->id,
            'perfil'     => 'admin',
        ]);

        $this->solicitante = User::create([
            'name'                  => 'SOLICITANTE TESTE',
            'email'                 => 'solicitante.teste@progest.teste',
            'password'              => Hash::make('Admin123'),
            'cpf'                   => '33333333332',
            'telefone'              => '77999990032',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        DB::table('usuario_setor')->insert([
            'usuario_id' => $this->solicitante->id,
            'setor_id'   => $this->cafSetor->id,
            'perfil'     => 'solicitante',
        ]);

        $this->almoxarife = User::create([
            'name'                  => 'ALMOXARIFE TESTE',
            'email'                 => 'almoxarife.teste@progest.teste',
            'password'              => Hash::make('Admin123'),
            'cpf'                   => '33333333333',
            'telefone'              => '77999990033',
            'data_nascimento'       => '1990-01-01',
            'status'                => 'A',
            'regime_contratacao_id' => $regimeId,
        ]);
        DB::table('usuario_setor')->insert([
            'usuario_id' => $this->almoxarife->id,
            'setor_id'   => $this->cafSetor->id,
            'perfil'     => 'almoxarife',
        ]);
    }

    /**
     * Helper para gerar um usuário adicional para testes isolados
     */
    private function criarUsuarioTeste(string $email, string $cpf): User
    {
        return User::create([
            'name'                  => 'USUARIO TESTE ' . strtoupper(substr(md5($email), 0, 5)),
            'email'                 => $email,
            'password'              => Hash::make('Admin123'),
            'cpf'                   => $cpf,
            'telefone'              => '77988887777',
            'data_nascimento'       => '1995-05-15',
            'status'                => 'A',
            'regime_contratacao_id' => 1,
        ]);
    }

    // =========================================================================
    // 1. REGRA: BLOQUEIO DE ALMOXARIFE EM SETOR SEM ESTOQUE
    // =========================================================================

    /**
     * Tentar vincular um usuário com perfil 'almoxarife' a um setor sem estoque (estoque = false)
     * DEVE falhar com status HTTP 422.
     */
    public function test_bloqueia_vinculo_almoxarife_em_setor_sem_estoque_no_add()
    {
        Sanctum::actingAs($this->superAdmin);

        $novoUser = $this->criarUsuarioTeste('farmaceutico_clinica@teste.com', '12345678901');

        $payload = [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->setorSemEstoque->id,
            'perfil'     => 'almoxarife',
        ];

        $response = $this->postJson('/api/usuarioSetor/add', $payload);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'Operação negada: Um setor sem estoque próprio não pode ter usuários almoxarifes.');

        $this->assertDatabaseMissing('usuario_setor', [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->setorSemEstoque->id,
            'perfil'     => 'almoxarife',
        ]);
    }

    /**
     * Tentar atualizar o perfil de um usuário existente em setor sem estoque para 'almoxarife'
     * DEVE falhar com status HTTP 422.
     */
    public function test_bloqueia_atualizacao_para_almoxarife_em_setor_sem_estoque_no_update()
    {
        Sanctum::actingAs($this->superAdmin);

        $novoUser = $this->criarUsuarioTeste('assistente_rh@teste.com', '12345678902');

        // Cria vínculo inicial como solicitante (permitido em setor sem estoque)
        DB::table('usuario_setor')->insert([
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->setorSemEstoque->id,
            'perfil'     => 'solicitante',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenta promover para almoxarife no setor sem estoque
        $payload = [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->setorSemEstoque->id,
            'perfil'     => 'almoxarife',
        ];

        $response = $this->postJson('/api/usuarioSetor/update', $payload);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'Operação negada: Um setor sem estoque próprio não pode ter usuários almoxarifes.');

        // Garante que permaneceu como solicitante
        $this->assertDatabaseHas('usuario_setor', [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->setorSemEstoque->id,
            'perfil'     => 'solicitante',
        ]);
    }

    // =========================================================================
    // 2. REGRA: PERMISSÃO DE SOLICITANTE NA CAF (REGRA ANTIGA REMOVIDA)
    // =========================================================================

    /**
     * Vincular um usuário com perfil 'solicitante' à CAF DEVE ser aceito com sucesso (HTTP 200).
     */
    public function test_permite_vinculo_solicitante_na_caf_com_sucesso()
    {
        Sanctum::actingAs($this->superAdmin);

        $novoUser = $this->criarUsuarioTeste('farmaceutico_caf@teste.com', '12345678903');

        $payload = [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->cafSetor->id,
            'perfil'     => 'solicitante',
        ];

        $response = $this->postJson('/api/usuarioSetor/add', $payload);

        $response->assertStatus(200)
                 ->assertJsonPath('status', true)
                 ->assertJsonPath('data.usuario_id', $novoUser->id)
                 ->assertJsonPath('data.setor_id', $this->cafSetor->id)
                 ->assertJsonPath('data.perfil', 'solicitante');

        $this->assertDatabaseHas('usuario_setor', [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->cafSetor->id,
            'perfil'     => 'solicitante',
        ]);
    }

    // =========================================================================
    // 3. REGRA: CONSTRAINT DE UNICIDADE (DUPLICIDADE DE VÍNCULO)
    // =========================================================================

    /**
     * Tentar vincular o mesmo usuário ao mesmo setor que já possui vínculo DEVE falhar com HTTP 422.
     */
    public function test_bloqueia_vinculo_duplicado_mesmo_usuario_e_setor()
    {
        Sanctum::actingAs($this->superAdmin);

        // solicitante já está vinculado à CAF pelo setUp
        $this->assertDatabaseHas('usuario_setor', [
            'usuario_id' => $this->solicitante->id,
            'setor_id'   => $this->cafSetor->id,
        ]);

        $payload = [
            'usuario_id' => $this->solicitante->id,
            'setor_id'   => $this->cafSetor->id,
            'perfil'     => 'admin', // tenta adicionar novamente com outro perfil
        ];

        $response = $this->postJson('/api/usuarioSetor/add', $payload);

        $response->assertStatus(422)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'Este usuário já está vinculado a este setor.');
    }

    // =========================================================================
    // 4. REGRA: GOD MODE DO SUPER ADMIN (adminti@gmail.com)
    // =========================================================================

    /**
     * Super Admin adminti@gmail.com pode gerenciar vínculos em qualquer setor sem
     * necessidade de vínculo prévio na tabela usuario_setor.
     */
    public function test_super_admin_possui_bypass_global_e_altera_qualquer_setor()
    {
        // Garante que o super admin NÃO possui registro explícito na tabela usuario_setor para o setor de teste
        DB::table('usuario_setor')
            ->where('usuario_id', $this->superAdmin->id)
            ->where('setor_id', $this->setorComEstoque->id)
            ->delete();

        $this->assertDatabaseMissing('usuario_setor', [
            'usuario_id' => $this->superAdmin->id,
            'setor_id'   => $this->setorComEstoque->id,
        ]);

        Sanctum::actingAs($this->superAdmin);

        $novoUser = $this->criarUsuarioTeste('novo_colaborador@teste.com', '12345678904');

        // 1. Adicionar vínculo como Super Admin
        $addResponse = $this->postJson('/api/usuarioSetor/add', [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->setorComEstoque->id,
            'perfil'     => 'almoxarife',
        ]);

        $addResponse->assertStatus(200)
                    ->assertJsonPath('status', true);

        // 2. Listar equipe do setor como Super Admin
        $listResponse = $this->postJson('/api/usuarioSetor/listarPorSetor', [
            'setor_id' => $this->setorComEstoque->id,
        ]);

        $listResponse->assertStatus(200)
                     ->assertJsonPath('status', true);

        // 3. Atualizar perfil como Super Admin
        $updateResponse = $this->postJson('/api/usuarioSetor/update', [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->setorComEstoque->id,
            'perfil'     => 'admin',
        ]);

        $updateResponse->assertStatus(200)
                       ->assertJsonPath('status', true)
                       ->assertJsonPath('data.perfil', 'admin');

        // 4. Remover vínculo como Super Admin
        $deleteResponse = $this->postJson('/api/usuarioSetor/delete', [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->setorComEstoque->id,
        ]);

        $deleteResponse->assertStatus(200)
                       ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('usuario_setor', [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->setorComEstoque->id,
        ]);
    }

    /**
     * Usuário sem permissão de admin no setor é barrado com HTTP 403.
     */
    public function test_usuario_comum_sem_perfil_admin_recebe_forbidden_no_add()
    {
        // jeanSolicitante possui perfil 'solicitante'
        Sanctum::actingAs($this->solicitante);

        $novoUser = $this->criarUsuarioTeste('tentativa_bloqueada@teste.com', '12345678905');

        $payload = [
            'usuario_id' => $novoUser->id,
            'setor_id'   => $this->setorComEstoque->id,
            'perfil'     => 'solicitante',
        ];

        $response = $this->postJson('/api/usuarioSetor/add', $payload);

        $response->assertStatus(403)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'Ação permitida apenas para administradores deste setor.');
    }

    // =========================================================================
    // 5. REGRA: PROTEÇÃO DO SUPER ADMIN CONTRA EDIÇÃO / INATIVAÇÃO
    // =========================================================================

    /**
     * Um administrador comum (ex: pabloadmin@gmail.com) NÃO PODE inativar ou remover
     * o usuário adminti@gmail.com via endpoint de exclusão/desativação (HTTP 403).
     */
    public function test_admin_comum_nao_pode_inativar_ou_deletar_super_admin()
    {
        Sanctum::actingAs($this->adminComum);

        $response = $this->postJson("/api/user/delete/{$this->superAdmin->id}");

        $response->assertStatus(403)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'O usuário super admin não pode ser desativado.');

        // Confirma que o Super Admin continua com status 'A' ativo
        $this->assertDatabaseHas('users', [
            'id'     => $this->superAdmin->id,
            'email'  => 'adminti@gmail.com',
            'status' => 'A',
        ]);
    }

    /**
     * Um administrador comum (ex: pabloadmin@gmail.com) NÃO PODE alterar os dados
     * cadastrais do usuário adminti@gmail.com via update (HTTP 403).
     */
    public function test_admin_comum_nao_pode_alterar_dados_do_super_admin()
    {
        Sanctum::actingAs($this->adminComum);

        $cpfValido = '88852395105';
        $this->superAdmin->update(['cpf' => $cpfValido]);

        $payload = [
            'user' => [
                'id'                    => $this->superAdmin->id,
                'name'                  => 'TENTATIVA DE HACK',
                'email'                 => 'adminti_modificado@gmail.com',
                'cpf'                   => $cpfValido,
                'data_nascimento'       => '1990-01-01',
                'telefone'              => '77988887777',
                'regime_contratacao_id' => 1,
                'status'                => 'I', // tenta inativar
            ]
        ];

        $response = $this->postJson('/api/user/update', $payload);

        $response->assertStatus(403)
                 ->assertJsonPath('status', false)
                 ->assertJsonPath('message', 'O super admin não pode ser alterado por outros usuários.');

        // Confirma que os dados permaneceram intactos no banco
        $this->assertDatabaseHas('users', [
            'id'     => $this->superAdmin->id,
            'email'  => 'adminti@gmail.com',
            'name'   => 'ADMIN TI',
            'status' => 'A',
        ]);
    }
}
