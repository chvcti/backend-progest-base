<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

use App\Http\Controllers\Cadastros\SetoresController;
use App\Http\Controllers\Cadastros\FornecedorController;
use App\Http\Controllers\Cadastros\ProdutoController;
use App\Http\Controllers\Cadastros\UnidadeMedidaController;
use App\Http\Controllers\Cadastros\EstoqueController as CadastrosEstoqueController;
use App\Http\Controllers\Cadastros\RegimeContratacaoController;
use App\Http\Controllers\Cadastros\GrupoProdutoController;
use App\Http\Controllers\Cadastros\PoloController;
use App\Http\Controllers\EstoqueController;
use App\Http\Controllers\EstoqueLoteController;
use App\Http\Controllers\EntradaController;
use App\Http\Controllers\UsuarioSetorController;
use App\Http\Controllers\RelatoriosController;
use App\Http\Controllers\MovimentacaoController;
use App\Http\Controllers\DashboardController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Health check - rota pública para testar se a API está funcionando
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API is running',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ============================================================
// AUTENTICAÇÃO (público)
// ============================================================
Route::post("login",    [AuthController::class, 'login']);
Route::post("register", [AuthController::class, 'register']);

// ============================================================
// ROTAS PROTEGIDAS — exigem auth:sanctum
// ============================================================
Route::middleware('auth:sanctum')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);

    Route::get('countUsers', [UserController::class, 'countUsers']);

    // --------------------------------------------------------
    // Dashboard (Métricas e Indicadores Agregados)
    // --------------------------------------------------------
    Route::match(['get', 'post'], '/dashboard/metrics', [DashboardController::class, 'metrics']);

    // --------------------------------------------------------
    // Vínculo usuário ↔ setor
    // --------------------------------------------------------
    Route::post('/usuarioSetor/add',              [UsuarioSetorController::class, 'add']);
    Route::post('/usuarioSetor/update',           [UsuarioSetorController::class, 'update']);
    Route::post('/usuarioSetor/delete',           [UsuarioSetorController::class, 'delete']);
    Route::post('/usuarioSetor/listarPorSetor',   [UsuarioSetorController::class, 'listarPorSetor']);
    Route::post('/usuarioSetor/listarPorUsuario', [UsuarioSetorController::class, 'listarPorUsuario']);
    Route::match(['get', 'post'], '/usuario-setor/listar-por-usuario', [UsuarioSetorController::class, 'listarPorUsuario']);
    // @deprecated — manter para compatibilidade com frontend legado
    Route::post('/usuarioSetor/create',         [UsuarioSetorController::class, 'add']);
    Route::post('/usuarioSetor/listBySetor',    [UsuarioSetorController::class, 'listarPorSetor']);
    Route::post('/usuarioSetor/listByUsuario',  [UsuarioSetorController::class, 'listarPorUsuario']);

    // --------------------------------------------------------
    // Usuários
    // --------------------------------------------------------
    Route::prefix('user')->group(function () {
        Route::post('/add',            [UserController::class, 'add']);
        Route::post('/update',         [UserController::class, 'update']);
        Route::post('/list',           [UserController::class, 'listAll']);
        Route::post('/listData',       [UserController::class, 'listData']);
        Route::post('/delete/{id}',    [UserController::class, 'delete']);
    });

    // --------------------------------------------------------
    // Setores — escrita protegida; leitura pública abaixo
    // --------------------------------------------------------
    Route::post('/setores/add',             [SetoresController::class, 'add']);
    Route::post('/setores/update',          [SetoresController::class, 'update']);
    Route::post('/setores/delete/{id}',     [SetoresController::class, 'delete']);
    Route::post('/setores/toggleStatus',    [SetoresController::class, 'toggleStatus']);
    Route::post('/setores/addDistribuidor',    [SetoresController::class, 'addDistribuidor']);
    Route::post('/setores/removeDistribuidor', [SetoresController::class, 'removeDistribuidor']);
    Route::post('/setores/listWithAccess',           [SetoresController::class, 'listWithAccess']);
    Route::post('/setores/getDetail',                [SetoresController::class, 'getDetail']);
    Route::post('/setores/listConsumers',            [SetoresController::class, 'listConsumers']);
    Route::post('/setores/listDistribuidoresParaSetor', [SetoresController::class, 'listDistribuidoresParaSetor']);

    // --------------------------------------------------------
    // Estoque — todas as rotas protegidas
    // --------------------------------------------------------
    Route::get('/estoque/setor/{setorId}',          [EstoqueController::class, 'listarPorSetor']);
    Route::get('/estoque/{id}',                     [EstoqueController::class, 'show']);
    Route::put('/estoque/{id}/quantidade-minima',   [EstoqueController::class, 'atualizarQuantidadeMinima']);
    Route::put('/estoque/{id}/status',              [EstoqueController::class, 'atualizarStatus']);
    // Rotas antigas do estoque (compatibilidade)
    Route::post('/estoque/add',       [CadastrosEstoqueController::class, 'add']);
    Route::post('/estoque/update',    [CadastrosEstoqueController::class, 'update']);
    Route::post('/estoque/list',      [CadastrosEstoqueController::class, 'listAll']);
    Route::post('/estoque/listData',  [CadastrosEstoqueController::class, 'listData']);
    Route::post('/estoque/delete/{id}', [CadastrosEstoqueController::class, 'delete']);

    // --------------------------------------------------------
    // Lotes de estoque
    // --------------------------------------------------------
    Route::post('/estoqueLote/list',             [EstoqueLoteController::class, 'list']);
    Route::post('/estoqueLote/updateQuantidade', [EstoqueLoteController::class, 'updateQuantidade']);

    // --------------------------------------------------------
    // Produtos — escrita protegida; leitura pública abaixo
    // --------------------------------------------------------
    Route::post('/produtos',                [ProdutoController::class, 'add']);
    Route::post('/produtos/add',            [ProdutoController::class, 'add']);
    Route::post('/produtos/store',          [ProdutoController::class, 'store']);
    Route::post('/produtos/update',         [ProdutoController::class, 'update']);
    Route::match(['post', 'delete'], '/produtos/delete/{id}', [ProdutoController::class, 'delete']);
    Route::delete('/produtos/{id}',         [ProdutoController::class, 'destroy']);
    Route::post('/produtos/toggleStatus',   [ProdutoController::class, 'toggleStatus']);

    // --------------------------------------------------------
    // Entradas de NF — todas as rotas protegidas
    // --------------------------------------------------------
    Route::post('/entrada',        [EntradaController::class, 'store']);
    Route::post('/entrada/add',    [EntradaController::class, 'add']);
    Route::post('/entrada/store',  [EntradaController::class, 'store']);
    Route::post('/entrada/update', [EntradaController::class, 'update']);
    Route::post('/entrada/delete', [EntradaController::class, 'delete']);

    // --------------------------------------------------------
    // Movimentações — todas as rotas protegidas
    // --------------------------------------------------------
    Route::post('/movimentacao/consumo-interno',       [MovimentacaoController::class, 'consumoInterno']);
    Route::post('/movimentacao/add',                   [MovimentacaoController::class, 'store']);
    Route::post('/movimentacao/create',                [MovimentacaoController::class, 'store']);
    Route::match(['get', 'post'], '/movimentacao/listBySetor',   [MovimentacaoController::class, 'listBySetor']);
    Route::match(['get', 'post'], '/movimentacao/listByUnidade', [MovimentacaoController::class, 'listBySetor']); // legado
    Route::get('/movimentacao/{id}',                   [MovimentacaoController::class, 'show']);
    Route::get('/movimentacao/{id}/preview-lotes',     [MovimentacaoController::class, 'previewLotes']);
    Route::post('/movimentacao/{id}/process',          [MovimentacaoController::class, 'process']);
    Route::post('/movimentacao/{id}/delete',           [MovimentacaoController::class, 'destroy']);
    Route::post('/movimentacoes/{id}/status',          [MovimentacaoController::class, 'updateStatus']);
    Route::post('/movimentacao/{id}/update-rascunho',  [MovimentacaoController::class, 'updateRascunho']);
    Route::post('/movimentacao/{id}/devolver',         [MovimentacaoController::class, 'devolver']);

    // --------------------------------------------------------
    // Polo / Unidade — escrita protegida; leitura pública abaixo
    // --------------------------------------------------------
    Route::post('/polo/add',           [PoloController::class, 'add']);
    Route::post('/polo/update',        [PoloController::class, 'update']);
    Route::post('/polo/delete/{id}',   [PoloController::class, 'delete']);
    Route::post('/polo/toggleStatus',  [PoloController::class, 'toggleStatus']);
    // @deprecated — manter para compatibilidade
    Route::post('/unidade/add',          [PoloController::class, 'add']);
    Route::post('/unidade/update',       [PoloController::class, 'update']);
    Route::post('/unidade/delete/{id}',  [PoloController::class, 'delete']);
    Route::post('/unidade/toggleStatus', [PoloController::class, 'toggleStatus']);

    // --------------------------------------------------------
    // Regime de Contratação — escrita protegida; leitura pública abaixo
    // --------------------------------------------------------
    Route::post('/RegimeContratacao/add',           [RegimeContratacaoController::class, 'add']);
    Route::post('/RegimeContratacao/update',        [RegimeContratacaoController::class, 'update']);
    Route::post('/RegimeContratacao/delete/{id}',   [RegimeContratacaoController::class, 'delete']);
    Route::post('/regime-contratacao/add',          [RegimeContratacaoController::class, 'add']);
    Route::post('/regime-contratacao/update',       [RegimeContratacaoController::class, 'update']);
    Route::post('/regime-contratacao/delete/{id}',  [RegimeContratacaoController::class, 'delete']);

    // --------------------------------------------------------
    // Grupo de Produto — escrita protegida; leitura pública abaixo
    // --------------------------------------------------------
    Route::post('/grupoProduto/add',         [GrupoProdutoController::class, 'add']);
    Route::post('/grupoProduto/update',      [GrupoProdutoController::class, 'update']);
    Route::post('/grupoProduto/delete/{id}', [GrupoProdutoController::class, 'delete']);

    // --------------------------------------------------------
    // Fornecedores — escrita protegida; leitura pública abaixo
    // --------------------------------------------------------
    Route::post('/fornecedores/add',           [FornecedorController::class, 'add']);
    Route::post('/fornecedores/update',        [FornecedorController::class, 'update']);
    Route::post('/fornecedores/delete/{id}',   [FornecedorController::class, 'delete']);
    Route::post('/fornecedores/toggleStatus',  [FornecedorController::class, 'toggleStatus']);

    // --------------------------------------------------------
    // Unidade de Medida — escrita protegida; leitura pública abaixo
    // --------------------------------------------------------
    Route::post('/unidadeMedida/add',         [UnidadeMedidaController::class, 'add']);
    Route::post('/unidadeMedida/update',      [UnidadeMedidaController::class, 'update']);
    Route::post('/unidadeMedida/delete/{id}', [UnidadeMedidaController::class, 'delete']);

    // --------------------------------------------------------
    // Relatórios — exige autenticação (perfil verificado no controller)
    // --------------------------------------------------------
    Route::post('/relatorios/entradas/list',           [RelatoriosController::class, 'listEntradasReport']);
    Route::post('/relatorios/movimentacoes/list',      [RelatoriosController::class, 'listMovimentacoesReport']);
    Route::post('/relatorios/saidas/list',             [RelatoriosController::class, 'listSaidasReport']);
    Route::post('/relatorios/saidas-por-data/list',    [RelatoriosController::class, 'listSaidasPorData']);
    Route::post('/relatorios/entradas-por-data/list',  [RelatoriosController::class, 'listEntradasPorData']);
    Route::post('/relatorios/estoque/list',            [RelatoriosController::class, 'listEstoqueReport']);
    Route::post('/relatorios/usuarios/list',           [RelatoriosController::class, 'listUsuariosReport']);
    Route::post('/relatorios/medicamentos-controlados/list', [RelatoriosController::class, 'listMedicamentosControladosReport']);

    // Relatórios Financeiros
    Route::post('/relatorios/financeiro/entradas',     [RelatoriosController::class, 'listEntradasFinanceiras']);
    Route::post('/relatorios/financeiro/saidas',       [RelatoriosController::class, 'listSaidasFinanceiras']);

    // ============================================================
    // ROTAS DE LEITURA (AGORA PROTEGIDAS)
    // ============================================================

    // Setores — leitura (seleção de setor no login)
    Route::post('/setores/list',     [SetoresController::class, 'listAll']);
    Route::post('/setores/listData', [SetoresController::class, 'listData']);

    // Produtos — leitura (catálogo de busca)
    Route::post('/produtos/list',            [ProdutoController::class, 'listAll']);
    Route::post('/produtos/listData',        [ProdutoController::class, 'listData']);
    Route::post('/produtos/dadosAuxiliares', [ProdutoController::class, 'getDadosAuxiliares']);
    Route::post('/produtos/listByTipo',      [ProdutoController::class, 'listByTipo']);

    // Entrada — leitura
    Route::post('/entrada/list', [EntradaController::class, 'list']);

    // Polo / Unidade — leitura
    Route::post('/polo/list',         [PoloController::class, 'listAll']);
    Route::post('/polo/listData',     [PoloController::class, 'listData']);
    Route::post('/unidade/list',      [PoloController::class, 'listAll']);
    Route::post('/unidade/listData',  [PoloController::class, 'listData']);

    // Regime de Contratação — leitura (usado no cadastro de usuário)
    Route::post('/RegimeContratacao/list',     [RegimeContratacaoController::class, 'listAll']);
    Route::post('/RegimeContratacao/listData', [RegimeContratacaoController::class, 'listData']);
    Route::post('/regime-contratacao/list',    [RegimeContratacaoController::class, 'listAll']);
    Route::post('/regime-contratacao/listData',[RegimeContratacaoController::class, 'listData']);

    // Grupo de Produto — leitura
    Route::post('/grupoProduto/list',     [GrupoProdutoController::class, 'listAll']);
    Route::post('/grupoProduto/listData', [GrupoProdutoController::class, 'listData']);

    // Fornecedores — leitura
    Route::post('/fornecedores/list',    [FornecedorController::class, 'listAll']);
    Route::post('/fornecedores/listData',[FornecedorController::class, 'listData']);

    // Unidade de Medida — leitura
    Route::post('/unidadeMedida/list',    [UnidadeMedidaController::class, 'listAll']);
    Route::post('/unidadeMedida/listData',[UnidadeMedidaController::class, 'listData']);

}); // fim middleware auth:sanctum


