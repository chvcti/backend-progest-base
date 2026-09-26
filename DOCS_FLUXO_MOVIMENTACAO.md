# Documentação Arquitetural: Fluxo de Movimentações, Pedidos e Devoluções — ProGest

## 1. Centralização na Tabela `movimentacao`
Na arquitetura do ProGest, **não existe** uma tabela física ou entidade chamada `pedidos`. 
Todo o fluxo de requisição, suprimento, transferência, saída assistencial, perda/avaria e devolução é centralizado na tabela **`movimentacao`** e detalhado em **`item_movimentacao`**.

### 1.1. Tipos de Movimentação (`tipo`)
- **`tipo = 'T'` (Transferência Setorial):** Fluxo hospitalar padrão entre setores (ex: CAF $\to$ Farmácia Satélite $\to$ Clínicas/UTIs).
- **`tipo = 'D'` (Devolução):** Fluxo reverso originado de um pedido atendido. Devolve itens não administrados ou sobras cirúrgicas, restituindo saldo ao lote e estoque de origem.
- **`tipo = 'S'` (Saída Direta):** Dispensação assistencial direta ou baixa sem retorno.
- **`tipo = 'C'` (Consumo Interno / Perda / Avaria):** Baixa interna por quebra de frasco, extravio ou avaria identificada no almoxarifado.

---

## 2. Ciclo de Vida e Máquina de Estados (`status_solicitacao`)

| Status | Código | Visibilidade & Efeitos no Estoque |
|---|:---:|---|
| **Rascunho** | `'C'` | Visível **apenas** para o solicitante. Permite adicionar, alterar e remover itens iterativamente. **Não reserva nem deduz saldo**. |
| **Pendente** | `'P'` | Torna-se visível na fila de triagem do distribuidor. Bloqueia alterações pelo solicitante (permite apenas cancelamento `'X'`). |
| **Aprovado / Atendido** | `'A'` | O almoxarife/admin do distribuidor analisa as quantidades e aprova. **Gera baixa/consumo estrito via FIFO nos lotes**. |
| **Reprovado** | `'R'` | Negado pelo distribuidor com justificativa obrigatória registrada em `observacao`. Não afeta estoques. |
| **Cancelado** | `'X'` | Cancelado pelo próprio solicitante enquanto o pedido estava pendente (`'P'`). |

---

## 3. Travas de Negócio e Integridade Transacional (Fases 4 e 5)

### 3.1. Trava de Teto de Solicitação (Trava 1)
- **Regra:** O almoxarife é estritamente impedido de aprovar uma quantidade maior do que a solicitada pelo requisitante (`$qtdLiberar > $qtdPedida`).
- **Validação:** Aplica-se tanto para transferências (`'T'`) quanto para devoluções (`'D'`). Caso violado, a API aborta a requisição com HTTP 422 e rollback automático.

### 3.2. Atendimento Parcial com Item Zerado por Desabastecimento (Trava 2)
- **Regra:** Quando o almoxarifado não possuir estoque para determinado produto do pedido, o item pode ser liberado com quantidade **zero** (`quantidade_liberada = 0`).
- **Preservação de Histórico:** O item zerado permanece registrado no pedido para comprovar formalmente a recusa por desabastecimento. O pedido é aprovado normalmente se ao menos um item tiver quantidade liberada $> 0$. Se todos os itens forem zerados, o pedido deve ser reprovado (`'R'`).

### 3.3. Prévia Reativa de Lotes FIFO (`/preview-lotes`)
- **Regra:** O modal de atendimento no frontend consulta reativamente a rota `/api/movimentacao/{id}/preview-lotes`.
- **Funcionamento:** Ordena os lotes vigentes com saldo disponível por `data_vencimento ASC, id ASC`, exibindo em tempo real quais lotes e validades serão consumidos conforme o almoxarife altera a quantidade a liberar.

### 3.4. Teto de Devoluções e Abate Consecutivo
- **Regra:** Devoluções do tipo `'D'` só podem ser originadas de pedidos atendidos (`'A'`).
- **Fórmula de Teto:** O saldo devolvível máximo de cada item é calculado por:
  $$\text{Saldo Devolvível} = \text{Quantidade Atendida} - \text{Quantidade Já Devolvida}$$
- **Tentativas Consecutivas:** Se um pedido de 50 ampolas teve 15 devolvidas anteriormente, uma nova devolução fica limitada a no máximo 35 ampolas. Tentativas acima desse teto são rejeitadas com erro 422.

### 3.5. Sigilo Seletivo de Estoque e Requisição Cega
- **No Painel do Setor:** O solicitante **só visualiza a aba de Estoque** se o setor atual possuir controle físico de estoque (`setor.estoque == true`). Em setores assistenciais sem estoque (ex: UTI, Clínicas), a aba de Estoque é completamente oculta para o solicitante.
- **Requisição Cega (Novo Pedido):** Na busca de produtos para adicionar ao carrinho (`ProductSearch.vue`), é proibido exibir saldo, quantidade física ou badge de disponibilidade do setor distribuidor, garantindo pedidos baseados em prescrição médica real e não no estoque alheio.

### 3.6. Auditoria Obrigatória do MySQL
- Triggers ativas garantem que qualquer movimentação com status `'A'` ou `'R'` possua obrigatoriamente `aprovador_usuario_id` preenchido.
- Triggers `before_insert_estoque` e `before_update_estoque` impedem a inserção de saldos negativos, mantendo alinhamento estrito com os saldos em `estoque_lote`.
