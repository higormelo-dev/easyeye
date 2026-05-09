# Docker — EasyEye

Guia completo de configuração e operação do ambiente Docker do EasyEye.

---

## O que é automático

Ao executar `docker compose up -d`, os containers cuidam de tudo sozinhos:

| Passo | Quem faz | Quando |
|---|---|---|
| `composer install` | `entrypoint.sh` (app) | `vendor/` ausente ou `composer.lock` atualizado |
| `php artisan key:generate` | `entrypoint.sh` (app) | `APP_KEY` vazia no `.env` |
| `php artisan migrate --force` | `entrypoint.sh` (app) | a cada inicialização do container `app` |
| `npm install && npm run dev` | serviço `node` | a cada inicialização do container `node` |

> **Seeds não rodam automaticamente** — execute uma vez manualmente após o primeiro `up` (veja o passo a passo abaixo).

---

## Arquitetura

```
Browser
  │
  ├── :8085 ──► nginx:1.27-alpine ──► easyeye_app (php-fpm:9000)
  │                                         │
  │                                    entrypoint.sh
  │                                    ├── composer install (se necessário)
  │                                    ├── key:generate   (se APP_KEY vazia)
  │                                    ├── migrate --force
  │                                    └── php-fpm
  │
  ├── :5173 ──► easyeye_node (Vite dev server + HMR)
  │              └── npm install + npm run dev (automático)
  │
  └── [host Ubuntu]
        ├── PostgreSQL :5432  (acessado via host.docker.internal)
        └── Redis      :6379  (acessado via host.docker.internal)

easyeye_worker ──► supervisord ──► queue:work (2 processos: default, high)
```

---

## Estrutura de arquivos Docker

```
easyeye/
├── Dockerfile                          # Imagem PHP 8.4-fpm (app + worker)
├── docker-compose.yml                  # Orquestração dos serviços
├── .dockerignore                       # Arquivos excluídos do build context
└── .docker/
    ├── nginx/
    │   └── laravel.conf                # Configuração do servidor web
    ├── php/
    │   ├── custom.ini                  # Limites PHP (upload, memória, timezone)
    │   ├── opcache.ini                 # OPcache para performance
    │   └── entrypoint.sh              # composer install + key:generate + migrate
    └── supervisor/
        ├── supervisord.conf            # Configuração base do Supervisor
        └── easyeye-worker.conf         # Definição dos processos de fila
```

---

## Pré-requisitos

### 1. Docker e Docker Compose

```bash
docker --version          # >= 24.x
docker compose version    # >= 2.x
```

### 2. PostgreSQL no Ubuntu (host)

O PostgreSQL roda no Ubuntu host, **não** em container.

```bash
# Verificar se está rodando
pg_lsclusters

# Verificar se aceita conexões externas (deve retornar listen_addresses = '*')
sudo grep "listen_addresses" /etc/postgresql/*/main/postgresql.conf
```

Se `listen_addresses` não for `'*'`, edite e reinicie:

```bash
sudo nano /etc/postgresql/*/main/postgresql.conf
# listen_addresses = '*'

sudo systemctl reload postgresql
```

Adicione permissão para a rede Docker no `pg_hba.conf`:

```bash
sudo nano /etc/postgresql/*/main/pg_hba.conf
# Adicione ao final:
# host    all    all    172.0.0.0/8    scram-sha-256

sudo systemctl reload postgresql
```

Crie o banco e o usuário:

```bash
sudo -u postgres psql -c "CREATE DATABASE easyeye;"
sudo -u postgres psql -c "CREATE USER easyeye WITH PASSWORD 'sua_senha';"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE easyeye TO easyeye;"
```

### 3. Redis no Ubuntu (host)

O Redis roda no Ubuntu host, **não** em container.

```bash
# Verificar se está rodando
systemctl is-active redis-server

# Verificar se aceita conexões além de localhost
redis-cli config get bind
# Esperado: bind  127.0.0.1 172.17.0.1
```

Se retornar apenas `127.0.0.1`, configure:

```bash
sudo nano /etc/redis/redis.conf
# bind 127.0.0.1 172.17.0.1

sudo systemctl restart redis-server
```

> **`172.17.0.1`** é o gateway padrão da bridge Docker. Confirme com `ip addr show docker0`.

---

## Configuração inicial (primeira vez)

### 1. Copiar e ajustar o .env

```bash
cp .env.example .env
```

Edite as variáveis obrigatórias:

```env
DB_PASSWORD=sua_senha_postgres
```

> `APP_KEY` pode ficar vazia — o `entrypoint.sh` gera automaticamente no primeiro `up`.

Configurações Docker já pré-configuradas no `.env.example`:

```env
# Acesso ao host Ubuntu a partir dos containers
DB_HOST=host.docker.internal
REDIS_HOST=host.docker.internal

# Drivers
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis

# Separação de databases Redis
REDIS_DB=0          # filas
REDIS_CACHE_DB=1    # cache
REDIS_SESSION_DB=3  # sessões

# Portas dos containers
APP_PORT=8085
VITE_PORT=5173
```

