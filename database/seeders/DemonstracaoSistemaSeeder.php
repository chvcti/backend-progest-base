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
        $this->command->info('🏥 [DemonstracaoSistemaSeeder] Iniciando simulação hiper-realista do ProGest...');

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

        // Desativa observers durante a carga para evitar efeitos colaterais em setores oficiais
        Produto::unsetEventDispatcher();

        // 1. Polos Oficiais
        $hgvc = Polo::where('sigla', 'HGVC')->orWhere('nome', 'like', '%Geral%')->first();
        $hap  = Polo::where('sigla', 'HAP')->orWhere('nome', 'like', '%Afrânio%')->first();

        if (!$hgvc || !$hap) {
            throw new \RuntimeException('Polos HGVC e HAP são obrigatórios para a demonstração.');
        }

        // =====================================================================
        // 2. TOPOLOGIA DOS SETORES DE DEMONSTRAÇÃO (SUFIXO "[Exemplo]")
        // =====================================================================
        $this->command->info('🏛️ [1/5] Configurando setores e cadeia de distribuição de demonstração...');

        // HAP
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

        // HGVC
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

        $demoSetorIds = [
            $almoxHap->id,
            $farmSateliteHap->id,
            $clinicaMedicaHap->id,
            $cafHgvc->id,
            $farmSateliteHgvc->id,
            $utiHgvc->id
        ];

        // Limpar dados anteriores exclusivamente vinculados aos setores de exemplo (Idempotência segura)
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

        // Limpar dados nos setores de exemplo e purificar qualquer setor oficial
        EstoqueLote::whereIn('setor_id', $demoSetorIds)->delete();
        Estoque::whereIn('setor_id', $demoSetorIds)->delete();
        EstoqueLote::whereNotIn('setor_id', $demoSetorIds)->delete();
        Estoque::whereNotIn('setor_id', $demoSetorIds)->delete();

        // Cadeia de Suprimentos (setor_distribuidor)
        $distribuicoes = [
            // HAP
            [$farmSateliteHap->id, $almoxHap->id],
            [$clinicaMedicaHap->id, $almoxHap->id],
            [$clinicaMedicaHap->id, $farmSateliteHap->id],
            [$almoxHap->id, $farmSateliteHap->id], // Remanejamento entre estoques

            // HGVC
            [$farmSateliteHgvc->id, $cafHgvc->id],
            [$utiHgvc->id, $cafHgvc->id],
            [$utiHgvc->id, $farmSateliteHgvc->id],
            [$cafHgvc->id, $farmSateliteHgvc->id], // Remanejamento entre estoques

            // Inter-polo
            [$almoxHap->id, $cafHgvc->id],
            [$farmSateliteHap->id, $cafHgvc->id],
        ];

        foreach ($distribuicoes as [$solicId, $distId]) {
            DB::table('setor_distribuidor')->updateOrInsert(
                ['setor_solicitante_id' => $solicId, 'setor_distribuidor_id' => $distId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        // =====================================================================
        // 3. USUÁRIOS E ISOLAMENTO SETORIAL
        // =====================================================================
        $this->command->info('👥 [2/5] Criando usuários de homologação e vínculos de acesso...');

        $senhaHash = Hash::make(env('USER_DEFAULT_PASSWORD', 'Mudar@123'));
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

        // Vínculos de polos
        $vinculosPolos = [
            [$userAdminGeral->id, $hgvc->id],
            [$userAdminGeral->id, $hap->id],
            [$userAlmoxCaf->id, $hgvc->id],
            [$userSolicUti->id, $hgvc->id],
            [$userAlmoxHap->id, $hap->id],
            [$userSolicHap->id, $hap->id],
        ];
        foreach ($vinculosPolos as [$uId, $pId]) {
            DB::table('usuario_polo')->updateOrInsert(
                ['usuario_id' => $uId, 'polo_id' => $pId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        // Vínculos setoriais estritos
        $vinculosSetores = [
            // Admin geral vinculado como admin nos setores-chave
            [$userAdminGeral->id, $cafHgvc->id, 'admin'],
            [$userAdminGeral->id, $almoxHap->id, 'admin'],
            [$userAdminGeral->id, $farmSateliteHgvc->id, 'admin'],
            [$userAdminGeral->id, $farmSateliteHap->id, 'admin'],
            // Almoxarifes
            [$userAlmoxCaf->id, $cafHgvc->id, 'almoxarife'],
            [$userAlmoxCaf->id, $farmSateliteHgvc->id, 'almoxarife'],
            [$userAlmoxHap->id, $almoxHap->id, 'almoxarife'],
            [$userAlmoxHap->id, $farmSateliteHap->id, 'almoxarife'],
            // Solicitantes
            [$userSolicUti->id, $utiHgvc->id, 'solicitante'],
            [$userSolicHap->id, $clinicaMedicaHap->id, 'solicitante'],
        ];
        foreach ($vinculosSetores as [$uId, $sId, $perf]) {
            DB::table('usuario_setor')->updateOrInsert(
                ['usuario_id' => $uId, 'setor_id' => $sId],
                ['perfil' => $perf, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        // =====================================================================
        // 4. SELEÇÃO DE PRODUTOS REPRESENTATIVOS (CATÁLOGO REAL)
        // =====================================================================
        $this->command->info('💊 [3/5] Selecionando produtos com SIMPAS, Barras e Portaria 344/98...');

        // Resgatar ou enriquecer produtos reais
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

        // Catálogo Selecionado
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

        // Fornecedores
        $fornCristalia = Fornecedor::where('razao_social_nome', 'like', '%Cristália%')->first()
            ?? Fornecedor::firstOrCreate(['cnpj' => '44734671000151'], ['tipo_pessoa' => 'J', 'razao_social_nome' => 'Cristália Produtos Químicos Farmacêuticos Ltda', 'status' => 'A']);
        
        $fornEurofarma = Fornecedor::where('razao_social_nome', 'like', '%Eurofarma%')->first()
            ?? Fornecedor::firstOrCreate(['cnpj' => '61190096000192'], ['tipo_pessoa' => 'J', 'razao_social_nome' => 'Eurofarma Laboratórios S.A.', 'status' => 'A']);

        $fornCremer = Fornecedor::where('razao_social_nome', 'like', '%Cremer%')->first()
            ?? Fornecedor::firstOrCreate(['cnpj' => '82641325000118'], ['tipo_pessoa' => 'J', 'razao_social_nome' => 'Cremer S.A. Produtos Hospitalares', 'status' => 'A']);

        $fornFresenius = Fornecedor::where('razao_social_nome', 'like', '%Fresenius%')->first()
            ?? Fornecedor::firstOrCreate(['cnpj' => '49324221000104'], ['tipo_pessoa' => 'J', 'razao_social_nome' => 'Fresenius Kabi Brasil Ltda', 'status' => 'A']);

        // =====================================================================
        // 5. ENTRADAS POR NOTA FISCAL E ESTOQUE FÍSICO COM FIFO
        // =====================================================================
        $this->command->info('📦 [4/5] Gerando Notas Fiscais históricas (90 dias) e lotes (saudáveis, críticos e vencidos)...');

        $lotesConfiguracao = [
            // [produto_key, lote_nome, dias_vencimento, quantidade, valor_un]
            // CAF - Entradas e Lotes
            ['ALFENTANILA',  'LOTE-ALF-24A', 450, 200, 14.50],
            ['ALFENTANILA',  'LOTE-ALF-CRIT', 20,  40, 14.50], // Estado crítico (vence em 20 dias!)
            ['MORFINA',      'LOTE-MORF-12', 360, 300, 8.90],
            ['DIAZEPAM',     'LOTE-DZP-18',  540, 500, 0.45],
            ['CLONAZEPAM',   'LOTE-CNP-14',  420, 400, 0.65],
            ['HALOPERIDOL',  'LOTE-HAL-24',  720, 250, 3.20],
            ['HALOPERIDOL',  'LOTE-HAL-VENC',-10,  15, 3.20], // Lote vencido há 10 dias (quarentena!)
            ['AMITRIPTILINA','LOTE-AMI-18',  540, 300, 0.35],
            ['DIPIRONA_AMP', 'LOTE-DIP-24A', 700, 800, 1.85],
            ['DIPIRONA_AMP', 'LOTE-DIP-CRIT', 18, 120, 1.85], // Crítico (vence em 18 dias)
            ['DIPIRONA_CP',  'LOTE-DIP-CP1', 600, 1200, 0.25],
            ['PARACETAMOL',  'LOTE-PCT-18',  540, 600, 0.30],
            ['CEFTRIAXONA',  'LOTE-CEF-24',  730, 350, 18.50],
            ['OMEPRAZOL',    'LOTE-OMP-12',  360, 200, 9.20],
            ['SORO_FISIO',   'LOTE-SOR-18',  540, 450, 6.40],
            ['SERINGA_10',   'LOTE-SER-36', 1080, 2000, 0.75],
            ['AGULHA_25',    'LOTE-AGU-36', 1080, 2500, 0.30],
            ['LUVA_PROC',    'LOTE-LUV-24',  720, 150, 35.00],
        ];

        // 5.1 Criar NFs na CAF (5 NFs nos últimos 90 dias)
        $nfsCaf = [
            ['nf' => 'NF-CAF-2026-081', 'dias_atras' => 80, 'forn' => $fornEurofarma],
            ['nf' => 'NF-CAF-2026-094', 'dias_atras' => 60, 'forn' => $fornCristalia],
            ['nf' => 'NF-CAF-2026-112', 'dias_atras' => 45, 'forn' => $fornFresenius],
            ['nf' => 'NF-CAF-2026-135', 'dias_atras' => 25, 'forn' => $fornCremer],
            ['nf' => 'NF-CAF-2026-150', 'dias_atras' => 5,  'forn' => $fornEurofarma],
        ];

        $nfIndex = 0;
        foreach ($nfsCaf as $dadosNf) {
            $dataEmissao = Carbon::now()->subDays($dadosNf['dias_atras']);
            $entrada = Entrada::create([
                'nota_fiscal'   => $dadosNf['nf'],
                'setor_id'      => $cafHgvc->id,
                'fornecedor_id' => $dadosNf['forn']->id,
                'created_at'    => $dataEmissao,
                'updated_at'    => $dataEmissao,
            ]);

            // Vincular itens a esta NF
            $fatiaItens = array_slice($lotesConfiguracao, $nfIndex * 3, 4);
            foreach ($fatiaItens as [$pKey, $loteNome, $diasVenc, $qtd, $valorUn]) {
                $produto = $produtosDemo[$pKey];
                $dataVenc = Carbon::now()->addDays($diasVenc)->toDateString();
                $dataFab = Carbon::now()->addDays($diasVenc)->subYears(2)->toDateString();

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

                // Registrar Estoque e EstoqueLote na CAF
                $loteCadastrado = EstoqueLote::updateOrCreate(
                    [
                        'setor_id'   => $cafHgvc->id,
                        'produto_id' => $produto->id,
                        'lote'       => $loteNome,
                    ],
                    [
                        'quantidade_disponivel' => $qtd,
                        'valor_unitario'        => $valorUn,
                        'data_fabricacao'       => $dataFab,
                        'data_vencimento'       => $dataVenc,
                        'created_at'            => $dataEmissao,
                        'updated_at'            => $dataEmissao,
                    ]
                );
            }
            $nfIndex++;
        }

        // 5.2 Criar NFs no Almoxarifado Central HAP (3 NFs nos últimos 90 dias)
        $nfsHap = [
            ['nf' => 'NF-HAP-2026-031', 'dias_atras' => 70, 'forn' => $fornEurofarma],
            ['nf' => 'NF-HAP-2026-054', 'dias_atras' => 35, 'forn' => $fornCremer],
            ['nf' => 'NF-HAP-2026-088', 'dias_atras' => 8,  'forn' => $fornFresenius],
        ];

        $lotesHap = [
            ['DIPIRONA_AMP', 'LOTE-HAP-DIP', 500, 400, 1.85],
            ['PARACETAMOL',  'LOTE-HAP-PCT', 480, 300, 0.30],
            ['SERINGA_10',   'LOTE-HAP-SER', 900, 1000, 0.75],
            ['AGULHA_25',    'LOTE-HAP-AGU', 900, 1200, 0.30],
            ['SORO_FISIO',   'LOTE-HAP-SOR', 420, 250, 6.40],
            ['DIAZEPAM',     'LOTE-HAP-DZP', 380, 150, 0.45],
        ];

        $hapIndex = 0;
        foreach ($nfsHap as $dadosNf) {
            $dataEmissao = Carbon::now()->subDays($dadosNf['dias_atras']);
            $entrada = Entrada::create([
                'nota_fiscal'   => $dadosNf['nf'],
                'setor_id'      => $almoxHap->id,
                'fornecedor_id' => $dadosNf['forn']->id,
                'created_at'    => $dataEmissao,
                'updated_at'    => $dataEmissao,
            ]);

            $itensHap = array_slice($lotesHap, $hapIndex * 2, 2);
            foreach ($itensHap as [$pKey, $loteNome, $diasVenc, $qtd, $valorUn]) {
                $produto = $produtosDemo[$pKey];
                $dataVenc = Carbon::now()->addDays($diasVenc)->toDateString();
                $dataFab = Carbon::now()->addDays($diasVenc)->subYears(2)->toDateString();

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

                EstoqueLote::updateOrCreate(
                    [
                        'setor_id'   => $almoxHap->id,
                        'produto_id' => $produto->id,
                        'lote'       => $loteNome,
                    ],
                    [
                        'quantidade_disponivel' => $qtd,
                        'valor_unitario'        => $valorUn,
                        'data_fabricacao'       => $dataFab,
                        'data_vencimento'       => $dataVenc,
                        'created_at'            => $dataEmissao,
                        'updated_at'            => $dataEmissao,
                    ]
                );
            }
            $hapIndex++;
        }

        // 5.3 Consolidar tabela 'estoque' e calcular proporção de níveis (70% ideal, 20% baixo, 10% zerado)
        $setoresComEstoque = [$cafHgvc, $almoxHap, $farmSateliteHgvc, $farmSateliteHap];

        foreach ($setoresComEstoque as $setorEstoque) {
            $prodCount = 0;
            foreach ($produtosDemo as $pKey => $produto) {
                $prodCount++;
                $saldoLotes = (int) EstoqueLote::where('setor_id', $setorEstoque->id)
                    ->where('produto_id', $produto->id)
                    ->sum('quantidade_disponivel');

                // Distribuir níveis para a demonstração visual:
                if ($prodCount % 10 === 0) {
                    // 10% Zerado
                    $qtdAtual = 0;
                    $qtdMin = 30;
                    EstoqueLote::where('setor_id', $setorEstoque->id)
                        ->where('produto_id', $produto->id)
                        ->update(['quantidade_disponivel' => 0]);
                } elseif ($prodCount % 5 === 0) {
                    // 20% Abaixo do Mínimo (Alerta de Reposição)
                    $qtdMin = 100;
                    $qtdAtual = min($saldoLotes, 35);
                    if ($qtdAtual == 0) $qtdAtual = 25;
                    // Ajustar saldo nos lotes se necessário
                    $lotePrimeiro = EstoqueLote::where('setor_id', $setorEstoque->id)
                        ->where('produto_id', $produto->id)
                        ->first();
                    if ($lotePrimeiro) {
                        $lotePrimeiro->update(['quantidade_disponivel' => $qtdAtual]);
                    } elseif ($setorEstoque->id === $cafHgvc->id) {
                        EstoqueLote::create([
                            'setor_id'              => $setorEstoque->id,
                            'produto_id'            => $produto->id,
                            'lote'                  => 'LOTE-ALERTA-' . $prodCount,
                            'quantidade_disponivel' => $qtdAtual,
                            'data_vencimento'       => Carbon::now()->addMonths(12)->toDateString(),
                        ]);
                    }
                } else {
                    // 70% Nível Ideal
                    $qtdMin = 50;
                    $qtdAtual = max($saldoLotes, 180);
                    // Assegura lote para os que estão na Farmácia Satélite
                    if ($saldoLotes < $qtdAtual && ($setorEstoque->id === $farmSateliteHgvc->id || $setorEstoque->id === $farmSateliteHap->id)) {
                        $qtdAtual = 80;
                        $qtdMin = 20;
                        EstoqueLote::updateOrCreate(
                            [
                                'setor_id'   => $setorEstoque->id,
                                'produto_id' => $produto->id,
                                'lote'       => 'SAT-LOTE-' . $prodCount,
                            ],
                            [
                                'quantidade_disponivel' => $qtdAtual,
                                'data_vencimento'       => Carbon::now()->addMonths(16)->toDateString(),
                            ]
                        );
                    }
                }

                // Invariante técnica estrita: soma dos lotes rigorosamente idêntica a estoque.quantidade_atual
                $qtdAtual = (int) EstoqueLote::where('setor_id', $setorEstoque->id)
                    ->where('produto_id', $produto->id)
                    ->sum('quantidade_disponivel');

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

        // =====================================================================
        // 6. CICLO COMPLETO DE MOVIMENTAÇÕES HOSPITALARES (TODOS OS TIPOS E STATUS)
        // =====================================================================
        $this->command->info('🔄 [5/5] Registrando movimentações clínicas, pedidos rascunho, FIFO, devoluções e quebras...');

        // 6.1 Transferência Concluída (Status 'A', D-35): CAF -> UTI Adulto
        $movConcluida1 = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(35),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Atendimento regular de plantão hospitalar',
        ]);

        $itemPedOriginal1 = ItemMovimentacao::create([
            'movimentacao_id'       => $movConcluida1->id,
            'produto_id'            => $produtosDemo['DIPIRONA_AMP']->id,
            'quantidade_solicitada' => 50,
            'quantidade_liberada'   => 50,
            'lote'                  => json_encode([['lote' => 'LOTE-DIP-24A', 'qtd' => 50, 'data_vencimento' => Carbon::now()->addDays(700)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(35),
            'updated_at'            => Carbon::now()->subDays(35),
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movConcluida1->id,
            'produto_id'            => $produtosDemo['SERINGA_10']->id,
            'quantidade_solicitada' => 100,
            'quantidade_liberada'   => 100,
            'lote'                  => json_encode([['lote' => 'LOTE-SER-36', 'qtd' => 100, 'data_vencimento' => Carbon::now()->addDays(1080)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(35),
            'updated_at'            => Carbon::now()->subDays(35),
        ]);

        // Item de medicamento de controle especial (Portaria 344) para enriquecer relatórios
        ItemMovimentacao::create([
            'movimentacao_id'       => $movConcluida1->id,
            'produto_id'            => $produtosDemo['DIAZEPAM']->id,
            'quantidade_solicitada' => 20,
            'quantidade_liberada'   => 20,
            'lote'                  => json_encode([['lote' => 'LOTE-DZP-18', 'qtd' => 20, 'data_vencimento' => Carbon::now()->addDays(540)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(35),
            'updated_at'            => Carbon::now()->subDays(35),
        ]);

        // 6.2 Devolução Concluída (Status 'A', D-20): UTI Adulto -> CAF vinculada ao pedido original #$movConcluida1->id
        $movDevolucaoAprovada = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $utiHgvc->id,
            'setor_destino_id'     => $cafHgvc->id,
            'tipo'                 => 'D',
            'data_hora'            => Carbon::now()->subDays(20),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Devolução originada do pedido #' . $movConcluida1->id . ' - Sobra de procedimento cirúrgico',
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
            'item_movimentacao_id'  => $itemPedOriginal1->id,
            'lote'                  => 'LOTE-DIP-24A',
            'quantidade'            => 15,
            'quantidade_solicitada' => 15,
            'quantidade_aprovada'   => 15,
            'usuario_id'            => $userSolicUti->id,
            'motivo'                => 'Sobra de procedimento cirúrgico',
            'created_at'            => Carbon::now()->subDays(20),
            'updated_at'            => Carbon::now()->subDays(20),
        ]);

        // Atualizar saldo já devolvido no pedido original
        $itemPedOriginal1->update(['quantidade_devolvendo' => 15]);

        // 6.3 Atendimento Parcial com Item Zerado (Status 'A', D-12): CAF -> UTI Adulto
        $movParcial = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(12),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Atendimento parcial por desabastecimento temporário de Omeprazol',
        ]);

        // Item 1: Atendido 100%
        ItemMovimentacao::create([
            'movimentacao_id'       => $movParcial->id,
            'produto_id'            => $produtosDemo['CEFTRIAXONA']->id,
            'quantidade_solicitada' => 20,
            'quantidade_liberada'   => 20,
            'lote'                  => json_encode([['lote' => 'LOTE-CEF-24', 'qtd' => 20, 'data_vencimento' => Carbon::now()->addDays(730)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(12),
            'updated_at'            => Carbon::now()->subDays(12),
        ]);

        // Item 2: Quantidade ZERO liberada (desabastecimento com preservação de histórico)
        ItemMovimentacao::create([
            'movimentacao_id'       => $movParcial->id,
            'produto_id'            => $produtosDemo['OMEPRAZOL']->id,
            'quantidade_solicitada' => 15,
            'quantidade_liberada'   => 0,
            'lote'                  => null,
            'created_at'            => Carbon::now()->subDays(12),
            'updated_at'            => Carbon::now()->subDays(12),
        ]);

        // 6.4 Pedido Reprovado com Justificativa (Status 'R', D-10): Farmácia Satélite HGVC -> UTI
        $movReprovada = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $farmSateliteHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(10),
            'status_solicitacao'   => 'R',
            'observacao'           => 'Reprovado pelo almoxarife: cota setorial mensal atingida. Requer autorização da coordenação.',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movReprovada->id,
            'produto_id'            => $produtosDemo['MORFINA']->id,
            'quantidade_solicitada' => 40,
            'quantidade_liberada'   => 0,
            'lote'                  => null,
            'created_at'            => Carbon::now()->subDays(10),
            'updated_at'            => Carbon::now()->subDays(10),
        ]);

        // 6.5 Pedido Cancelado pelo Solicitante (Status 'X', D-15):
        $movCancelada = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => null,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(15),
            'status_solicitacao'   => 'X',
            'observacao'           => 'Cancelado pelo solicitante: paciente transferido antes da dispensação da medicação.',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movCancelada->id,
            'produto_id'            => $produtosDemo['ALFENTANILA']->id,
            'quantidade_solicitada' => 10,
            'quantidade_liberada'   => 0,
            'lote'                  => null,
            'created_at'            => Carbon::now()->subDays(15),
            'updated_at'            => Carbon::now()->subDays(15),
        ]);

        // 6.6 Pedido Pendente Aguardando Triagem (Status 'P', D-1): UTI -> CAF
        $movPendente = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => null,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDay(),
            'status_solicitacao'   => 'P',
            'observacao'           => 'Reposição diária para prescrições das 20h',
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

        // 6.7 Rascunho Aberto no Dia Atual (Status 'C', Hoje):
        $movRascunho = Movimentacao::create([
            'usuario_id'           => $userSolicUti->id,
            'aprovador_usuario_id' => null,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $utiHgvc->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now(),
            'status_solicitacao'   => 'C',
            'observacao'           => 'Rascunho em edição pelo enfermeiro de plantão',
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

        // 6.8 Consumo Interno / Baixa por Quebra na CAF (Tipo 'C', D-18):
        $movConsumo = Movimentacao::create([
            'usuario_id'           => $userAlmoxCaf->id,
            'aprovador_usuario_id' => $userAlmoxCaf->id,
            'setor_origem_id'      => $cafHgvc->id,
            'setor_destino_id'     => $cafHgvc->id,
            'tipo'                 => 'C',
            'data_hora'            => Carbon::now()->subDays(18),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Baixa Interna/Consumo: Frasco de Haloperidol avariado durante reorganização de gaveteiro.',
        ]);

        ItemMovimentacao::create([
            'movimentacao_id'       => $movConsumo->id,
            'produto_id'            => $produtosDemo['HALOPERIDOL']->id,
            'quantidade_solicitada' => 2,
            'quantidade_liberada'   => 2,
            'lote'                  => json_encode([['lote' => 'LOTE-HAL-24', 'qtd' => 2, 'data_vencimento' => Carbon::now()->addDays(720)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(18),
            'updated_at'            => Carbon::now()->subDays(18),
        ]);

        // 6.9 Movimentações no Polo Menor (HAP):
        // Pedido atendido do Almoxarifado Central HAP para a Farmácia Satélite HAP (D-25)
        $movHapAtendida = Movimentacao::create([
            'usuario_id'           => $userAlmoxHap->id,
            'aprovador_usuario_id' => $userAlmoxHap->id,
            'setor_origem_id'      => $almoxHap->id,
            'setor_destino_id'     => $farmSateliteHap->id,
            'tipo'                 => 'T',
            'data_hora'            => Carbon::now()->subDays(25),
            'status_solicitacao'   => 'A',
            'observacao'           => 'Ressuprimento quinzenal da farmácia satélite HAP',
        ]);

        $itemHapOrig = ItemMovimentacao::create([
            'movimentacao_id'       => $movHapAtendida->id,
            'produto_id'            => $produtosDemo['DIPIRONA_AMP']->id,
            'quantidade_solicitada' => 60,
            'quantidade_liberada'   => 60,
            'lote'                  => json_encode([['lote' => 'LOTE-HAP-DIP', 'qtd' => 60, 'data_vencimento' => Carbon::now()->addDays(500)->toDateString()]]),
            'created_at'            => Carbon::now()->subDays(25),
            'updated_at'            => Carbon::now()->subDays(25),
        ]);

        // Devolução pendente (Status 'P', D-2): Clínica Médica HAP -> Farmácia Satélite HAP
        $movDevolucaoPendenteHap = Movimentacao::create([
            'usuario_id'           => $userSolicHap->id,
            'aprovador_usuario_id' => null,
            'setor_origem_id'      => $clinicaMedicaHap->id,
            'setor_destino_id'     => $farmSateliteHap->id,
            'tipo'                 => 'D',
            'data_hora'            => Carbon::now()->subDays(2),
            'status_solicitacao'   => 'P',
            'observacao'           => 'Devolução originada do pedido #' . $movHapAtendida->id . ' - Frascos lacrados não administrados',
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
            'motivo'                => 'Frascos lacrados não administrados',
            'created_at'            => Carbon::now()->subDays(2),
            'updated_at'            => Carbon::now()->subDays(2),
        ]);
    }
}
