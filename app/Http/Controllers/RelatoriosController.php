<?php

namespace App\Http\Controllers;

use App\Models\Entrada;
use App\Models\Movimentacao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use \Illuminate\Support\Facades\DB;

class RelatoriosController extends Controller
{
    /**
     * Relatório de Entradas
     * POST /api/relatorios/entradas/list
     * 
     * Retorna lista paginada de entradas com filtros por:
     * - Data inicial/final
     * - Setor
     * - Fornecedor
     * - Nota fiscal
     */
    public function listEntradasReport(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.fornecedor_id' => 'nullable|exists:fornecedores,id',
                'filters.nota_fiscal' => 'nullable|string|max:255',
            ], [
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.fornecedor_id.exists' => 'Fornecedor não encontrado.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Query base com eager loading para evitar N+1
            $query = Entrada::with([
                'fornecedor:id,razao_social_nome,cnpj',
                'setor:id,nome,tipo,polo_id',
                'setor.polo:id,nome',
                'itens.produto:id,nome,codigo_simpas,codigo_barras,grupo_produto_id,unidade_medida_id',
                'itens.produto.unidadeMedida:id,nome',
                'itens.produto.grupoProduto:id,nome,tipo'
            ]);

            // Restringir aos setores que o usuário autenticado tem acesso.
            // Super admin enxerga todos os setores (sem restrição).
            $user = auth()->user();
            if (!$user->isSuperAdmin()) {
                $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                    ->where('usuario_id', $user->id)
                    ->pluck('setor_id');
                $query->whereIn('setor_id', $setoresPermitidos);
            }

            // Aplicar filtros se fornecidos
            $filters = $data['filters'] ?? [];

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            if (!empty($filters['polo_id'])) {
                $query->whereHas('setor', function ($q) use ($filters) {
                    $q->where('polo_id', $filters['polo_id']);
                });
            }

            if (!empty($filters['setor_id'])) {
                $query->where('setor_id', $filters['setor_id']);
            }

            if (!empty($filters['fornecedor_id'])) {
                $query->where('fornecedor_id', $filters['fornecedor_id']);
            }

            if (!empty($filters['nota_fiscal'])) {
                $query->where('nota_fiscal', 'like', '%' . mb_strtoupper($filters['nota_fiscal']) . '%');
            }

            // Ordenação: mais recentes primeiro
            $query->orderByDesc('created_at');

            // Buscar todos os resultados
            $results = $query->get();

            $user = auth()->user();
            $isSuperAdmin = $user ? $user->isSuperAdmin() : false;
            
            $setoresFinanceiro = collect();
            if ($user && !$isSuperAdmin) {
                $setoresFinanceiro = \Illuminate\Support\Facades\DB::table('usuario_setor')
                    ->where('usuario_id', $user->id)
                    ->whereIn('perfil', ['admin', 'almoxarife'])
                    ->pluck('setor_id');
            }

            $results = $results->map(function ($entrada) use ($user, $isSuperAdmin, $setoresFinanceiro) {
                $podeVer = false;
                if ($user && $entrada->setor) {
                    $podeVer = $isSuperAdmin || ($entrada->setor->isCAF() && $setoresFinanceiro->contains($entrada->setor_id));
                }
                $valorTotalNota = 0;
                $temItensComValor = false;

                if ($entrada->itens) {
                    $entrada->itens->transform(function ($item) use ($podeVer, &$valorTotalNota, &$temItensComValor) {
                        if ($podeVer && $item->valor_unitario !== null) {
                            $subtotal = round((float) $item->valor_unitario * (float) $item->quantidade, 2);
                            $item->subtotal = $subtotal;
                            $valorTotalNota += $subtotal;
                            $temItensComValor = true;
                        } else {
                            $item->valor_unitario = null;
                            $item->subtotal = null;
                        }
                        return $item;
                    });
                }

                $entrada->pode_ver_valores = $podeVer;
                $entrada->valor_total_nota = ($podeVer && $temItensComValor) ? round($valorTotalNota, 2) : null;

                return $entrada;
            });

            $totalFinanceiroEntradas = $results->where('pode_ver_valores', true)->sum('valor_total_nota');

            return response()->json([
                'status' => true,
                'message' => 'Relatório de entradas recuperado com sucesso',
                'data' => $results,
                'totalizadores' => [
                    'total_entradas' => $results->count(),
                    'valor_total_entradas' => round($totalFinanceiroEntradas, 2),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de entradas: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de entradas'
            ], 500);
        }
    }

    /**
     * Extrai um array de códigos de lotes a partir de uma string ou estrutura bruta de lote.
     * Suporta string simples, string JSON serializada ou objeto/array.
     *
     * @param mixed $loteRaw
     * @return array
     */
    private function extrairCodigosLote($loteRaw): array
    {
        if (empty($loteRaw)) {
            return [];
        }

        if (is_array($loteRaw)) {
            $codigos = [];
            foreach ($loteRaw as $entry) {
                if (is_array($entry)) {
                    $cod = $entry['lote'] ?? $entry['numero_lote'] ?? null;
                    if ($cod) $codigos[] = trim((string)$cod);
                } elseif (is_object($entry)) {
                    $cod = $entry->lote ?? $entry->numero_lote ?? null;
                    if ($cod) $codigos[] = trim((string)$cod);
                } elseif (is_string($entry) || is_numeric($entry)) {
                    $codigos[] = trim((string)$entry);
                }
            }
            return array_values(array_unique(array_filter($codigos)));
        }

        $str = trim((string)$loteRaw);
        if ($str === '' || $str === '-' || strtolower($str) === 'null') {
            return [];
        }

        // Se for JSON serializado
        if (str_starts_with($str, '[') || str_starts_with($str, '{')) {
            $decoded = json_decode($str, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($decoded)) {
                return $this->extrairCodigosLote($decoded);
            }
        }

        // Remover prefixos como "Lote: " e sufixos como "(Validade: ...)"
        $limpo = preg_replace('/\s*\([Vv]alidade:?[^)]*\)/i', '', $str);
        $limpo = preg_replace('/^[Ll]ote:\s*/i', '', $limpo);

        // Se houver vírgula separando vários lotes
        $partes = explode(',', $limpo);
        $codigos = [];
        foreach ($partes as $p) {
            $p = trim($p);
            if ($p !== '' && $p !== '-') {
                $codigos[] = $p;
            }
        }

        return array_values(array_unique($codigos));
    }

    /**
     * Sanitiza a exibição de lote para formato legível (apenas códigos separados por vírgula).
     *
     * @param mixed $loteRaw
     * @return string
     */
    private function sanitizarLoteExibicao($loteRaw): string
    {
        $codigos = $this->extrairCodigosLote($loteRaw);
        if (empty($codigos)) {
            return '-';
        }
        return implode(', ', $codigos);
    }

