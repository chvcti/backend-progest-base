<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use App\Models\Polo;
use App\Models\Setores;
use App\Models\User;
use App\Models\Fornecedor;
use App\Models\Produto;
use App\Models\Estoque;
use App\Models\EstoqueLote;
use App\Models\Entrada;
use App\Models\ItensEntrada;
use App\Models\Movimentacao;
use App\Models\ItemMovimentacao;
use App\Models\Devolucao;

class DemonstracaoSistemaSeeder extends Seeder
{
    /**
     * Executa a população da demonstração hospitalar hiper-realista.
     * 
     * Comando para rodar:
     * php artisan db:seed --class=DemonstracaoSistemaSeeder
     */
    public function run()
    {
        $this->command->info('🏥 [DemonstracaoSistemaSeeder] Iniciando simulação hospitalar hiper-realista do ProGest...');

        // 0. Garante que a base oficial limpa existe antes de popular cenários de demonstração
        if (Polo::count() === 0 || Produto::count() === 0) {
            $this->command->info('📋 Base limpa não detectada. Executando DatabaseSeeder primeiro...');
            $this->call(DatabaseSeeder::class);
        }

        DB::transaction(function () {
            $this->executarSeedDemonstracao();
        });

        $this->command->info('✅ [DemonstracaoSistemaSeeder] Demonstração hospitalar populada com 100% de sucesso!');
    }

