<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemMovimentacao extends Model
{
    protected $table = 'item_movimentacao';
    protected $fillable = ['movimentacao_id', 'produto_id', 'quantidade_solicitada', 'quantidade_liberada', 'quantidade_devolvendo', 'lote'];
    protected $appends = ['codigo_simpass', 'codigo_simpas', 'data_formatada', 'lotes_parsed', 'numero_lote', 'validade', 'quantidade_devolvida', 'quantidade_original_atendida', 'quantidade_liberada_original', 'quantidade_aprovada_original'];

    public function getCodigoSimpassAttribute()
    {
        return $this->produto ? ($this->produto->codigo_simpas ?? $this->produto->codigo_simpass) : null;
    }

    public function getCodigoSimpasAttribute()
    {
        return $this->produto ? ($this->produto->codigo_simpas ?? $this->produto->codigo_simpass) : null;
    }

    public function getDataFormatadaAttribute()
    {
        if ($this->relationLoaded('movimentacao') && $this->movimentacao) {
            return $this->movimentacao->data_formatada;
        }
        return null;
    }

    public function getNumeroLoteAttribute()
    {
        $parsed = $this->lotes_parsed;
        if (!empty($parsed)) {
            $lotes = [];
            foreach ($parsed as $p) {
                if (is_array($p) && !empty($p['lote'])) {
                    $lotes[] = $p['lote'];
                } elseif (is_string($p)) {
                    $lotes[] = $p;
                }
            }
            if (!empty($lotes)) {
                return implode(', ', array_unique($lotes));
            }
        }
        $raw = $this->attributes['lote'] ?? null;
        return (is_string($raw) && !str_starts_with($raw, '[') && !str_starts_with($raw, '{')) ? $raw : null;
    }

    public function getLotesParsedAttribute()
    {
        $raw = $this->attributes['lote'] ?? null;
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return [['lote' => $raw, 'qtd' => null, 'data_vencimento' => null]];
        }
        return [];
    }

    public function getValidadeAttribute()
    {
        $parsed = $this->lotes_parsed;
        if (!empty($parsed)) {
            $validades = [];
            foreach ($parsed as $p) {
                if (is_array($p) && !empty($p['data_vencimento'])) {
                    try {
                        $validades[] = \Carbon\Carbon::parse($p['data_vencimento'])->format('d/m/Y');
                    } catch (\Exception $e) {
                        $validades[] = $p['data_vencimento'];
                    }
                }
            }
            if (!empty($validades)) {
                return implode(', ', array_unique($validades));
            }
        }
        return null;
    }

    public function getQuantidadeDevolvidaAttribute()
    {
        if (array_key_exists('quantidade_devolvida', $this->attributes)) {
            return (float) $this->attributes['quantidade_devolvida'];
        }

        if ($this->relationLoaded('movimentacao') && $this->movimentacao && $this->movimentacao->status_solicitacao !== 'A') {
            return 0;
        }

        $totalTabela = 0;
        if ($this->relationLoaded('devolucoes')) {
            $totalTabela = (float) $this->devolucoes->sum('quantidade');
        } elseif ($this->relationLoaded('movimentacao') && $this->movimentacao && $this->movimentacao->relationLoaded('devolucoes')) {
            $totalTabela = (float) $this->movimentacao->devolucoes->where('item_movimentacao_id', $this->id)->sum('quantidade');
        } elseif ($this->id) {
            $totalTabela = (float) Devolucao::where('item_movimentacao_id', $this->id)->sum('quantidade');
        }

        $totalMov = 0;
        $movOrigemId = $this->movimentacao_id;
        if ($movOrigemId && $this->produto_id) {
            $movDevs = Movimentacao::where('tipo', 'D')
                ->where('status_solicitacao', 'A')
                ->where(function ($q) use ($movOrigemId) {
                    $q->where('observacao', 'like', '%pedido #' . $movOrigemId . '%')
                      ->orWhere('observacao', 'like', '%pedido ' . $movOrigemId . '%');
                })
                ->pluck('id');

            if ($movDevs->isNotEmpty()) {
                $totalMov = (float) self::whereIn('movimentacao_id', $movDevs)
                    ->where('produto_id', $this->produto_id)
                    ->sum(\Illuminate\Support\Facades\DB::raw('CASE WHEN quantidade_liberada > 0 THEN quantidade_liberada ELSE quantidade_devolvendo END'));
            }
        }

        return max($totalTabela, $totalMov);
    }

    public function movimentacao()
    {
        return $this->belongsTo(Movimentacao::class);
    }
    public function produto()
    {
        return $this->belongsTo(Produto::class);
    }

    public function estoqueLote()
    {
        return $this->belongsTo(EstoqueLote::class, 'lote');
    }
    public function devolucoes()
    {
        return $this->hasMany(Devolucao::class);
    }

    public function getQuantidadeOriginalAtendidaAttribute()
    {
        if (isset($this->attributes['quantidade_original_atendida'])) {
            return (float) $this->attributes['quantidade_original_atendida'];
        }

        $mov = $this->relationLoaded('movimentacao') ? $this->movimentacao : $this->movimentacao()->first();
        if ($mov && $mov->tipo === 'D') {
            $origId = $mov->pedido_origem_id;
            if ($origId && $this->produto_id) {
                $itemOrig = self::where('movimentacao_id', $origId)
                    ->where('produto_id', $this->produto_id)
                    ->first();
                if ($itemOrig) {
                    return (float) $itemOrig->quantidade_liberada;
                }
            }
        }
        return null;
    }

    public function getQuantidadeLiberadaOriginalAttribute()
    {
        return $this->getQuantidadeOriginalAtendidaAttribute();
    }

    public function getQuantidadeAprovadaOriginalAttribute()
    {
        return $this->getQuantidadeOriginalAtendidaAttribute();
    }
}