    /**
     * Relatório de Movimentações
     * POST /api/relatorios/movimentacoes/list
     * 
     * Retorna lista paginada de movimentações com filtros por:
     * - Data inicial/final
     * - Tipo (transferencia, saida, devolucao)
     * - Setor origem
     * - Setor destino
     * - Status
     */
    public function listMovimentacoesReport(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.tipo' => 'nullable|string|in:T,S,D',
                'filters.setor_origem_id' => 'nullable|exists:setores,id',
                'filters.setor_destino_id' => 'nullable|exists:setores,id',
                'filters.status' => 'nullable|string|in:A,I',
            ], [
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.tipo.in' => 'Tipo de movimentação inválido. Use: T (Transferência), S (Saída) ou D (Devolução).',
                'filters.setor_origem_id.exists' => 'Setor de origem não encontrado.',
                'filters.setor_destino_id.exists' => 'Setor de destino não encontrado.',
                'filters.status.in' => 'Status inválido. Use: A (Ativo) ou I (Inativo).',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Query base com eager loading para evitar N+1
            $query = Movimentacao::with([
                'setorOrigem:id,nome,tipo,polo_id',
                'setorOrigem.polo:id,nome',
                'setorDestino:id,nome,tipo,polo_id',
                'setorDestino.polo:id,nome',
                'usuario:id,name,email',
                'aprovador:id,name,email',
                'itens.produto:id,nome,codigo_simpas,codigo_barras,grupo_produto_id,unidade_medida_id',
                'itens.produto.unidadeMedida:id,nome',
                'itens.produto.grupoProduto:id,nome,tipo'
            ]);

            // Restringir aos setores que o usuário autenticado tem acesso.
            // Super admin enxerga todos os setores (sem restrição).
            $user = auth()->user();
            if (!$user->isSuperAdmin()) {
                $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                    ->where('usuario_id', $user->id)
                    ->pluck('setor_id');
                $query->where(function ($q) use ($setoresPermitidos) {
                    $q->whereIn('setor_origem_id', $setoresPermitidos)
                      ->orWhereIn('setor_destino_id', $setoresPermitidos);
                });
            }

            // Aplicar filtros se fornecidos
            $filters = $data['filters'] ?? [];

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            if (!empty($filters['tipo'])) {
                $query->where('tipo', $filters['tipo']);
            }

            // Filtro de polo: considera origem OU destino
            if (!empty($filters['polo_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->whereHas('setorOrigem', function ($sq) use ($filters) {
                        $sq->where('polo_id', $filters['polo_id']);
                    })->orWhereHas('setorDestino', function ($sq) use ($filters) {
                        $sq->where('polo_id', $filters['polo_id']);
                    });
                });
            }

            // Filtro de setor: considera origem OU destino
            if (!empty($filters['setor_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('setor_origem_id', $filters['setor_id'])
                      ->orWhere('setor_destino_id', $filters['setor_id']);
                });
            }

            if (!empty($filters['setor_origem_id'])) {
                $query->where('setor_origem_id', $filters['setor_origem_id']);
            }

            if (!empty($filters['setor_destino_id'])) {
                $query->where('setor_destino_id', $filters['setor_destino_id']);
            }

            if (!empty($filters['status'])) {
                $query->where('status_solicitacao', $filters['status']);
            }

            // Ordenação: mais recentes primeiro
            $query->orderByDesc('created_at');

            // Buscar todos os resultados
            $results = $query->get();

            // BATCH LOADING DE LOTES (Eliminando N+1 e decodificando lotes)
            $produtosIds = [];
            $lotes = [];
            $setoresIds = [];
            
            foreach ($results as $mov) {
                if ($mov->setor_destino_id) {
                    $setoresIds[] = $mov->setor_destino_id;
                }
                if ($mov->setor_origem_id) {
                    $setoresIds[] = $mov->setor_origem_id;
                }
                foreach ($mov->itens as $item) {
                    if ($item->lote) {
                        $codigos = $this->extrairCodigosLote($item->lote);
                        if (!empty($codigos)) {
                            $produtosIds[] = $item->produto_id;
                            foreach ($codigos as $cod) {
                                $lotes[] = $cod;
                            }
                        }
                    }
                }
            }
            
            $lotesPreloaded = collect();
            if (!empty($produtosIds) && !empty($lotes) && !empty($setoresIds)) {
                $produtosIds = array_unique($produtosIds);
                $lotes = array_unique($lotes);
                $setoresIds = array_unique($setoresIds);
                
                $lotesPreloaded = \App\Models\EstoqueLote::whereIn('produto_id', $produtosIds)
                    ->whereIn('lote', $lotes)
                    ->whereIn('setor_id', $setoresIds)
                    ->get(['produto_id', 'lote', 'setor_id', 'data_fabricacao', 'data_vencimento'])
                    ->groupBy(function($item) {
                        return $item->produto_id . '-' . $item->lote;
                    });
            }

            // Enriquecer os itens com informações de lote (data_fabricacao e data_vencimento)
            // e higienizar a string de lote
            $results->each(function ($movimentacao) use ($lotesPreloaded) {
                $movimentacao->itens->each(function ($item) use ($movimentacao, $lotesPreloaded) {
                    $codigos = $this->extrairCodigosLote($item->lote);
                    $item->lote = !empty($codigos) ? implode(', ', $codigos) : ($item->lote ? $this->sanitizarLoteExibicao($item->lote) : '-');

                    $validades = [];
                    $fabricacoes = [];
                    
                    if (!empty($codigos)) {
                        foreach ($codigos as $cod) {
                            $key = $item->produto_id . '-' . $cod;
                            $matches = $lotesPreloaded->get($key);
                            if ($matches && $matches->isNotEmpty()) {
                                $match = $matches->firstWhere('setor_id', $movimentacao->setor_destino_id)
                                      ?? $matches->firstWhere('setor_id', $movimentacao->setor_origem_id)
                                      ?? $matches->first();
                                if ($match) {
                                    if ($match->data_vencimento) {
                                        $validades[] = date('d/m/Y', strtotime($match->data_vencimento));
                                    }
                                    if ($match->data_fabricacao) {
                                        $fabricacoes[] = date('d/m/Y', strtotime($match->data_fabricacao));
                                    }
                                }
                            }
                        }
                    }

                    $item->data_vencimento = !empty($validades) ? implode(', ', array_unique($validades)) : null;
                    $item->data_fabricacao = !empty($fabricacoes) ? implode(', ', array_unique($fabricacoes)) : null;
                });
            });

            return response()->json([
                'status' => true,
                'message' => 'Relatório de movimentações recuperado com sucesso',
                'data' => $results,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de movimentações: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de movimentações'
            ], 500);
        }
    }

    /**
     * Relatório de Saídas
     * POST /api/relatorios/saidas/list
     * 
     * Retorna lista paginada de movimentações do tipo 'S' (Saída) com filtros por:
     * - Data inicial/final (usando campo data_hora)
     * - Setor origem (quem solicitou a saída)
     * - Produto
     * - Status da solicitação
     */
    public function listSaidasReport(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.tipo' => 'nullable|string|in:S,T',
                'filters.setor_origem_id' => 'nullable|exists:setores,id',
                'filters.produto_id' => 'nullable|exists:produtos,id',
                'filters.status' => 'nullable|string|in:A,R,P,C,X',
            ], [
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.tipo.in' => 'Tipo inválido. Use: S (Saída) ou T (Transferência).',
                'filters.setor_origem_id.exists' => 'Setor de origem não encontrado.',
                'filters.produto_id.exists' => 'Produto não encontrado.',
                'filters.status.in' => 'Status inválido. Use: A (Aprovado), R (Reprovado), P (Pendente), C (Rascunho), X (Cancelado).',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Aplicar filtros se fornecidos
            $filters = $data['filters'] ?? [];

            // Query base com eager loading para evitar N+1
            // Contempla saídas operacionais ('S') e transferências setoriais ('T')
            $query = Movimentacao::with([
                'setorOrigem:id,nome,tipo,polo_id',
                'setorOrigem.polo:id,nome',
                'setorDestino:id,nome,tipo,polo_id',
                'setorDestino.polo:id,nome',
                'usuario:id,name,email',
                'aprovador:id,name,email',
                'itens.produto:id,nome,codigo_simpas,codigo_barras,grupo_produto_id,unidade_medida_id',
                'itens.produto.unidadeMedida:id,nome',
                'itens.produto.grupoProduto:id,nome,tipo'
            ]);

            if (!empty($filters['tipo'])) {
                $query->where('tipo', $filters['tipo']);
            } else {
                $query->whereIn('tipo', ['S', 'T']);
            }

            // Restringir aos setores que o usuário autenticado tem acesso.
            // Super admin enxerga todos os setores (sem restrição).
            $user = auth()->user();
            if (!$user->isSuperAdmin()) {
                $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                    ->where('usuario_id', $user->id)
                    ->pluck('setor_id');
                $query->where(function ($q) use ($setoresPermitidos) {
                    $q->whereIn('setor_origem_id', $setoresPermitidos)
                      ->orWhereIn('setor_destino_id', $setoresPermitidos);
                });
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('data_hora', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('data_hora', '<=', $filters['date_to']);
            }

            // Filtro de polo: considera origem OU destino
            if (!empty($filters['polo_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->whereHas('setorOrigem', function ($sq) use ($filters) {
                        $sq->where('polo_id', $filters['polo_id']);
                    })->orWhereHas('setorDestino', function ($sq) use ($filters) {
                        $sq->where('polo_id', $filters['polo_id']);
                    });
                });
            }

            // Filtro de setor: considera origem OU destino
            if (!empty($filters['setor_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('setor_origem_id', $filters['setor_id'])
                      ->orWhere('setor_destino_id', $filters['setor_id']);
                });
            }

            if (!empty($filters['setor_origem_id'])) {
                $query->where('setor_origem_id', $filters['setor_origem_id']);
            }

            if (!empty($filters['produto_id'])) {
                // Filtrar por produto através da relação com itens
                $query->whereHas('itens', function ($q) use ($filters) {
                    $q->where('produto_id', $filters['produto_id']);
                });
            }

            if (!empty($filters['status'])) {
                $query->where('status_solicitacao', $filters['status']);
            }

            // Ordenação: mais recentes primeiro
            $query->orderByDesc('data_hora');

            // Buscar todos os resultados
            $results = $query->get();

            // BATCH LOADING DE LOTES (Eliminando N+1 e decodificando lotes)
            $produtosIds = [];
            $lotes = [];
            $setoresIds = [];
            
            foreach ($results as $mov) {
                if ($mov->setor_destino_id) {
                    $setoresIds[] = $mov->setor_destino_id;
                }
                if ($mov->setor_origem_id) {
                    $setoresIds[] = $mov->setor_origem_id;
                }
                foreach ($mov->itens as $item) {
                    if ($item->lote) {
                        $codigos = $this->extrairCodigosLote($item->lote);
                        if (!empty($codigos)) {
                            $produtosIds[] = $item->produto_id;
                            foreach ($codigos as $cod) {
                                $lotes[] = $cod;
                            }
                        }
                    }
                }
            }
            
            $lotesPreloaded = collect();
            if (!empty($produtosIds) && !empty($lotes) && !empty($setoresIds)) {
                $produtosIds = array_unique($produtosIds);
                $lotes = array_unique($lotes);
                $setoresIds = array_unique($setoresIds);
                
                $lotesPreloaded = \App\Models\EstoqueLote::whereIn('produto_id', $produtosIds)
                    ->whereIn('lote', $lotes)
                    ->whereIn('setor_id', $setoresIds)
                    ->get(['produto_id', 'lote', 'setor_id', 'data_fabricacao', 'data_vencimento'])
                    ->groupBy(function($item) {
                        return $item->produto_id . '-' . $item->lote;
                    });
            }

            // Enriquecer os itens com informações de lote (data_fabricacao e data_vencimento)
            // e higienizar a string de lote
            $results->each(function ($movimentacao) use ($lotesPreloaded) {
                $movimentacao->itens->each(function ($item) use ($movimentacao, $lotesPreloaded) {
                    $codigos = $this->extrairCodigosLote($item->lote);
                    $item->lote = !empty($codigos) ? implode(', ', $codigos) : ($item->lote ? $this->sanitizarLoteExibicao($item->lote) : '-');

                    $validades = [];
                    $fabricacoes = [];
                    
                    if (!empty($codigos)) {
                        foreach ($codigos as $cod) {
                            $key = $item->produto_id . '-' . $cod;
                            $matches = $lotesPreloaded->get($key);
                            if ($matches && $matches->isNotEmpty()) {
                                $match = $matches->firstWhere('setor_id', $movimentacao->setor_origem_id)
                                      ?? $matches->firstWhere('setor_id', $movimentacao->setor_destino_id)
                                      ?? $matches->first();
                                if ($match) {
                                    if ($match->data_vencimento) {
                                        $validades[] = date('d/m/Y', strtotime($match->data_vencimento));
                                    }
                                    if ($match->data_fabricacao) {
                                        $fabricacoes[] = date('d/m/Y', strtotime($match->data_fabricacao));
                                    }
                                }
                            }
                        }
                    }

                    $item->data_vencimento = !empty($validades) ? implode(', ', array_unique($validades)) : null;
                    $item->data_fabricacao = !empty($fabricacoes) ? implode(', ', array_unique($fabricacoes)) : null;
                });
            });

            return response()->json([
                'status' => true,
                'message' => 'Relatório de saídas recuperado com sucesso',
                'data' => $results,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de saídas: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de saídas'
            ], 500);
        }
    }

    /**
     * Relatório de Saídas por Data (Agregado por Produto)
     * POST /api/relatorios/saidas-por-data/list
     * 
     * Retorna lista consolidada de saídas agrupadas por data e produto.
     * Calcula a quantidade total de cada produto que saiu em cada dia,
     * somando todas as requisições de todos os setores.
     * 
     * Exemplo: Se saiu 10 dipironas para UTI e 15 para Emergência no mesmo dia,
     * o relatório mostrará 25 dipironas no total.
     * 
     * Filtros opcionais:
     * - Data inicial/final (se não informado, usa data atual)
     * - Setor origem (para filtrar por setor específico)
     * - Produto (para filtrar produto específico)
     */
    public function listSaidasPorData(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.produto_id' => 'nullable|exists:produtos,id',
            ], [
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.produto_id.exists' => 'Produto não encontrado.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            $filters = $data['filters'] ?? [];
            
            // Se não informar período, usa data atual
            $dateFrom = $filters['date_from'] ?? date('Y-m-d');
            $dateTo = $filters['date_to'] ?? $dateFrom;

            // Restringir aos setores que o usuário autenticado tem acesso.
            $user = auth()->user();
            $isSuperAdmin = $user->isSuperAdmin();
            $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                ->where('usuario_id', $user->id)
                ->pluck('setor_id')
                ->toArray();

            // Query agregada UNIFICADA no Banco de Dados (S + T + D)
            // Abatimento líquido feito no MySQL/PostgreSQL usando agregações condicionais.
            $query = DB::table('item_movimentacao as im')
                ->join('movimentacao as m', 'im.movimentacao_id', '=', 'm.id')
                ->join('produtos as p', 'im.produto_id', '=', 'p.id')
                ->join('unidade_medida as um', 'p.unidade_medida_id', '=', 'um.id')
                ->join('grupo_produto as gp', 'p.grupo_produto_id', '=', 'gp.id')
                ->select(
                    DB::raw('DATE(m.data_hora) as data'),
                    'p.id as produto_id',
                    'p.nome as produto_nome',
                    'p.codigo_simpas',
                    'p.codigo_barras',
                    'um.nome as unidade_medida',
                    'gp.nome as grupo_produto',
                    'gp.tipo as tipo_produto',
                    // Agregação Condicional SQL:
                    DB::raw("SUM(CASE WHEN m.tipo IN ('S', 'T') THEN im.quantidade_liberada ELSE 0 END) as quantidade_saida_bruta"),
                    DB::raw("SUM(CASE WHEN m.tipo = 'D' THEN im.quantidade_liberada ELSE 0 END) as quantidade_devolvida"),
                    DB::raw("SUM(CASE WHEN m.tipo = 'D' THEN -im.quantidade_liberada ELSE im.quantidade_liberada END) as consumo_liquido"),
                    DB::raw("COUNT(DISTINCT CASE WHEN m.tipo IN ('S', 'T') THEN m.id END) as total_movimentacoes")
                )
                ->whereIn('m.tipo', ['S', 'T', 'D'])
                ->where('m.status_solicitacao', 'A') // Apenas aprovadas
                ->whereDate('m.data_hora', '>=', $dateFrom)
                ->whereDate('m.data_hora', '<=', $dateTo);

            if (!$isSuperAdmin) {
                $query->where(function ($q) use ($setoresPermitidos) {
                    $q->whereIn('m.setor_origem_id', $setoresPermitidos)
                      ->orWhereIn('m.setor_destino_id', $setoresPermitidos);
                });
            }

            // Aplicar filtros opcionais
            if (!empty($filters['polo_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->whereExists(function ($subq) use ($filters) {
                        $subq->select(DB::raw(1))
                             ->from('setores as so')
                             ->whereRaw('so.id = m.setor_origem_id')
                             ->where('so.polo_id', $filters['polo_id']);
                    })->orWhereExists(function ($subq) use ($filters) {
                        $subq->select(DB::raw(1))
                             ->from('setores as sd')
                             ->whereRaw('sd.id = m.setor_destino_id')
                             ->where('sd.polo_id', $filters['polo_id']);
                    });
                });
            }

            if (!empty($filters['setor_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('m.setor_origem_id', $filters['setor_id'])
                      ->orWhere('m.setor_destino_id', $filters['setor_id']);
                });
            }

            if (!empty($filters['produto_id'])) {
                $query->where('p.id', $filters['produto_id']);
            }

            // Agrupar por data e produto - Força o banco a devolver apenas 1 linha sumarizada
            $query->groupBy(
                DB::raw('DATE(m.data_hora)'),
                'p.id',
                'p.nome',
                'p.codigo_simpas',
                'p.codigo_barras',
                'um.nome',
                'gp.nome',
                'gp.tipo'
            );

            // Buscar resultados PAGINADOS com ordenação por data e volume (Substituindo o memory leak do GET)
            $paginator = $query
                ->orderByDesc(DB::raw('DATE(m.data_hora)'))
                ->orderByDesc('consumo_liquido') 
                ->paginate(50);

            // Estruturar a coleção apenas dos 50 itens desta PÁGINA
            $groupedByDate = [];
            foreach ($paginator->items() as $item) {
                $data = $item->data;
                
                if (!isset($groupedByDate[$data])) {
                    $groupedByDate[$data] = [
                        'data' => $data,
                        'produtos' => [],
                        'total_produtos' => 0,
                        'quantidade_saida_bruta_dia' => 0,
                        'quantidade_devolvida_dia' => 0,
                        'quantidade_liquida_dia' => 0,
                    ];
                }
                
                // Buscar detalhadas Apenas da PÁGINA: N+1 mitigado de 10k para no máximo 50.
                $detalhadasQuery = DB::table('item_movimentacao as im')
                    ->join('movimentacao as m', 'im.movimentacao_id', '=', 'm.id')
                    ->join('setores as so', 'm.setor_origem_id', '=', 'so.id')
                    ->leftJoin('setores as sd', 'm.setor_destino_id', '=', 'sd.id')
                    ->select(
                        'm.id as movimentacao_id',
                        'm.tipo',
                        'so.id as setor_origem_id',
                        'so.nome as setor_origem_nome',
                        'sd.id as setor_destino_id',
                        'sd.nome as setor_destino_nome',
                        'im.quantidade_liberada as quantidade',
                        'm.data_hora',
                        'm.observacao'
                    )
                    ->where('im.produto_id', $item->produto_id)
                    ->whereDate('m.data_hora', $data)
                    ->whereIn('m.tipo', ['S', 'T', 'D'])
                    ->where('m.status_solicitacao', 'A');

                if (!$isSuperAdmin) {
                    $detalhadasQuery->where(function ($q) use ($setoresPermitidos) {
                        $q->whereIn('m.setor_origem_id', $setoresPermitidos)
                          ->orWhereIn('m.setor_destino_id', $setoresPermitidos);
                    });
                }

                $movimentacoesDetalhadas = $detalhadasQuery->orderBy('m.data_hora', 'desc')->get();
                
                $groupedByDate[$data]['produtos'][] = [
                    'produto' => [
                        'id' => $item->produto_id,
                        'nome' => $item->produto_nome,
                        'codigo_simpas' => $item->codigo_simpas,
                        'codigo_barras' => $item->codigo_barras,
                        'unidade_medida' => $item->unidade_medida,
                        'grupo_produto' => $item->grupo_produto,
                        'tipo' => $item->tipo_produto,
                    ],
                    'quantidade_saida_bruta' => (float)$item->quantidade_saida_bruta,
                    'quantidade_devolvida' => (float)$item->quantidade_devolvida,
                    'quantidade_liquida' => (float)$item->consumo_liquido,
                    'quantidade_total' => (float)$item->consumo_liquido, // Mantido para retrocompatibilidade do front
                    'total_movimentacoes' => (int) $item->total_movimentacoes,
                    'movimentacoes' => $movimentacoesDetalhadas->map(function($mov) {
                        return [
                            'movimentacao_id' => $mov->movimentacao_id,
                            'tipo' => $mov->tipo,
                            'tipo_descricao' => $mov->tipo === 'T' ? 'Transferência Setorial' : ($mov->tipo === 'D' ? 'Devolução' : 'Saída Direta'),
                            'quantidade' => (int) $mov->quantidade,
                            'setor_origem' => [
                                'id' => $mov->setor_origem_id,
                                'nome' => $mov->setor_origem_nome
                            ],
                            'setor_destino' => $mov->setor_destino_id ? [
                                'id' => $mov->setor_destino_id,
                                'nome' => $mov->setor_destino_nome
                            ] : null,
                            'data_hora' => $mov->data_hora,
                            'observacao' => $mov->observacao
                        ];
                    })
                ];
                
                $groupedByDate[$data]['total_produtos']++;
                $groupedByDate[$data]['quantidade_saida_bruta_dia'] += (float)$item->quantidade_saida_bruta;
                $groupedByDate[$data]['quantidade_devolvida_dia'] += (float)$item->quantidade_devolvida;
                $groupedByDate[$data]['quantidade_liquida_dia'] += (float)$item->consumo_liquido;
            }

            // Injetamos a estrutura modificada dentro do objeto nativo do Paginator
            $paginator->setCollection(collect(array_values($groupedByDate)));

            return response()->json([
                'status' => true,
                'message' => 'Relatório de saídas por data recuperado com sucesso',
                'data' => $paginator, // Metadados automáticos (current_page, next_page_url, total)
                'periodo' => [
                    'data_inicial' => $dateFrom,
                    'data_final' => $dateTo
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de saídas por data: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de saídas por data'
            ], 500);
        }
    }

    /**
     * Relatório de Entradas por Data (Agregado por Produto)
     * POST /api/relatorios/entradas-por-data/list
     * 
     * Retorna lista consolidada de entradas agrupadas por data e produto.
     * Calcula a quantidade total de cada produto que entrou em cada dia,
     * somando todas as entradas de todos os fornecedores.
     * 
     * Exemplo: Se entraram 100 dipironas do Fornecedor A e 50 do Fornecedor B no mesmo dia,
     * o relatório mostrará 150 dipironas no total.
     * 
     * Filtros opcionais:
     * - Data inicial/final (se não informado, usa data atual)
     * - Setor (unidade que recebeu)
     * - Fornecedor (para filtrar por fornecedor específico)
     * - Produto (para filtrar produto específico)
     */
    public function listEntradasPorData(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.fornecedor_id' => 'nullable|exists:fornecedores,id',
                'filters.produto_id' => 'nullable|exists:produtos,id',
            ], [
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.fornecedor_id.exists' => 'Fornecedor não encontrado.',
                'filters.produto_id.exists' => 'Produto não encontrado.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            $filters = $data['filters'] ?? [];
            
            // Se não informar período, usa data atual
            $dateFrom = $filters['date_from'] ?? date('Y-m-d');
            $dateTo = $filters['date_to'] ?? $dateFrom;

            // Restringir aos setores que o usuário autenticado tem acesso.
            // Super admin enxerga todos os setores (sem restrição).
            $user = auth()->user();
            $isSuperAdmin = $user->isSuperAdmin();
            $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                ->where('usuario_id', $user->id)
                ->pluck('setor_id')
                ->toArray();

            // Query agregada: agrupa por data e produto, soma quantidades
            $query = DB::table('itens_entrada as ie')
                ->join('entrada as e', 'ie.entrada_id', '=', 'e.id')
                ->join('produtos as p', 'ie.produto_id', '=', 'p.id')
                ->join('unidade_medida as um', 'p.unidade_medida_id', '=', 'um.id')
                ->join('grupo_produto as gp', 'p.grupo_produto_id', '=', 'gp.id')
                ->select(
                    DB::raw('DATE(e.created_at) as data'),
                    'p.id as produto_id',
                    'p.nome as produto_nome',
                    'p.codigo_simpas',
                    'p.codigo_barras',
                    'um.nome as unidade_medida',
                    'gp.nome as grupo_produto',
                    'gp.tipo as tipo_produto',
                    DB::raw('SUM(ie.quantidade) as quantidade_total'),
                    DB::raw('COUNT(DISTINCT e.id) as total_entradas'),
                    DB::raw('COUNT(DISTINCT e.fornecedor_id) as total_fornecedores')
                )
                ->whereDate('e.created_at', '>=', $dateFrom)
                ->whereDate('e.created_at', '<=', $dateTo);

            if (!$isSuperAdmin) {
                $query->whereIn('e.setor_id', $setoresPermitidos);
            }

            // Aplicar filtros opcionais
            if (!empty($filters['polo_id'])) {
                $query->whereExists(function ($subq) use ($filters) {
                    $subq->select(DB::raw(1))
                         ->from('setores as s')
                         ->whereRaw('s.id = e.setor_id')
                         ->where('s.polo_id', $filters['polo_id']);
                });
            }

            if (!empty($filters['setor_id'])) {
                $query->where('e.setor_id', $filters['setor_id']);
            }

            if (!empty($filters['fornecedor_id'])) {
                $query->where('e.fornecedor_id', $filters['fornecedor_id']);
            }

            if (!empty($filters['produto_id'])) {
                $query->where('p.id', $filters['produto_id']);
            }

            // Agrupar por data e produto
            $query->groupBy(
                DB::raw('DATE(e.created_at)'),
                'p.id',
                'p.nome',
                'p.codigo_simpas',
                'p.codigo_barras',
                'um.nome',
                'gp.nome',
                'gp.tipo'
            );

            // Buscar todos os resultados com ordenação
            $results = $query
                ->orderByDesc(DB::raw('DATE(e.created_at)'))
                ->orderByDesc(DB::raw('SUM(ie.quantidade)'))
                ->get();

            // Agrupar resultados por data
            $groupedByDate = [];
            foreach ($results as $item) {
                $data = $item->data;
                
                if (!isset($groupedByDate[$data])) {
                    $groupedByDate[$data] = [
                        'data' => $data,
                        'produtos' => [],
                        'total_produtos' => 0,
                        'quantidade_total_dia' => 0
                    ];
                }
                
                // Buscar entradas detalhadas (fornecedor, nota fiscal, setor) deste produto nesta data
                $entradasDetalhadasQuery = DB::table('itens_entrada as ie')
                    ->join('entrada as e', 'ie.entrada_id', '=', 'e.id')
                    ->join('fornecedores as f', 'e.fornecedor_id', '=', 'f.id')
                    ->join('setores as s', 'e.setor_id', '=', 's.id')
                    ->select(
                        'e.id as entrada_id',
                        'e.nota_fiscal',
                        'f.id as fornecedor_id',
                        'f.razao_social_nome as fornecedor_nome',
                        'f.cnpj as fornecedor_cnpj',
                        's.id as setor_id',
                        's.nome as setor_nome',
                        'ie.quantidade',
                        'ie.lote',
                        'ie.data_vencimento',
                        'ie.data_fabricacao',
                        'e.created_at'
                    )
                    ->where('ie.produto_id', $item->produto_id)
                    ->whereDate('e.created_at', $data);

                // Reaplicar o escopo de setores permitidos também no detalhe.
                if (!$isSuperAdmin) {
                    $entradasDetalhadasQuery->whereIn('e.setor_id', $setoresPermitidos);
                }

                $entradasDetalhadas = $entradasDetalhadasQuery
                    ->orderBy('e.created_at', 'desc')
                    ->get();
                
                $groupedByDate[$data]['produtos'][] = [
                    'produto' => [
                        'id' => $item->produto_id,
                        'nome' => $item->produto_nome,
                        'codigo_simpas' => $item->codigo_simpas,
                        'codigo_barras' => $item->codigo_barras,
                        'unidade_medida' => $item->unidade_medida,
                        'grupo_produto' => $item->grupo_produto,
                        'tipo' => $item->tipo_produto,
                    ],
                    'quantidade_total' => (int) $item->quantidade_total,
                    'total_entradas' => (int) $item->total_entradas,
                    'total_fornecedores' => (int) $item->total_fornecedores,
                    'entradas' => $entradasDetalhadas->map(function($entrada) {
                        return [
                            'entrada_id' => $entrada->entrada_id,
                            'nota_fiscal' => $entrada->nota_fiscal,
                            'quantidade' => (int) $entrada->quantidade,
                            'lote' => $entrada->lote,
                            'data_vencimento' => $entrada->data_vencimento,
                            'data_fabricacao' => $entrada->data_fabricacao,
                            'fornecedor' => [
                                'id' => $entrada->fornecedor_id,
                                'nome' => $entrada->fornecedor_nome,
                                'cnpj' => $entrada->fornecedor_cnpj
                            ],
                            'setor' => [
                                'id' => $entrada->setor_id,
                                'nome' => $entrada->setor_nome
                            ],
                            'created_at' => $entrada->created_at
                        ];
                    })
                ];
                
                $groupedByDate[$data]['total_produtos']++;
                $groupedByDate[$data]['quantidade_total_dia'] += (int) $item->quantidade_total;
            }
            
            // Converter para array indexado e ordenar por data
            $resultsWithDetails = array_values($groupedByDate);

            return response()->json([
                'status' => true,
                'message' => 'Relatório de entradas por data recuperado com sucesso',
                'data' => $resultsWithDetails,
                'periodo' => [
                    'data_inicial' => $dateFrom,
                    'data_final' => $dateTo
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de entradas por data: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de entradas por data'
            ], 500);
        }
    }


    public function listEstoqueReport(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.produto_id' => 'nullable|exists:produtos,id',
                'filters.tipo' => 'nullable|string|in:Medicamento,Material',
                'filters.status_disponibilidade' => 'nullable|string|in:D,I',
                'filters.abaixo_minimo' => 'nullable|boolean',
                'filters.dias_vencimento' => 'nullable|integer|min:1|max:365',
            ], [
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.produto_id.exists' => 'Produto não encontrado.',
                'filters.tipo.in' => 'Tipo inválido. Use: Medicamento ou Material.',
                'filters.status_disponibilidade.in' => 'Status inválido. Use: D (Disponível) ou I (Indisponível).',
                'filters.abaixo_minimo.boolean' => 'Filtro abaixo do mínimo deve ser verdadeiro ou falso.',
                'filters.dias_vencimento.integer' => 'Dias de vencimento deve ser um número inteiro.',
                'filters.dias_vencimento.min' => 'Dias de vencimento deve ser ao menos 1.',
                'filters.dias_vencimento.max' => 'Dias de vencimento não pode exceder 365.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Query base com eager loading massivo para evitar N+1
            $query = \App\Models\Estoque::with([
                'produto:id,nome,codigo_simpas,codigo_barras,grupo_produto_id,lista_portaria,unidade_medida_id',
                'produto.grupoProduto:id,nome,tipo,controlado',
                'produto.unidadeMedida:id,nome',
                'setor:id,polo_id,nome,tipo',
                'setor.polo:id,nome',
                // Eager Load inteligente dos lotes
                'lotes' => function($q) use ($data) {
                    $q->where('quantidade_disponivel', '>', 0)
                      ->orderBy('data_vencimento', 'asc');
                      
                    if (!empty($data['filters']['dias_vencimento'])) {
                        $dataLimite = now()->addDays($data['filters']['dias_vencimento']);
                        $q->whereDate('data_vencimento', '<=', $dataLimite);
                    }
                }
            ]);

            // Restringir aos setores que o usuário autenticado tem acesso.
            $user = auth()->user();
            if (!$user->isSuperAdmin()) {
                $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                    ->where('usuario_id', $user->id)
                    ->pluck('setor_id');
                $query->whereIn('estoque.setor_id', $setoresPermitidos);
            }

            // Aplicar filtros se fornecidos
            $filters = $data['filters'] ?? [];

            if (!empty($filters['polo_id'])) {
                $query->whereHas('setor', function ($q) use ($filters) {
                    $q->where('polo_id', $filters['polo_id']);
                });
            }

            if (!empty($filters['setor_id'])) {
                $query->where('estoque.setor_id', $filters['setor_id']);
            }

            if (!empty($filters['produto_id'])) {
                $query->where('estoque.produto_id', $filters['produto_id']);
            }

            if (!empty($filters['status_disponibilidade'])) {
                $query->where('estoque.status_disponibilidade', $filters['status_disponibilidade']);
            }

            if (!empty($filters['tipo'])) {
                $query->whereHas('produto.grupoProduto', function ($q) use ($filters) {
                    $q->where('tipo', $filters['tipo']);
                });
            }

            if (!empty($filters['abaixo_minimo']) && $filters['abaixo_minimo'] === true) {
                $query->whereRaw('estoque.quantidade_atual < estoque.quantidade_minima');
            }

            // Ordenação
            $query->join('setores as s', 'estoque.setor_id', '=', 's.id')
                  ->join('produtos as p', 'estoque.produto_id', '=', 'p.id')
                  ->orderBy('p.nome', 'asc')
                  ->orderBy('s.nome', 'asc')
                  ->select('estoque.*'); 

            // CLONAR a query original ANTES de aplicar get() ou paginate()
            // Isso erradica as queries soltas e burras que contavam toda a tabela
            $queryBaseTotais = clone $query;
            $totalItens = (clone $queryBaseTotais)->count();
            $totalDisp = (clone $queryBaseTotais)->where('estoque.status_disponibilidade', 'D')->count();
            $totalIndisp = (clone $queryBaseTotais)->where('estoque.status_disponibilidade', 'I')->count();
            $totalAbaixoMin = (clone $queryBaseTotais)->whereRaw('estoque.quantidade_atual < estoque.quantidade_minima')->count();

            // Total financeiro calculado direto no banco via JOIN
            // Em vez de somar Arrays PHP na RAM, fazemos uma agregação massiva e leve no banco.
            $totalFinanceiroQuery = clone $queryBaseTotais;
            $totalFinanceiroEstoque = $totalFinanceiroQuery->join('estoque_lote as el', function($join) {
                $join->on('el.setor_id', '=', 'estoque.setor_id')
                     ->on('el.produto_id', '=', 'estoque.produto_id')
                     ->where('el.quantidade_disponivel', '>', 0);
            })->sum(\Illuminate\Support\Facades\DB::raw('el.quantidade_disponivel * COALESCE(el.valor_unitario, 0)'));

            $totalizadores = [
                'total_itens' => $totalItens,
                'total_produtos_disponiveis' => $totalDisp,
                'total_produtos_indisponiveis' => $totalIndisp,
                'total_abaixo_minimo' => $totalAbaixoMin,
                'valor_total_estoque' => round($totalFinanceiroEstoque, 2),
            ];

            // PAGINAÇÃO OBRIGATÓRIA - Resolve o Out of Memory em relatórios massivos
            $paginator = $query->paginate(50);

            // Pré-cálculo da permissão de verificação financeira (Memoization Cache)
            // Evita disparar 50 consultas ao BD por página para checar o mesmo setor
            $permissoesFinanceirasCache = [];
            $checkPermissaoFinanceira = function($setor) use (&$permissoesFinanceirasCache, $user) {
                if (!$setor) return false;
                if (!isset($permissoesFinanceirasCache[$setor->id])) {
                    $permissoesFinanceirasCache[$setor->id] = $user->podeVerValoresFinanceiros($setor);
                }
                return $permissoesFinanceirasCache[$setor->id];
            };

            // Transformação iterativa (apenas para exibição da página)
            $items = $paginator->getCollection()->map(function ($estoque) use ($checkPermissaoFinanceira) {
                
                // Validação de permissão instantânea via Cache O(1)
                $podeVerValores = $checkPermissaoFinanceira($estoque->setor);

                // Lotes já vieram no Eager Load! Nada de N+1 aqui.
                $lotes = $estoque->lotes ?? collect();

                $loteVencimentoProximo = $lotes->first(); 
                $totalLotes = $lotes->count();
                $quantidadeLotes = $lotes->sum('quantidade_disponivel');

                $valorTotalProduto = 0;
                $temValor = false;

                $lotesFormatados = $lotes->map(function($lote) use ($podeVerValores, &$valorTotalProduto, &$temValor) {
                    $vUnit = ($podeVerValores && $lote->valor_unitario !== null) ? (float) $lote->valor_unitario : null;
                    $subtotal = ($vUnit !== null) ? round($vUnit * (float) $lote->quantidade_disponivel, 2) : null;
                    
                    if ($subtotal !== null) {
                        $valorTotalProduto += $subtotal;
                        $temValor = true;
                    }
                    
                    return [
                        'id' => $lote->id,
                        'lote' => $lote->lote,
                        'quantidade_disponivel' => (int) $lote->quantidade_disponivel,
                        'valor_unitario' => $vUnit,
                        'valor_total_lote' => $subtotal,
                        'data_vencimento' => $lote->data_vencimento ? \Carbon\Carbon::parse($lote->data_vencimento)->format('Y-m-d') : null,
                        'data_fabricacao' => $lote->data_fabricacao ? \Carbon\Carbon::parse($lote->data_fabricacao)->format('Y-m-d') : null,
                        'dias_para_vencer' => $lote->data_vencimento ? now()->diffInDays(\Carbon\Carbon::parse($lote->data_vencimento), false) : null,
                        'vencido' => $lote->data_vencimento ? \Carbon\Carbon::parse($lote->data_vencimento) < now() : false
                    ];
                });

                $estoque->lotes_info = [
                    'total_lotes' => $totalLotes,
                    'quantidade_total_lotes' => (int) $quantidadeLotes,
                    'lote_proximo_vencimento' => $loteVencimentoProximo ? [
                        'lote' => $loteVencimentoProximo->lote,
                        'quantidade' => (int) $loteVencimentoProximo->quantidade_disponivel,
                        'data_vencimento' => $loteVencimentoProximo->data_vencimento ? \Carbon\Carbon::parse($loteVencimentoProximo->data_vencimento)->format('Y-m-d') : null,
                        'dias_para_vencer' => $loteVencimentoProximo->data_vencimento ? now()->diffInDays(\Carbon\Carbon::parse($loteVencimentoProximo->data_vencimento), false) : null
                    ] : null,
                    'lotes' => $lotesFormatados
                ];

                $estoque->pode_ver_valores = $podeVerValores;
                $estoque->valor_total = ($podeVerValores && $temValor) ? round($valorTotalProduto, 2) : null;
                $estoque->preco_medio = ($podeVerValores && $temValor && $quantidadeLotes > 0) ? round($valorTotalProduto / $quantidadeLotes, 4) : null;
                $estoque->abaixo_minimo = $estoque->quantidade_atual < $estoque->quantidade_minima;

                return $estoque;
            });

            // Devolver a coleção mascarada ao paginador
            $paginator->setCollection($items);

            return response()->json([
                'status' => true,
                'message' => 'Relatório de estoque recuperado com sucesso',
                // Enviamos o paginator nativo com metadata (current_page, last_page, total)
                'data' => $paginator, 
                'totalizadores' => $totalizadores
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de estoque: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de estoque'
            ], 500);
        }
    }

    /**
     * Relatório de Usuários
     * POST /api/relatorios/usuarios/list
     * 
     * Retorna lista de usuários com seus setores vinculados e filtros por:
     * - Status (A/I)
     * - Tipo de vínculo
     * - Setor
     * - Perfil
     */
    public function listUsuariosReport(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.status' => 'nullable|string|in:A,I',
                'filters.regime_contratacao_id_id' => 'nullable|exists:regime_contratacao_id,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.perfil' => 'nullable|string|in:admin,almoxarife,solicitante',
            ], [
                'filters.status.in' => 'Status inválido. Use: A (Ativo) ou I (Inativo).',
                'filters.regime_contratacao_id_id.exists' => 'Tipo de vínculo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.perfil.in' => 'Perfil inválido. Use: admin, almoxarife ou solicitante.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Query base com eager loading para evitar N+1
            $query = \App\Models\User::with([
                'RegimeContratacao:id,nome,descricao,status',
                'setores:id,nome,tipo,polo_id',
                'setores.polo:id,nome'
            ])->where('email', '!=', 'adminti@gmail.com');

            // Aplicar filtros se fornecidos
            $filters = $data['filters'] ?? [];

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['regime_contratacao_id_id'])) {
                $query->where('regime_contratacao_id', $filters['regime_contratacao_id_id']);
            }

            // Filtro por setor: usuários que pertencem ao setor especificado
            if (!empty($filters['setor_id'])) {
                $query->whereHas('setores', function ($q) use ($filters) {
                    $q->where('setores.id', $filters['setor_id']);
                });
            }

            // Filtro por perfil: usuários que têm o perfil especificado em algum setor
            if (!empty($filters['perfil'])) {
                $query->whereHas('setores', function ($q) use ($filters) {
                    $q->where('usuario_setor.perfil', $filters['perfil']);
                });
            }

            // Ordenação: por nome do usuário
            $query->orderBy('name', 'asc');

            // Buscar todos os resultados
            $results = $query->get();

            // Transformar os dados para incluir informações do pivot
            $users = $results->map(function ($user) use ($filters) {
                $userData = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'cpf' => $user->cpf,
                    'telefone' => $user->telefone,
                    'data_nascimento' => $user->data_nascimento,
                    'status' => $user->status,
                    'regime_contratacao_id_id' => $user->regime_contratacao_id,
                    'regime_contratacao_id' => $user->RegimeContratacao ? [
                        'id' => $user->RegimeContratacao->id,
                        'nome' => $user->RegimeContratacao->nome,
                        'descricao' => $user->RegimeContratacao->descricao,
                        'status' => $user->RegimeContratacao->status
                    ] : null,
                    'usuario_tipo' => $user->usuario_tipo,
                    'created_at' => $user->created_at,
                    'setores' => []
                ];

                // Adicionar setores com informações do pivot (perfil)
                foreach ($user->setores as $setor) {
                    // Se há filtro de setor_id, incluir apenas esse setor
                    if (!empty($filters['setor_id']) && $setor->id != $filters['setor_id']) {
                        continue;
                    }
                    
                    // Se há filtro de perfil, incluir apenas setores com esse perfil
                    if (!empty($filters['perfil']) && $setor->pivot->perfil !== $filters['perfil']) {
                        continue;
                    }

                    $userData['setores'][] = [
                        'id' => $setor->id,
                        'nome' => $setor->nome,
                        'tipo' => $setor->tipo,
                        'unidade' => $setor->unidade ? [
                            'id' => $setor->unidade->id,
                            'nome' => $setor->unidade->nome
                        ] : null,
                        'perfil' => $setor->pivot->perfil,
                        'data_vinculo' => $setor->pivot->created_at ? $setor->pivot->created_at->format('Y-m-d H:i:s') : null,
                    ];
                }

                // Contar total de setores
                $userData['total_setores'] = count($userData['setores']);

                return $userData;
            });

            // Calcular totalizadores
            $totalizadores = [
                'total_usuarios' => $users->count(),
                'total_ativos' => \App\Models\User::where('status', 'A')->where('email', '!=', 'adminti@gmail.com')->count(),
                'total_inativos' => \App\Models\User::where('status', 'I')->where('email', '!=', 'adminti@gmail.com')->count(),
            ];

            return response()->json([
                'status' => true,
                'message' => 'Relatório de usuários recuperado com sucesso',
                'data' => $users,
                'totalizadores' => $totalizadores
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de usuários: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de usuários'
            ], 500);
        }
    }
    /**
     * Relatório de Medicamentos Controlados
     * POST /api/relatorios/medicamentos-controlados/list
     *
     * Lista o estoque dos produtos pertencentes a grupos marcados como
     * controlados (Portaria SVS/MS 344/98), com lotes, validade e o
     * movimento de entradas/saídas do período informado.
     *
     * Filtros: polo_id, setor_id, grupo_produto_id, lista_portaria,
     *          produto_id, date_from, date_to, somente_com_saldo
     */
    public function listMedicamentosControladosReport(Request $request)
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.grupo_produto_id' => 'nullable|exists:grupo_produto,id',
                'filters.produto_id' => 'nullable|exists:produtos,id',
                'filters.lista_portaria' => 'nullable|string|max:5',
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.somente_com_saldo' => 'nullable|boolean',
            ], [
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.grupo_produto_id.exists' => 'Grupo de produto não encontrado.',
                'filters.produto_id.exists' => 'Produto não encontrado.',
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            $filters = $data['filters'] ?? [];

            // Período usado no resumo de movimento (padrão: mês corrente)
            $dateFrom = !empty($filters['date_from'])
                ? \Carbon\Carbon::parse($filters['date_from'])->startOfDay()
                : now()->startOfMonth();
            $dateTo = !empty($filters['date_to'])
                ? \Carbon\Carbon::parse($filters['date_to'])->endOfDay()
                : now()->endOfDay();

            // Estoque restrito a produtos de grupos controlados
            $query = \App\Models\Estoque::with([
                'produto:id,nome,codigo_simpas,codigo_barras,grupo_produto_id,lista_portaria,unidade_medida_id',
                'produto.grupoProduto:id,nome,tipo,controlado',
                'produto.unidadeMedida:id,nome',
                'setor:id,polo_id,nome,tipo',
                'setor.polo:id,nome'
            ])->whereHas('produto.grupoProduto', function ($q) {
                $q->where('controlado', true);
            });

            // Restringir aos setores que o usuário autenticado tem acesso.
            // Super admin enxerga todos os setores (sem restrição).
            $user = auth()->user();
            if (!$user->isSuperAdmin()) {
                $setoresPermitidos = DB::table('usuario_setor')
                    ->where('usuario_id', $user->id)
                    ->pluck('setor_id');
                $query->whereIn('estoque.setor_id', $setoresPermitidos);
            }

            if (!empty($filters['polo_id'])) {
                $query->whereHas('setor', function ($q) use ($filters) {
                    $q->where('polo_id', $filters['polo_id']);
                });
            }

            if (!empty($filters['setor_id'])) {
                $query->where('estoque.setor_id', $filters['setor_id']);
            }

            if (!empty($filters['produto_id'])) {
                $query->where('estoque.produto_id', $filters['produto_id']);
            }

            if (!empty($filters['grupo_produto_id'])) {
                $query->whereHas('produto', function ($q) use ($filters) {
                    $q->where('grupo_produto_id', $filters['grupo_produto_id']);
                });
            }

            if (!empty($filters['lista_portaria'])) {
                $query->whereHas('produto', function ($q) use ($filters) {
                    $q->where('lista_portaria', strtoupper($filters['lista_portaria']));
                });
            }

            if (!empty($filters['somente_com_saldo'])) {
                $query->where('estoque.quantidade_atual', '>', 0);
            }

            $query->join('produtos as p', 'estoque.produto_id', '=', 'p.id')
                  ->join('setores as s', 'estoque.setor_id', '=', 's.id')
                  ->orderBy('p.nome', 'asc')
                  ->orderBy('s.nome', 'asc')
                  ->select('estoque.*');

            $estoques = $query->get();

            if ($estoques->isEmpty()) {
                return response()->json([
                    'status' => true,
                    'message' => 'Relatório de medicamentos controlados recuperado com sucesso',
                    'data' => [],
                    'periodo' => [
                        'date_from' => $dateFrom->format('Y-m-d'),
                        'date_to' => $dateTo->format('Y-m-d'),
                    ],
                    'totalizadores' => [
                        'total_itens' => 0,
                        'total_produtos' => 0,
                        'total_setores' => 0,
                        'quantidade_total' => 0,
                        'total_abaixo_minimo' => 0,
                        'total_lotes_vencidos' => 0,
                        'total_lotes_a_vencer' => 0,
                        'total_entradas_periodo' => 0,
                        'total_saidas_periodo' => 0,
                        'por_lista' => [],
                    ]
                ], 200);
            }

            $produtoIds = $estoques->pluck('produto_id')->unique()->values();
            $setorIds = $estoques->pluck('setor_id')->unique()->values();

            // Lotes disponíveis por produto/setor (uma única consulta)
            $lotesPorChave = \App\Models\EstoqueLote::whereIn('produto_id', $produtoIds)
                ->whereIn('setor_id', $setorIds)
                ->where('quantidade_disponivel', '>', 0)
                ->orderBy('data_vencimento', 'asc')
                ->get()
                ->groupBy(function ($lote) {
                    return $lote->setor_id . '-' . $lote->produto_id;
                });

            // Entradas do período por produto/setor
            $entradasPorChave = DB::table('itens_entrada')
                ->join('entrada', 'itens_entrada.entrada_id', '=', 'entrada.id')
                ->whereIn('itens_entrada.produto_id', $produtoIds)
                ->whereIn('entrada.setor_id', $setorIds)
                ->whereBetween('itens_entrada.created_at', [$dateFrom, $dateTo])
                ->groupBy('entrada.setor_id', 'itens_entrada.produto_id')
                ->select(
                    'entrada.setor_id',
                    'itens_entrada.produto_id',
                    DB::raw('SUM(itens_entrada.quantidade) as total')
                )
                ->get()
                ->keyBy(function ($row) {
                    return $row->setor_id . '-' . $row->produto_id;
                });

            // Saídas/transferências aprovadas do período por produto/setor de origem
            $saidasPorChave = DB::table('item_movimentacao')
                ->join('movimentacao', 'item_movimentacao.movimentacao_id', '=', 'movimentacao.id')
                ->whereIn('item_movimentacao.produto_id', $produtoIds)
                ->whereIn('movimentacao.setor_origem_id', $setorIds)
                ->whereIn('movimentacao.tipo', ['S', 'T'])
                ->where('movimentacao.status_solicitacao', 'A')
                ->whereBetween('movimentacao.data_hora', [$dateFrom, $dateTo])
                ->groupBy('movimentacao.setor_origem_id', 'item_movimentacao.produto_id')
                ->select(
                    'movimentacao.setor_origem_id as setor_id',
                    'item_movimentacao.produto_id',
                    DB::raw('SUM(item_movimentacao.quantidade_liberada) as total')
                )
                ->get()
                ->keyBy(function ($row) {
                    return $row->setor_id . '-' . $row->produto_id;
                });

            $totalLotesVencidos = 0;
            $totalLotesAVencer = 0;
            $totalEntradas = 0;
            $totalSaidas = 0;
            $porLista = [];

            $items = $estoques->map(function ($estoque) use (
                $lotesPorChave, $entradasPorChave, $saidasPorChave,
                &$totalLotesVencidos, &$totalLotesAVencer, &$totalEntradas, &$totalSaidas, &$porLista
            ) {
                $chave = $estoque->setor_id . '-' . $estoque->produto_id;
                $lotes = $lotesPorChave->get($chave, collect());

                $lotesFormatados = $lotes->map(function ($lote) use (&$totalLotesVencidos, &$totalLotesAVencer) {
                    $vencido = $lote->data_vencimento < now();
                    $diasParaVencer = (int) now()->diffInDays($lote->data_vencimento, false);

                    if ($vencido) {
                        $totalLotesVencidos++;
                    } elseif ($diasParaVencer <= 30) {
                        $totalLotesAVencer++;
                    }

                    return [
                        'id' => $lote->id,
                        'lote' => $lote->lote,
                        'quantidade_disponivel' => (int) $lote->quantidade_disponivel,
                        'data_fabricacao' => $lote->data_fabricacao ? $lote->data_fabricacao->format('Y-m-d') : null,
                        'data_vencimento' => $lote->data_vencimento->format('Y-m-d'),
                        'dias_para_vencer' => $diasParaVencer,
                        'vencido' => $vencido,
                    ];
                })->values();

                $entradas = (int) (optional($entradasPorChave->get($chave))->total ?? 0);
                $saidas = (int) (optional($saidasPorChave->get($chave))->total ?? 0);
                $totalEntradas += $entradas;
                $totalSaidas += $saidas;

                $lista = $estoque->produto->lista_portaria ?: 'Sem lista';
                $porLista[$lista] = ($porLista[$lista] ?? 0) + (int) $estoque->quantidade_atual;

                $estoque->lista_portaria = $estoque->produto->lista_portaria;
                $estoque->abaixo_minimo = $estoque->quantidade_atual < $estoque->quantidade_minima;
                $estoque->lotes_info = [
                    'total_lotes' => $lotesFormatados->count(),
                    'quantidade_total_lotes' => (int) $lotes->sum('quantidade_disponivel'),
                    'lotes' => $lotesFormatados,
                ];
                $estoque->movimento_periodo = [
                    'entradas' => $entradas,
                    'saidas' => $saidas,
                    'saldo_movimento' => $entradas - $saidas,
                ];

                return $estoque;
            });

            ksort($porLista);

            $totalizadores = [
                'total_itens' => $items->count(),
                'total_produtos' => $produtoIds->count(),
                'total_setores' => $setorIds->count(),
                'quantidade_total' => (int) $items->sum('quantidade_atual'),
                'total_abaixo_minimo' => $items->where('abaixo_minimo', true)->count(),
                'total_lotes_vencidos' => $totalLotesVencidos,
                'total_lotes_a_vencer' => $totalLotesAVencer,
                'total_entradas_periodo' => $totalEntradas,
                'total_saidas_periodo' => $totalSaidas,
                'por_lista' => $porLista,
            ];

            return response()->json([
                'status' => true,
                'message' => 'Relatório de medicamentos controlados recuperado com sucesso',
                'data' => $items,
                'periodo' => [
                    'date_from' => $dateFrom->format('Y-m-d'),
                    'date_to' => $dateTo->format('Y-m-d'),
                ],
                'totalizadores' => $totalizadores
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de medicamentos controlados: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de medicamentos controlados'
            ], 500);
        }
    }

    /**
     * Relat�rio Financeiro de Entradas
     */
    public function listEntradasFinanceiras(Request $request)
    {
        try {
            $data = $request->all();
            $filters = $data['filters'] ?? [];
            
            $query = DB::table('entrada')
                ->join('itens_entrada', 'entrada.id', '=', 'itens_entrada.entrada_id')
                ->join('produtos', 'itens_entrada.produto_id', '=', 'produtos.id')
                ->join('setores', 'entrada.setor_id', '=', 'setores.id')
                ->join('polos', 'setores.polo_id', '=', 'polos.id')
                ->join('fornecedores', 'entrada.fornecedor_id', '=', 'fornecedores.id')
                ->select(
                    'entrada.created_at as data_entrada',
                    'polos.nome as polo',
                    'setores.nome as setor',
                    'fornecedores.razao_social_nome as fornecedor',
                    'produtos.nome as produto',
                    'produtos.codigo_simpas',
                    'itens_entrada.quantidade',
                    'itens_entrada.valor_unitario',
                    DB::raw('COALESCE(itens_entrada.quantidade * itens_entrada.valor_unitario, 0) as subtotal')
                );

            if (!empty($filters['date_from'])) {
                $query->whereDate('entrada.created_at as data_entrada', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->whereDate('entrada.created_at as data_entrada', '<=', $filters['date_to']);
            }
            if (!empty($filters['setor_id'])) {
                $query->where('entrada.setor_id', $filters['setor_id']);
            }

            $query->orderBy('entrada.created_at', 'desc');

            $result = $query->paginate(30);

            return response()->json(['status' => true, 'data' => $result]);

        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Erro ao listar relat�rio financeiro de entradas: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Relat�rio Financeiro de Sa�das (Movimenta��es)
     */
    public function listSaidasFinanceiras(Request $request)
    {
        try {
            $data = $request->all();
            $filters = $data['filters'] ?? [];

            // Obt�m o custo pegando a entrada mais recente daquele lote e produto
            $query = DB::table('movimentacao')
                ->join('item_movimentacao', 'movimentacao.id', '=', 'item_movimentacao.movimentacao_id')
                ->join('produtos', 'item_movimentacao.produto_id', '=', 'produtos.id')
                ->join('setores as setor_destino', 'movimentacao.setor_destino_id', '=', 'setor_destino.id')
                ->leftJoin('itens_entrada', function ($join) {
                    $join->on('item_movimentacao.produto_id', '=', 'itens_entrada.produto_id')
                         ->on('item_movimentacao.lote', '=', 'itens_entrada.lote');
                })
                ->select(
                    'movimentacao.id as pedido_id',
                    'movimentacao.created_at',
                    'setor_destino.nome as setor_destino',
                    'produtos.nome as produto',
                    'produtos.codigo_simpas',
                    'produtos.codigo_simpas as codigo_simpass',
                    'item_movimentacao.lote',
                    'item_movimentacao.quantidade_liberada as quantidade',
                    DB::raw('MAX(itens_entrada.valor_unitario) as valor_unitario'),
                    DB::raw('MAX(itens_entrada.valor_unitario) * item_movimentacao.quantidade_liberada as valor_total')
                )
                ->where('movimentacao.tipo', 'S')
                ->where('movimentacao.status_solicitacao', 'A') // Aprovados
                ->groupBy(
                    'movimentacao.id',
                    'movimentacao.created_at',
                    'setor_destino.nome',
                    'produtos.nome',
                    'produtos.codigo_simpas',
                    'item_movimentacao.lote',
                    'item_movimentacao.quantidade_liberada'
                );

            if (!empty($filters['date_from'])) {
                $query->whereDate('movimentacao.created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->whereDate('movimentacao.created_at', '<=', $filters['date_to']);
            }
            if (!empty($filters['setor_id'])) {
                $query->where('movimentacao.setor_destino_id', $filters['setor_id']);
            }

            $query->orderBy('movimentacao.created_at', 'desc');

            $totalGeral = (clone $query)->get()->sum('valor_total');
            $result = $query->paginate(30);

            return response()->json([
                'status' => true,
                'data' => $result,
                'total_valor' => (float)$totalGeral,
                'valor_total_geral' => (float)$totalGeral
            ]);

        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Erro ao listar relat�rio financeiro de sa�das: ' . $e->getMessage()], 500);
        }
    }
}
