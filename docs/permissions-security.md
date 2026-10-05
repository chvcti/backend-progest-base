# 🛡️ Segurança, Autenticação e Matriz RBAC

O ProGest adota um modelo de **Controle de Acesso Baseado em Papéis Setoriais** (*Sectoral Role-Based Access Control*), onde as permissões de um usuário não são apenas globais, mas dependem fundamentalmente do **setor ativo** selecionado na sessão.

---

## 👥 1. Perfis Fundamentais

```
                    ┌──────────────────────────────────────────────┐
                    │            Super Admin (adminti)             │
                    │   Bypass global irrestrito em todo o sistema │
                    └──────────────────────┬───────────────────────┘
                                           │
         ┌─────────────────────────────────┼─────────────────────────────────┐
         ▼                                 ▼                                 ▼
┌──────────────────┐             ┌──────────────────┐             ┌──────────────────┐
│   Administrador  │             │    Almoxarife    │             │   Solicitante    │
│  Gestão setorial,│             │ Operação física, │             │  Consumo final,  │
│  catálogo (CAF)  │             │  lotes e FIFO    │             │ requisição cega  │
│    e equipe      │             │ (só c/ estoque)  │             │   em /pedidos    │
└──────────────────┘             └──────────────────┘             └──────────────────┘
```

### 1.1. Perfil `solicitante`
* **Destinado a:** Médicos, enfermeiros, técnicos de enfermagem e assistentes administrativos.
* **Vínculo:** Associado a setores sem estoque (UTIs, Enfermarias, Clínicas) e na CAF.
* **Ações:**
  - Cria pedidos de medicamentos e materiais em busca cega (sem visualização de saldos de terceiros).
  - Pode salvar rascunhos, submeter para triagem e cancelar pedidos pendentes.
* **Travas:**
  - Não acessa a tela de estoque físico.
  - Não acessa a fila de triagem de movimentações (`TabMovimentacoes`).
  - Não emite relatórios analíticos ou regulatórios.

### 1.2. Perfil `almoxarife`
* **Destinado a:** Farmacêuticos hospitalares e operadores de almoxarifado.
* **Vínculo:** **Restrito exclusivamente a setores com estoque físico** (`estoque: true`). O controller `UsuarioSetorController` rejeita qualquer tentativa de vincular um almoxarife a um setor sem estoque.
* **Ações:**
  - Gerencia o estoque local e consulta posições de lotes.
  - Efetua a triagem de pedidos com alocação automática sugerida via FIFO/FEFO.
  - Realiza atendimento total, parcial ou reprovação motivada.
  - Lança Notas Fiscais (exclusivamente quando alocado na CAF).
  - Acessa 6 relatórios operacionais.

### 1.3. Perfil `admin`
Possui 4 níveis hierárquicos de abrangência:
1. **Admin de Setor:** Gerencia os vínculos de usuários do seu próprio setor e herda funções de almoxarife.
2. **Admin de Polo:** Cria e edita setores do seu polo e gerencia colaboradores de toda a unidade.
3. **Admin da CAF:** Cadastra produtos, fornecedores, grupos e unidades de medida no catálogo mestre.
4. **Super Admin (`adminti@gmail.com`):** Bypass global automático no backend, invisível em listagens comuns de usuários, acesso total à governança de polos.

---

## 📊 2. Matriz Comparativa de Permissões

| Recurso / Funcionalidade | Solicitante | Almoxarife | Admin Setor | Admin Polo | Admin CAF | Super Admin |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Fazer Pedido em Busca Cega | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Cancelar Próprio Pedido Pendente | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Visualizar Aba de Estoque Físico | ❌* | ✅ | ✅ | ✅ | ✅ | ✅ |
| Atender/Reprovar Movimentações | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Alocação FIFO de Lotes | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Lançamento de NF de Entrada | ❌ | ✅ (só CAF) | ❌ | ❌ | ✅ (só CAF) | ✅ |
| Relatórios Operacionais (6) | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Relatório de Usuários e Equipe | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| Gestão de Usuários e Vínculos | ❌ | ❌ | ✅ (setor) | ✅ (polo) | ✅ (CAF) | ✅ (global) |
| Cadastro e Edição de Setores | ❌ | ❌ | ❌ | ✅ (polo) | ✅ (geral) | ✅ (global) |
| Cadastro de Produtos e Fornecedores | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| Cadastro e Edição de Polos | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |

*\* Em setores com estoque físico, o solicitante visualiza a aba Estoque apenas em modo de leitura, preservando a busca cega ao fazer pedidos.*

---

## 🔒 3. Políticas de Sessão e Cookies

* **Sanctum Expiration:** Configurado por padrão em 480 minutos (8 horas de plantão hospitalar).
* **Prevenção de CSRF:** Em ambiente local sem certificado SSL (porta 80 HTTP), o arquivo `.env.docker.local` deve manter `APP_URL` e `FRONTEND_URL` como `http://` para evitar que os navegadores descartem cookies marcados com a flag `Secure`.
* **Rate Limiting:** A API possui proteção de taxa ajustada para 300 requisições por minuto (`API_RATE_LIMIT=300`) para garantir fluidez durante picos de triagem e dispensação de medicamentos.
