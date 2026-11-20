🇧🇷 [Português](README.pt-BR.md) (esta página) | 🇺🇸 [Read in English](README.md)

# StatusConnect Community Edition

> Monitoramento de disponibilidade e páginas de status públicas, multi-organização e leve, para hospedagem compartilhada tradicional.

[![Testes](https://github.com/suporterfid/statusconnect-ce/actions/workflows/tests.yml/badge.svg)](https://github.com/suporterfid/statusconnect-ce/actions/workflows/tests.yml)
[![Licença: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

O StatusConnect verifica sites, APIs, servidores e rotinas agendadas em intervalos regulares. Quando algo falha, ele abre um incidente, avisa a sua equipe e publica uma página de status que os seus usuários podem acompanhar. Roda em hospedagem compartilhada comum com PHP e MySQL: sem daemon, sem Redis, sem worker de fila, apenas uma entrada de cron executada a cada minuto.

Esta é a **Community Edition**, a edição de código aberto (MIT) do StatusConnect, publicada como snapshots de versão. Faz parte da [Iniciativa IROH](https://github.com/suporterfid/iroh) (*It Runs On Hostinger*), uma família de ferramentas auto-hospedadas feitas para hospedagem compartilhada de baixo custo.

---

## Recursos

- **Verificações**: HTTP(S) (código de status, limite de latência, regex no corpo, cabeçalhos, validade do certificado SSL), TCP, MQTT, heartbeats (rotinas cron e scripts chamam uma URL) e monitores de agente (Telegraf e OpenTelemetry enviam métricas, com regras de limite).
- **Incidentes**: limites de confirmação e de recuperação, supressão de oscilação, janelas de manutenção, incidentes manuais com atualizações, confirmação opcional a partir de um segundo local.
- **Notificações**: e-mail, webhooks, Telegram e TaskConnect.
- **Páginas de status públicas**: componentes, histórico de disponibilidade, tempos de resposta, comunicados, domínios próprios, inscrição por e-mail, JSON e feeds. Snapshots em cache com suporte a ETag/304.
- **Multi-organização**: organizações, ambientes, membros e papéis, chaves de API por ambiente (`sc_…`), administração da plataforma, cadastro público opcional com aprovação.
- **API e integrações**: API REST documentada (`/api`), servidor MCP para clientes de IA, login com Google, login único com [GrandpaSSOn](https://github.com/suporterfid/grandpasson-ce).
- **Requisições de saída seguras**: HTTP com DNS fixado que bloqueia endereços de loopback, privados, CGNAT, link-local e de metadados de nuvem, para que monitores não alcancem a sua rede interna.
- **Aplicativo do operador** em Vue 3 e **páginas públicas** em Blade, em português do Brasil e inglês.

## Como funciona

O cron de um minuto executa três comandos artisan:

| Comando | O que faz |
|---|---|
| `monitor:check-due` | Reserva os monitores vencidos com leases no MySQL (`SKIP LOCKED`), verifica-os em paralelo com `curl_multi` dentro de um orçamento de tempo, avalia incidentes e envia notificações |
| `monitor:maintenance` | Libera leases abandonados, aplica a retenção e envia os e-mails aos inscritos nas páginas de status |
| `monitor:rollup` | Agrega os resultados por hora e por dia e regera os snapshots das páginas de status |

Com a configuração padrão, um tick verifica algumas centenas de monitores HTTP com intervalo de 60 s. Veja [docs/deployment/capacity.md](docs/deployment/capacity.md) (em inglês).

## Requisitos

- PHP 8.2 ou superior com `pdo_mysql`, `curl`, `mbstring`, `openssl` e `fileinfo`
- MySQL 8.0 ou superior (MariaDB 10.5+ deve funcionar, mas não é testado)
- Um cron que execute um comando a cada minuto e uma raiz de documentos que possa apontar para `public/`
- Acesso HTTP de saída do servidor para os sites monitorados

Para desenvolver, basta o Docker. PHP, Composer e Node rodam em contêineres.

## Instalação em hospedagem compartilhada

1. **Gere uma versão** na sua máquina (só Docker):
   ```bash
   ./scripts/stc.sh composer install --no-dev --optimize-autoloader
   ./scripts/stc.sh npm --prefix frontend ci
   ./scripts/stc.sh npm run build
   ```
   As tags de versão deste repositório já trazem o aplicativo do operador compilado em `public/app/`; recompile só se alterar o frontend.
2. **Envie** o projeto (incluindo `vendor/`) para o servidor, por exemplo para `~/statusconnect`.
3. **Aponte a raiz de documentos** do domínio ou subdomínio para `public/`.
4. **Configure**: copie `.env.example` para `.env`, defina `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, as variáveis `DB_*` e `MAIL_*` e gere a chave (`php artisan key:generate`). Coloque em `SETUP_ALLOWED_EMAILS` o e-mail do primeiro administrador.
5. **Migre**: `php artisan migrate --force`, depois `php artisan config:cache` e `php artisan route:cache`.
6. **Crie as entradas de cron** (use o caminho completo do PHP 8.2+ que a sua hospedagem oferece):
   ```cron
   * * * * * /usr/bin/php /home/voce/statusconnect/artisan monitor:check-due >/dev/null 2>&1
   * * * * * /usr/bin/php /home/voce/statusconnect/artisan monitor:maintenance >/dev/null 2>&1
   * * * * * /usr/bin/php /home/voce/statusconnect/artisan monitor:rollup >/dev/null 2>&1
   ```
7. **Crie a primeira conta** em `/app/setup` com um endereço de `SETUP_ALLOWED_EMAILS`, ou pela linha de comando com `php artisan statusconnect:provision-account`.

Guias passo a passo (em inglês): [docs/deployment/hostinger.md](docs/deployment/hostinger.md) (hospedagem compartilhada), [docs/deployment/cron.md](docs/deployment/cron.md) (cron e heartbeats), [docs/deployment/custom-domains.md](docs/deployment/custom-domains.md), [docs/deployment/saas.md](docs/deployment/saas.md) (cadastro, inscrições, administração, aceite dos termos), [docs/deployment/second-location.md](docs/deployment/second-location.md).

### Página inicial e páginas legais

`/` (pt-BR) e `/en` mostram uma página inicial simples. `/termos` e `/privacidade` (em inglês: `/terms`, `/privacy`) exibem os modelos de Termos de Uso e Política de Privacidade de `resources/legal/`. Preencha os dados do operador nas variáveis `LEGAL_*` do `.env`; valores vazios exibem um texto neutro. Os textos são modelos: peça a revisão de um advogado antes de oferecer o serviço ao público.

## Desenvolvimento local

```bash
./scripts/stc.sh up          # aplicação, MySQL, Mailpit, alvo de testes
./scripts/stc.sh bootstrap   # instala dependências, cria o .env, migra
./scripts/stc.sh test        # suíte PHPUnit
```

No Windows use `.\scripts\stc.ps1` com os mesmos verbos. Outros verbos: `artisan`, `composer`, `npm`, `shell`, `e2e` (testes de navegador com Playwright), `down`.

| Serviço | Endereço |
|---|---|
| Aplicação | http://localhost:8070 (aplicativo do operador em `/app`) |
| Mailpit | http://localhost:8035 |
| Alvo de testes | http://localhost:8095 |
| MySQL | localhost:3308 |

O código é dividido em camadas: `app/Domain` (regras de negócio sem framework), `app/Application` (casos de uso e transações), `app/Infrastructure` (Eloquent, HTTP, DNS, TCP), `app/Http` (controllers finos). O aplicativo do operador fica em `frontend/`.

## Integrações

- Métricas de servidores: [Telegraf](docs/integrations/telegraf.md), [OpenTelemetry](docs/integrations/opentelemetry.md), [agentes consultados e heartbeats](docs/integrations/agents-pull.md)
- Notificações: [Telegram](docs/integrations/telegram.md), [TaskConnect](docs/integrations/taskconnect.md)
- Login: [Google](docs/integrations/google-login.md), [GrandpaSSOn](docs/integrations/grandpasson.md)
- Clientes de IA: [MCP](docs/integrations/mcp.md)
- Receitas de monitores: [docs/guides/monitors.md](docs/guides/monitors.md)

## Como contribuir

Dúvidas e ideias vão para as [Discussions](https://github.com/suporterfid/statusconnect-ce/discussions); bugs para as [Issues](https://github.com/suporterfid/statusconnect-ce/issues). Pull requests são bem-vindos: leia antes o [CONTRIBUTING.md](CONTRIBUTING.md). Todo commit precisa da assinatura DCO (`git commit -s`).

Problemas de segurança: não abra uma issue pública. Veja o [SECURITY.md](SECURITY.md).

## Licença

[MIT](LICENSE).
