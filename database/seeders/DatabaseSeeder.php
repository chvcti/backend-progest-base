<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * ESTE É O SEEDER DE PRODUÇÃO / ENTREGA AO CLIENTE (BASE LIMPA).
     * Popula apenas a estrutura oficial e o catálogo real:
     * - Regimes de contratação
     * - Polos e os 63 setores oficiais limpos
     * - Fornecedores reais do setor de saúde
     * - Usuário Super Admin raiz (TI)
     * - Grupos, Unidades de Medida e Catálogo Oficial de Produtos (417 itens)
     *
     * Para popular o ambiente de Demonstração / Homologação com dados hiper-realistas:
     * php artisan db:seed --class=DemonstracaoSistemaSeeder
     */
    public function run()
    {
        $this->call([
            RegimeContratacaoSeeder::class,        // Regimes de contratação hospitalares e do setor de saúde
            PolosESetoresFullSeeder::class,        // Estrutura oficial completa (63 setores limpos)
            FornecedoresSeeder::class,             // Fornecedores e distribuidores da área da saúde
            AdminInicialSeeder::class,             // Cria adminti no setor de TI
            CatalogoProdutosOficialSeeder::class,  // Grupos, Unidades de Medida e Catálogo oficial de produtos
        ]);
    }
}
