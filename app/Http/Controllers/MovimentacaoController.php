<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Movimentacao;
use App\Models\ItemMovimentacao;
use App\Models\Estoque;
use App\Models\EstoqueLote;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\EstoqueService;
use App\Http\Requests\StoreMovimentacaoRequest;
use App\Http\Requests\Movimentacao\ProcessMovimentacaoRequest;
use App\Http\Requests\Movimentacao\UpdateRascunhoRequest;
use App\Http\Requests\Movimentacao\ConsumoInternoRequest;
use App\Http\Requests\Movimentacao\DevolverRequest;

class MovimentacaoController extends Controller
{
    public function __construct(protected EstoqueService $estoqueService)
    {
    }

    // Criar movimentação (pode ser rascunho ou pendente)
    public function store(StoreMovimentacaoRequest $request)
    {
        // $data já contém todos os campos validados, limpos e com chaves em snake_case!
        $data = $request->validated();

        // Validações de Regras de Negócio de Distribuição e Estoque
        if (in_array($data['tipo'] ?? '', ['S', 'T'])) {
            if (empty($data['setor_origem_id'])) {
                return response()->json(['status' => false, 'message' => 'O setor de origem (fornecedor/distribuidor) é obrigatório.'], 422);
            }
            if (!empty($data['setor_destino_id']) && $data['setor_origem_id'] == $data['setor_destino_id']) {
                return response()->json(['status' => false, 'message' => 'O setor de origem não pode ser igual ao setor solicitante/destino.'], 422);
            }
            $setorOrigem = \App\Models\Setores::find($data['setor_origem_id']);
            if (!$setorOrigem || !$setorOrigem->estoque) {
                return response()->json(['status' => false, 'message' => 'O setor de origem selecionado não possui controle de estoque para fornecer itens.'], 422);
            }

            if (!empty($data['setor_destino_id'])) {
                $setorDestino = \App\Models\Setores::find($data['setor_destino_id']);
                $ehDistribuidorAutorizado = DB::table('setor_distribuidor')
                    ->where('setor_solicitante_id', $data['setor_destino_id'])
                    ->where('setor_distribuidor_id', $data['setor_origem_id'])
                    ->exists();

                $remanejamentoEntreEstoque = ($setorOrigem->estoque && $setorDestino && $setorDestino->estoque);

                if (!$ehDistribuidorAutorizado && !$remanejamentoEntreEstoque) {
                    return response()->json([
                        'status' => false,
                        'message' => 'O setor de origem não está configurado como distribuidor autorizado para este setor solicitante.'
                    ], 422);
                }
            }
        }

        // Validações específicas para Devolução ('D')
        if (($data['tipo'] ?? '') === 'D') {
            if (empty($data['setor_destino_id'])) {
                return response()->json(['status' => false, 'message' => 'O setor de destino é obrigatório para devoluções.'], 422);
            }
            $setorDestino = \App\Models\Setores::find($data['setor_destino_id']);
            if (!$setorDestino || !$setorDestino->estoque) {
                return response()->json(['status' => false, 'message' => 'Devoluções só podem ser enviadas para setores com controle de estoque ativo (farmácias/almoxarifados).'], 422);
            }
            if (!empty($data['setor_origem_id'])) {
                $ehDistribuidor = DB::table('setor_distribuidor')
                    ->where('setor_solicitante_id', $data['setor_origem_id'])
                    ->where('setor_distribuidor_id', $data['setor_destino_id'])
                    ->exists();
                $remanejamentoEntreEstoque = ($setorDestino->estoque && \App\Models\Setores::where('id', $data['setor_origem_id'])->where('estoque', true)->exists());
                if (!$ehDistribuidor && !$remanejamentoEntreEstoque) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Devoluções só podem ser realizadas para o distribuidor autorizado do setor solicitante.'
                    ], 422);
                }
            }
        }

        try {
            // tornar criação atômica: se falhar a criação dos itens, rollback
            $mov = DB::transaction(function () use ($data) {
                $mov = Movimentacao::create([
                    'usuario_id' => $data['usuario_id'],
                    'setor_origem_id' => $data['setor_origem_id'] ?? null,
                    'setor_destino_id' => $data['setor_destino_id'] ?? null,
                    'tipo' => $data['tipo'],
                    'data_hora' => now(),
                    'observacao' => $data['observacao'] ?? null,
                    'status_solicitacao' => $data['status_solicitacao'] ?? 'P'
                ]);

                // criar itens (obrigatórios quando enviados)
                if (!empty($data['itens']) && is_array($data['itens'])) {
                    foreach ($data['itens'] as $it) {
                        ItemMovimentacao::create([
                            'movimentacao_id' => $mov->id,
                            'produto_id' => $it['produto_id'],
                            'quantidade_solicitada' => $it['quantidade_solicitada'] ?? 0,
                            'quantidade_liberada' => $it['quantidade_liberada'] ?? 0,
                            'quantidade_devolvendo' => $it['quantidade_devolvendo'] ?? (($data['tipo'] === 'D') ? ($it['quantidade_solicitada'] ?? 0) : 0),
                            'lote' => $it['lote'] ?? null
                        ]);
                    }
                }

                return $mov;
            });

            return response()->json(['status' => true, 'data' => $mov], 201);
        } catch (\Exception $e) {
            Log::error('Erro criando movimentação: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['status' => false, 'message' => 'Erro ao criar movimentação. Ocorreu uma falha interna.'], 500);
        }
    }

    // Listar movimentações em geral
    public function index(Request $request)
    {
        $query = Movimentacao::with([
            'usuario',
            'aprovador',
            'setorOrigem.polo',
            'setorDestino.polo',
            'itens.produto.unidadeMedida',
            'itens.devolucoes',
            'devolucoes.usuario',
            'devolucoes.pedido.setorDestino'
        ]);

        if ($request->has('setor_id')) {
            $setorId = $request->input('setor_id');
            $query->where(function ($q) use ($setorId) {
                $q->where('setor_origem_id', $setorId)
                  ->orWhere('setor_destino_id', $setorId);
            });
        }

        if ($request->has('tipo')) {
            $query->where('tipo', $request->input('tipo'));
        }

        if ($request->has('status')) {
            $query->where('status_solicitacao', $request->input('status'));
        }

        $movs = $query->orderBy('data_hora', 'desc')->get()->map(function ($m) {
            $distinctCount = 0;
            if ($m->relationLoaded('itens') && $m->itens->isNotEmpty()) {
                $distinctCount = $m->itens->pluck('produto_id')->unique()->count();
            }
            $m->total_itens = $distinctCount;
            $m->numero_pedido = $m->id;
            $m->tem_devolucao = $m->devolucoes && $m->devolucoes->count() > 0;

            if ($m->relationLoaded('itens')) {
                $isAtendido = ($m->status_solicitacao === 'A');
                foreach ($m->itens as $item) {
                    if ($isAtendido) {
                        $qtdDev = 0;
                        if ($item->relationLoaded('devolucoes') && $item->devolucoes->isNotEmpty()) {
                            $qtdDev = (float) $item->devolucoes->sum('quantidade');
                        } elseif ($m->relationLoaded('devolucoes') && $m->devolucoes->isNotEmpty()) {
                            $qtdDev = (float) $m->devolucoes->where('item_movimentacao_id', $item->id)->sum('quantidade');
                        } else {
                            $qtdDev = (float) \App\Models\Devolucao::where('item_movimentacao_id', $item->id)->sum('quantidade');
                        }
                        $item->quantidade_devolvida = $qtdDev;
                    } else {
                        $item->quantidade_devolvida = 0;
                    }

                    $raw = $item->getRawOriginal('lote');
                    if (is_string($raw) && !empty($raw)) {
                        $decoded = json_decode($raw, true);
                        $item->lotes_parsed = is_array($decoded) ? $decoded : [['lote' => $raw, 'qtd' => null, 'data_vencimento' => null]];
                    } else {
                        $item->lotes_parsed = [];
                    }
                }
            }

            return $m;
        });

        return response()->json(['status' => true, 'data' => $movs]);
    }

    // Listar solicitações por setor (relacionada como origem OU destino)
    public function listBySetor(Request $request)
    {
        // Compatibilidade: aceite 'setor_id' (novo) ou 'unidade_id' (legado do front)
        $setorId = $request->input('setor_id') ?? $request->input('unidade_id');
        if (!$setorId) {
            return response()->json(['status' => false, 'message' => 'setor_id (ou unidade_id) é obrigatório'], 422);
        }

        $query = Movimentacao::with([
            'usuario',
            'aprovador',
            'setorOrigem.polo',
            'setorDestino.polo',
            'itens.produto.unidadeMedida',
            'itens.devolucoes',
            'devolucoes.usuario',
            'devolucoes.pedido.setorDestino'
        ])
            ->where(function ($q) use ($setorId) {
                $q->where('setor_origem_id', $setorId)
                  ->orWhere('setor_destino_id', $setorId);
            });

        // Filtro opcional por lote
        $lote = $request->input('lote');
        if ($lote) {
            $query->where(function($q) use ($lote) {
                $q->whereHas('itens', function($iq) use ($lote) {
                    $iq->where('lote', 'LIKE', '%' . $lote . '%');
                })->orWhereHas('devolucoes', function($dq) use ($lote) {
                    $dq->where('lote', 'LIKE', '%' . $lote . '%');
                });
            });
        }

        $movs = $query->orderBy('data_hora', 'desc')
            ->get()
            ->filter(function ($m) use ($setorId) {
                if ($m->status_solicitacao === 'C') { // rascunho
                    // Em devolução (D), quem solicitou/criou o rascunho é a origem; em transferência (T), é o destino
                    $setorCriadorId = ($m->tipo === 'D') ? $m->setor_origem_id : $m->setor_destino_id;
                    return $setorCriadorId == $setorId;
                }
                return true;
            })
            ->values()
            ->map(function ($m) {
                // calcular quantidade de produtos distintos na movimentação
                $distinctCount = 0;
                if ($m->relationLoaded('itens') && $m->itens->isNotEmpty()) {
                    $distinctCount = $m->itens->pluck('produto_id')->unique()->count();
                }
                $m->total_itens = $distinctCount;

                // Alias legível para o frontend
                $m->numero_pedido = $m->id;

                // Adiciona a flag tem_devolucao
                $m->tem_devolucao = $m->devolucoes && $m->devolucoes->count() > 0;

                // Normaliza o campo `lote` e consolida `quantidade_devolvida` de cada item
                if ($m->relationLoaded('itens')) {
                    $isAtendido = ($m->status_solicitacao === 'A');
                    foreach ($m->itens as $item) {
                        if ($isAtendido) {
                            $qtdDev = 0;
                            if ($item->relationLoaded('devolucoes') && $item->devolucoes->isNotEmpty()) {
                                $qtdDev = (float) $item->devolucoes->sum('quantidade');
                            } elseif ($m->relationLoaded('devolucoes') && $m->devolucoes->isNotEmpty()) {
                                $qtdDev = (float) $m->devolucoes->where('item_movimentacao_id', $item->id)->sum('quantidade');
                            } else {
                                $qtdDev = (float) \App\Models\Devolucao::where('item_movimentacao_id', $item->id)->sum('quantidade');
                            }
                            $item->quantidade_devolvida = $qtdDev;
                        } else {
                            $item->quantidade_devolvida = 0;
                        }

                        $raw = $item->getRawOriginal('lote');
                        if (is_string($raw) && !empty($raw)) {
                            $decoded = json_decode($raw, true);
                            $item->lotes_parsed = is_array($decoded) ? $decoded : [['lote' => $raw, 'qtd' => null, 'data_vencimento' => null]];
                        } else {
                            $item->lotes_parsed = [];
                        }
                    }
                }

                return $m;
            });

        // Batch pre-carregar quantidade liberada original para movimentações de devolução
        $devolucoes = $movs->where('tipo', 'D');
        $pedidosOrigemIds = $devolucoes->map(function ($d) {
            return $d->pedido_origem_id;
        })->filter()->unique();

        if ($pedidosOrigemIds->isNotEmpty()) {
            $itensOriginais = ItemMovimentacao::whereIn('movimentacao_id', $pedidosOrigemIds)->get();
            foreach ($devolucoes as $devMov) {
                if (!$devMov->pedido_origem_id || !$devMov->relationLoaded('itens')) continue;
                $itensDoPedido = $itensOriginais->where('movimentacao_id', $devMov->pedido_origem_id);
                foreach ($devMov->itens as $item) {
                    $orig = $itensDoPedido->where('produto_id', $item->produto_id)->first();
                    $qtdLib = $orig ? (float) $orig->quantidade_liberada : 0;
                    $item->quantidade_liberada_original = $qtdLib;
                    $item->quantidade_aprovada_original = $qtdLib;
                }
            }
        }

        return response()->json([
            'status' => true,
            'data' => $movs
        ]);
    }

    // Detalhes / itens da movimentação
    public function show($id)
    {
        $mov = Movimentacao::with([
            'itens.produto.unidadeMedida',
            'itens.devolucoes',
            'usuario',
            'aprovador',
            'setorOrigem.polo',
            'setorDestino.polo',
            'devolucoes.usuario',
            'devolucoes.pedido.setorDestino'
        ])->find($id);

        if (!$mov) {
            return response()->json(['status' => false, 'message' => 'Movimentação não encontrada'], 404);
        }

        $mov->tem_devolucao = $mov->devolucoes && $mov->devolucoes->count() > 0;
        $mov->numero_pedido = $mov->id;

        $isAtendido = ($mov->status_solicitacao === 'A');
        foreach ($mov->itens as $item) {
            if ($isAtendido) {
                $qtdDev = 0;
                if ($item->relationLoaded('devolucoes') && $item->devolucoes->isNotEmpty()) {
                    $qtdDev = (float) $item->devolucoes->sum('quantidade');
                } elseif ($mov->relationLoaded('devolucoes') && $mov->devolucoes->isNotEmpty()) {
                    $qtdDev = (float) $mov->devolucoes->where('item_movimentacao_id', $item->id)->sum('quantidade');
                } else {
                    $qtdDev = (float) \App\Models\Devolucao::where('item_movimentacao_id', $item->id)->sum('quantidade');
                }
                $item->quantidade_devolvida = $qtdDev;
            } else {
                $item->quantidade_devolvida = 0;
            }

            $raw = $item->getRawOriginal('lote');
            if (is_string($raw) && !empty($raw)) {
                $decoded = json_decode($raw, true);
                $item->lotes_parsed = is_array($decoded) ? $decoded : [['lote' => $raw, 'qtd' => null, 'data_vencimento' => null]];
            } else {
                $item->lotes_parsed = [];
            }
        }

        if ($mov->tipo === 'D' && $mov->pedido_origem_id && $mov->relationLoaded('itens')) {
            $itensOriginais = ItemMovimentacao::where('movimentacao_id', $mov->pedido_origem_id)->get();
            foreach ($mov->itens as $item) {
                $orig = $itensOriginais->where('produto_id', $item->produto_id)->first();
                $qtdLib = $orig ? (float) $orig->quantidade_liberada : 0;
                $item->quantidade_liberada_original = $qtdLib;
                $item->quantidade_aprovada_original = $qtdLib;
            }
        }

        return response()->json(['status' => true, 'data' => $mov]);
    }

    // Processar movimentação: aprovar, reprovar, ou mover rascunho->pendente
    public function process(ProcessMovimentacaoRequest $request, $id)
    {
        $mov = Movimentacao::with('itens.produto')->find($id);
        if (!$mov) return response()->json(['status' => false, 'message' => 'Movimentação não encontrada'], 404);

        $validated = $request->validated();
        $action = $validated['action'];
        $aprovadorId = $validated['aprovador_usuario_id'] ?? $validated['usuario_id'] ?? auth()->id();
        $itens = $validated['itens'] ?? $request->input('itens');

        $user = auth()->user();
        if (!$user->isSuperAdmin()) {
            $isDevolucao = ($mov->tipo === 'D');

            if (in_array($action, ['approve', 'reject'])) {
                // A autorização (Policy) assume o controle e lança 403 AccessDeniedHttpException automaticamente caso falhe.
                $this->authorize('processar', $mov);
            } elseif (in_array($action, ['submit', 'cancel'])) {
                // Em devoluções (D), quem envia ou cancela é o setor de origem (devolvente)
                // Em transferências (T, S), quem envia ou cancela é o setor de destino (solicitante)
                $setorSolicitanteId = $isDevolucao ? $mov->setor_origem_id : $mov->setor_destino_id;

                $podeEditar = \Illuminate\Support\Facades\DB::table('usuario_setor')
                    ->where('usuario_id', $user->id)
                    ->where('setor_id', $setorSolicitanteId)
                    ->exists(); 

                if (!$podeEditar) {
                    $papel = $isDevolucao ? 'ao setor que está devolvendo' : 'ao setor solicitante';
                    return response()->json(['status' => false, 'message' => "Permissão negada. Você não pertence {$papel}."], 403);
                }
            }
        }

        try {
            return DB::transaction(function () use ($action, $id, $mov, $itens, $aprovadorId) {

            Log::info("Processando ação: $action para Movimentacao ID: $id");

            // Guarda de máquina de estados: impede que a mesma movimentação seja
            // aprovada duas vezes (duplo clique / retry), o que debitaria o estoque
            // de novo, e impede aprovar/reprovar o que já foi decidido ou cancelado.
            if (in_array($action, ['approve', 'reject']) && $mov->status_solicitacao !== 'P') {
                DB::rollBack();
                $rotulos = [
                    'A' => 'já foi aprovada',
                    'R' => 'já foi reprovada',
                    'X' => 'foi cancelada',
                    'C' => 'ainda é um rascunho',
                ];
                $motivo = $rotulos[$mov->status_solicitacao] ?? 'não está pendente';
                return response()->json([
                    'status' => false,
                    'message' => "Esta movimentação {$motivo} e não pode ser processada novamente."
                ], 422);
            }

            if ($action === 'approve') {
                // Preparar mapa de quantidades liberadas
                $quantidadesLiberadas = [];
                if (!empty($itens) && is_array($itens)) {
                    foreach ($itens as $itemData) {
                        if (isset($itemData['quantidade_liberada']) && (float) $itemData['quantidade_liberada'] < 0) {
                            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                                'status' => false,
                                'message' => 'A quantidade aprovada não pode ser negativa.'
                            ], 422));
                        }
                        if (isset($itemData['id']) && isset($itemData['quantidade_liberada'])) {
                            $quantidadesLiberadas[$itemData['id']] = (float) $itemData['quantidade_liberada'];
                        }
                    }
                }

                if ($mov->itens->isEmpty()) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                        'status' => false,
                        'message' => 'Não é possível aprovar uma movimentação sem itens.'
                    ], 422));
                }
 
                $isDevolucao = ($mov->tipo === 'D');
                $setorOrigem = \App\Models\Setores::find($mov->setor_origem_id);
                $origemControlaEstoque = $setorOrigem && (bool) $setorOrigem->estoque;

                // Validar se pelo menos um item da solicitação tem quantidade a liberar > 0
                $algumItemComQuantidade = false;
                foreach ($mov->itens as $item) {
                    $qtdPedida = $isDevolucao
                        ? (((float) ($item->quantidade_devolvendo ?? 0) > 0) ? (float) $item->quantidade_devolvendo : (float) $item->quantidade_solicitada)
                        : (float) $item->quantidade_solicitada;
                    $qtdLiberar = $quantidadesLiberadas[$item->id] ?? $qtdPedida;
                    if ($qtdLiberar > 0) {
                        $algumItemComQuantidade = true;
                        break;
                    }
                }

                if (!$algumItemComQuantidade) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                        'status' => false,
                        'message' => 'Não é possível aprovar uma movimentação com todos os itens zerados. Rejeite a solicitação se não houver atendimento.'
                    ], 422));
                }

                // --- INÍCIO: Lock Ordenado em Lote para Prevenir Deadlocks ---
                $produtosIds = $mov->itens->pluck('produto_id')->unique()->toArray();
                $setoresIds = array_filter([$mov->setor_origem_id, $mov->setor_destino_id]);

                // 1. Extrair os IDs dos estoques envolvidos e 2. Ordenar
                $estoquesIds = Estoque::whereIn('produto_id', $produtosIds)
                    ->whereIn('setor_id', $setoresIds)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->toArray();

                // 3. Bloquear todos os registros ordenados de uma só vez e
                // 4. Indexar a collection pela chave composta setorId_produtoId para acesso rápido
                $estoquesBloqueados = Estoque::whereIn('id', $estoquesIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(function ($estoque) {
                        return $estoque->setor_id . '_' . $estoque->produto_id;
                    });
                // --- FIM: Lock Ordenado em Lote ---

                // Validar estoque da origem antes de aprovar
                $errosEstoque = [];
                foreach ($mov->itens as $item) {
                    $qtdPedida = $isDevolucao
                        ? (((float) ($item->quantidade_devolvendo ?? 0) > 0) ? (float) $item->quantidade_devolvendo : (float) $item->quantidade_solicitada)
                        : (float) $item->quantidade_solicitada;
                    $qtdLiberar = $quantidadesLiberadas[$item->id] ?? $qtdPedida;

                    // TRAVA 1 — Teto de Solicitação: impede aprovar mais do que foi pedido
                    if ($qtdLiberar > $qtdPedida) {
                        $nomeProduto = $item->produto?->nome ?? "ID {$item->produto_id}";
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                            'status' => false,
                            'message' => "A quantidade aprovada ({$qtdLiberar}) não pode ser maior do que a quantidade solicitada ({$qtdPedida}) para o item {$nomeProduto}."
                        ], 422));
                    }

                    // Se a quantidade for <= 0, não consome estoque e não valida saldo
                    if ($qtdLiberar <= 0) {
                        continue;
                    }

                    // Se for devolução de um setor SEM controle de estoque físico (enfermarias, clínicas),
                    // não há estoque armazenado na origem para debitar; segue direto para recebimento no destino.
                    // Obs.: o Teto de Solicitação (Trava 1) acima permanece obrigatório mesmo neste caso.
                    if ($isDevolucao && !$origemControlaEstoque) {
                        continue;
                    }

                    // Buscar estoque do produto na origem já bloqueado em memória
                    $estoqueOrigem = $estoquesBloqueados->get($mov->setor_origem_id . '_' . $item->produto_id);
 
                    if (!$estoqueOrigem) {
                        $nomeProduto = $item->produto?->nome ?? "ID {$item->produto_id}";
                        $errosEstoque[] = "Produto '{$nomeProduto}' não encontrado no estoque.";
                        continue;
                    }

                    if ($estoqueOrigem->quantidade_atual < $qtdLiberar) {
                        $nomeProduto = $item->produto?->nome ?? "ID {$item->produto_id}";
                        $errosEstoque[] = "Estoque insuficiente para '{$nomeProduto}'. Disponível: {$estoqueOrigem->quantidade_atual}, Solicitado: {$qtdLiberar}.";
                        continue;
                    }

                    // O saldo agregado não basta: os lotes precisam cobrir a quantidade,
                    // senão a baixa FIFO deixaria estoque e lotes divergentes.
                    // Produtos sem nenhum lote no setor seguem só pelo saldo agregado.
                    $erroLote = $this->validarCoberturaDeLotes(
                        $item->produto_id,
                        $mov->setor_origem_id,
                        $qtdLiberar,
                        $item->produto?->nome ?? "ID {$item->produto_id}"
                    );
                    if ($erroLote) {
                        $errosEstoque[] = $erroLote;
                    }
                }

                if (!empty($errosEstoque)) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                        'status' => false,
                        'message' => 'Estoque insuficiente.',
                        'erros' => $errosEstoque
                    ], 422));
                }

                // Atualizar quantidades liberadas e transferir estoque
                foreach ($mov->itens as $item) {
                    $qtdPedida = $isDevolucao
                        ? (((float) ($item->quantidade_devolvendo ?? 0) > 0) ? (float) $item->quantidade_devolvendo : (float) $item->quantidade_solicitada)
                        : (float) $item->quantidade_solicitada;
                    $qtdLiberar = $quantidadesLiberadas[$item->id] ?? $qtdPedida;

                    // Defesa extra: nunca gravar acima do teto de solicitação (camada dupla)
                    $qtdLiberar = min($qtdLiberar, $qtdPedida);

                    // Gravar quantidade aceita exclusivamente em quantidade_liberada,
                    // preservando quantidade_solicitada e quantidade_devolvendo originais
                    $item->quantidade_liberada = $qtdLiberar;
                    $item->save();

                    if ($isDevolucao && $mov->pedido_origem_id && $qtdLiberar > 0) {
                        $itemOrig = ItemMovimentacao::where('movimentacao_id', $mov->pedido_origem_id)
                            ->where('produto_id', $item->produto_id)
                            ->first();
                        if ($itemOrig) {
                            \App\Models\Devolucao::firstOrCreate([
                                'movimentacao_id' => $mov->pedido_origem_id,
                                'item_movimentacao_id' => $itemOrig->id,
                                'lote' => $item->lote,
                                'quantidade' => $qtdLiberar,
                                'usuario_id' => $mov->usuario_id
                            ]);
                        }
                    }

                    if ($qtdLiberar <= 0) continue;

                    Log::info("Processando movimentação (" . ($isDevolucao ? "Devolução" : "Transferência") . ")", [
                        'produto_id' => $item->produto_id,
                        'quantidade' => $qtdLiberar,
                        'origem_id' => $mov->setor_origem_id,
                        'destino_id' => $mov->setor_destino_id
                    ]);

                    // 1. DEDUZIR do estoque de ORIGEM (apenas se a origem controlar estoque físico)
                    if (!$isDevolucao || $origemControlaEstoque) {
                        $estoqueOrigem = $estoquesBloqueados->get($mov->setor_origem_id . '_' . $item->produto_id);

                        if (!$estoqueOrigem) {
                            throw new \Exception("Estoque de origem não encontrado para produto {$item->produto_id} no setor {$mov->setor_origem_id}");
                        }

                        $estoqueOrigem = $this->estoqueService->registrarMovimentacaoContabil(
                            $estoqueOrigem->id,
                            -$qtdLiberar, // Quantidade negativa (Saída/Baixa)
                            $isDevolucao ? 'saida' : 'transferencia',
                            $aprovadorId,
                            $mov->id,
                            'Baixa de itens'
                        );

                        Log::info("Estoque origem atualizado", [
                            'estoque_id' => $estoqueOrigem->id,
                            'nova_quantidade' => $estoqueOrigem->quantidade_atual
                        ]);

                        // 1b. DEDUZIR dos lotes da ORIGEM (FIFO: vencimento mais próximo primeiro)
                        $lotesTransferidos = $this->transferirLotesFifo(
                            $item->produto_id,
                            $mov->setor_origem_id,
                            $mov->setor_destino_id,
                            $qtdLiberar
                        );

                        $item->lote = json_encode($lotesTransferidos);
                        $item->save();
                    }

                    // 2. INCREMENTAR o estoque de DESTINO
                    // Se for devolução de setor sem estoque físico, criamos/incrementamos o lote no destino
                    if ($isDevolucao && !$origemControlaEstoque) {
                        $estoqueDestino = $estoquesBloqueados->get($mov->setor_destino_id . '_' . $item->produto_id);

                        if (!$estoqueDestino) {
                            $estoqueDestino = Estoque::create([
                                'produto_id' => $item->produto_id,
                                'setor_id' => $mov->setor_destino_id,
                                'quantidade_atual' => 0, // Inicia zerado para que o Service controle a entrada
                                'quantidade_minima' => 0,
                                'status_disponibilidade' => 'I'
                            ]);
                        }
                        
                        $estoqueDestino = $this->estoqueService->registrarMovimentacaoContabil(
                            $estoqueDestino->id,
                            $qtdLiberar, // Quantidade positiva (Entrada)
                            $isDevolucao ? 'entrada' : 'transferencia',
                            $aprovadorId,
                            $mov->id,
                            'Recebimento de itens'
                        );

                        // Lotes na Devolução: se informado lote pelo solicitante, usamos ele;
                        // caso contrário, incorporamos ao lote vigente mais recente do destino ou criamos lote de devolução
                        $loteIdentificador = $item->lote;
                        $loteNome = null;
                        $dataVencimento = null;
                        if (!empty($loteIdentificador)) {
                            $parsed = json_decode($loteIdentificador, true);
                            if (is_array($parsed) && isset($parsed[0]['lote'])) {
                                $loteNome = $parsed[0]['lote'];
                                $dataVencimento = $parsed[0]['data_vencimento'] ?? null;
                            } elseif (is_string($loteIdentificador)) {
                                $loteNome = $loteIdentificador;
                            }
                        }

                        if (empty($loteNome)) {
                            $loteExistente = EstoqueLote::where('produto_id', $item->produto_id)
                                ->where('setor_id', $mov->setor_destino_id)
                                ->where('data_vencimento', '>=', now()->toDateString())
                                ->orderBy('data_vencimento', 'desc')
                                ->first();

                            if ($loteExistente) {
                                $loteNome = $loteExistente->lote;
                                $dataVencimento = $loteExistente->data_vencimento;
                            } else {
                                $loteNome = 'DEV-' . date('Ymd');
                                $dataVencimento = now()->addMonths(6)->toDateString();
                            }
                        }

                        $loteDestino = EstoqueLote::firstOrCreate(
                            [
                                'setor_id' => $mov->setor_destino_id,
                                'produto_id' => $item->produto_id,
                                'lote' => $loteNome,
                            ],
                            [
                                'data_vencimento' => $dataVencimento ?? now()->addMonths(6)->toDateString(),
                                'quantidade_disponivel' => 0,
                            ]
                        );
                        $loteDestino->quantidade_disponivel += $qtdLiberar;
                        $loteDestino->save();

                        $item->lote = json_encode([
                            [
                                'lote' => $loteNome,
                                'data_vencimento' => $loteDestino->data_vencimento,
                                'qtd' => $qtdLiberar
                            ]
                        ]);
                        $item->save();
                    } else {
                        // Transferência normal ou devolução de setor COM estoque:
                        // transferirLotesFifo já incrementou o lote do destino se havia lotes.
                        // Atualizamos o saldo agregado de Estoque do destino:
                        $estoqueDestino = $estoquesBloqueados->get($mov->setor_destino_id . '_' . $item->produto_id);

                        if (!$estoqueDestino) {
                            Log::info("Criando novo estoque de destino", [
                                'produto_id' => $item->produto_id,
                                'setor_id' => $mov->setor_destino_id
                            ]);

                            $estoqueDestino = Estoque::create([
                                'produto_id' => $item->produto_id,
                                'setor_id' => $mov->setor_destino_id,
                                'quantidade_atual' => 0, // Inicia zerado
                                'quantidade_minima' => 0,
                                'status_disponibilidade' => 'I'
                            ]);
                        }
                        
                        $estoqueDestino = $this->estoqueService->registrarMovimentacaoContabil(
                            $estoqueDestino->id,
                            $qtdLiberar, // Quantidade positiva (Entrada)
                            $isDevolucao ? 'entrada' : 'transferencia',
                            $aprovadorId,
                            $mov->id,
                            'Recebimento de itens'
                        );
                    }
                }

                $mov->status_solicitacao = 'A';
                $mov->aprovador_usuario_id = auth()->id() ?: ($aprovadorId ?? null);

            } elseif ($action === 'reject') {
                $mov->status_solicitacao = 'R';
                $mov->aprovador_usuario_id = auth()->id() ?: ($aprovadorId ?? null);
            } elseif ($action === 'submit') {
                // sair de rascunho para pendente
                $mov->status_solicitacao = 'P';
            } elseif ($action === 'cancel') {
                Log::info("Entrou no bloco cancel. Status atual: " . $mov->status_solicitacao);
                // solicitante cancelando o pedido pendente
                if ($mov->status_solicitacao !== 'P') {
                    Log::warning("Tentativa de cancelar pedido não pendente. Status: " . $mov->status_solicitacao);
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['status' => false, 'message' => 'Apenas pendentes podem ser canceladas.'], 422));
                }
                $mov->status_solicitacao = 'X'; // X = Cancelado pelo solicitante
                Log::info("Status alterado para X");
            }

            $mov->save();
            Log::info("Movimentação salva com sucesso.");
            
            Log::info("Transação commitada.");

            return response()->json(['status' => true, 'data' => $mov]);
        }, 5);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Erro ao processar: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno.'], 500);
        }
    }

    // Atualizar status (compatibilidade com frontend)
    public function updateStatus(Request $request, $id)
    {
        Log::info("updateStatus chamado para movimentação ID: $id", [
            'payload' => $request->all()
        ]);

        $statusMap = [
            'A' => 'approve',  // Aprovado
            'R' => 'reject',   // Reprovado
            'P' => 'submit',   // Pendente (enviar rascunho)
            'X' => 'cancel'    // Cancelado
        ];

        $status = $request->input('status');
        if (!isset($statusMap[$status])) {
            Log::warning("Status inválido recebido: $status");
            return response()->json([
                'status' => false,
                'message' => "Status '$status' inválido. Use: A (Aprovar), R (Reprovar), P (Pendente), X (Cancelar)"
            ], 422);
        }

        $request->merge([
            'action' => $statusMap[$status],
            'aprovador_usuario_id' => $request->input('aprovador_usuario_id') ?? $request->input('usuario_id')
        ]);

        return $this->process($request, $id);
    }

    // Deletar apenas rascunhos
    public function destroy($id)
    {
        $mov = Movimentacao::find($id);
        if (!$mov) return response()->json(['status' => false, 'message' => 'Movimentação não encontrada'], 404);
        if ($mov->status_solicitacao !== 'C') {
            return response()->json(['status' => false, 'message' => 'Só é possível deletar movimentações em rascunho'], 403);
        }
        $mov->itens()->delete();
        $mov->delete();
        return response()->json(['status' => true]);
    }

    public function update(UpdateRascunhoRequest $request, $id)
    {
        return $this->updateRascunho($request, $id);
    }

    // Atualizar rascunho (apenas movimentações em rascunho podem ser editadas)
    public function updateRascunho(UpdateRascunhoRequest $request, $id)
    {
        $mov = Movimentacao::with('itens')->find($id);
        if (!$mov) {
            return response()->json(['status' => false, 'message' => 'Movimentação não encontrada'], 404);
        }
        if ($mov->status_solicitacao !== 'C') {
            return response()->json(['status' => false, 'message' => 'Apenas pedidos em rascunho podem ser editados.'], 422);
        }

        $data = $request->validated();

        $origemId = $data['setor_origem_id'] ?? $mov->setor_origem_id;
        $destinoId = $mov->setor_destino_id;

        if (in_array($mov->tipo, ['S', 'T'])) {
            if (!empty($origemId) && !empty($destinoId) && $origemId == $destinoId) {
                return response()->json(['status' => false, 'message' => 'O setor de origem não pode ser igual ao setor solicitante/destino.'], 422);
            }
            if (!empty($origemId)) {
                $setorOrigem = \App\Models\Setores::find($origemId);
                if (!$setorOrigem || !$setorOrigem->estoque) {
                    return response()->json(['status' => false, 'message' => 'O setor de origem selecionado não possui controle de estoque para fornecer itens.'], 422);
                }

                if (!empty($destinoId)) {
                    $setorDestino = \App\Models\Setores::find($destinoId);
                    $ehDistribuidorAutorizado = DB::table('setor_distribuidor')
                        ->where('setor_solicitante_id', $destinoId)
                        ->where('setor_distribuidor_id', $origemId)
                        ->exists();

                    $remanejamentoEntreEstoque = ($setorOrigem->estoque && $setorDestino && $setorDestino->estoque);

                    if (!$ehDistribuidorAutorizado && !$remanejamentoEntreEstoque) {
                        return response()->json([
                            'status' => false,
                            'message' => 'O setor de origem não está configurado como distribuidor autorizado para este setor solicitante.'
                        ], 422);
                    }
                }
            }
        }

        try {
            DB::transaction(function () use ($mov, $data) {
                // Atualizar campos da movimentação
                $mov->setor_origem_id = $data['setor_origem_id'] ?? $mov->setor_origem_id;
                $mov->observacao      = $data['observacao'] ?? $mov->observacao;
                if (isset($data['status_solicitacao'])) {
                    $mov->status_solicitacao = $data['status_solicitacao'];
                }
                $mov->save();

                // Substituir itens completamente
                $mov->itens()->delete();
                foreach ($data['itens'] as $it) {
                    // aceitar 'produto_id' ou 'produtoId'
                    $produtoId = $it['produto_id'] ?? ($it['produtoId'] ?? null);
                    $qtd       = $it['quantidade_solicitada'] ?? ($it['quantidade'] ?? 0);
                    if (!$produtoId) continue;

                    ItemMovimentacao::create([
                        'movimentacao_id'      => $mov->id,
                        'produto_id'           => $produtoId,
                        'quantidade_solicitada'=> $qtd,
                        'quantidade_liberada'  => 0,
                        'lote'                 => null,
                    ]);
                }
            });

            return response()->json(['status' => true, 'data' => $mov->fresh('itens.produto', 'setorOrigem', 'setorDestino')]);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar rascunho/pendente: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro ao atualizar pedido. Ocorreu uma falha interna.'], 500);
        }
    }

    /**
     * Pré-visualização dos lotes que serão consumidos (FIFO) ao aprovar a movimentação.
     * Chame este endpoint ANTES de confirmar a aprovação para exibir ao usuário
     * qual lote (o de vencimento mais próximo) será descontado de cada produto.
     *
     * GET /api/movimentacao/{id}/preview-lotes
     */
    public function previewLotes($id)
    {
        $mov = Movimentacao::with('itens.produto')->find($id);
        if (!$mov) {
            return response()->json(['status' => false, 'message' => 'Movimentação não encontrada'], 404);
        }

        $preview = [];

        foreach ($mov->itens as $item) {
            $qtdNecessaria = intval($item->quantidade_solicitada);
            $lotesUsados   = [];
            $restante      = $qtdNecessaria;

            $colunaValidade = \Illuminate\Support\Facades\Schema::hasColumn('estoque_lote', 'data_validade') ? 'data_validade' : 'data_vencimento';

            // Mesma regra da aprovação: só lotes na validade entram no FIFO (validade mais próxima e menor id)
            $lotes = $this->lotesDisponiveisQuery($item->produto_id, $mov->setor_origem_id)
                ->orderBy($colunaValidade, 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $vencidoIgnorado = intval(EstoqueLote::where('produto_id', $item->produto_id)
                ->where('setor_id', $mov->setor_origem_id)
                ->where('quantidade_disponivel', '>', 0)
                ->whereDate('data_vencimento', '<', now()->toDateString())
                ->sum('quantidade_disponivel'));

            foreach ($lotes as $lote) {
                if ($restante <= 0) break;

                $saldoLote = intval($lote->quantidade_disponivel ?? $lote->quantidade ?? 0);
                if ($saldoLote <= 0) continue;

                $qtdUsada = min($saldoLote, $restante);
                $lotesUsados[] = [
                    'lote'                  => $lote->lote,
                    'data_vencimento'       => $lote->data_vencimento,
                    'data_validade'         => $lote->data_vencimento,
                    'data_fabricacao'       => $lote->data_fabricacao,
                    'quantidade_disponivel' => $saldoLote,
                    'quantidade_a_usar'     => intval($qtdUsada),
                ];
                $restante -= $qtdUsada;
            }

            $preview[] = [
                'produto_id'               => $item->produto_id,
                'produto_nome'             => $item->produto?->nome ?? "ID {$item->produto_id}",
                'quantidade_solicitada'    => intval($qtdNecessaria),
                'quantidade_sem_cobertura' => max(0, intval($restante)),
                'quantidade_vencida_ignorada' => $vencidoIgnorado,
                'lotes_a_consumir'         => $lotesUsados,
            ];
        }

        return response()->json(['status' => true, 'data' => $preview]);
    }

    /**
     * Lotes que podem ser consumidos: com saldo e dentro da validade.
     * Lote vencido não é dispensado nem transferido — sai por descarte/baixa.
     */
    private function lotesDisponiveisQuery(int $produtoId, int $setorId)
    {
        return EstoqueLote::where('produto_id', $produtoId)
            ->where('setor_id', $setorId)
            ->where('quantidade_disponivel', '>', 0)
            ->whereDate('data_vencimento', '>=', now()->toDateString());
    }

    /**
     * Verifica se os lotes válidos cobrem a quantidade a liberar.
     *
     * @return string|null Mensagem de erro, ou null quando está tudo certo.
     */
    private function validarCoberturaDeLotes(int $produtoId, int $setorOrigemId, float $qtdLiberar, string $nomeProduto): ?string
    {
        $totalComSaldo = (float) EstoqueLote::where('produto_id', $produtoId)
            ->where('setor_id', $setorOrigemId)
            ->where('quantidade_disponivel', '>', 0)
            ->sum('quantidade_disponivel');

        // Produto sem controle de lote neste setor: segue apenas pelo saldo agregado
        if ($totalComSaldo <= 0) {
            return null;
        }

        $disponivelValido = (float) $this->lotesDisponiveisQuery($produtoId, $setorOrigemId)
            ->sum('quantidade_disponivel');

        if ($disponivelValido >= $qtdLiberar) {
            return null;
        }

        $vencido = $totalComSaldo - $disponivelValido;
        if ($vencido > 0 && $totalComSaldo >= $qtdLiberar) {
            return "Lotes vencidos não podem ser dispensados: '{$nomeProduto}' tem {$disponivelValido} na validade"
                . " (e {$vencido} vencido(s)), mas a movimentação pede {$qtdLiberar}.";
        }

        return "Saldo em lotes insuficiente para '{$nomeProduto}'. Disponível em lotes válidos: {$disponivelValido},"
            . " solicitado: {$qtdLiberar}. Verifique o estoque do produto neste setor.";
    }

    /**
     * Transfere quantidades entre lotes seguindo FIFO (vencimento mais próximo primeiro).
     * Deduz do setor de origem e incrementa no setor de destino (criando o registro se necessário).
     */
    private function transferirLotesFifo(int $produtoId, int $setorOrigemId, ?int $setorDestinoId, float $qtdLiberar): array
    {
        $restante = intval($qtdLiberar);
        $lotesConsumidos = [];

        $colunaValidade = \Illuminate\Support\Facades\Schema::hasColumn('estoque_lote', 'data_validade') ? 'data_validade' : 'data_vencimento';

        $lotes = $this->lotesDisponiveisQuery($produtoId, $setorOrigemId)
            ->orderBy($colunaValidade, 'asc')
            ->orderBy('id', 'asc') // FIFO estrito
            ->lockForUpdate()
            ->get();

        // Sem lote nenhum: produto controlado apenas pelo saldo agregado
        $temLotes = $lotes->isNotEmpty();

        foreach ($lotes as $lote) {
            if ($restante <= 0) break;

            $saldoLote = intval($lote->quantidade_disponivel ?? $lote->quantidade ?? 0);
            if ($saldoLote <= 0) continue;

            $qtdDeducao = min($restante, $saldoLote);

            // Deduzir da origem garantindo que nunca negative nem consuma além da conta
            $lote->quantidade_disponivel = max(0, $saldoLote - $qtdDeducao);
            $lote->save();
            $restante -= $qtdDeducao;
            
            $dataVenc = $lote->data_vencimento;
            if ($dataVenc instanceof \DateTimeInterface) {
                $dataVenc = $dataVenc->format('Y-m-d');
            } elseif (is_string($dataVenc) && strlen($dataVenc) > 10) {
                $dataVenc = substr($dataVenc, 0, 10);
            }

            $lotesConsumidos[] = [
                'lote' => $lote->lote,
                'data_vencimento' => $dataVenc,
                'qtd' => intval($qtdDeducao)
            ];

            Log::info('EstoqueLote origem descontado', [
                'lote'            => $lote->lote,
                'data_vencimento' => $lote->data_vencimento,
                'qtd_deduzida'    => $qtdDeducao,
                'qtd_restante'    => $lote->quantidade_disponivel,
            ]);

            // Incrementar no destino (apenas transferências com destino definido)
            if ($setorDestinoId) {
                $loteDestino = EstoqueLote::firstOrCreate(
                    [
                        'setor_id' => $setorDestinoId,
                        'produto_id' => $produtoId,
                        'lote'       => $lote->lote,
                    ],
                    [
                        'data_vencimento'       => $lote->data_vencimento,
                        'data_fabricacao'       => $lote->data_fabricacao,
                        'quantidade_disponivel' => 0,
                    ]
                );
                $loteDestino->quantidade_disponivel = intval($loteDestino->quantidade_disponivel) + intval($qtdDeducao);
                $loteDestino->save();

                Log::info('EstoqueLote destino incrementado', [
                    'lote'            => $lote->lote,
                    'data_vencimento' => $lote->data_vencimento,
                    'qtd_adicionada'  => $qtdDeducao,
                    'qtd_total'       => $loteDestino->quantidade_disponivel,
                ]);
            }
        }

        // Rede de segurança: se os lotes não cobriram tudo, o saldo agregado já foi
        // debitado e estoque/lotes ficariam divergentes. Aborta a transação inteira.
        if ($temLotes && $restante > 0) {
            throw new \RuntimeException(
                "Lotes insuficientes para o produto {$produtoId} no setor {$setorOrigemId}: "
                . "faltaram {$restante} unidade(s) com validade vigente."
            );
        }

        return $lotesConsumidos;
    }
    public function consumoInterno(ConsumoInternoRequest $request)
    {
        $data = $request->validated();

        $userId = auth()->id();
        $setorId = $data['setor_id'];
        
        $hasAccess = DB::table('usuario_setor')
            ->where('usuario_id', $userId)
            ->where('setor_id', $setorId)
            ->exists();
            
        if (!$hasAccess) {
            return response()->json([
                'status' => false,
                'message' => 'Usuário sem permissão neste setor.'
            ], 403);
        }

        $produtoId = $data['produto_id'];
        $lote = $data['lote'];
        $qtdConsumir = intval($data['quantidade']);
        $observacao = $data['observacao'] ?? null;

        try {
            DB::beginTransaction();

            $estoqueLote = EstoqueLote::where('produto_id', $produtoId)
                ->where('lote', $lote)
                ->where('setor_id', $setorId)
                ->lockForUpdate()
                ->first();

            if (!$estoqueLote) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Lote não encontrado para este setor.'
                ], 403);
            }

            if ($estoqueLote->quantidade_disponivel < $qtdConsumir) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Saldo insuficiente no lote selecionado.',
                    'erros' => [
                        'quantidade' => ['Saldo disponível: ' . $estoqueLote->quantidade_disponivel]
                    ]
                ], 422);
            }

            $estoqueLote->quantidade_disponivel -= $qtdConsumir;
            $estoqueLote->save();

            $estoque = Estoque::where('produto_id', $produtoId)
                ->where('setor_id', $setorId)
                ->lockForUpdate()
                ->first();

            if ($estoque) {
                $estoque->quantidade_atual -= $qtdConsumir;
                $estoque->status_disponibilidade = $estoque->quantidade_atual > 0 ? 'D' : 'I';
                $estoque->save();
            }

            $mov = Movimentacao::create([
                'usuario_id' => $userId,
                'setor_origem_id' => $setorId,
                'setor_destino_id' => $setorId,
                'tipo' => 'C',
                'data_hora' => now(),
                'observacao' => 'Baixa Interna/Consumo: ' . $observacao,
                'status_solicitacao' => 'A',
                'aprovador_usuario_id' => $userId
            ]);

            ItemMovimentacao::create([
                'movimentacao_id' => $mov->id,
                'produto_id' => $produtoId,
                'quantidade_solicitada' => $qtdConsumir,
                'quantidade_liberada' => $qtdConsumir,
                'lote' => json_encode([
                    ['lote' => $lote, 'quantidade' => $qtdConsumir]
                ])
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Consumo interno registrado com sucesso!',
                'data' => $mov
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro em consumoInterno: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao processar consumo interno.'
            ], 500);
        }
    }
    public function devolver(DevolverRequest $request, $id)
    {
        $data = $request->validated();

        // 1. Fluxo de devolução direta por lote individual
        if (!$request->has('itens')) {
            $movimentacao = Movimentacao::with('itens')->find($id);
            if (!$movimentacao) {
                return response()->json(['status' => false, 'message' => 'Movimentação não encontrada.'], 404);
            }

            $itemMov = $movimentacao->itens->where('id', $data['item_movimentacao_id'])->first();
            if (!$itemMov) {
                return response()->json(['status' => false, 'message' => 'Item não pertence a esta movimentação.'], 422);
            }

            $quantidadeADevolver = (int) $data['quantidade'];
            $loteNome = $data['lote'];
            $userId = auth()->id() ?: ($movimentacao->usuario_id ?? null);
            $motivo = $data['motivo'] ?? null;

            $lotesOriginal = json_decode($itemMov->lote, true);
            if (!is_array($lotesOriginal)) {
                 return response()->json(['status' => false, 'message' => 'Lote original inválido no item.'], 422);
            }

            $qtdOriginalNoLote = 0;
            foreach ($lotesOriginal as $lot) {
                if (($lot['lote'] ?? null) === $loteNome) {
                    $qtdOriginalNoLote = (float) ($lot['quantidade'] ?? $lot['qtd'] ?? 0);
                    break;
                }
            }

            if ($qtdOriginalNoLote <= 0) {
                return response()->json(['status' => false, 'message' => 'Este lote não foi utilizado no atendimento deste item.'], 422);
            }

            try {
                DB::beginTransaction();

                $jaDevolvida = \App\Models\Devolucao::where('item_movimentacao_id', $itemMov->id)
                    ->where('lote', $loteNome)
                    ->sum('quantidade');

                if ($quantidadeADevolver + $jaDevolvida > $qtdOriginalNoLote) {
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'message' => 'Quantidade a devolver excede a quantidade atendida no pedido para este lote.',
                        'erros' => [
                            'quantidade' => ['Quantidade máxima permitida: ' . ($qtdOriginalNoLote - $jaDevolvida)]
                        ]
                    ], 422);
                }

                // O estoque/lote pertence ao setor_origem_id da movimentacao (o distribuidor)
                $estoqueLote = \App\Models\EstoqueLote::firstOrCreate(
                    [
                        'setor_id' => $movimentacao->setor_origem_id,
                        'produto_id' => $itemMov->produto_id,
                        'lote' => $loteNome,
                    ],
                    [
                        'quantidade_disponivel' => 0
                    ]
                );

                $estoqueLote = \App\Models\EstoqueLote::where('id', $estoqueLote->id)->lockForUpdate()->first();
                $estoqueLote->quantidade_disponivel += $quantidadeADevolver;
                $estoqueLote->save();

                $estoque = \App\Models\Estoque::where('produto_id', $itemMov->produto_id)
                    ->where('setor_id', $movimentacao->setor_origem_id)
                    ->lockForUpdate()
                    ->first();

                if ($estoque) {
                    $estoque->quantidade_atual += $quantidadeADevolver;
                    $estoque->status_disponibilidade = $estoque->quantidade_atual > 0 ? 'D' : 'I';
                    $estoque->save();
                } else {
                    \App\Models\Estoque::create([
                        'produto_id' => $itemMov->produto_id,
                        'setor_id' => $movimentacao->setor_origem_id,
                        'quantidade_atual' => $quantidadeADevolver,
                        'quantidade_minima' => 0,
                        'status_disponibilidade' => 'D'
                    ]);
                }

                \App\Models\Devolucao::create([
                    'movimentacao_id' => $movimentacao->id,
                    'item_movimentacao_id' => $itemMov->id,
                    'lote' => $loteNome,
                    'quantidade' => $quantidadeADevolver,
                    'motivo' => $motivo,
                    'usuario_id' => $userId
                ]);

                DB::commit();

                return response()->json([
                    'status' => true,
                    'message' => 'Devolução registrada com sucesso!'
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Erro em devolver: ' . $e->getMessage());
                return response()->json([
                    'status' => false,
                    'message' => 'Erro ao processar devolução.'
                ], 500);
            }
        }

        // 2. Fluxo de devolução múltipla (em lote de itens do pedido)
        // Filtrar apenas itens com quantidade_devolvendo > 0
        $itensParaDevolver = array_filter($data['itens'] ?? [], function ($reqItem) {
            return isset($reqItem['quantidade_devolvendo']) && (int) $reqItem['quantidade_devolvendo'] > 0;
        });

        if (empty($itensParaDevolver)) {
            return response()->json([
                'status' => false,
                'message' => 'Informe a quantidade (mínimo 1) para ao menos um item a ser devolvido.'
            ], 422);
        }

        $movimentacaoOriginal = Movimentacao::with('itens')->find($id);
        if (!$movimentacaoOriginal) {
            return response()->json(['status' => false, 'message' => 'Movimentação original não encontrada.'], 404);
        }

        try {
            DB::beginTransaction();

            $userId = auth()->id() ?: ($movimentacaoOriginal->usuario_id ?? null);
            $motivo = $request->input('motivo');
            $obs = 'Devolução originada do pedido #' . $movimentacaoOriginal->id . ($motivo ? ' - ' . $motivo : '');

            // 1. Validar teto de devolução para cada item antes de registrar
            foreach ($itensParaDevolver as $reqItem) {
                $itemOriginal = $movimentacaoOriginal->itens->where('id', $reqItem['item_movimentacao_id'])->first();
                if (!$itemOriginal) continue;

                $qtdDevolvendo = (int) $reqItem['quantidade_devolvendo'];

                // Total já devolvido registrado na tabela 'devolucoes'
                $jaDevolvidoTabela = (float) \App\Models\Devolucao::where('item_movimentacao_id', $itemOriginal->id)->sum('quantidade');

                // Total já devolvido em movimentações tipo 'D' anteriores (aprovadas ou pendentes)
                $movDevolucoesIds = \App\Models\Movimentacao::where('tipo', 'D')
                    ->whereIn('status_solicitacao', ['A', 'P'])
                    ->where(function ($q) use ($movimentacaoOriginal) {
                        $q->where('observacao', 'like', '%pedido #' . $movimentacaoOriginal->id . '%')
                          ->orWhere('observacao', 'like', '%pedido ' . $movimentacaoOriginal->id . '%');
                    })
                    ->pluck('id');

                $jaDevolvidoMov = 0;
                if ($movDevolucoesIds->isNotEmpty()) {
                    $jaDevolvidoMov = (float) \App\Models\ItemMovimentacao::whereIn('movimentacao_id', $movDevolucoesIds)
                        ->where('produto_id', $itemOriginal->produto_id)
                        ->sum(\Illuminate\Support\Facades\DB::raw('CASE WHEN quantidade_devolvendo > 0 THEN quantidade_devolvendo ELSE quantidade_liberada END'));
                }

                $totalJaDevolvido = max($jaDevolvidoTabela, $jaDevolvidoMov);
                $saldoDisponivel = max(0, (float) $itemOriginal->quantidade_liberada - $totalJaDevolvido);

                if ($qtdDevolvendo > $saldoDisponivel) {
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'message' => "A quantidade devolvida ({$qtdDevolvendo}) não pode superar o saldo atendido disponível ({$saldoDisponivel})."
                    ], 422);
                }
            }

            // A devolução inverte a origem e destino
            $mov = Movimentacao::create([
                'usuario_id' => $userId,
                'setor_origem_id' => $movimentacaoOriginal->setor_destino_id,
                'setor_destino_id' => $movimentacaoOriginal->setor_origem_id,
                'tipo' => 'D',
                'data_hora' => now(),
                'observacao' => $obs,
                'status_solicitacao' => 'P',
                'aprovador_usuario_id' => null,
            ]);

            foreach ($itensParaDevolver as $reqItem) {
                $itemOriginal = $movimentacaoOriginal->itens->where('id', $reqItem['item_movimentacao_id'])->first();
                if (!$itemOriginal) continue;

                $qtdDevolvendo = (int) $reqItem['quantidade_devolvendo'];

                \App\Models\ItemMovimentacao::create([
                    'movimentacao_id' => $mov->id,
                    'produto_id' => $itemOriginal->produto_id,
                    'quantidade_solicitada' => $qtdDevolvendo,
                    'quantidade_liberada' => 0,
                    'quantidade_devolvendo' => $qtdDevolvendo,
                    'lote' => $itemOriginal->lote,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Solicitação de devolução criada com sucesso e aguarda aprovação!'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro em devolver: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao processar criação da devolução.'
            ], 500);
        }
    }
}
