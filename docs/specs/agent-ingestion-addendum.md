# StatusConnect — Adendo de escopo: ingestão de métricas de agentes (pós-v1)

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

- **Versão:** 1.0
- **Data:** 2026-11-06
- **Aprovação:** mantenedor (`suporterfid`), 2026-11-06 — issue [#69](https://github.com/suporterfid/statusconnect/issues/69)
- **Épico:** [#67](https://github.com/suporterfid/statusconnect/issues/67)
- **Documento base:** `docs/specs/statusconnectinitialspecandbuildplan.md` (referido abaixo como "o spec")
- **Adendo anterior em vigor:** `docs/specs/v1-reduced-scope-addendum.md` (referido abaixo como "o adendo v1")
- **Pesquisa que fundamenta este adendo:** `docs/research/2026-11-06-agent-integration.md`

---

## 0. Precedência

1. Onde este adendo e o spec discordarem, **este adendo vence**, mas apenas nos pontos listados na §2.
2. **Enquanto o adendo v1 estiver em vigor** (até o Definition of Done do R13), **o adendo v1 vence este adendo**. Tudo o que este adendo cria fica atrás de uma feature flag desligada por padrão (§4) e, portanto, dormente no v1.
3. Onde este adendo for omisso, vale o spec. Isso inclui integralmente as **hard constraints do §4** e as **regras de código do §5.2**, que **não são relaxadas** por este adendo.
4. A linguagem normativa do **§2 do spec** (MUST / MUST NOT / SHOULD / MAY) vale neste documento.

Este adendo **não edita o spec**.

---

## 1. Objetivo

Permitir que o StatusConnect receba métricas de servidores e dispositivos (CPU, memória, disco, serviços, SNMP de equipamentos de rede/IoT), produzidas por **agentes abertos já existentes**, e as transforme em estado de monitor (`up` / `degraded` / `down`), incidentes e status page.

**Princípio:** o StatusConnect é o **receptor**. Ele **MUST NOT** ter agente próprio, e **MUST NOT** abrir conexões de entrada além de rotas HTTP comuns servidas pelo PHP. Os agentes suportados são o **Telegraf** (MIT), via InfluxDB line protocol, e o **OpenTelemetry Collector** e o **Grafana Alloy** (Apache-2.0), via OTLP/HTTP JSON.

---

## 2. O que muda no spec

| # | Seção do spec | Mudança |
|---|---|---|
| A1 | **§3.4**, item "Log ingestion, APM, tracing, or metrics scraping (Prometheus/OpenTelemetry)" | **Revogado parcialmente**, apenas para: (a) asserções numéricas em `json_path` (§3 abaixo); (b) **ingestão por push** de métricas via HTTP com token, em InfluxDB line protocol; (c) o mesmo em **OTLP/HTTP com codificação JSON**. **Continuam não-objetivos:** ingestão de logs, traces, APM, armazenamento de série temporal bruta, scraping em volume de endpoints Prometheus, OTLP via gRPC ou protobuf, Prometheus remote_write, protocolo Zabbix, SNMP direto da hospedagem, qualquer protocolo UDP. |
| A2 | **§7.1** (kinds suportados) | Novo kind **`agent`**, com a semântica da §5. |
| A3 | **§7.3** (asserções) | `json_path` passa a aceitar `lt`, `lte`, `gte` e `between` (§3). |
| A4 | **§13.2 / §13.3** (tabelas e colunas) | Novas colunas e tabelas da §6. |
| A5 | **§13.6** (retenção) | Nova linha `RETENTION_AGENT_METRIC_DAYS` (§6). |
| A6 | **§19.2** (rotas públicas) | Novas rotas `POST /ingest/{token}` e `POST /ingest/{token}/v1/metrics` (§4). |
| A7 | **Adendo v1, C4** ("A SPA MUST oferecer apenas `kind=http`") | Continua valendo no v1. Depois do R13, a SPA **MAY** oferecer `kind=agent`, mas só quando `AGENT_INGEST_ENABLED=true`. |
| A8 | **Adendo v1, C3** (piso de produto de 300 s) | **Não se aplica a `agent`**. O piso de 300 s existe por causa do custo de checagem de saída, que monitores `agent` não têm. Para `agent` vale o piso de plataforma de **60 s** (§5). |

---

## 3. Fase 1 — `json_path` numérico (issue #70)

- `json_path` **MUST** aceitar `lt`, `lte`, `gte` e `between`. Para `between`, o valor esperado é `"min,max"` ou `"min..max"`, inclusivo, com `min <= max`. O separador `-` aceito por `status_code` **não** é aceito aqui, porque é ambíguo com números negativos.
- Um valor encontrado que não seja numérico (inteiro, float ou string numérica) **MUST** falhar com motivo explícito. **MUST NOT** ser convertido silenciosamente para `0`.
- **Decisão de severidade:** uma falha numérica em `json_path` produz **`down`**, igual às demais asserções de correção. O único `degraded` de asserção continua sendo `latency_ms` e `tls_expires_in_days` (§7.6). Severidade configurável existe só nas regras do kind `agent` (§5).
- A validação na escrita (API v1) **MUST** rejeitar operador incompatível com o tipo de asserção e valor esperado não numérico.
- Esta fase altera só o monitor `http` e não depende da feature flag da §4.

---

## 4. Endpoints de ingestão (issues #75 e #79)

### 4.1 Rotas

| Rota | Formato | Agente típico |
|---|---|---|
| `POST /ingest/{token}` | InfluxDB line protocol, `Content-Type: text/plain` | Telegraf (`outputs.http`, `data_format = "influx"`) |
| `POST /ingest/{token}/v1/metrics` | OTLP/HTTP JSON, `Content-Type: application/json` | OpenTelemetry Collector / Grafana Alloy (`otlphttp`, `encoding: json`) |

As rotas ficam na superfície **pública** (sem sessão), como `/ping/{token}`.

**Respostas:**

- Line protocol bem-sucedido ⇒ **`204`** sem corpo.
- OTLP bem-sucedido ⇒ **`200`** com `ExportMetricsServiceResponse` JSON, incluindo `partialSuccess` quando houver descarte.
- `application/x-protobuf` ⇒ **`415`**.

### 4.2 Token

- **MUST** ter ≥ 128 bits de entropia e ser distinto do `public_id` e do `heartbeat_token`.
- **MUST** ser guardado **apenas como hash SHA-256** (`agent_token_hash`, índice único). O texto claro só aparece na criação e na rotação.
- Token desconhecido ou malformado ⇒ **`404`**, sem diferença de tempo mensurável em relação a um token conhecido (§11.5).
- Rotação: `POST /v1/tenants/{t}/environments/{e}/monitors/{m}/rotate-agent-token`. O token anterior **MUST** deixar de funcionar imediatamente.

### 4.3 Proteções obrigatórias

- **Rate limit** por token e por IP, com o bucket MySQL de janela fixa (§11.5). **MUST NOT** usar Redis. Excesso ⇒ `429`.
- **Teto de payload** verificado **antes** de ler o corpo inteiro (`Content-Length` e leitura limitada). Excesso ⇒ `413`.
- **gzip** (`Content-Encoding: gzip`) **MUST** ser aceito, com teto próprio sobre o tamanho descomprimido para impedir zip bomb. Excesso ⇒ `413`.
- **Erro de parse** ⇒ `400`, com motivo curto, sem ecoar o payload.
- Nenhum valor, nome de métrica ou tag recebido **MUST** ir para log sem passar pelo `SecretRedactor` (§9.4).

### 4.4 Custo do caminho de ingestão

O request de ingestão **MUST** ser barato:

- no máximo uma consulta de token, um upsert em lote dos últimos valores e um `UPDATE monitors SET last_ingest_at`;
- **sem** linha em `check_results`, **sem** avaliação de regra e **sem** notificação síncrona.

Quem avalia é o scheduler (§5.3).

### 4.5 Feature flag

`AGENT_INGEST_ENABLED=false` por padrão. Com a flag desligada:

- as rotas de ingestão **MUST** responder `404`, indistinguível de token desconhecido;
- a API **MUST** recusar criar monitor `kind=agent`;
- a SPA **MUST NOT** oferecer o kind.

---

## 5. Kind `agent` — semântica

### 5.1 Configuração

- `interval_seconds` é a **cadência esperada de envio** do agente. Piso: **60 s** (piso de plataforma; ver A8).
- `agent_grace_seconds`: padrão `180`.
- Regras de limite: 0 a `AGENT_MAX_RULES_PER_MONITOR`.

### 5.2 Regras de limite

Cada regra tem os seguintes campos:

| Campo | Valores |
|---|---|
| `metric_key` | chave canônica (§5.4) |
| `operator` | `lt`, `lte`, `gt`, `gte`, `between`, `equals` |
| `expected` | número, ou `"min,max"` para `between` |
| `severity` | `down` ou `degraded` |
| `missing_policy` | `ignore` (padrão) ou `fail` — o que fazer quando a métrica não chegou na última ingestão |

### 5.3 Avaliação (no tick do `monitor:check-due`)

- A avaliação **MUST** ser uma **função pura** em `app/Domain/` (§5.2), sem HTTP nem banco, unit-testada.
- **MUST NOT** consumir slot de concorrência (§8.5) nem fazer I/O de rede. É uma comparação no banco, como no `heartbeat`.

Ordem de decisão:

1. Fora de janela de manutenção? Se não, ⇒ `skipped`.
2. `now - last_ingest_at > interval_seconds + agent_grace_seconds`, ou nenhuma ingestão desde a criação ⇒ **`down`**, motivo `no_data`.
3. Alguma regra com `severity = down` violada (ou métrica ausente com `missing_policy = fail`) ⇒ **`down`**.
4. Alguma regra com `severity = degraded` violada ⇒ **`degraded`**.
5. Caso contrário ⇒ **`up`**.

O resultado grava uma linha em `check_results` com estado e motivo, por exemplo `mem.used_percent{host=web1}=93.2 gt 90`. A partir daí, valem sem mudança:

- a máquina de estados de incidentes (§10: confirmação, recuperação, flap);
- os rollups (§13.5);
- a supressão por manutenção (§10.4).

### 5.4 Chave canônica de métrica

- **Line protocol:** `measurement.field`, mais as tags em ordem alfabética entre chaves. Exemplo: `mem.used_percent{host=web1}`.
- **OTLP:** nome da métrica, mais os atributos de resource e data point permitidos, no mesmo formato.
- A chave **MUST** ter no máximo `AGENT_METRIC_KEY_MAX_LENGTH` caracteres. Se passar, a amostra é descartada e contada.

### 5.5 Tipos de valor

- **Line protocol:** inteiros (`i`), unsigned (`u`), floats e booleanos (`true` = 1, `false` = 0). **Campos string são ignorados e contados**, não armazenados.
- **OTLP:** **Gauge** e **Sum**, usando o último data point (`asInt` como string é convertido para número). Histogram, ExponentialHistogram e Summary são **ignorados e contados**, e entram no `partialSuccess`.

---

## 6. Armazenamento e retenção (issue #73)

- **Só o último valor por métrica.** O StatusConnect **MUST NOT** armazenar série temporal bruta de métricas. O histórico do monitor continua sendo o de estado (`check_results` → `check_rollups`), como nos demais kinds. Isso mantém válida a premissa de volume do §13.1.
- Colunas novas em `monitors`: `agent_token_hash` (único), `agent_grace_seconds`, `last_ingest_at`.
- Tabela `agent_metric_latest`:
  - colunas `tenant_id`, `monitor_id`, `metric_key_hash`, `metric_key`, `value`, `sampled_at`, `received_at`;
  - índice único (`monitor_id`, `metric_key_hash`), com escrita por upsert em lote.
- Tabela `agent_metric_rules`, com os campos da §5.2.
- **Cardinalidade:** métricas novas acima de `AGENT_MAX_METRICS_PER_MONITOR` são **descartadas e contadas**. Métricas já conhecidas continuam sendo atualizadas.
- **Retenção:** linhas de `agent_metric_latest` sem atualização há mais de `RETENTION_AGENT_METRIC_DAYS` dias são apagadas pelo `monitor:maintenance`, em lotes com teto por tick (§13.6).
- Escopo de tenant por global scope e teste de isolamento explícito (§5.2).
- Modelos Eloquent em `app/Infrastructure/Persistence/Eloquent/` (§5.2).

---

## 7. Limites e variáveis de ambiente

| Variável | Padrão | Significado |
|---|---|---|
| `AGENT_INGEST_ENABLED` | `false` | Feature flag (§4.5) |
| `AGENT_INGEST_MAX_BYTES` | `262144` (256 KB) | Corpo da requisição como recebido |
| `AGENT_INGEST_MAX_DECOMPRESSED_BYTES` | `1048576` (1 MB) | Corpo após gzip |
| `AGENT_INGEST_MAX_LINES` | `2000` | Linhas de line protocol ou data points OTLP por requisição |
| `AGENT_INGEST_RATE_PER_TOKEN_PER_MINUTE` | `12` | Rate limit por token |
| `AGENT_INGEST_RATE_PER_IP_PER_MINUTE` | `60` | Rate limit por IP |
| `AGENT_MAX_METRICS_PER_MONITOR` | `200` | Cardinalidade |
| `AGENT_MAX_RULES_PER_MONITOR` | `50` | Regras por monitor |
| `AGENT_METRIC_KEY_MAX_LENGTH` | `255` | Tamanho da chave canônica |
| `RETENTION_AGENT_METRIC_DAYS` | `30` | Retenção de `agent_metric_latest` |

Todas **MUST** aparecer em `.env.example` e `.env.production.example` com o padrão e uma linha de explicação.

---

## 8. Divulgação de informação

- A status page pública **MUST** mostrar só o **estado** do componente. Nomes de métrica, valores, tags e atributos **MUST NOT** aparecer no HTML nem no JSON público, porque tags costumam conter hostnames e IPs (§11.4).
- Motivos públicos usam o vocabulário fixo do §11.4: estado obsoleto ⇒ **`No data`**; regra violada ⇒ **`Unexpected response`**. **Nenhum termo novo** é adicionado ao vocabulário.
- Um teste **MUST** afirmar que a resposta da status page não contém nenhuma `metric_key` nem valor de tag do monitor, em HTML e JSON.
- **API autenticada:**
  - `viewer` **MAY** ler os últimos valores;
  - só `owner` e `admin` **MAY** editar regras e rotacionar o token;
  - o token **MUST NOT** aparecer em nenhuma leitura posterior à criação ou rotação.

---

## 9. Licenças e dependências

- Os parsers (line protocol e OTLP JSON) **MUST** ser código próprio do StatusConnect (MIT). Esta funcionalidade **não exige** nenhuma dependência nova de runtime.
- Os agentes rodam como processos separados nas máquinas monitoradas e **não são distribuídos** com o StatusConnect. Por isso as licenças deles (incluindo GPL/AGPL de outros agentes não adotados) não afetam o MIT do StatusConnect (§26).
- No ambiente de desenvolvimento, imagens de container do Telegraf (MIT) e do OpenTelemetry Collector (Apache-2.0) **MAY** ser usadas em perfis opcionais do `compose.yaml`. **MUST NOT** fazer parte do pacote de release.
- Se um dia for necessário protobuf (fora deste adendo), qualquer dependência nova segue a regra do §4.4 do spec e do §5 do adendo v1: **parar e perguntar**.

---

## 10. Sequência de PRs

**Regras de execução** (mesmas do §6 do adendo v1):

- um PR por issue;
- `stc test` verde;
- `STATUS.md` e `BACKLOG.md` atualizados;
- issue fechada com evidência de verificação.

**Quando começar:**

- A **Fase 1** **MAY** começar depois do DoD do R13, ou antes, por decisão do mantenedor registrada na issue.
- As **Fases 2 e 3** **MUST NOT** começar antes do DoD do R13.

| Fase | Issue | Escopo | Depende de |
|---|---|---|---|
| 0 | [#68](https://github.com/suporterfid/statusconnect/issues/68) | Documento de pesquisa | — |
| 0 | [#69](https://github.com/suporterfid/statusconnect/issues/69) | Este adendo | #68 |
| 1 | [#70](https://github.com/suporterfid/statusconnect/issues/70) | `json_path` numérico (§3) | #69 |
| 1 | [#71](https://github.com/suporterfid/statusconnect/issues/71) | Receitas de pull (Glances, Netdata, node_exporter) e heartbeat por script | #70 |
| 2 | [#72](https://github.com/suporterfid/statusconnect/issues/72) | Domínio: parser de line protocol e avaliador de regras (§5) | #69 |
| 2 | [#73](https://github.com/suporterfid/statusconnect/issues/73) | Persistência, cardinalidade, retenção (§6) | #72 |
| 2 | [#75](https://github.com/suporterfid/statusconnect/issues/75) | `POST /ingest/{token}` (§4) | #72, #73 |
| 2 | [#76](https://github.com/suporterfid/statusconnect/issues/76) | Avaliação no scheduler, incidentes, rollups (§5.3) | #72, #73, #75 |
| 2 | [#77](https://github.com/suporterfid/statusconnect/issues/77) | API v1 e SPA (§8) | #73, #75, #76 |
| 2 | [#78](https://github.com/suporterfid/statusconnect/issues/78) | Telegraf em dev, E2E, `docs/integrations/telegraf.md` | #75, #76, #77 |
| 3 | [#79](https://github.com/suporterfid/statusconnect/issues/79) | `POST /ingest/{token}/v1/metrics` (OTLP/HTTP JSON) | #73, #75, #76 |

**Definition of Done do épico:**

- um Telegraf real (container de dev) envia métricas;
- uma regra de limite abre e fecha um incidente;
- parar o agente leva o monitor a `down` (`No data`) após `interval + grace`;
- tudo isso com `stc test` verde e evidência anexada às issues.

---

## 11. Fora deste adendo

Fora de escopo, sem issue. Cada item exige novo adendo:

| Item | Motivo |
|---|---|
| Endpoint compatível com Nagios NRDP | Útil, mas não necessário para o objetivo. Avaliar depois da Fase 3 |
| Push em texto Prometheus (estilo Pushgateway) | Idem |
| Protocolo Zabbix (passivo TCP 10050 / ativo 10051) | O ativo exige porta escutando (§4.1). O passivo exige porta aberta em cada servidor monitorado |
| Beszel | O hub conecta no agente via SSH |
| Prometheus remote_write | Protobuf + Snappy, que exige extensão PHP incomum em hospedagem compartilhada |
| OTLP via gRPC ou protobuf | gRPC exige servidor HTTP/2 persistente. Protobuf exige dependência nova |
| SNMP direto da hospedagem | UDP e `ext-snmp` indisponíveis. Use o coletor de borda (Telegraf na rede local) |
| Série temporal e gráficos de métricas | Volume incompatível com o §13.1. "Métricas de latência com gráficos" também está na lista EXCLUI do adendo v1 |

---

## 12. Questões em aberto

1. **Enquadramento comercial.** Monitores `agent` entram em qual plano (Starter, Pro ou add-on)? Decisão de produto, não de arquitetura. **Decidido em 2026-11-11 ([#181](https://github.com/suporterfid/statusconnect/issues/181)):** os limites ficam por plano em `config/plans.php` (desde #119): Free 0, Pro 5, Business 20, contados dentro do total de monitores do plano. Sem add-on por ora; revisar se clientes pedirem mais monitores `agent` do que o plano permite.

---

## 13. Registro de alterações

| Data | Alteração |
|---|---|
| 2026-11-06 | Versão 1.0 — adendo criado e aprovado pelo mantenedor (issue #69). |
| 2026-11-06 | §3: formato de `between` corrigido (`min,max` ou `min..max`; sem `-`), durante a implementação de #70. |
