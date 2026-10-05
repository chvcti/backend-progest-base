# 📡 Catálogo e Referência de APIs (REST)

A API do ProGest segue o padrão RESTful, retornando respostas no formato JSON e utilizando tokens de autenticação via **Laravel Sanctum**.

---

## 🔐 Autenticação e Cabeçalhos

Com exceção do endpoint de healthcheck e das rotas de login/registro, todas as rotas exigem autenticação prévia:

```http
Authorization: Bearer <TOKEN_SANCTUM>
Accept: application/json
Content-Type: application/json
```

---

## 🩺 1. Healthcheck e Autenticação

### `GET /api/health`
Verifica se a API está ativa e responsiva.
* **Acesso:** Público
* **Resposta `200 OK`:**
  ```json
  {
    "status": "ok",
    "message": "API is running",
    "timestamp": "2026-10-05T18:30:00+00:00"
  }
  ```

### `POST /api/login`
Autentica o usuário no sistema e retorna os dados de perfil e token.
* **Acesso:** Público
* **Payload:**
  ```json
  {
    "email": "adminti@gmail.com",
    "password": "adminti"
  }
  ```
* **Resposta `200 OK`:**
  ```json
  {
    "token": "1|AbCdEf123456...",
    "user": {
      "id": 1,
      "name": "ADMIN TI",
      "email": "adminti@gmail.com",
      "is_super_admin": true,
      "setores": [...]
    }
  }
  ```

### `POST /api/logout`
Revoga o token atual do usuário.
* **Acesso:** Autenticado

---

## 📊 2. Dashboard e Métricas

### `GET|POST /api/dashboard/metrics`
Retorna indicadores em tempo real para o setor selecionado (lotes críticos a vencer, total de itens, solicitações pendentes e movimentações do dia).
* **Parâmetros:** `setor_id` (integer)

---

## 🏢 3. Setores e Topologia

| Método | Endpoint | Descrição |
|---|---|---|
| `POST` | `/api/setores/listAll` | Lista todos os setores cadastrados |
| `POST` | `/api/setores/getDetail` | Obtém detalhes do setor (`estoque`, tipo, polo) |
| `POST` | `/api/setores/listWithAccess` | Lista setores aos quais o usuário logado tem permissão |
| `POST` | `/api/setores/listDistribuidoresParaSetor` | Retorna as farmácias autorizadas a fornecer para o setor |
| `POST` | `/api/setores/listConsumers` | Retorna os setores assistenciais atendidos pela farmácia |
| `POST` | `/api/setores/add` | Cria um novo setor (Exclusivo Administrador) |
| `POST` | `/api/setores/update` | Atualiza dados do setor |
| `POST` | `/api/setores/toggleStatus` | Ativa ou desativa um setor |

---

## 📦 4. Gestão de Estoque e Lotes Físicos

### `GET /api/estoque/setor/{setorId}`
Retorna a listagem consolidada do acervo de produtos do setor.
* **Filtros:** `termo` (busca por nome/código SIMPAS/código de barras), `grupo_id`, `tipo`.

### `POST /api/estoqueLote/list`
Retorna todos os lotes físicos individuais de um determinado produto no setor com suas validades e saldos.
* **Payload:**
  ```json
  {
    "setor_id": 1,
    "produto_id": 10
  }
  ```

### `PUT /api/estoque/{id}/quantidade-minima`
Atualiza a margem de estoque de segurança de um produto no setor.

---

## 📝 5. Movimentações, Pedidos e Devoluções

### `POST /api/movimentacao/add` (ou `/api/movimentacao/create`)
Cria uma solicitação de material (em rascunho `C` ou enviada `P`).
* **Payload:**
  ```json
  {
    "setor_origem_id": 1,
    "setor_destino_id": 2,
    "tipo": "T",
    "status_solicitacao": "P",
    "observacao": "Solicitação de reposição diária",
    "itens": [
      { "produto_id": 5, "quantidade_solicitada": 100 }
    ]
  }
  ```

### `GET /api/movimentacao/{id}/preview-lotes`
Sugere a alocação de lotes via **FIFO/FEFO** para atendimento da solicitação.

### `POST /api/movimentacao/{id}/process`
Efetiva o atendimento da movimentação (liberação total, parcial ou reprovação).
* **Payload:**
  ```json
  {
    "status_solicitacao": "A",
    "itens": [
      {
        "item_movimentacao_id": 12,
        "quantidade_liberada": 100,
        "lote": "[{\"lote\":\"LOTE-01\",\"qtd\":100}]"
      }
    ]
  }
  ```

### `POST /api/movimentacao/{id}/devolver`
Registra devolução de sobras de procedimentos para a farmácia de origem.

### `POST /api/movimentacao/consumo-interno`
Registra baixa física imediata por motivo de consumo interno ou avaria/quebra de frasco.

---

## 🧾 6. Entrada de Notas Fiscais (Exclusivo CAF)

### `POST /api/entrada/store`
Registra a entrada de NF com cálculo automático de rateio por lote e atualização de estoque.
* **Payload:**
  ```json
  {
    "nota_fiscal": "NF-10293",
    "fornecedor_id": 3,
    "setor_id": 1,
    "itens": [
      {
        "produto_id": 15,
        "lote": "LOTE-XYZ",
        "quantidade": 500,
        "data_fabricacao": "2025-01-01",
        "data_vencimento": "2027-01-01",
        "valor_unitario": 2.50
      }
    ]
  }
  ```

---

## 📈 7. Relatórios Regulatórios e Operacionais

| Endpoint | Descrição |
|---|---|
| `POST /api/relatorios/estoque/list` | Posição física consolidada e validades críticas |
| `POST /api/relatorios/movimentacoes/list` | Histórico analítico de transferências |
| `POST /api/relatorios/saidas/list` | Saídas por centro de custo e setor consumidor |
| `POST /api/relatorios/medicamentos-controlados/list` | Balanço analítico da Portaria 344/98 |
| `POST /api/relatorios/financeiro/entradas` | Custos acumulados de aquisição por fornecedor/NF |
| `POST /api/relatorios/financeiro/saidas` | Valor financeiro dispensado por setor de destino |
