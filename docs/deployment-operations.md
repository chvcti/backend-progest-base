# 🚀 Deploy, Infraestrutura e Operações — Backend

Este guia orienta engenheiros DevOps, SREs e desenvolvedores sobre a instalação, configuração e manutenção do container da API do ProGest em servidores Linux (AWS EC2, VPS ou servidores locais).

---

## 🛠️ 1. Requisitos do Servidor

* **Sistema Operacional:** Ubuntu 22.04 LTS ou Ubuntu 24.04 LTS (x86_64).
* **Hardware Mínimo:** 1 vCPU / 1 GB de RAM (obrigatório criar SWAP de 2GB).
* **Portas no Firewall / Security Group:**
  - `22` (SSH): Acesso gerencial.
  - `80` (HTTP): Roteamento web e validação Let's Encrypt.
  - `443` (HTTPS): Acesso seguro com certificado SSL.
  - `8080` (Opcional): Dashboard do Traefik.

---

## 📋 2. Procedimento de Instalação e Subida

### Passo 1: Configuração do Swap de 2GB
```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

### Passo 2: Rede Docker Compartilhada
```bash
docker network create traefik-public
```

### Passo 3: Clonagem e Configuração do Backend
```bash
cd ~
git clone https://github.com/progest-hgvca/backend-progest-base.git
cd ~/backend-progest-base

# Configuração do ambiente de servidor
cp .env.aws.example .env.aws

# Substituir o IP ou Domínio oficial
sed -i 's/APP_DOMAIN=IP_DA_AWS/APP_DOMAIN=18.230.26.35/g' .env.aws

# Subir os containers (API + MySQL 8)
docker compose -f docker-compose.aws.yml up -d --build

# Gerar e gravar a chave de criptografia da aplicação
KEY=$(docker compose -f docker-compose.aws.yml exec -T progest-api php artisan key:generate --show)
sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|" .env.aws

# Reiniciar o container da API para carregar a nova chave
docker compose -f docker-compose.aws.yml up -d progest-api
```

### Passo 4: Execução das Migrações e Seeders
```bash
# Para base oficial de produção:
docker compose -f docker-compose.aws.yml exec progest-api php artisan migrate:fresh --seed

# OU para carregar cenário de demonstração com histórico:
docker compose -f docker-compose.aws.yml exec progest-api php artisan db:seed --class=DemonstracaoSistemaSeeder
```

---

## 🔍 3. Variáveis de Ambiente Cruciais (`.env.docker.local` / `.env.aws`)

| Variável | Valor Padrão Local | Descrição |
|---|---|---|
| `APP_ENV` | `local` / `production` | Ambiente de execução |
| `APP_DOMAIN` | `app.localhost` / `18.230.26.35` | Hostname principal usado pelo Traefik |
| `APP_KEY` | *(Gerada via key:generate)* | Chave de criptografia de sessões e senhas |
| `DB_CONNECTION` | `mysql` | Driver do banco |
| `DB_HOST` | `mysql` | **Atenção:** Em Docker, usar o nome do container `mysql`, nunca `127.0.0.1` |
| `DB_DATABASE` | `progest` | Nome da base de dados |
| `DB_USERNAME` | `progest` | Usuário do banco |
| `DB_PASSWORD` | `progest_secret` | Senha do banco |
| `ADMIN_DEFAULT_EMAIL` | `adminti@gmail.com` | E-mail do Super Admin para os seeders |
| `ADMIN_DEFAULT_PASSWORD` | `adminti` | Senha inicial do Super Admin |
| `USER_DEFAULT_PASSWORD` | `Admin123` | Senha padrão para os usuários de teste |
| `API_RATE_LIMIT` | `300` | Limite de requisições por minuto |

---

## 💾 4. Rotinas de Backup e Manutenção

### Backup Automatizado do Banco de Dados
```bash
docker exec backend-progest-base-mysql-1 mysqldump -u progest -pprogest_secret progest | gzip > ~/backup_progest_$(date +%Y%m%d_%H%M%S).sql.gz
```

### Visualização de Logs
```bash
# Logs em tempo real da API Laravel
docker compose -f docker-compose.aws.yml logs -f --tail 100 progest-api

# Logs do banco de dados MySQL
docker compose -f docker-compose.aws.yml logs -f --tail 50 mysql
```

### Limpeza de Caches do Laravel
```bash
docker compose -f docker-compose.aws.yml exec progest-api php artisan optimize:clear
```
