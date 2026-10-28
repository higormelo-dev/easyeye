# Runbook — `pg_stat_statements` e baseline `EXPLAIN` dos hot paths

Origem: plano de performance F0 (`docs/internal/planos/performance-f0-f1-plano.md`, §3.3) · Achado #17 de `docs/internal/planos/performance-filas-jobs.md`.

**Este runbook é procedimento.** Quem executa é o DBA (ou o responsável pelo banco), em janela combinada ou em réplica de leitura. O agente de desenvolvimento **não** roda nada daqui contra banco real, e nenhum comando abaixo faz parte de setup, migration ou seed.

**Hipótese de invalidação:** refazer a leitura se o Postgres mudar de versão maior (nomes de coluna de `pg_stat_statements` mudaram na 13), se um hot path listado na §4 mudar de arquivo/método ou se `AccentInsensitiveSearch` passar a usar `f_unaccent` (a query do §4.3 muda).

## 1. Segurança (ler antes de coletar)

- **Somente leitura.** Nada de `ALTER`, `CREATE INDEX`, `VACUUM` ou `ANALYZE` neste procedimento. A decisão de índice é da F3, com o resultado em mãos.
- **Não exporte `query` bruta.** O texto normalizado pode conter literais (nome, CPF, telefone) em comandos que o Postgres não parametriza, e `EXPLAIN ANALYZE` executa a query de verdade. Compartilhe `queryid` e trechos com `$1`, `$2`; nunca cole a coluna `query` inteira em ticket, chat ou issue.
- **Sem paciente real no baseline.** Use `entity_id` de clínica de teste ou de homologação com volume representativo, e intervalos de datas, nunca CPF, nome ou telefone reais.
- **Janela ou réplica.** `EXPLAIN (ANALYZE)` roda a query. Prefira réplica de leitura; na falta dela, janela de baixo uso e dentro de `BEGIN READ ONLY; … ROLLBACK;`.

## 2. Pré-requisitos (uma vez, pelo DBA)

1. Extensão disponível no pacote do Postgres (`postgresql-contrib` em instalação própria; já vem em imagens oficiais).
2. `postgresql.conf`: `shared_preload_libraries = 'pg_stat_statements'` — **exige restart** do PostgreSQL.
3. Parâmetros: `pg_stat_statements.max = 10000`, `pg_stat_statements.track = top` (use `all` só por pouco tempo), `track_io_timing = on` (para `shared_blk_read_time`).
4. No banco da aplicação: `CREATE EXTENSION IF NOT EXISTS pg_stat_statements;`
5. Conferir: `SELECT count(*) FROM pg_stat_statements;` retorna sem erro.

A extensão tem custo pequeno e contínuo de CPU/memória compartilhada. Em banco gerenciado (RDS, Cloud SQL, Supabase) o ajuste é feito pelo painel/parameter group.

## 3. Coleta

Sempre filtre pelo banco da aplicação (`dbid`) e pelo usuário da aplicação (`userid`), e não selecione a coluna `query`:

```sql
-- Maiores consumidores de tempo total (o que mais pesa no banco)
SELECT queryid, calls,
       round(total_exec_time::numeric, 0) AS total_ms,
       round(mean_exec_time::numeric, 2)  AS mean_ms,
       round(stddev_exec_time::numeric, 2) AS stddev_ms,
       rows,
       shared_blks_hit, shared_blks_read,
       temp_blks_read, temp_blks_written
FROM pg_stat_statements
WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database())
  AND userid = (SELECT usesysid FROM pg_user WHERE usename = current_user)
ORDER BY total_exec_time DESC
LIMIT 30;
```

Variações úteis: `ORDER BY mean_exec_time DESC` (queries lentas individualmente), `ORDER BY calls DESC` (polling e N+1: muitas chamadas baratas), `ORDER BY temp_blks_written DESC` (sort/hash em disco), `ORDER BY shared_blks_read DESC` (cache frio).

Para ler o formato da query de um `queryid` suspeito, consulte **uma** linha e confira que não há literal sensível antes de colar qualquer trecho:

