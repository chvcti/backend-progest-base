<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEstoqueLedgerTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('estoque_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('estoque_id');
            $table->unsignedBigInteger('produto_id');
            $table->unsignedBigInteger('usuario_id');
            
            // Tipo da operação que originou a movimentação contábil
            $table->enum('tipo_operacao', [
                'entrada', 
                'saida', 
                'transferencia', 
                'ajuste', 
                'estorno'
            ]);
            
            // Quantidade de fato que entrou ou saiu (positivo ou negativo)
            $table->decimal('quantidade_movimentada', 15, 4);
            $table->decimal('saldo_anterior', 15, 4);
            $table->decimal('saldo_novo', 15, 4);
            
            // Relacionamento com a tabela movimentacoes (opcional)
            $table->unsignedBigInteger('movimentacao_id')->nullable();
            
            // Utilizado para justificar ajustes manuais ou estornos excepcionais
            $table->text('justificativa')->nullable();
            
            $table->timestamps();

            // Índices e chaves estrangeiras
            $table->foreign('estoque_id')->references('id')->on('estoque')->onDelete('cascade');
            $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('movimentacao_id')->references('id')->on('movimentacao')->onDelete('set null');
            
            // Índices para otimizar relatórios e extratos
            $table->index(['estoque_id', 'created_at']);
            $table->index('tipo_operacao');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('estoque_ledger');
    }
}
