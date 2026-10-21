# Homologa Solar

API Laravel em `backend/` e aplicação Vue/Vite em `frontend/`. Os arquivos Next.js na raiz não são o frontend deste sistema.

O `docker-compose.yml` atende ao desenvolvimento local. A implantação Oracle ARM64 usa o arquivo **independente** `compose.production.yaml`, PostgreSQL 17, Redis e Caddy com HTTPS. O frontend é publicado separadamente na Cloudflare Pages.

Consulte [infra/README.md](infra/README.md) para preparação, publicação, backup, restauração e atualização. O procedimento adapta o runbook OCI-AF-01 ao código real: preserva Redis, mantém os documentos privados e usa um único scheduler.

## Desenvolvimento

```sh
cp backend/.env.example backend/.env
docker compose build
make install
docker compose up -d
make migrate
```

Acesse `http://localhost:8080`. As portas auxiliares ficam vinculadas a `127.0.0.1`. Para acesso de desenvolvimento pela rede, configure conscientemente `APP_BIND_ADDRESS`; os serviços de dados continuam locais. Os seeders existentes são demonstrativos e devem ser usados somente em desenvolvimento.

O frontend usa Node **24.15.0** e pnpm **10.28.2**:

```sh
cd frontend
corepack enable
pnpm install --frozen-lockfile
pnpm test
pnpm build
```

`make check` executa as verificações backend e frontend existentes; `make fe-e2e` chama Playwright. O CI valida frontend, infraestrutura e testes PHP com PostgreSQL 17 descartável; o workflow manual de imagens constrói e verifica as imagens em AMD64 e ARM64 sem publicá-las.

## Configuração do cliente HTTP

Sem `VITE_API_URL`, o Vue usa `/api` no mesmo domínio, adequado ao proxy local. Na Pages, defina `VITE_API_URL=https://api.SEU_DOMINIO/api` durante o build. Chamadas, cookie CSRF, uploads e links de download usam o backend configurado.

O modo SPA do Sanctum requer frontend e API em subdomínios do mesmo domínio, CORS explícito, cookies Secure e `SESSION_DOMAIN` compartilhado. A configuração final deve ser testada no domínio próprio; o endereço `pages.dev` não compartilha cookies com seu domínio.
