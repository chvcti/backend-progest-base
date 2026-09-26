<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movimentacao extends Model
{
    use HasFactory;

    protected $table = 'movimentacao';
    protected $fillable = ['usuario_id', 'setor_origem_id', 'setor_destino_id', 'tipo', 'data_hora', 'observacao', 'status_solicitacao', 'aprovador_usuario_id'];
    protected $appends = ['data_formatada', 'data', 'numero_pedido', 'pedido_origem_id', 'movimentacao_origem_id', 'respondido_por', 'avaliado_por'];

    public function getDataFormatadaAttribute()
    {
        $dt = $this->data_hora ?? $this->created_at;
        return $dt ? \Carbon\Carbon::parse($dt)->format('d/m/Y') : null;
    }

    public function getDataAttribute()
    {
        return $this->getDataFormatadaAttribute();
    }

    public function getNumeroPedidoAttribute()
    {
        return $this->id;
    }

    public function getPedidoOrigemIdAttribute()
    {
        if ($this->tipo === 'D' && !empty($this->observacao)) {
            if (preg_match('/pedido #?(\d+)/i', $this->observacao, $matches)) {
                return (int) $matches[1];
            }
            if (preg_match('/(?:movimentac(?:ao|ão)|origem)\s*#?\s*(\d+)/i', $this->observacao, $matches)) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    public function getMovimentacaoOrigemIdAttribute()
    {
        return $this->getPedidoOrigemIdAttribute();
    }

    public function getRespondidoPorAttribute()
    {
        return $this->aprovador;
    }

    public function getAvaliadoPorAttribute()
    {
        return $this->aprovador;
    }

    public function usuario()
    {
        return $this->belongsTo(User::class);
    }

    public function setorOrigem()
    {
        return $this->belongsTo(Setores::class, 'setor_origem_id');
    }

    public function setorDestino()
    {
        return $this->belongsTo(Setores::class, 'setor_destino_id');
    }

    public function itens()
    {
        return $this->hasMany(ItemMovimentacao::class);
    }

    public function aprovador()
    {
        return $this->belongsTo(User::class, 'aprovador_usuario_id');
    }
    public function devolucoes()
    {
        return $this->hasMany(Devolucao::class);
    }
}
