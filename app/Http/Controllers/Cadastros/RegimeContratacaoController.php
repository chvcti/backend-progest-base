<?php

namespace App\Http\Controllers\Cadastros;

use App\Http\Requests\RegimeContratacaoRequest;
use Illuminate\Http\Request;
use App\Models\RegimeContratacao; 

class RegimeContratacaoController
{
    public function add(RegimeContratacaoRequest $request){

    }

    public function listAll(Request $request){  
        $data = $request->all();
        $filters = $data['filters'] ?? [];  

        $regimesQuery = RegimeContratacao::query();

        // Aplicar filtros com Whitelist de Colunas (Proteção SQL Injection)
        $allowedColumns = ['id', 'nome', 'descricao', 'status'];
        foreach ($filters as $key => $condition) {
            if (is_array($condition)) {
                foreach ($condition as $field => $value) {
                    if (in_array($field, $allowedColumns, true) && $value !== null && $value !== '') {
                        if (in_array($field, ['nome', 'descricao'], true)) {
                            $regimesQuery->where($field, 'like', '%' . $value . '%');
                        } else {
                            $regimesQuery->where($field, $value);
                        }
                    }
                }
            } elseif (in_array($key, $allowedColumns, true) && $condition !== null && $condition !== '') {
                if (in_array($key, ['nome', 'descricao'], true)) {
                    $regimesQuery->where($key, 'like', '%' . $condition . '%');
                } else {
                    $regimesQuery->where($key, $condition);
                }
            }
        }

        if (!isset($data['paginate'])) {
            $regimes = $regimesQuery
                ->select('id', 'nome', 'descricao', 'status')
                ->orderBy('nome')
                ->get();
        } else {
            $regimes = $regimesQuery
                ->select('id', 'nome', 'descricao', 'status')
                ->orderBy('nome')
                ->get();
        }

        return ['status' => true, 'data' => $regimes];
    }

    public function listData(Request $request)
    {
        $id = $request->input('id');
        if (!$id) {
            return response()->json(['status' => false, 'message' => 'ID do regime é obrigatório'], 400);
        }

        $regime = RegimeContratacao::find($id);
        if (!$regime) {
            return response()->json(['status' => false, 'message' => 'Regime de contratação não encontrado'], 404);
        }

        return response()->json(['status' => true, 'data' => $regime]);
    }

    public function update(RegimeContratacaoRequest $request){

    }

    public function delete(Request $request){

    }
}