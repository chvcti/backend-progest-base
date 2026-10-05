# 🗺️ Regras de Negócio e Matriz de Distribuição

O ProGest opera sob regras de negócio hospitalares estritas para garantir que nenhum medicamento ou insumo seja movimentado sem rastreabilidade, controle de validade e autorização formal.

---

## 🏥 1. Classificação Fundamental dos Setores

A topologia da rede hospitalar divide os 62 setores em três grupos com comportamentos operacionais distintos:

```
                       ┌─────────────────────────────────────────┐
                       │                   CAF                   │
                       │    (Central de Abastecimento Farm.)     │
                       │    Único com Entrada de Notas Fiscais   │
                       └────────────────────┬────────────────────┘
                                            │
                    ┌───────────────────────┴───────────────────────┐
                    ▼                                               ▼
         ┌─────────────────────┐                         ┌─────────────────────┐
         │ Setores com Estoque │                         │ Setores sem Estoque │
         │ Farmácias Satélites │                         │ UTIs, Enfermarias,  │
         │ Controle Físico FIFO│                         │ Clínicas e Apoio    │
         │    (Almoxarife)     │                         │    (Solicitante)    │
         └──────────┬──────────┘                         └─────────────────────┘
                    │                                               ▲
                    └───────────────────────────────────────────────┘
                                Abastecimento Local
```

### 1.1. CAF (Central de Abastecimento Farmacêutico)
* **Função:** Matriz logística de compras e recebimento central de suprimentos de todo o complexo hospitalar (Polo HGVC).
* **Entrada de Mercadorias:** **Exclusividade da CAF**. É o único setor onde é permitido registrar Notas Fiscais (NF) de fornecedores externos, com rateio contábil e geração de lotes.
* **Distribuição:** Ressupre tanto os setores com estoque (farmácias satélites) quanto setores sem estoque (consumo direto de grande volume).
* **Perfis Permitidos:** `admin` e `almoxarife`.

### 1.2. Setores com Estoque (`estoque: true` — 7 Farmácias)
* **Farmácias Homologadas:**
  1. **CAF** (HGVC)
  2. **Farmácia de Dispensação** (HGVC)
  3. **Satélite da Emergência** (HGVC)
  4. **Farmácia Central** (HAP)
  5. **Farmácia Satélite** (HAP)
  6. **Farmácia** (HCS)
  7. **Farmácia** (UPA)
* **Entrada de Mercadorias:** Ocorrem **exclusivamente via transferências aprovadas** vindas da CAF ou por remanejamento livre entre as próprias farmácias com estoque.
* **Controle Físico:** Controle de saldo e lotes por critério **FIFO/FEFO** (First-In, First-Out / First-Expired, First-Out).
* **Perfis Permitidos:** `almoxarife` e `admin`. (O backend bloqueia a associação de usuários com perfil `almoxarife` em setores sem estoque).

### 1.3. Setores sem Estoque (`estoque: false` — 55 Setores)
* **Composição:** UTIs, Clínicas Cirúrgicas, Salas de Emergência e 27 setores administrativos de apoio (RH, Compras, TI, Manutenção, etc.).
* **Consumo Direto:** O material recebido é considerado consumido imediatamente no ato do atendimento do paciente ou uso operacional, não gerando acervo de saldo físico nem cadastro de lotes no setor.
* **Modo de Pedido:** Criado via busca cega em **Fazer Pedido** (`/pedidos`), sem visualização dos saldos físicos ou lotes do setor fornecedor.
* **Perfis Permitidos:** `solicitante` e `admin`.

---

## 🔄 2. Matriz de Distribuição (`setor_distribuidor`)

A tabela `setor_distribuidor` determina formalmente quem pode solicitar material de quem:

| Setor Solicitante | Tipo | Distribuidores Habilitados | Regra de Negócio |
|---|:---:|---|---|
| **7 Farmácias com Estoque** | Com Estoque | **Qualquer farmácia com estoque** | **Remanejamento Livre Bidirecional:** Qualquer farmácia pode ressuprir outra farmácia da rede. |
| **Centro Cirúrgico (HGVC)** | Sem Estoque | **Todas as 7 farmácias com estoque** | Ponto de atendimento crítico; habilitado a requisitar de qualquer distribuidor com estoque. |
| **Clínicas e UTIs do HGVC** | Sem Estoque | 1. **Farmácia de Dispensação**<br>2. **CAF** | Dispensação para doses e prescrições imediatas; CAF para grandes volumes e soros. |
| **Salas de Emergência do HGVC** | Sem Estoque | 1. **Satélite da Emergência**<br>2. **CAF** | Satélite da Emergência para medicação imediata; CAF para apoio de materiais. |
| **Setores Administrativos (HGVC)** | Sem Estoque | **CAF** | Supridos diretamente pela CAF para materiais de expediente e consumo geral. |
| **UTIs do HAP (5, 6A, 6B)** | Sem Estoque | **Farmácia Satélite (HAP)** | Suprimento setorial imediato pela farmácia satélite do polo. |
| **Internação e Apoio do HAP** | Sem Estoque | **Farmácia Central (HAP)** | Supridos pela farmácia central do Hospital Afrânio Peixoto. |
| **Clínicas do HCS** | Sem Estoque | **Farmácia (HCS)** | Supridas localmente pela farmácia do Hospital Crescêncio Silveira. |

---

## 💊 3. Rastreabilidade, FIFO/FEFO e Portaria 344/98

### Alocação FIFO/FEFO Automática
Ao receber um pedido na aba de **Movimentações**, o almoxarife aciona o endpoint `/movimentacao/{id}/preview-lotes`. A API calcula automaticamente a alocação dos lotes com vencimento mais próximo (**FEFO**), respeitando a quantidade solicitada. O almoxarife pode:
* Liberar a quantidade sugerida integralmente.
* Liberar quantidade parcial (ex: em caso de contingenciamento).
* Reprovar com justificativa formal registrada no histórico.

### Devoluções Auditadas
Setores solicitantes podem devolver sobras de procedimentos cirúrgicos ou de internação. Ao registrar a devolução, o pedido é auditado pelo almoxarife da farmácia, que estorna a quantidade para o lote físico original.

### Controle Regulatório da Portaria 344/98
Os medicamentos pertencentes às listas **A1, A2, B1, B2 e C1** possuem flag regulatória específica (`lista_portaria`). Suas movimentações alimentam o **Livro de Registro e Relatório de Medicamentos Controlados**, exigido pela Vigilância Sanitária (ANVISA).
