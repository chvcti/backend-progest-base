<?php

namespace App\Http\Controllers\Cadastros;

use App\Models\Estoque;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\Setores;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Http\Requests\Estoque\StoreEstoqueRequest;
use App\Http\Requests\Estoque\UpdateEstoqueRequest;

class EstoqueController
{
    public function add(StoreEstoqueRequest $request)
    {
        $validated = $request->validated();

        $estoque = new Estoque;
        $estoque->produto_id = $validated['produto_id'];
        $estoque->setor_id = $validated['setor_id'];
        // CORREÇÃO: O banco espera 'quantidade_atual', mas o front envia 'quantidade'
        $estoque->quantidade_atual = $validated['quantidade'] ?? 0;
        $estoque->quantidade_minima = $validated['quantidade_minima'] ?? 0;

        $estoque->save();

        return ['status' => true, 'data' => $estoque];
    }

    public function listAll(Request $request)
    {
        $data = $request->all();
        $filters = $data['filters'] ?? [];

        $query = Estoque::query();

        // Whitelist de colunas permitidas e mapeamento de aliases legados (Proteção SQL Injection)
        $allowedColumns = [
            'id', 'produto_id', 'setor_id', 'quantidade_atual',
            'quantidade_minima', 'status_disponibilidade', 'localizacao'
        ];
        $columnAliases = [
            'quantidade' => 'quantidade_atual',
            'status'     => 'status_disponibilidade',
        ];

        foreach ($filters as $key => $condition) {
            if (is_array($condition)) {
                foreach ($condition as $column => $value) {
                    $column = $columnAliases[$column] ?? $column;
                    if (in_array($column, $allowedColumns, true) && $value !== null && $value !== '') {
                        $query->where($column, $value);
                    }
                }
            } elseif ($condition !== null && $condition !== '') {
                $column = $columnAliases[$key] ?? $key;
                if (in_array($column, $allowedColumns, true)) {
                    $query->where($column, $condition);
                }
            }
        }

        $registros = $query
            ->select(
                'id',
                'produto_id',
                'setor_id',
                'quantidade_atual',
                'quantidade_atual as quantidade',
                'quantidade_minima',
                'status_disponibilidade',
                'status_disponibilidade as status'
            )
            ->orderBy('produto_id')
            ->get();

        return ['status' => true, 'data' => $registros];
    }

    public function listData(Request $request)
    {
        $id = $request->input('id');

        if (!$id) {
            return response()->json([
                'status'  => false,
                'message' => 'ID do estoque é obrigatório.'
            ], 400);
        }

        DB::enableQueryLog();

        $estoque = Estoque::find($id);

        if (!$estoque) {
            return response()->json([
                'status'  => false,
                'message' => 'Estoque não encontrado.'
            ], 404);
        }

        return ['status' => true, 'data' => $estoque, 'query' => DB::getQueryLog()];
    }

    public function update(UpdateEstoqueRequest $request)
    {
        $validated = $request->validated();
        $id = $validated['id'] ?? ($request->input('fornecedor.id') ?? $request->input('estoque.id'));

        // Verifica se o estoque existe
        $estoque = Estoque::find($id);

        if (!$estoque) {
            return response()->json([
                'status' => false,
                'message' => 'Item de estoque não encontrado'
            ], 404);
        }

        if (isset($validated['produto_id'])) {
            $estoque->produto_id = $validated['produto_id'];
        }
        if (isset($validated['setor_id'])) {
            $estoque->setor_id = $validated['setor_id'];
        }
        if (isset($validated['quantidade'])) {
            $estoque->quantidade_atual = $validated['quantidade'];
        }
        if (isset($validated['quantidade_minima'])) {
            $estoque->quantidade_minima = $validated['quantidade_minima'];
        }
        if (isset($validated['status'])) {
            $estoque->status_disponibilidade = $validated['status'];
        }
        $estoque->save();

        return ['status' => true, 'data' => $estoque];
    }
}
