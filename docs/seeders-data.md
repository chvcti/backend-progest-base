# 🌾 Provisionamento de Dados e Seeders

O ProGest conta com uma estratégia consolidada de seeders automatizados para suportar desde a implantação oficial limpa em hospitais até ambientes de homologação clínica com simulação de histórico temporal.

---

## 🚀 1. As Duas Estratégias de Provisionamento

### Opção A: Base de Produção Limpa (`DatabaseSeeder`)
Esta é a base oficial entregue ao cliente ou utilizada em ambiente de entrada em operação.
* **Comando:**
  ```bash
  docker compose -f docker-compose.local.yml exec progest-api php artisan migrate:fresh --seed
  ```
* **O que é provisionado:**
  1. **Polos Hospitalares:** 4 Polos com siglas oficiais (`HGVC`, `HAP`, `HCS`, `UPA`).
  2. **Topologia Setorial:** 62 setores oficiais cadastrados com seus respectivos tipos e flags de estoque.
  3. **Matriz de Distribuição (`setor_distribuidor`):** Todas as regras de quem pode pedir para quem configuradas.
  4. **Catálogo Oficial SIMPAS:** 417 produtos farmacêuticos e hospitalares catalogados com códigos SIMPAS, código de barras e listas da Portaria 344/98.
  5. **Regimes e Fornecedores:** 13 regimes de contratação e fornecedores homologados da área de saúde.
  6. **Super Administrador:** Usuário raiz `adminti@gmail.com` com senha `adminti`.
  7. **Saldos:** Todos os estoques e movimentações iniciam **100% zerados**, prontos para o inventário inicial do hospital.

---

### Opção B: Base de Homologação e Demonstração (`DemonstracaoSistemaSeeder`)
Projetada para validação de fluxos clínicos, auditorias e testes de usabilidade antes do go-live.
* **Comando:**
  ```bash
  docker compose -f docker-compose.local.yml exec progest-api php artisan migrate:fresh --seed
  docker compose -f docker-compose.local.yml exec progest-api php artisan db:seed --class=DemonstracaoSistemaSeeder
  ```
* **O que é provisionado no cenário simulado:**
  - **Histórico Retroativo de 90 Dias:** Movimentações com datas realistas retroativas para simular curvas de consumo hospitalar.
  - **Entradas por Nota Fiscal:** NFs lançadas de laboratórios reais (Eurofarma, Cristália, Fresenius, Cremer) na CAF.
  - **Lotes com Datas Críticas:** Lotes regulares, lotes próximos do vencimento (alerta visual) e lotes vencidos para teste de quarentena.
  - **Ciclo Completo de Pedidos:** Casos reais com status Aprovado, Parcial, Reprovado com justificativa e Rascunho.
  - **Devoluções Auditadas:** Estornos físicos aprovados entre UTI e CAF.
  - **Usuários Homologados:** Contas de teste com a senha padrão `Admin123`:
    - `admin.geral@progest.teste`
    - `almoxarife.caf@progest.teste`
    - `solicitante.uti@progest.teste`
    - `almoxarife.hap@progest.teste`
    - `solicitante.hap@progest.teste`

---

## ⚡ 2. Performance do Catálogo: Seeder Estático

Em vez de processar planilhas Excel em tempo de execução via *seed* (o que consumiria gigabytes de memória e minutos de processamento), o ProGest utiliza o **`CatalogoProdutosOficialSeeder`**:
* Um arquivo PHP com array pré-processado contendo os 417 produtos.
* Tempo de inserção no banco: **menos de 2 segundos**.
* Baixo consumo de memória RAM, viabilizando execução até em instâncias com 1GB de RAM.

### Como atualizar o catálogo no futuro:
Se o hospital fornecer uma nova planilha `.xlsx` com novos códigos SIMPAS, utilize o comando Artisan personalizado:
```bash
php artisan produtos:gerar-seeder "storage/app/nova_planilha_produtos.xlsx"
```
O comando atualizará a classe `CatalogoProdutosOficialSeeder.php` automaticamente com os novos dados.
