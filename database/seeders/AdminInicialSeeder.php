<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * ATENÇÃO - REGRA DE NEGÓCIO DO PROJETO:
 * Usuário Super Admin padrão do sistema.
 * NÃO ALTERAR NEM EXCLUIR em seeders/migrations.
 */
class AdminInicialSeeder extends Seeder
{
    /**
     * Cria o usuário adminti (super admin do sistema)
     */
    public function run()
    {
        $now = Carbon::now();

        $adminEmail = env('ADMIN_DEFAULT_EMAIL', 'adminti@gmail.com');
        $adminPassword = env('ADMIN_DEFAULT_PASSWORD', 'adminti');
        $hashedPassword = Hash::make($adminPassword);

        // 1. Criar ou Atualizar Usuário Admin TI
        $userExistente = DB::table('users')->where('email', $adminEmail)->orWhere('cpf', '00000000000')->first();
        if ($userExistente) {
            DB::table('users')->where('id', $userExistente->id)->update([
                'name'            => 'ADMIN TI',
                'email'           => $adminEmail,
                'password'        => $hashedPassword,
                'cpf'             => '00000000000',
                'telefone'        => '00000000000',
                'data_nascimento' => '1990-01-01',
                'status'          => 'A',
                'regime_contratacao_id' => 1,
                'updated_at'      => $now,
            ]);
        } else {
            DB::table('users')->insert([
                'name'            => 'ADMIN TI',
                'email'           => $adminEmail,
                'password'        => $hashedPassword,
                'cpf'             => '00000000000',
                'telefone'        => '00000000000',
                'data_nascimento' => '1990-01-01',
                'status'          => 'A',
                'regime_contratacao_id' => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        $mensagem = "Super Admin provisionado com sucesso: e-mail [{$adminEmail}]";
        if ($this->command) {
            $this->command->info($mensagem);
        }
        Log::info($mensagem);

        $admin = DB::table('users')->where('email', $adminEmail)->first();
        if (!$admin) return;

        // 2. Tentar encontrar o Polo HGVC e Setor TI para vincular o adminti
        $polo = DB::table('polos')->where('sigla', 'HGVC')->first();
        if ($polo) {
            DB::table('usuario_polo')->updateOrInsert(
                ['usuario_id' => $admin->id, 'polo_id' => $polo->id],
                ['created_at' => $now, 'updated_at' => $now]
            );

            $setor = DB::table('setores')->where('polo_id', $polo->id)->where('nome', 'TI')->first();
            if ($setor) {
                DB::table('usuario_setor')->updateOrInsert(
                    ['usuario_id' => $admin->id, 'setor_id' => $setor->id],
                    [
                        'perfil'     => 'admin', // O adminti tem acesso global de qualquer forma pelos guards
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }
}
