<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemMovimentacao extends Model
{
    protected $table = 'item_movimentacao';
    protected $fillable = ['movimentacao_id', 'produto_id', 'quantidade_solicitada', 'quantidade_liberada', 'quantidade_devolvendo', 'lote'];
    protected $appends = ['codigo_simpass', 'codigo_simpas', 'data_formatada', 'lotes_parsed', 'numero_lote', 'validade', 'quantidade_devolvida'];

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

        if ($this->relationLoaded('devolucoes')) {
            return (float) $this->devolucoes->sum('quantidade');
        }

        if ($this->relationLoaded('movimentacao') && $this->movimentacao && $this->movimentacao->relationLoaded('devolucoes')) {
            return (float) $this->movimentacao->devolucoes->where('item_movimentacao_id', $this->id)->sum('quantidade');
        }

        if ($this->id) {
            return (float) Devolucao::where('item_movimentacao_id', $this->id)->sum('quantidade');
        }

        return 0;
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
}