```sql
SELECT left(query, 400) FROM pg_stat_statements WHERE queryid = <queryid> LIMIT 1;
```

As estatísticas acumulam desde o último restart ou `SELECT pg_stat_statements_reset();`. O reset apaga o histórico de todos: faça-o de propósito, **antes** de uma janela de medição, e registre quando.

## 4. Baseline `EXPLAIN` dos 5 hot paths

Objetivo: guardar o plano **de hoje** para comparar depois das mudanças de F3 (índices, `whereDate` → `whereBetween`, `f_unaccent`).

| # | Hot path | Onde nasce a query |
|---|---|---|
| 1 | Dashboard — agenda do dia (polling de 30 s) | `PanelDashboardController::scheduleToday` |
| 2 | Agenda — visão de mês | `AgendaQueryService::schedules` |
| 3 | Busca de pacientes | `PatientsController` + `AccentInsensitiveSearch` |
| 4 | Validação e prévia de série de sessões | `ScheduleService::validateFor` |
| 5 | Worklist e snapshot offline do integrador | `Api\SchedulesController` / `Api\OfflineSnapshotController` |

Para **cada** um:

1. **Capture o SQL** do query builder numa clínica de homologação (não em produção): `DB::listen` temporário em sessão local, ou `->toSql()` com `->getBindings()`. Não deixe esse código no repositório.
2. **Troque os literais por valores sintéticos** (um `entity_id` de teste, um intervalo de datas representativo, um termo de busca fictício como `silva`). Nada de dado pessoal.
3. **Rode** numa transação somente leitura, preferencialmente na réplica:

   ```sql
   BEGIN READ ONLY;
   EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) <sql-sem-dado-pessoal>;
   ROLLBACK;
   ```

4. **Registre** em arquivo privado (fora do repositório, ou em `docs/internal/planos/` somente o resumo): commit/data, versão do Postgres, tamanho aproximado das tabelas (`entity_id` de teste) e o JSON do plano **sem literais**.
5. **Leia o plano** e anote em 3–5 linhas:
   - `Seq Scan` vs `Index Scan` / `Bitmap Heap Scan` nas tabelas grandes (`schedules`, `people`, `patients`);
   - `Nested Loop` sobre muitas linhas vs `Hash Join`;
   - `temp read/written` (sort ou hash em disco);
   - `rows` estimado vs `rows` real (estatística velha → `ANALYZE` na F3, não aqui);
   - tempo de planejamento vs execução.

### 4.1 O que esperar (hipóteses a confirmar ou derrubar)

- **#1, #2, #5:** `whereDate('date_time', …)` vira `date_time::date = ?`; o cast na coluna impede `schedules_entity_date_time_idx (entity_id, date_time)` de filtrar pela data (só serve pelo `entity_id`). `doctor_id` não tem índice.
- **#3:** `unaccent(col) ILIKE unaccent('%x%')` em 4–5 colunas varre `people` inteira; `unaccent()` é `STABLE`, então nenhum índice de expressão se aplica.
- **#4:** muitas queries pequenas por data (a prévia de 60 sessões repete a mesma validação): olhe `calls` em `pg_stat_statements`, mais do que o plano de uma delas.

Hipótese não é conclusão: o baseline existe para confirmar com número.

## 5. Limitações

- As estatísticas acumulam desde o restart; compare janelas curtas após `pg_stat_statements_reset()`.
- O texto da query pode ter literais; o `queryid` agrupa por estrutura, não por valor.
- A extensão tem overhead pequeno e não é profiling de aplicação (não mostra qual rota ou job disparou a query; para isso, o log `db.slow_query` — conexão, duração, rota/comando/job — e os traces do Sentry).
- `EXPLAIN ANALYZE` mede com o cache atual; rode duas vezes e registre a segunda (cache quente) e a primeira (fria), quando possível.
- Execução e decisão de índices são F3/DBA. Este documento não autoriza nenhuma mudança de schema.
