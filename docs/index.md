# 🏥 ProGest Backend API — Visão Geral

> **Componente Backstage:** `progest-backend-api`  
> **Tipo:** `service` | **Lifecycle:** `production` | **Owner:** `squad-progest-hgvc`  
> **Sistema:** `progest-hospitalar`

O **ProGest Backend API** é o núcleo de regras de negócio, persistência de dados e segurança do ecossistema hospitalar ProGest. Desenvolvido em **PHP 8.2** sobre o framework **Laravel 10**, a aplicação é responsável pelo gerenciamento de estoques, rastreabilidade física de lotes por critério FIFO/FEFO, dispensação assistencial para enfermarias e UTIs, cadeia de ressuprimento hospitalar e conformidade regulatória com a **Portaria SVS/MS nº 344/1998** e com o catálogo **SIMPAS Bahia**.

---

## 🎯 Principais Responsabilidades do Serviço

1. **Gestão Multissetorial e Polos:** Isolamento e governança de estoques entre 4 polos hospitalares (HGVC, HAP, HCS, UPA) e 62 setores cadastrados.
2. **Rastreabilidade Físico-Financeira:** Controle rigoroso de lotes (`estoque_lotes`), datas de validade, valores unitários e histórico de entradas por Nota Fiscal.
3. **Ledger Imutável de Estoque (`estoque_ledger`):** Registro de auditoria contábil de cada movimentação de entrada, transferência, dispensação, perda e devolução.
4. **Matriz de Suprimentos (`setor_distribuidor`):** Validação de rotas autorizadas de requisição entre setores solicitantes e farmácias distribuidoras.
5. **Autenticação e RBAC Granular:** Controle de sessão via **Laravel Sanctum** com papéis vinculados por setor (`usuario_setor`) e por polo (`usuario_polo`).
6. **Relatórios Regulatórios e Financeiros:** Emissão de relatórios operacionais, consumo por centro de custo e balanços de medicamentos controlados.

---

## 🛠️ Stack Tecnológica

| Camada | Tecnologia | Detalhes |
|---|---|---|
| **Linguagem** | PHP 8.2 (FPM) | Execução via container Docker oficial |
| **Framework** | Laravel 10.x | Arquitetura MVC com Controllers e Eloquent ORM |
| **Banco de Dados** | MySQL 8.0 | InnoDB, Foreign Keys estritas e Triggers nativas |
| **Proxy Reverso** | Traefik v2.10 / v2.11 | Roteamento dinâmico via Docker Provider e SSL Let's Encrypt |
| **Autenticação** | Laravel Sanctum | Tokens bearer para API REST e cookies de sessão |
| **Processamento** | PhpSpreadsheet | Importação e geração estática de catálogos oficiais |

---

## 🌐 Ambientes e Endpoints Oficiais

* **Repositório GitHub:** [https://github.com/progest-hgvca/backend-progest-base](https://github.com/progest-hgvca/backend-progest-base)
* **Organização GitHub:** [https://github.com/progest-hgvca](https://github.com/progest-hgvca)
* **Ambiente de Produção / Homologação (AWS):** `http://18.230.26.35/api`
  - Healthcheck: `http://18.230.26.35/api/health`
* **Ambiente de Desenvolvimento Local (Docker Desktop):** `http://app.localhost/api` (ou `http://api.localhost`)
  - Healthcheck: `http://app.localhost/api/health`

---

## 👥 Credenciais Padrão do Sistema

### 1. Base Oficial de Produção (Limpa)
* **Super Admin (adminti):**
  - **E-mail:** `adminti@gmail.com`
  - **Senha:** `adminti`
  - **Escopo:** Bypass irrestrito em todos os polos, setores e cadastros mestres.

### 2. Base de Homologação / Demonstração (`DemonstracaoSistemaSeeder`)
* **Admin Geral:** `admin.geral@progest.teste` | Senha: `Admin123`
* **Almoxarife CAF (HGVC):** `almoxarife.caf@progest.teste` | Senha: `Admin123`
* **Solicitante UTI Adulto (HGVC):** `solicitante.uti@progest.teste` | Senha: `Admin123`
* **Almoxarife Central (HAP):** `almoxarife.hap@progest.teste` | Senha: `Admin123`
* **Solicitante Clínica Médica (HAP):** `solicitante.hap@progest.teste` | Senha: `Admin123`

---

## 📂 Mapa da Documentação Técnica

* **[Arquitetura & Design](architecture.md):** Padrões arquiteturais, containers Docker, Triggers e integração.
* **[Regras de Negócio & Distribuição](domain-rules.md):** Classificação dos 62 setores, fluxo CAF, remanejamento e FIFO.
* **[Catálogo de APIs](api-reference.md):** Referência completa de endpoints REST, payloads e códigos de retorno.
* **[Modelo de Dados & Ledger](database-schema.md):** Diagrama ER, invariantes de estoque e estrutura do Ledger contábil.
* **[Segurança & RBAC](permissions-security.md):** Matriz de permissões, perfis setoriais e travas de segurança.
* **[Dados & Seeders](seeders-data.md):** Provisionamento de base limpa (`DatabaseSeeder`) e homologação (`DemonstracaoSistemaSeeder`).
* **[Deploy & Operações](deployment-operations.md):** Procedimento de subida em servidores AWS EC2 / Linux, backups e monitoramento.