### 2. Build das imagens

```bash
docker compose build
```

> Na primeira vez leva alguns minutos — baixa a imagem base PHP 8.4, instala
> extensões e compila o wkhtmltopdf.

### 3. Subir os serviços

```bash
docker compose up -d
```

O `entrypoint.sh` executa automaticamente, nesta ordem:
1. `composer install` (se necessário)
2. `php artisan key:generate` (se `APP_KEY` estiver vazia)
3. `php artisan migrate --force`

O serviço `node` executa `npm install && npm run dev` automaticamente.

Acompanhe a inicialização:

```bash
docker compose logs -f app
```

Aguarde a linha `[entrypoint] Executando migrations...` e a conclusão das migrations antes de acessar a aplicação.

### 4. Seeds (opcional — apenas na primeira vez)

Os seeds populam tabelas de referência (especialidades, CIDs, planos, etc.).
Execute **uma única vez** após o primeiro `up`:

```bash
docker exec easyeye_app php artisan db:seed
```

> Não execute seeds repetidamente — a maioria não é idempotente e duplicará registros.

### 5. Verificar os serviços

```bash
docker compose ps
```

Resultado esperado:

```
NAME               STATUS
easyeye_app        Up (healthy)
easyeye_worker     Up (healthy)
easyeye_nginx      Up
easyeye_node       Up
```

Acesse: **http://localhost:8085**

---

## Serviços

| Container | Imagem | Porta | Função |
|---|---|---|---|
| `easyeye_app` | `php:8.4-fpm-bookworm` (custom) | 9000 (interno) | PHP-FPM — processa requisições Laravel |
| `easyeye_worker` | mesma imagem do app | — | Supervisor + queue workers |
| `easyeye_nginx` | `nginx:1.27-alpine` | 8085 → 80 | Proxy reverso HTTP |
| `easyeye_node` | `node:22-alpine` | 5173 | Vite dev server com HMR |

### Como o Vite funciona em Docker

O `easyeye_node` roda `npm install && npm run dev` na inicialização.
O Vite escuta em `0.0.0.0:5173` dentro do container (acessível via porta mapeada).

Ao subir, o Vite cria o arquivo `public/hot` com a URL do dev server.
O `@vite()` nas views Blade lê esse arquivo e injeta scripts apontando para
`localhost:5173` — o browser puxa JS/CSS direto do Vite, com HMR em tempo real.

```
Browser ──► localhost:5173 ──► easyeye_node (Vite HMR)
```

### Separação de databases Redis

| DB | Uso | Variável |
|---|---|---|
| 0 | Filas (queue:work) | `REDIS_DB=0` |
| 1 | Cache | `REDIS_CACHE_DB=1` |
| 3 | Sessões | `REDIS_SESSION_DB=3` |

### Worker (Supervisor)

O `easyeye_worker` roda 2 processos paralelos na fila `default,high` com:

- `--timeout=90` — jobs longos são encerrados após 90s
- `--tries=3` — 3 tentativas antes de mover para failed_jobs
- `--max-jobs=1000` — recicla o processo após 1000 jobs (evita memory leak)

---

## Comandos do dia a dia

### Subir e parar

```bash
# Subir todos os serviços em background
docker compose up -d

# Subir com rebuild (após alterar Dockerfile ou dependências)
docker compose up -d --build

# Parar sem remover containers
docker compose stop

# Parar e remover containers (mantém volumes e imagens)
docker compose down
```

### Logs

```bash
# Todos os serviços
docker compose logs -f

# Serviço específico
docker compose logs -f app
docker compose logs -f worker
docker compose logs -f node

# Logs do Supervisor (workers)
docker exec easyeye_worker cat /var/log/supervisor/easyeye-default.log
```

### Artisan

```bash
# Formato: docker exec easyeye_app php artisan <comando>

docker exec easyeye_app php artisan migrate
docker exec easyeye_app php artisan migrate:rollback
docker exec easyeye_app php artisan db:seed
docker exec easyeye_app php artisan cache:clear
docker exec easyeye_app php artisan queue:failed
docker exec easyeye_app php artisan queue:retry all
docker exec easyeye_app php artisan tinker
```

### Composer

```bash
# Formato: docker exec easyeye_app composer <comando>

# Adicionar pacote
docker exec easyeye_app composer require vendor/pacote

# Atualizar dependências (intencional — commitar composer.lock após)
docker exec easyeye_app composer update
```

> `composer install` roda automaticamente no entrypoint — não é necessário executar manualmente.

### Frontend (Vite / Node)

```bash
# O node já sobe com npm run dev automaticamente.
# Para forçar rebuild de assets de produção:
docker compose run --rm node npm run build

# Instalar novo pacote npm
docker exec easyeye_node npm install nome-do-pacote

# Ver logs do Vite
docker compose logs -f node
```

