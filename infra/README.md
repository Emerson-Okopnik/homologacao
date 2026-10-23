# Produção Oracle ARM64

Este guia implementa os arquivos do runbook no projeto. Pressupõe Ubuntu 24.04 ARM64, Docker Engine/Compose oficiais, repositório em `/opt/homologa` e uma A1 com 2 OCPUs/12 GB. Provisionamento OCI, NSG, DNS, orçamento e aceite de disponibilidade continuam sendo etapas da conta do operador.

O runtime PHP foi atualizado para **8.5.12**, substituindo o exemplo PHP 8.4 do runbook/kit. As imagens usam `php:8.5.12-fpm-bookworm` em produção e `php:8.5.12-fpm-alpine` no desenvolvimento; o Composer exige `~8.5.12`. Essa versão é uma [atualização de segurança oficial](https://www.php.net/releases/8_5_12.php). O OPcache já integra o PHP 8.5 e não deve ser compilado/carregado novamente como extensão compartilhada; suas diretivas INI permanecem configuradas.

## Arquivos e serviços

| Arquivo | Responsabilidade |
| --- | --- |
| `compose.production.yaml` | Ambiente independente do Compose local; somente Caddy publica 80/443 TCP. |
| `.env.production` | Tags de imagens e nomes do banco/papel da aplicação; passado por `--env-file`. |
| `.env.app` | Domínios, APP_KEY, SMTP e integrações do Laravel. |
| `.env.db` | Senha administrativa PostgreSQL; não é entregue ao Laravel. |
| `.env.web` | Domínio da API e e-mail ACME. |
| `secrets/db_password.txt` | Senha do papel da aplicação, montada somente no banco e na API/workers. |
| `secrets/redis_password.txt` | Senha Redis, montada somente nos serviços que a utilizam. |
| `data/storage` | Documentos privados, eventuais arquivos públicos e estado compartilhado Laravel. |

Serviços: `db`, `redis`, `app`, `caddy`, `worker`, `scheduler`. Banco e Redis ficam na rede `data` com `internal: true`; Caddy participa somente da rede `edge`. API e processos de fila/agendamento têm saída para SMTP e integrações. Não há Vite, Mailpit ou MinIO em produção: os documentos atuais usam o disco local privado.

As senhas via `_FILE` são exportadas pelo entrypoint antes do cache de configuração. O PostgreSQL cria `homologa_app` sem SUPERUSER/CREATEDB/CREATEROLE/REPLICATION apenas no primeiro provisionamento de um volume vazio. Esse papel é dono do banco para executar migrations. Alterar arquivos de senha não rotaciona um banco já existente: altere o papel no PostgreSQL e atualize o segredo na mesma janela.

Os volumes de produção têm nomes próprios (`homologa-solar-prod_*`). **Não use `down -v`, `volume prune`, `migrate:fresh` ou o seeder demonstrativo em produção.**

## Preparar o primeiro ambiente

Na VM, depois de clonar a release aprovada:

```sh
cd /opt/homologa
sudo bash scripts/prepare-production.sh
sudo nano .env.production .env.app .env.web
```

O script cria somente os arquivos ausentes e preenche chaves/senhas vazias com valores aleatórios, sem exibi-los. Não rotaciona valores já preenchidos. O diretório `secrets/` fica root/0700; seus arquivos ficam 0444 para leitura dos mounts individuais por usuários diferentes nos contêineres. Os `.env` ficam 0600 e o storage pertence a UID/GID 33.

Preencha:

- `APP_IMAGE` e `WEB_IMAGE` com tags da mesma release construída a partir do mesmo checkout.
- `APP_URL=https://api.SEU_DOMINIO` e `FRONTEND_URL=https://app.SEU_DOMINIO`.
- `SESSION_DOMAIN=.SEU_DOMINIO` e `SANCTUM_STATEFUL_DOMAINS=app.SEU_DOMINIO`.
- `API_DOMAIN=api.SEU_DOMINIO` e `ACME_EMAIL` em `.env.web`.
- SMTP autenticado real em `.env.app` e credenciais das integrações utilizadas.

Em uma migração, **preserve a APP_KEY de produção** e os demais segredos existentes; a chave nova gerada é apropriada somente para instalação nova. Importe banco/documentos em uma janela de manutenção e ajuste donos/permissões. Os uploads existentes do ambiente local ficam em `backend/storage/app/private`; precisam ser copiados para `data/storage/app/private`, preservando a estrutura `tenants/...` e os caminhos registrados no banco. Não aponte o volume PostgreSQL antigo para uma imagem diferente por tentativa: use dump/restore aprovado para migrar entre hosts/imagens.

## Construir e iniciar

Execute o Compose sempre com os dois argumentos abaixo. Não combine o arquivo de produção com `docker-compose.yml` usando múltiplos `-f`, pois isso herdaria configurações de desenvolvimento.

```sh
sudo docker compose --env-file .env.production -f compose.production.yaml config --quiet
sudo docker buildx imagetools inspect php:8.5.12-fpm-bookworm
sudo docker buildx imagetools inspect postgres:17-bookworm
sudo docker buildx imagetools inspect caddy:2-alpine
sudo docker buildx imagetools inspect redis:7-alpine
sudo docker compose --env-file .env.production -f compose.production.yaml build --pull app caddy
sudo docker compose --env-file .env.production -f compose.production.yaml up -d db redis
sudo docker compose --env-file .env.production -f compose.production.yaml run --rm app composer check-platform-reqs --no-dev
sudo docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan migrate --force
sudo docker compose --env-file .env.production -f compose.production.yaml up -d app worker scheduler
```

Faça o build nativamente na A1 ou valide ARM64 pelo workflow manual de imagens antes de publicar. Após homologação, fixe os digests das bases e mantenha a atualização de patches planejada. Os limites somam aproximadamente 7,25 GB de RAM, deixando margem na VM de 12 GB; precisam ser confirmados por carga real. O PHP-FPM limita a seis processos, com 256 MB por execução.

Crie o registro DNS A da API apontando para a VM e libere somente 80/443 públicos e SSH por IP autorizado no NSG/firewall. Evite AAAA sem IPv6 configurado. Valide Caddy antes de iniciar:

```sh
sudo docker compose --env-file .env.production -f compose.production.yaml run --rm --no-deps caddy caddy validate --config /etc/caddy/Caddyfile
sudo docker compose --env-file .env.production -f compose.production.yaml up -d caddy
curl --fail --show-error https://api.SEU_DOMINIO/up
curl --fail --show-error https://api.SEU_DOMINIO/ready
sudo docker compose --env-file .env.production -f compose.production.yaml ps
```

`/up` verifica o bootstrap do Laravel. `/ready` executa SELECT 1 e uma leitura de cache, retornando 503 genérico se uma dependência falhar. Os healthchecks Docker são verificações de processo/disponibilidade e não substituem um teste autenticado.

Somente `storage/app/public` é acessível por `/storage/`; documentos em `app/private` são entregues pelos endpoints autenticados da API. PHP e Caddy aceitam uploads coerentes com o limite de 20 MiB da aplicação. Certificados persistem nos volumes Caddy.

## Cloudflare Pages

| Configuração | Valor |
| --- | --- |
| Root directory | `frontend` |
| Node | `24.15.0` (`frontend/.node-version`) |
| Gerenciador | pnpm `10.28.2` (`frontend/package.json`) |
| Build command | `pnpm build` |
| Build output | `dist` |
| Variável de build | `VITE_API_URL=https://api.SEU_DOMINIO/api` |
| Custom domain | `app.SEU_DOMINIO` |

Use instalação com `pnpm install --frozen-lockfile` quando o pipeline for manual. Toda variável `VITE_*` é pública e exige novo build quando muda. Cadastre o domínio próprio na Pages e configure o DNS; teste acesso direto a uma rota profunda da SPA. Previews devem usar homologação com um domínio compatível com a estratégia de cookies.

Valide login/logout, expiração/CSRF, recuperação de senha com SMTP real, upload/download, duas contas de tenants diferentes e operações principais no navegador usando os domínios finais. CORS permite somente `FRONTEND_URL` em produção. Não habilite proxy adicional da API sem ajustar TLS, IP real e proxies confiáveis.

## Filas e agendamento

O Compose inicia exatamente um `worker` Redis e um `scheduler`. Não instale também o timer systemd de scheduler do kit original. O worker usa a conexão configurada, timeout de 60s, retry_after de 90s, três tentativas e encerramento gracioso. Ao atualizar código, recrie worker e scheduler junto com a API. Redis usa AOF, senha e política `noeviction`: monitore memória/backlog e falhas, pois esgotamento de memória passa a recusar escritas em vez de descartar sessões/jobs silenciosamente.

## Backup e restauração

O backup inclui dump PostgreSQL custom, todo `data/storage/app` (inclusive documentos privados), `.env` de produção, `secrets/`, Compose, configuração infra e identificação das imagens em execução. Ele criptografa com age e verifica tamanho/checksum no Object Storage antes de registrar sucesso ou podar cópias locais. A chave privada age deve ficar fora da VM/tenancy.

Prepare o bucket privado Standard, o dynamic group restrito à VM e a política de [backup-iam.policy.example](backup-iam.policy.example). Configure lifecycle de sete dias com identidade administrativa separada; a VM não deve sobrescrever/apagar objetos. Instale dependências na VM:

```sh
sudo apt install -y age python3-venv
sudo python3 -m venv /opt/oci-cli
sudo /opt/oci-cli/bin/pip install oci-cli
sudo install -o root -g root -m 600 infra/backup.conf.example /etc/homologa-backup.conf
sudo nano /etc/homologa-backup.conf
sudo install -o root -g root -m 750 scripts/backup.sh /usr/local/sbin/homologa-backup
sudo cp infra/homologa-backup.service infra/homologa-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl start homologa-backup.service
sudo systemctl status homologa-backup.service --no-pager
```

Preencha namespace, região, bucket e destinatário **público** age antes da primeira execução. Confirme upload e coleta do pacote por uma identidade independente fora da conta. Só então habilite o timer:

```sh
sudo systemctl enable --now homologa-backup.timer
sudo cat /var/backups/homologa/last-success.txt
```

O timer executa aproximadamente às 03:15 de São Paulo. Configure monitor externo para API/prontidão, disco/inodes, memória, falhas/backlog e backup sem sucesso por mais de 26h. O teto inicial é 1 GB por pacote e sete diárias no bucket; confirme uso total e margem reais.

Em uma estação segura **fora da produção**, com Docker, age e Python 3:

```sh
bash scripts/restore-check.sh /CAMINHO/backup.tar.gz.age /CAMINHO/chave.agekey
```

O script valida hashes e o conteúdo dos arquivos, restaura o dump em PostgreSQL 17 descartável sem rede/portas e remove somente seus próprios recursos temporários. Depois teste a aplicação com essa base em homologação, dados recentes, documentos e recuperação completa do host/DNS/segredos; esse teste técnico isolado não mede o RTO nem garante a integridade de todas as regras de negócio.

Para recuperação operacional, mantenha o host original preservado e prepare uma instalação nova/isolada com a release, APP_KEY e segredos recuperados. Inicialize o banco novo pelo Compose para criar o papel da aplicação; restaure o dump como administrador usando `pg_restore --no-owner --no-acl --exit-on-error --role=homologa_app` (substitua pelo `DB_USERNAME` real). Esse `--role` garante que as tabelas restauradas pertençam ao papel utilizado pelo Laravel. Reponha o conteúdo de `uploads.tar.gz` em `data/storage`, ajuste UID/GID 33 e valide a aplicação com SMTP/webhooks/jobs desativados antes de alterar DNS/tráfego. O script de verificação descarta o banco ao terminar e não executa essa recuperação operacional.

O dump online e a cópia de arquivos são capturas separadas. Antes de migração/deploy, coloque a API em manutenção, interrompa scheduler, drene requisições/jobs e pause integrações/escritas para um backup coordenado. Cópia fora da tenancy e restore aprovado são requisitos antes do go-live.

## Atualização e reversão

1. Confirme backup externo íntegro antes da mudança e preserve as tags/imagens anteriores.
2. Selecione a release aprovada e ajuste APP_IMAGE/WEB_IMAGE. Construa as duas imagens e execute `composer check-platform-reqs --no-dev` em um contêiner novo.
3. Execute `php artisan down --retry=60` na API ativa, pare o scheduler e drene/pare o worker. Confirme que integrações e requisições não continuam escrevendo e faça o backup final coordenado.
4. Execute as migrations revisadas com `run --rm app php artisan migrate --force`. Recrie somente `app caddy worker scheduler` com `up -d --no-deps`; preserve banco/Redis/volumes.
5. Execute `php artisan up`, teste `/ready`, login e operações críticas, e publique o frontend compatível na Pages.

Ao atualizar o PHP, altere as tags APP_IMAGE/WEB_IMAGE para uma nova release e siga essa mesma sequência de backup, build com `--pull` e recriação de `app`, `worker` e `scheduler`. Reconstruir uma imagem não atualiza os contêineres já ativos. Confirme a versão após a substituição:

```sh
sudo docker compose --env-file .env.production -f compose.production.yaml exec app php --version
sudo docker compose --env-file .env.production -f compose.production.yaml exec worker php --version
sudo docker compose --env-file .env.production -f compose.production.yaml exec scheduler php --version
```

Mantenha a API compatível com a release anterior do frontend durante a propagação. Para falha sem alteração incompatível de schema, volte as duas imagens e a Pages às versões anteriores e recrie os processos de aplicação. Para migration destrutiva, mantenha manutenção e restaure em banco novo/isolado com avaliação da perda de dados; não use rollback automático para tentar recuperar dados removidos.

Upgrade major PostgreSQL é uma mudança separada. Volumes Redis de sessão/fila não substituem backups do banco e documentos; para continuidade de jobs após perda do host, defina reprocessamento/idempotência e eventual captura própria do broker conforme a integração.

## Verificações locais e CI

```sh
python3 scripts/validate-compose.py
python3 -m unittest discover -s scripts/tests -v
for file in scripts/*.sh backend/docker/*.sh docker/postgres/production/*.sh; do bash -n "$file" || exit; done
```

O workflow automático valida o código e usa PostgreSQL 17 descartável no runner para testes funcionais, sem acessar a OCI; o workflow manual de imagens valida as bases/runtime em ambas as arquiteturas. Executá-los não provisiona OCI nem publica imagens. A validação final precisa acontecer na VM e nos domínios reais antes da liberação.