    private function executarSeedDemonstracao()
    {
        $now = Carbon::now();

        // Desativa observers durante a carga para evitar efeitos colaterais
        Produto::unsetEventDispatcher();

        // =====================================================================
        // 1. POLOS OFICIAIS
        // =====================================================================
        $hgvc = Polo::where('sigla', 'HGVC')->orWhere('nome', 'like', '%Geral%')->first();
        $hap  = Polo::where('sigla', 'HAP')->orWhere('nome', 'like', '%Afrânio%')->first();

        if (!$hgvc || !$hap) {
            throw new \RuntimeException('Polos HGVC e HAP são obrigatórios para a demonstração.');
        }

        // =====================================================================
        // 2. TOPOLOGIA DOS SETORES DE DEMONSTRAÇÃO (SUFIXO "[Exemplo]")
        // =====================================================================
        $this->command->info('🏛️ [1/6] Configurando setores e cadeia de distribuição de demonstração...');

        // HGVC (Polo de Grande Porte)
        $cafHgvc = Setores::updateOrCreate(
            ['polo_id' => $hgvc->id, 'nome' => 'CAF - Central de Abastecimento Farmacêutico (Exemplo)'],
            ['estoque' => true, 'tipo' => 'Medicamento', 'status' => 'A']
        );
        $farmSateliteHgvc = Setores::updateOrCreate(
            ['polo_id' => $hgvc->id, 'nome' => 'Farmácia Satélite Centro Cirúrgico (Exemplo)'],
            ['estoque' => true, 'tipo' => 'Medicamento', 'status' => 'A']
        );
        $utiHgvc = Setores::updateOrCreate(
            ['polo_id' => $hgvc->id, 'nome' => 'UTI Adulto (Exemplo)'],
            ['estoque' => false, 'tipo' => 'Medicamento', 'status' => 'A']
        );

        // HAP (Polo Menor / Especializado)
        $almoxHap = Setores::updateOrCreate(
            ['polo_id' => $hap->id, 'nome' => 'Almoxarifado Central HAP (Exemplo)'],
            ['estoque' => true, 'tipo' => 'Ambos', 'status' => 'A']
        );
        $farmSateliteHap = Setores::updateOrCreate(
            ['polo_id' => $hap->id, 'nome' => 'Farmácia Satélite HAP (Exemplo)'],
            ['estoque' => true, 'tipo' => 'Medicamento', 'status' => 'A']
        );
        $clinicaMedicaHap = Setores::updateOrCreate(
            ['polo_id' => $hap->id, 'nome' => 'Clínica Médica HAP (Exemplo)'],
            ['estoque' => false, 'tipo' => 'Ambos', 'status' => 'A']
        );

        $demoSetorIds = [
            $cafHgvc->id,
            $farmSateliteHgvc->id,
            $utiHgvc->id,
            $almoxHap->id,
            $farmSateliteHap->id,
            $clinicaMedicaHap->id
        ];

        // Limpeza Segura e Idempotente dos dados prévios nos setores de exemplo
        $movsExemploIds = Movimentacao::whereIn('setor_origem_id', $demoSetorIds)
            ->orWhereIn('setor_destino_id', $demoSetorIds)
            ->pluck('id');

        if ($movsExemploIds->isNotEmpty()) {
            Devolucao::whereIn('movimentacao_id', $movsExemploIds)->delete();
            ItemMovimentacao::whereIn('movimentacao_id', $movsExemploIds)->delete();
            Movimentacao::whereIn('id', $movsExemploIds)->delete();
        }

        $entradasExemploIds = Entrada::whereIn('setor_id', $demoSetorIds)->pluck('id');
        if ($entradasExemploIds->isNotEmpty()) {
            ItensEntrada::whereIn('entrada_id', $entradasExemploIds)->delete();
            Entrada::whereIn('id', $entradasExemploIds)->delete();
        }

        EstoqueLote::whereIn('setor_id', $demoSetorIds)->delete();
        Estoque::whereIn('setor_id', $demoSetorIds)->delete();

        // Cadeia de Suprimentos Oficial (setor_distribuidor)
        // Regra: Solicitante -> Distribuidor Autorizado
        $distribuicoes = [
            // HGVC
            [$farmSateliteHgvc->id, $cafHgvc->id],         // Satélite solicita para CAF
            [$utiHgvc->id,          $cafHgvc->id],         // UTI solicita para CAF
            [$utiHgvc->id,          $farmSateliteHgvc->id],// UTI solicita para Satélite
            [$cafHgvc->id,          $farmSateliteHgvc->id],// Remanejamento mútuo

            // HAP
            [$farmSateliteHap->id,  $almoxHap->id],        // Satélite solicita para Almoxarifado
            [$clinicaMedicaHap->id, $almoxHap->id],        // Clínica solicita para Almoxarifado
            [$clinicaMedicaHap->id, $farmSateliteHap->id], // Clínica solicita para Satélite
            [$almoxHap->id,         $farmSateliteHap->id], // Remanejamento mútuo

            // Inter-Polo (Rede Hospitalar Integrada)
            [$almoxHap->id,         $cafHgvc->id],         // HAP ressupre na CAF HGVC
            [$farmSateliteHap->id,  $cafHgvc->id],
        ];

        foreach ($distribuicoes as [$solicId, $distId]) {
            DB::table('setor_distribuidor')->updateOrInsert(
                ['setor_solicitante_id' => $solicId, 'setor_distribuidor_id' => $distId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        // =====================================================================
        // 3. USUÁRIOS E ISOLAMENTO SETORIAL (PERFIS HOMOLOGADOS)
        // =====================================================================
        $this->command->info('👥 [2/6] Configurando usuários oficiais de teste e permissões...');

        $senhaHash = Hash::make(env('USER_DEFAULT_PASSWORD', 'Admin123'));
        $regimeId = DB::table('regime_contratacao')->value('id') ?? 1;

        $criarUsuario = function($name, $email, $cpf, $telefone) use ($senhaHash, $regimeId, $now) {
            return User::updateOrCreate(
                ['email' => $email],
                [
                    'name'                  => $name,
                    'cpf'                   => $cpf,
                    'telefone'              => $telefone,
                    'data_nascimento'       => '1988-06-15',
                    'password'              => $senhaHash,
                    'status'                => 'A',
                    'regime_contratacao_id' => $regimeId,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]
            );
        };

        $userAdminGeral     = $criarUsuario('ADMINISTRADOR GERAL (DEMO)', 'admin.geral@progest.teste', '11111111111', '77999990001');
        $userAlmoxCaf       = $criarUsuario('ALMOXARIFE CAF HGVC', 'almoxarife.caf@progest.teste', '22222222222', '77999990002');
        $userSolicUti       = $criarUsuario('SOLICITANTE UTI ADULTO', 'solicitante.uti@progest.teste', '33333333333', '77999990003');
        $userAlmoxHap       = $criarUsuario('ALMOXARIFE CENTRAL HAP', 'almoxarife.hap@progest.teste', '44444444444', '77999990004');
        $userSolicHap       = $criarUsuario('SOLICITANTE CLÍNICA HAP', 'solicitante.hap@progest.teste', '55555555555', '77999990005');

        $demoUserIds = [
            $userAdminGeral->id,
            $userAlmoxCaf->id,
            $userSolicUti->id,
            $userAlmoxHap->id,
            $userSolicHap->id
        ];

        // Limpeza prévia estrita de permissões dos usuários demo
        DB::table('usuario_polo')->whereIn('usuario_id', $demoUserIds)->delete();
        DB::table('usuario_setor')->whereIn('usuario_id', $demoUserIds)->delete();

        // Vínculos de Polos: Apenas Administrador Geral Demo possui vínculo na tabela usuario_polo
        DB::table('usuario_polo')->insert([
            ['usuario_id' => $userAdminGeral->id, 'polo_id' => $hgvc->id, 'created_at' => $now, 'updated_at' => $now],
            ['usuario_id' => $userAdminGeral->id, 'polo_id' => $hap->id,  'created_at' => $now, 'updated_at' => $now],
        ]);

        // Vínculos Setoriais Estritos conforme Regra de Negócio:
        // - Almoxarifes: apenas em setores com estoque (CAF, Farmácias Satélites, Almoxarifado Central)
        // - Solicitantes: apenas em setores assistenciais/consumidores (UTI, Clínica Médica)
        // - Administrador: perfil admin nos setores-chave
        $vinculosSetores = [
            // Admin Geral Demo
            [$userAdminGeral->id, $cafHgvc->id, 'admin'],
            [$userAdminGeral->id, $farmSateliteHgvc->id, 'admin'],
            [$userAdminGeral->id, $almoxHap->id, 'admin'],
            [$userAdminGeral->id, $farmSateliteHap->id, 'admin'],

            // Almoxarife CAF HGVC (apenas setores com estoque do HGVC)
            [$userAlmoxCaf->id, $cafHgvc->id, 'almoxarife'],
            [$userAlmoxCaf->id, $farmSateliteHgvc->id, 'almoxarife'],

            // Solicitante UTI HGVC (apenas setor assistencial UTI)
            [$userSolicUti->id, $utiHgvc->id, 'solicitante'],

            // Almoxarife HAP (apenas setores com estoque do HAP)
            [$userAlmoxHap->id, $almoxHap->id, 'almoxarife'],
            [$userAlmoxHap->id, $farmSateliteHap->id, 'almoxarife'],

            // Solicitante Clínica HAP (apenas setor assistencial Clínica Médica)
            [$userSolicHap->id, $clinicaMedicaHap->id, 'solicitante'],
        ];

        foreach ($vinculosSetores as [$uId, $sId, $perf]) {
            DB::table('usuario_setor')->insert([
                'usuario_id' => $uId,
                'setor_id'   => $sId,
                'perfil'     => $perf,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // =====================================================================
        // 4. CATÁLOGO REAL DE PRODUTOS E FORNECEDORES
        // =====================================================================
        $this->command->info('💊 [3/6] Mapeando catálogo com SIMPAS, Código de Barras e Portaria 344/98...');

        $selecionarOuCriar = function($termoBusca, $nomePadrao, $portaria, $simpas, $barras, $grupoNome, $unidadeNome) use ($now) {
            $prod = Produto::where('nome', 'like', "%{$termoBusca}%")->first();
            if ($prod) {
                $prod->update([
                    'lista_portaria' => $portaria,
                    'codigo_simpas'  => $prod->codigo_simpas ?: $simpas,
                    'codigo_barras'  => $prod->codigo_barras ?: $barras,
                ]);
                return $prod;
            }

            $grupo = DB::table('grupo_produto')->where('nome', $grupoNome)->first();
            $unidade = DB::table('unidade_medida')->where('nome', $unidadeNome)->first();

            return Produto::create([
                'nome'              => $nomePadrao,
                'marca'             => 'Genérico / Padrão',
                'codigo_simpas'     => $simpas,
                'codigo_barras'     => $barras,
                'grupo_produto_id'  => $grupo ? $grupo->id : 1,
                'unidade_medida_id' => $unidade ? $unidade->id : 1,
                'lista_portaria'    => $portaria,
                'status'            => 'A',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        };

        $produtosDemo = [
            // Portaria 344 - A1 (Entorpecentes)
            'ALFENTANILA' => $selecionarOuCriar('ALFENTANILA', 'ALFENTANILA, cloridrato de solucao injetavel 0,544 mg/mL amp. 5mL', 'A1', '65.02.19.00008041-1', '7891058001015', 'MEDICAMENTOS CONTROLADOS', 'Ampola'),
            'MORFINA'     => $selecionarOuCriar('MORFINA', 'MORFINA, sulfato de 10 mg/mL solucao injetavel ampola 1mL', 'A1', '65.02.19.00004123-5', '7891058001022', 'MEDICAMENTOS CONTROLADOS', 'Ampola'),
            
            // Portaria 344 - B1 (Psicotrópicos)
            'DIAZEPAM'    => $selecionarOuCriar('DIAZEPAM, comprimido 10', 'DIAZEPAM, comprimido 10 mg', 'B1', '65.02.19.00002697-2', '7896004701234', 'MEDICAMENTOS CONTROLADOS', 'Comprimido'),
            'CLONAZEPAM'  => $selecionarOuCriar('CLONAZEPAM, comprimido 2', 'CLONAZEPAM, comprimido 2 mg', 'B1', '65.02.19.00105474-0', '7896004705678', 'MEDICAMENTOS CONTROLADOS', 'Comprimido'),
            
            // Portaria 344 - C1 (Outras Substâncias Sujeitas a Controle Especial)
            'HALOPERIDOL' => $selecionarOuCriar('HALOPERIDOL, solucao injetavel 5mg', 'HALOPERIDOL, solucao injetavel 5mg/ml ampola 1ml', 'C1', '65.02.19.00003145-8', '7896004709876', 'MEDICAMENTOS CONTROLADOS', 'Ampola'),
            'AMITRIPTILINA'=> $selecionarOuCriar('AMITRIPTILINA', 'AMITRIPTILINA, cloridrato 25 mg comprimido', 'C1', '65.02.19.00007890-4', '7896004703456', 'MEDICAMENTOS CONTROLADOS', 'Comprimido'),

            // Medicamentos Gerais / Antibióticos
            'DIPIRONA_AMP'=> $selecionarOuCriar('DIPIRONA, sodica 500 mg/ml ampola', 'DIPIRONA, sodica 500 mg/ml ampola 2mL', null, '65.02.19.00001234-9', '7891058002012', 'MEDICAMENTOS', 'Ampola'),
            'DIPIRONA_CP' => $selecionarOuCriar('DIPIRONA, sodica 500 mg.', 'DIPIRONA, sodica 500 mg comprimido', null, '65.02.19.00001235-7', '7891058002029', 'MEDICAMENTOS', 'Comprimido'),
            'PARACETAMOL' => $selecionarOuCriar('PARACETAMOL, paracetamol 500mg', 'PARACETAMOL, 500mg comprimido', null, '65.02.19.00005678-1', '7891058002036', 'MEDICAMENTOS', 'Comprimido'),
            'CEFTRIAXONA' => $selecionarOuCriar('CEFTRIAXONA, sodica 1g', 'CEFTRIAXONA, sodica 1g po para solucao injetavel', null, '65.02.19.00009988-2', '7891058002043', 'ANTIBIÓTICOS', 'Frasco'),
            'OMEPRAZOL'   => $selecionarOuCriar('OMEPRAZOL, omeprazol 40mg Injet', 'OMEPRAZOL, 40mg po liofilizado injetavel', null, '65.02.19.00004567-3', '7891058002050', 'MEDICAMENTOS', 'Frasco'),
            'SORO_FISIO'  => $selecionarOuCriar('CLORETO DE SODIO', 'CLORETO DE SODIO 0,9% solucao injetavel bolsa 500mL', null, '65.02.19.00003322-1', '7891058002067', 'SOLUÇÕES E SOROS', 'Frasco'),

            // Materiais Hospitalares
            'SERINGA_10'  => $selecionarOuCriar('SERINGA', 'SERINGA DESCARTÁVEL 10ML COM AGULHA', null, '65.15.05.00001122-3', '7898001122334', 'GERAL', 'Unidade'),
            'AGULHA_25'   => $selecionarOuCriar('AGULHA', 'AGULHA HIPODÉRMICA DESCARTÁVEL 25 X 0,7MM', null, '65.15.05.00004455-6', '7898001144556', 'GERAL', 'Unidade'),
            'LUVA_PROC'   => $selecionarOuCriar('LUVA', 'LUVA DE PROCEDIMENTO NÃO CIRÚRGICO TAMANHO M', null, '65.15.05.00007788-9', '7898001177889', 'GERAL', 'Caixa'),
        ];

        // Fornecedores Homologados
        $fornEurofarma = Fornecedor::where('razao_social_nome', 'like', '%Eurofarma%')->first()
            ?? Fornecedor::firstOrCreate(['cnpj' => '61190096000192'], ['tipo_pessoa' => 'J', 'razao_social_nome' => 'Eurofarma Laboratórios S.A.', 'status' => 'A']);

        $fornCristalia = Fornecedor::where('razao_social_nome', 'like', '%Cristália%')->first()
            ?? Fornecedor::firstOrCreate(['cnpj' => '44734671000151'], ['tipo_pessoa' => 'J', 'razao_social_nome' => 'Cristália Produtos Químicos Farmacêuticos Ltda', 'status' => 'A']);

        $fornFresenius = Fornecedor::where('razao_social_nome', 'like', '%Fresenius%')->first()
            ?? Fornecedor::firstOrCreate(['cnpj' => '49324221000104'], ['tipo_pessoa' => 'J', 'razao_social_nome' => 'Fresenius Kabi Brasil Ltda', 'status' => 'A']);

        $fornCremer = Fornecedor::where('razao_social_nome', 'like', '%Cremer%')->first()
            ?? Fornecedor::firstOrCreate(['cnpj' => '82641325000118'], ['tipo_pessoa' => 'J', 'razao_social_nome' => 'Cremer S.A. Produtos Hospitalares', 'status' => 'A']);

        // =====================================================================
        // 5. ENTRADAS VIA NOTA FISCAL (EXCLUSIVAMENTE NOS ALMOXARIFADOS CENTRAIS)
        // =====================================================================
        $this->command->info('📦 [4/6] Gerando Notas Fiscais históricas e lotes na CAF e Almoxarifado Central...');

        // 5.1 Entradas na CAF HGVC (Almoxarifado Central do Polo HGVC)
        $nfsCafConfig = [
            [
                'nf' => 'NF-CAF-2026-081', 'dias_atras' => 80, 'forn' => $fornEurofarma,
                'itens' => [
                    ['DIPIRONA_AMP', 'LOTE-DIP-24A', 700, 1000, 1.85],
                    ['CEFTRIAXONA',  'LOTE-CEF-24',  730, 400,  18.50],
                    ['PARACETAMOL',  'LOTE-PCT-18',  540, 700,  0.30],
                    ['DIPIRONA_CP',  'LOTE-DIP-CP1', 600, 1200, 0.25],
                ]
            ],
            [
                'nf' => 'NF-CAF-2026-094', 'dias_atras' => 60, 'forn' => $fornCristalia,
                'itens' => [
                    ['ALFENTANILA',  'LOTE-ALF-24A', 450, 200, 14.50],
                    ['MORFINA',      'LOTE-MORF-12', 360, 300, 8.90],
                    ['DIAZEPAM',     'LOTE-DZP-18',  540, 500, 0.45],
                    ['HALOPERIDOL',  'LOTE-HAL-24',  720, 250, 3.20],
                    ['HALOPERIDOL',  'LOTE-HAL-VENC', -10, 15, 3.20], // Lote vencido (quarentena)
                ]
            ],
            [
                'nf' => 'NF-CAF-2026-112', 'dias_atras' => 45, 'forn' => $fornFresenius,
                'itens' => [
                    ['SORO_FISIO',   'LOTE-SOR-18',  540, 500, 6.40],
                    ['OMEPRAZOL',    'LOTE-OMP-12',  360, 200, 9.20],
                    ['CLONAZEPAM',   'LOTE-CNP-14',  420, 400, 0.65],
                    ['AMITRIPTILINA','LOTE-AMI-18',  540, 300, 0.35],
                ]
            ],
            [
                'nf' => 'NF-CAF-2026-135', 'dias_atras' => 25, 'forn' => $fornCremer,
                'itens' => [
                    ['SERINGA_10',   'LOTE-SER-36', 1080, 2500, 0.75],
                    ['AGULHA_25',    'LOTE-AGU-36', 1080, 3000, 0.30],
                    ['LUVA_PROC',    'LOTE-LUV-24',  720,  200, 35.00],
                ]
            ],
            [
                'nf' => 'NF-CAF-2026-150', 'dias_atras' => 5, 'forn' => $fornEurofarma,
                'itens' => [
                    ['DIPIRONA_AMP', 'LOTE-DIP-CRIT', 18, 120, 1.85], // Estado crítico (vence em 18 dias)
                    ['ALFENTANILA',  'LOTE-ALF-CRIT', 20,  40, 14.50], // Estado crítico (vence em 20 dias)
                ]
            ],
        ];

        foreach ($nfsCafConfig as $dNF) {
            $dataEmissao = Carbon::now()->subDays($dNF['dias_atras']);
            $entrada = Entrada::create([
                'nota_fiscal'   => $dNF['nf'],
                'setor_id'      => $cafHgvc->id,
                'fornecedor_id' => $dNF['forn']->id,
                'created_at'    => $dataEmissao,
                'updated_at'    => $dataEmissao,
            ]);

            foreach ($dNF['itens'] as [$pKey, $loteNome, $diasVenc, $qtd, $valorUn]) {
                $produto = $produtosDemo[$pKey];
                $dataVenc = Carbon::now()->addDays($diasVenc)->toDateString();
                $dataFab  = Carbon::now()->addDays($diasVenc)->subYears(2)->toDateString();

                ItensEntrada::create([
                    'entrada_id'      => $entrada->id,
                    'produto_id'      => $produto->id,
                    'quantidade'      => $qtd,
                    'lote'            => $loteNome,
                    'data_fabricacao' => $dataFab,
                    'data_vencimento' => $dataVenc,
                    'valor_unitario'  => $valorUn,
                    'created_at'      => $dataEmissao,
                    'updated_at'      => $dataEmissao,
                ]);

                EstoqueLote::create([
                    'setor_id'              => $cafHgvc->id,
                    'produto_id'            => $produto->id,
                    'lote'                  => $loteNome,
                    'quantidade_disponivel' => $qtd,
                    'valor_unitario'        => $valorUn,
                    'data_fabricacao'       => $dataFab,
                    'data_vencimento'       => $dataVenc,
                    'created_at'            => $dataEmissao,
                    'updated_at'            => $dataEmissao,
                ]);
            }
        }

        // 5.2 Entradas no Almoxarifado Central HAP
        $nfsHapConfig = [
            [
                'nf' => 'NF-HAP-2026-031', 'dias_atras' => 70, 'forn' => $fornEurofarma,
                'itens' => [
                    ['DIPIRONA_AMP', 'LOTE-HAP-DIP', 500, 600, 1.85],
                    ['PARACETAMOL',  'LOTE-HAP-PCT', 480, 400, 0.30],
                ]
            ],
            [
                'nf' => 'NF-HAP-2026-054', 'dias_atras' => 35, 'forn' => $fornCremer,
                'itens' => [
                    ['SERINGA_10',   'LOTE-HAP-SER', 900, 1200, 0.75],
                    ['AGULHA_25',    'LOTE-HAP-AGU', 900, 1500, 0.30],
                ]
            ],
            [
                'nf' => 'NF-HAP-2026-088', 'dias_atras' => 10, 'forn' => $fornFresenius,
                'itens' => [
                    ['SORO_FISIO',   'LOTE-HAP-SOR', 420, 350, 6.40],
                    ['DIAZEPAM',     'LOTE-HAP-DZP', 380, 200, 0.45],
                ]
            ],
        ];

        foreach ($nfsHapConfig as $dNF) {
            $dataEmissao = Carbon::now()->subDays($dNF['dias_atras']);
            $entrada = Entrada::create([
                'nota_fiscal'   => $dNF['nf'],
                'setor_id'      => $almoxHap->id,
                'fornecedor_id' => $dNF['forn']->id,
                'created_at'    => $dataEmissao,
                'updated_at'    => $dataEmissao,
            ]);

            foreach ($dNF['itens'] as [$pKey, $loteNome, $diasVenc, $qtd, $valorUn]) {
                $produto = $produtosDemo[$pKey];
                $dataVenc = Carbon::now()->addDays($diasVenc)->toDateString();
                $dataFab  = Carbon::now()->addDays($diasVenc)->subYears(2)->toDateString();

                ItensEntrada::create([
                    'entrada_id'      => $entrada->id,
                    'produto_id'      => $produto->id,
                    'quantidade'      => $qtd,
                    'lote'            => $loteNome,
                    'data_fabricacao' => $dataFab,
                    'data_vencimento' => $dataVenc,
                    'valor_unitario'  => $valorUn,
                    'created_at'      => $dataEmissao,
                    'updated_at'      => $dataEmissao,
                ]);

                EstoqueLote::create([
                    'setor_id'              => $almoxHap->id,
                    'produto_id'            => $produto->id,
                    'lote'                  => $loteNome,
                    'quantidade_disponivel' => $qtd,
                    'valor_unitario'        => $valorUn,
                    'data_fabricacao'       => $dataFab,
                    'data_vencimento'       => $dataVenc,
                    'created_at'            => $dataEmissao,
                    'updated_at'            => $dataEmissao,
                ]);
            }
        }

        // =====================================================================
        // 6. CICLO COMPLETO DE MOVIMENTAÇÕES HOSPITALARES COM RASTREABILIDADE
        // =====================================================================
        $this->command->info('🔄 [5/6] Executando transferências, solicitações clínicas, FIFO e devoluções auditadas...');

        // Helper para debitar quantidade de um lote físico com consistência
        $debitarLote = function($setorId, $produtoId, $loteNome, $quantidade) {
            $lote = EstoqueLote::where('setor_id', $setorId)
                ->where('produto_id', $produtoId)
                ->where('lote', $loteNome)
                ->first();

            if ($lote) {
                $novaQtd = max(0, $lote->quantidade_disponivel - $quantidade);
                $lote->update(['quantidade_disponivel' => $novaQtd]);
                return $lote;
            }
            return null;
        };

        // Helper para creditar/estornar quantidade em lote físico
        $creditarLote = function($setorId, $produtoId, $loteNome, $quantidade, $valorUn = null, $dataVenc = null) use ($now) {
            $lote = EstoqueLote::where('setor_id', $setorId)
                ->where('produto_id', $produtoId)
                ->where('lote', $loteNome)
                ->first();

            if ($lote) {
                $lote->update(['quantidade_disponivel' => $lote->quantidade_disponivel + $quantidade]);
                return $lote;
            } else {
                return EstoqueLote::create([
                    'setor_id'              => $setorId,
                    'produto_id'            => $produtoId,
                    'lote'                  => $loteNome,
                    'quantidade_disponivel' => $quantidade,
                    'valor_unitario'        => $valorUn ?? 0,
                    'data_vencimento'       => $dataVenc ?? Carbon::now()->addYear()->toDateString(),
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]);
            }
        };

        // ---------------------------------------------------------------------
        // 6.1 Ressuprimento Entre Estoques (D-50): CAF HGVC -> Farmácia Satélite Centro Cirúrgico
        // ---------------------------------------------------------------------
        $movRessupHgvc = Movimentacao::create([
            'usuario_id'           => $userAlmoxCaf->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $farmSateliteHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(50),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Ressuprimento programado entre estoques: CAF para Farmácia Satélite Centro Cirúrgico',
        ]);

        $itensRessup = [
            ['DIPIRONA_AMP', 'LOTE-DIP-24A', 200, 1.85, 700],
            ['SERINGA_10',   'LOTE-SER-36',  500, 0.75, 1080],
            ['MORFINA',      'LOTE-MORF-12',  80, 8.90, 360],
            ['ALFENTANILA',  'LOTE-ALF-24A',  40, 14.50, 450],
            ['SORO_FISIO',   'LOTE-SOR-18',  100, 6.40, 540],
        ];

        foreach ($itensRessup as [$pKey, $loteNome, $qtd, $valorUn, $diasVenc]) {
            $produto = $produtosDemo[$pKey];
            $dataVenc = Carbon::now()->addDays($diasVenc)->toDateString();

            ItemMovimentacao::create([
                'movimentacao_id'       => $movRessupHgvc->id,
                'produto_id'            => $produto->id,
                'quantidade_solicitada' => $qtd,
                'quantidade_liberada'   => $qtd,
                'lote'                  => json_encode([['lote' => $loteNome, 'qtd' => $qtd, 'data_vencimento' => $dataVenc]]),
                'created_at'            => Carbon::now()->subDays(50),
                'updated_at'            => Carbon::now()->subDays(50),
            ]);

            // Debita da CAF e credita na Farmácia Satélite com rastreabilidade do mesmo lote do fabricante
            $debitarLote($cafHgvc->id, $produto->id, $loteNome, $qtd);
            $creditarLote($farmSateliteHgvc->id, $produto->id, $loteNome, $qtd, $valorUn, $dataVenc);
        }

        // ---------------------------------------------------------------------
        // 6.2 Pedido de Prescrições Atendido (D-35): CAF HGVC -> UTI Adulto
        // ---------------------------------------------------------------------
        $movConcluida1 = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(35),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Atendimento de prescrições hospitalares do plantão médico da UTI Adulto',
        ]);

        $itemPedDipirona = ItemMovimentacao::create([
            'movimentacao_id'       => $movConcluida1->id,
            'produto_id'            => $produtosDemo['DIPIRONA_AMP']->id,
            'quantidade_solicitada' => 50,
            'quantidade_liberada'   => 50,
            'lote'                  => json_encode([['lote' => 'LOTE-DIP-24A', 'qtd' => 50, 'data_vencimento' => Carbon::now()->addDays(700)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(35),
            'updated_at'            => Carbon::now()->subDays(35),
        ]);
        $debitarLote($cafHgvc->id, $produtosDemo['DIPIRONA_AMP']->id, 'LOTE-DIP-24A', 50);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movConcluida1->id,
            'produto_id'            => $produtosDemo['SERINGA_10']->id,
            'quantidade_solicitada' => 100,
            'quantidade_liberada'   => 100,
            'lote'                  => json_encode([['lote' => 'LOTE-SER-36', 'qtd' => 100, 'data_vencimento' => Carbon::now()->addDays(1080)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(35),
            'updated_at'            => Carbon::now()->subDays(35),
        ]);
        $debitarLote($cafHgvc->id, $produtosDemo['SERINGA_10']->id, 'LOTE-SER-36', 100);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movConcluida1->id,
            'produto_id'            => $produtosDemo['DIAZEPAM']->id,
            'quantidade_solicitada' => 20,
            'quantidade_liberada'   => 20,
            'lote'                  => json_encode([['lote' => 'LOTE-DZP-18', 'qtd' => 20, 'data_vencimento' => Carbon::now()->addDays(540)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(35),
            'updated_at'            => Carbon::now()->subDays(35),
        ]);
        $debitarLote($cafHgvc->id, $produtosDemo['DIAZEPAM']->id, 'LOTE-DZP-18', 20);

        // ---------------------------------------------------------------------
        // 6.3 Devolução Auditada Concluída (D-20): UTI Adulto -> CAF HGVC (Origem Pedido #$movConcluida1->id)
        // ---------------------------------------------------------------------
        $movDevolucaoAprovada = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $utiHgvc->id,
            'setor_destino_id'     => $cafHgvc->id,
            'tipo'                 => 'D',
            'data_hora'            => Carbon::now()->subDays(20),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Devolução originada do pedido #' . $movConcluida1->id . ' - Sobra de procedimento por transferência de paciente',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movDevolucaoAprovada->id,
            'produto_id'            => $produtosDemo['DIPIRONA_AMP']->id,
            'quantidade_solicitada' => 15,
            'quantidade_devolvendo' => 15,
            'quantidade_liberada'   => 15,
            'lote'                  => 'LOTE-DIP-24A',
            'created_at'            => Carbon::now()->subDays(20),
            'updated_at'            => Carbon::now()->subDays(20),
        ]);

        Devolucao::create([
            'movimentacao_id'       => $movConcluida1->id,
            'item_movimentacao_id'  => $itemPedDipirona->id,
            'lote'                  => 'LOTE-DIP-24A',
            'quantidade'            => 15,
            'quantidade_solicitada' => 15,
            'quantidade_aprovada'   => 15,
            'usuario_id'            => $userSolicUti->id,
            'motivo'                => 'Sobra de procedimento por transferência de paciente',
            'created_at'            => Carbon::now()->subDays(20),
            'updated_at'            => Carbon::now()->subDays(20),
        ]);

        // Atualiza quantidade já devolvida no item original e estorna lote na CAF
        $itemPedDipirona->update(['quantidade_devolvendo' => 15]);
        $creditarLote($cafHgvc->id, $produtosDemo['DIPIRONA_AMP']->id, 'LOTE-DIP-24A', 15);

        // ---------------------------------------------------------------------
        // 6.4 Atendimento Rápido de Urgência (D-15): Farmácia Satélite -> UTI Adulto
        // ---------------------------------------------------------------------
        $movSateliteAtendida = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $farmSateliteHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(15),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Dispensação imediata da Farmácia Satélite para intubação de urgência na UTI',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movSateliteAtendida->id,
            'produto_id'            => $produtosDemo['MORFINA']->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada'   => 10,
            'lote'                  => json_encode([['lote' => 'LOTE-MORF-12', 'qtd' => 10, 'data_vencimento' => Carbon::now()->addDays(360)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(15),
            'updated_at'            => Carbon::now()->subDays(15),
        ]);
        $debitarLote($farmSateliteHgvc->id, $produtosDemo['MORFINA']->id, 'LOTE-MORF-12', 10);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movSateliteAtendida->id,
            'produto_id'            => $produtosDemo['ALFENTANILA']->id,
            'quantidade_solicitada' => 8,
            'quantidade_liberada'   => 8,
            'lote'                  => json_encode([['lote' => 'LOTE-ALF-24A', 'qtd' => 8, 'data_vencimento' => Carbon::now()->addDays(450)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(15),
            'updated_at'            => Carbon::now()->subDays(15),
        ]);
        $debitarLote($farmSateliteHgvc->id, $produtosDemo['ALFENTANILA']->id, 'LOTE-ALF-24A', 8);

        // ---------------------------------------------------------------------
        // 6.5 Atendimento Parcial por Desabastecimento (D-12): CAF HGVC -> UTI Adulto
        // ---------------------------------------------------------------------
        $movParcial = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(12),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Atendimento parcial por contingenciamento temporário no almoxarifado central',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movParcial->id,
            'produto_id'            => $produtosDemo['CEFTRIAXONA']->id,
            'quantidade_solicitada' => 20,
            'quantidade_liberada'   => 20,
            'lote'                  => json_encode([['lote' => 'LOTE-CEF-24', 'qtd' => 20, 'data_vencimento' => Carbon::now()->addDays(730)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(12),
            'updated_at'            => Carbon::now()->subDays(12),
        ]);
        $debitarLote($cafHgvc->id, $produtosDemo['CEFTRIAXONA']->id, 'LOTE-CEF-24', 20);

        // Item desabastecido: liberado 0 com histórico preservado
        ItemMovimentacao::create([
            'movimentacao_id'       => $movParcial->id,
            'produto_id'            => $produtosDemo['OMEPRAZOL']->id,
            'quantidade_solicitada' => 15,
            'quantidade_liberada'   => 0,
            'lote'                  => null,
            'created_at'            => Carbon::now()->subDays(12),
            'updated_at'            => Carbon::now()->subDays(12),
        ]);

        // ---------------------------------------------------------------------
        // 6.6 Consumo Interno / Quebra por Avaria na CAF (D-10):
        // ---------------------------------------------------------------------
        $movConsumo = Movimentacao::create([
            'usuario_id'           => $userAlmoxCaf->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $cafHgvc->id,
            'tipo'                 => 'C',
            'data_hora'            => Carbon::now()->subDays(10),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Baixa Interna: 2 ampolas de Haloperidol quebradas acidentalmente durante reorganização de gaveteiro.',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movConsumo->id,
            'produto_id'            => $produtosDemo['HALOPERIDOL']->id,
            'quantidade_solicitada' => 2,
            'quantidade_liberada'   => 2,
            'lote'                  => json_encode([['lote' => 'LOTE-HAL-24', 'qtd' => 2, 'data_vencimento' => Carbon::now()->addDays(720)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(10),
            'updated_at'            => Carbon::now()->subDays(10),
        ]);
        $debitarLote($cafHgvc->id, $produtosDemo['HALOPERIDOL']->id, 'LOTE-HAL-24', 2);

        // ---------------------------------------------------------------------
        // 6.7 Pedido Reprovado pelo Almoxarife com Justificativa (D-7): CAF HGVC -> UTI
        // ---------------------------------------------------------------------
        $movReprovada = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(7),
            'status_solicitacao'   => 'R',
            'observacao'           => 'Reprovado pelo almoxarife: cota mensal de entorpecentes atingida para a UTI. Favor solicitar parecer da coordenação médica.',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movReprovada->id,
            'produto_id'            => $produtosDemo['MORFINA']->id,
            'quantidade_solicitada' => 40,
            'quantidade_liberada'   => 0,
            'lote'                  => null,
            'created_at'            => Carbon::now()->subDays(7),
            'updated_at'            => Carbon::now()->subDays(7),
        ]);

        // ---------------------------------------------------------------------
        // 6.8 Pedido Cancelado pelo Solicitante (D-4): Farmácia Satélite -> UTI
        // ---------------------------------------------------------------------
        $movCancelada = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => null,
            'setor_origem_id'      => $farmSateliteHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(4),
            'status_solicitacao'   => 'X',
            'observacao'           => 'Cancelado pelo solicitante: prescrição médica substituída antes da dispensação na farmácia.',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movCancelada->id,
            'produto_id'            => $produtosDemo['HALOPERIDOL']->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada'   => 0,
            'lote'                  => null,
            'created_at'            => Carbon::now()->subDays(4),
            'updated_at'            => Carbon::now()->subDays(4),
        ]);

        // ---------------------------------------------------------------------
        // 6.9 Pedido Pendente para Triagem (D-1 / Ontem): CAF HGVC -> UTI
        // ---------------------------------------------------------------------
        $movPendente = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => null,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDay(),
            'status_solicitacao'   => 'P',
            'observacao'           => 'Reposição noturna diária para pacientes internados nos leitos 01 a 10',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movPendente->id,
            'produto_id'            => $produtosDemo['DIAZEPAM']->id,
            'quantidade_solicitada' => 30,
            'quantidade_liberada'   => 0,
            'lote'                  => null,
            'created_at'            => Carbon::now()->subDay(),
            'updated_at'            => Carbon::now()->subDay(),
        ]);
        ItemMovimentacao::create([
            'movimentacao_id'       => $movPendente->id,
            'produto_id'            => $produtosDemo['SORO_FISIO']->id,
            'quantidade_solicitada' => 40,
            'quantidade_liberada'   => 0,
            'lote'                  => null,
            'created_at'            => Carbon::now()->subDay(),
            'updated_at'            => Carbon::now()->subDay(),
        ]);

        // ---------------------------------------------------------------------
        // 6.10 Pedido Rascunho Aberto no Plantão Atual (Hoje): CAF HGVC -> UTI
        // ---------------------------------------------------------------------
        $movRascunho = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => null,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now(),
            'status_solicitacao'   => 'C',
            'observacao'           => 'Rascunho de pedido do plantão diurno em elaboração pelo enfermeiro assistencial',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movRascunho->id,
            'produto_id'            => $produtosDemo['AGULHA_25']->id,
            'quantidade_solicitada' => 200,
            'quantidade_liberada'   => 0,
            'lote'                  => null,
            'created_at'            => Carbon::now(),
            'updated_at'            => Carbon::now(),
        ]);

        // ---------------------------------------------------------------------
        // 6.11 Cenários no Polo HAP (Hospital Afrânio Peixoto):
        // ---------------------------------------------------------------------
        // Ressuprimento Almoxarifado Central HAP -> Farmácia Satélite HAP (D-25, Atendido)
        $movHapRessup = Movimentacao::create([
            'usuario_id'           => $userAlmoxHap->id,
            'aprovador_usuario_id' => $userAlmoxHap->id,
            'setor_origem_id'      => $almoxHap->id,
            'setor_destino_id'     => $farmSateliteHap->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(25),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Ressuprimento quinzenal da Farmácia Satélite HAP',
        ]);

        $itensRessupHap = [
            ['DIPIRONA_AMP', 'LOTE-HAP-DIP', 100, 1.85, 500],
            ['PARACETAMOL',  'LOTE-HAP-PCT', 100, 0.30, 480],
            ['SERINGA_10',   'LOTE-HAP-SER', 300, 0.75, 900],
        ];

        foreach ($itensRessupHap as [$pKey, $loteNome, $qtd, $valorUn, $diasVenc]) {
            $produto = $produtosDemo[$pKey];
            $dataVenc = Carbon::now()->addDays($diasVenc)->toDateString();

            ItemMovimentacao::create([
                'movimentacao_id'       => $movHapRessup->id,
                'produto_id'            => $produto->id,
                'quantidade_solicitada' => $qtd,
                'quantidade_liberada'   => $qtd,
                'lote'                  => json_encode([['lote' => $loteNome, 'qtd' => $qtd, 'data_vencimento' => $dataVenc]]),
                'created_at'            => Carbon::now()->subDays(25),
                'updated_at'            => Carbon::now()->subDays(25),
            ]);

            $debitarLote($almoxHap->id, $produto->id, $loteNome, $qtd);
            $creditarLote($farmSateliteHap->id, $produto->id, $loteNome, $qtd, $valorUn, $dataVenc);
        }

        // Pedido Atendido: Farmácia Satélite HAP -> Clínica Médica HAP (D-15)
        $movHapAtendida = Movimentacao::create([
            'usuario_id'           => $userSolicHap->id,
            'aprovador_usuario_id' => $userAlmoxHap->id,
            'setor_origem_id'      => $farmSateliteHap->id,
            'setor_destino_id'     => $clinicaMedicaHap->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(15),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Dispensação regular de analgésicos para enfermaria da Clínica HAP',
        ]);

        $itemHapOrig = ItemMovimentacao::create([
            'movimentacao_id'       => $movHapAtendida->id,
            'produto_id'            => $produtosDemo['DIPIRONA_AMP']->id,
            'quantidade_solicitada' => 40,
            'quantidade_liberada'   => 40,
            'lote'                  => json_encode([['lote' => 'LOTE-HAP-DIP', 'qtd' => 40, 'data_vencimento' => Carbon::now()->addDays(500)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(15),
            'updated_at'            => Carbon::now()->subDays(15),
        ]);
        $debitarLote($farmSateliteHap->id, $produtosDemo['DIPIRONA_AMP']->id, 'LOTE-HAP-DIP', 40);

        // Devolução Pendente (D-2): Clínica Médica HAP -> Farmácia Satélite HAP
        $movDevolucaoPendenteHap = Movimentacao::create([
            'usuario_id'           => $userSolicHap->id,
            'aprovador_usuario_id' => null,
            'setor_origem_id'      => $clinicaMedicaHap->id,
            'setor_destino_id'     => $farmSateliteHap->id,
            'tipo'                 => 'D',
            'data_hora'            => Carbon::now()->subDays(2),
            'status_solicitacao'   => 'P',
            'observacao'           => 'Devolução originada do pedido #' . $movHapAtendida->id . ' - 8 ampolas lacradas não administradas',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movDevolucaoPendenteHap->id,
            'produto_id'            => $produtosDemo['DIPIRONA_AMP']->id,
            'quantidade_solicitada' => 8,
            'quantidade_devolvendo' => 8,
            'quantidade_liberada'   => 0,
            'lote'                  => 'LOTE-HAP-DIP',
            'created_at'            => Carbon::now()->subDays(2),
            'updated_at'            => Carbon::now()->subDays(2),
        ]);

        Devolucao::create([
            'movimentacao_id'       => $movHapAtendida->id,
            'item_movimentacao_id'  => $itemHapOrig->id,
            'lote'                  => 'LOTE-HAP-DIP',
            'quantidade'            => 8,
            'quantidade_solicitada' => 8,
            'quantidade_aprovada'   => null,
            'usuario_id'            => $userSolicHap->id,
            'motivo'                => 'Frascos lacrados não administrados ao paciente',
            'created_at'            => Carbon::now()->subDays(2),
            'updated_at'            => Carbon::now()->subDays(2),
        ]);

        // =====================================================================
        // 7. INVARIANTE MATEMÁTICA E CONSOLIDAÇÃO DO ESTOQUE
        // =====================================================================
        $this->command->info('📊 [6/6] Consolidando tabelas de estoque e curvas de suprimento com 100% de consistência...');

        $setoresComEstoque = [$cafHgvc, $farmSateliteHgvc, $almoxHap, $farmSateliteHap];

        foreach ($setoresComEstoque as $setorEstoque) {
            $prodCount = 0;
            foreach ($produtosDemo as $pKey => $produto) {
                $prodCount++;

                // A soma exata dos lotes físicos reais comanda o estoque atual
                $qtdAtual = (int) EstoqueLote::where('setor_id', $setorEstoque->id)
                    ->where('produto_id', $produto->id)
                    ->sum('quantidade_disponivel');

                // Define ponto de reposição para simular alertas clínicos
                if ($qtdAtual === 0) {
                    $qtdMin = 30; // Alerta: Zerado
                } elseif ($qtdAtual <= 40) {
                    $qtdMin = 50; // Alerta: Abaixo do Mínimo (Reposição)
                } else {
                    $qtdMin = 30; // Saudável
                }

                Estoque::updateOrCreate(
                    [
                        'setor_id'   => $setorEstoque->id,
                        'produto_id' => $produto->id,
                    ],
                    [
                        'quantidade_atual'       => $qtdAtual,
                        'quantidade_minima'      => $qtdMin,
                        'status_disponibilidade' => $qtdAtual > 0 ? 'D' : 'I',
                        'localizacao'            => 'Prateleira ' . chr(65 + ($prodCount % 6)) . '-' . ($prodCount % 4 + 1),
                        'created_at'             => Carbon::now()->subDays(60),
                        'updated_at'             => Carbon::now(),
                    ]
                );
            }
        }
    }
}