### Acessar shell dos containers

```bash
# Shell no app (php-fpm)
docker exec -it easyeye_app sh

# Shell no worker
docker exec -it easyeye_worker sh

# Shell no node
docker exec -it easyeye_node sh
```

---

## Variáveis de ambiente relevantes

| Variável | Padrão | Descrição |
|---|---|---|
| `APP_PORT` | `8085` | Porta HTTP do nginx no host |
| `VITE_PORT` | `5173` | Porta do Vite dev server no host |
| `APP_USER` | `easyeye` | Usuário do processo PHP dentro do container |
| `UID` | `1000` | UID do usuário (deve bater com o do host para evitar problemas de permissão) |
| `GID` | `1000` | GID do grupo |
| `QUEUE_CONNECTION_DOCKER` | `database` | Conexão de fila usada pelo worker no Docker |
| `RUN_MIGRATIONS` | `true` (no serviço app) | Habilita `migrate --force` automático no entrypoint |

---

## Build de produção (sem Vite dev server)

Em produção não se usa o Vite dev server. Os assets são compilados
uma vez e servidos via nginx como arquivos estáticos.

```bash
# 1. Gerar assets compilados
docker compose run --rm node npm run build

# 2. Subir sem o container node
docker compose up -d app worker nginx
```

O `npm run build` gera `public/build/manifest.json`. Sem o arquivo `public/hot`
(que o dev server cria), o `@vite()` usa o manifest para injetar os assets.

---

## Ativar PostgreSQL ou Redis em container (opcional)

Se preferir não usar o PostgreSQL/Redis do Ubuntu host, os blocos já estão
preparados no `docker-compose.yml`. Descomente as seções marcadas com
`[DOCKER-DB]` e `[DOCKER-REDIS]`, atualize o `.env` e suba novamente:

```bash
# .env
DB_HOST=db          # nome do serviço no compose
REDIS_HOST=redis    # nome do serviço no compose

docker compose up -d --build
```

---

## Troubleshooting

### 502 Bad Gateway após `docker compose up -d --build`

Causa: ao recriar o container `app` com `--build`, o Docker atribui um novo IP ao container.
O Nginx (que não foi recriado) tinha cacheado o IP antigo → Connection refused → 502.

O `laravel.conf` já está configurado com o resolver DNS interno do Docker (`127.0.0.11 valid=10s`)
e usa `set $phpfpm easyeye_app` para forçar re-resolução por request. Se ainda ocorrer,
recarregue o Nginx manualmente:

```bash
docker exec easyeye_nginx nginx -s reload
```

### Container app fica unhealthy

```bash
docker compose logs app
```

Causas comuns:
- `composer install` falhando → verificar compatibilidade de pacotes com PHP 8.4
- Migration falhando → banco inacessível ou credenciais erradas no `.env`
- PostgreSQL/Redis inacessível → verificar `listen_addresses` e `pg_hba.conf`

### Migrations falham no startup

```bash
# Ver erro exato
docker compose logs app | grep -A5 "migrate"

# Verificar conectividade com o banco a partir do container
docker exec easyeye_app php artisan db:show
```

Causas comuns:
- `DB_PASSWORD` incorreta no `.env`
- PostgreSQL não aceita conexões da rede Docker (`pg_hba.conf`)
- `listen_addresses` não configurado como `'*'` no `postgresql.conf`

### Vite manifest not found

Indica que o container `node` ainda não terminou o build ou não está rodando.

```bash
# Verificar se o node está up
docker compose ps node

# Ver logs do Vite
docker compose logs node

# Forçar build manual
docker compose run --rm node npm run build
```

### HMR não recarrega / alterações não refletem no browser

O Vite usa `inotify` por padrão, mas eventos de filesystem **não são propagados** do host para dentro de volumes montados no Docker/Linux. O `vite.config.js` já está configurado com `server.watch.usePolling: true` (intervalo 300 ms) para contornar isso — o watcher verifica os arquivos ativamente em vez de esperar por eventos do kernel.

Se mesmo com polling o HMR não funcionar, verifique se o WebSocket do HMR está acessível:

```bash
# O browser precisa alcançar localhost:5173 — verifique se a porta está mapeada
docker compose ps node
# Esperado: 0.0.0.0:5173->5173/tcp
```

### Problemas de permissão em storage/

```bash
docker exec easyeye_app chmod -R 775 storage bootstrap/cache
docker exec easyeye_app chown -R easyeye:www-data storage bootstrap/cache
```

### host.docker.internal não resolve

Verifique se o `extra_hosts` está presente no serviço no `docker-compose.yml`:

```yaml
extra_hosts:
  - "host.docker.internal:host-gateway"
```

Se ainda não resolver, descubra o IP do gateway Docker e use diretamente:

```bash
ip addr show docker0 | grep "inet "
# Ex: 172.17.0.1 → use esse IP no .env em vez de host.docker.internal
```
