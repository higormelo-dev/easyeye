# Dívida de lazy loading (N+1) levantada pela F0

Data: 22/10/2026 · Origem: plano de performance F0 (`docs/internal/planos/performance-f0-f1-plano.md`, §3.1 e §4) · Autora: Forja.

**Isto é um levantamento, não uma correção.** Nenhum código foi alterado para pagar a dívida; os destinos são F3 (consultas e índices) e F4 (segundo plano), conforme o plano.

## Como foi coletado

- `Model::preventLazyLoading(! app()->isProduction())` com callback que só loga em `testing` (`eloquent.lazy_loading_violation`, com `model` e `relation`; nunca lança). O framework só aciona o callback para model hidratado numa **coleção de 2+ itens**, que é o N+1 de verdade; um `find()` ou `first()` isolado nunca conta.
- Suíte `Feature` + `Unit` inteira no `easyeye_test` (9.428 testes, sem `--parallel`), com `LOG_CHANNEL=single`. As linhas novas do log foram agregadas por `model` + `relation`. Para achar a **origem**, uma segunda rodada da suíte `Feature` (8.339 testes) usou um patch **temporário** (não versionado, já revertido; `app/Providers/AppServiceProvider.php` voltou idêntico, conferido por checksum) que acrescentava ao log o primeiro frame de código da aplicação e o arquivo de teste.
- Resultado nas duas rodadas: **7 pares model/relação, 65 ocorrências**.

## Dívida encontrada

| Model | Relação | Ocorrências | Origem no código | Teste que expõe | Destino |
|---|---|---:|---|---|---|
| `Patient` | `person` | 34 | `app/Services/PatientMergeService.php:155` (`candidateRows`: `$source->person` por paciente candidato) | `tests/Feature/Patients/PatientMergeTest.php` (o frame de teste não veio no rastreio) | F3 — `loadMissing('person')` na coleção de candidatos |
| `PatientExam` | `equipment` | 18 | `app/Http/Resources/PatientExamResource.php:23` (`$this->equipment?->integrator_id` por exame) | testes da API do integrador que listam exames (`Api\ExamsController`, `Api\PatientExamsController`, `Api\ReceiptReconciliationController`); frame de teste não veio no rastreio | F3 — `with('equipment')` nas consultas desses controllers (worklist/snapshot do integrador, mesmo caminho do hot path 5 do runbook de `pg_stat_statements`) |
| `Doctor` | `entityUser` | 5 | `app/Models/Doctor.php:137` (`effectiveInterval()`: `$this->entityUser->entity`) | `tests/Feature/Subscriptions/IntegratorTestClinicSeederTest.php` | F3 — `with('entityUser.entity')` onde se calcula o intervalo de vários médicos (validação/prévia de série, hot path 4) |
| `SurgicalCase` | `surgeon` | 2 | `app/Services/Lgpd/SurgeryDataExporter.php:109` | `tests/Feature/Surgery/SurgeryLgpdExportTest.php` | F4 — exportação LGPD (rara; vira job com o resto dos exports longos) |
| `SurgicalCase` | `covenant` | 2 | `SurgeryDataExporter.php:110` | idem | F4 |
| `SurgicalCase` | `facility` | 2 | `SurgeryDataExporter.php:111` | idem | F4 |
| `SurgicalCase` | `room` | 2 | `SurgeryDataExporter.php:112` | idem | F4 |

## O que este levantamento NÃO prova

- **Só aparece o que a suíte exercita com 2+ registros.** Poucas ocorrências (65 em ~9.400 testes) significam que os testes usam coleções pequenas, não que o código não tenha N+1: as listas de produção (agenda do mês, pacientes, dashboard) têm centenas de linhas. As rotas que já eager-loadam (`Schedules`) não aparecem; as que não eager-loadam e não têm teste com 2+ linhas também não.
- Em `local`/`staging` o callback faz `report()` (stack e Sentry, quando configurado): é onde o N+1 de dado real vai aparecer de fato. **Risco:** uma lista de 500 linhas com N+1 reporta 500 vezes por request; se isso virar ruído, limitar com amostragem no callback (fora do escopo da F0).
- `origin`/`test` só existem nesta rodada de rastreio; o log permanente traz apenas `model` e `relation`. Para localizar uma violação nova: rodar o teste suspeito em `local` (o `report()` traz o stack).

## Próximos passos (fora desta entrega)

1. F3: corrigir as 3 primeiras linhas junto com o resto das consultas (`whereDate` → `whereBetween`, índices), rodando de novo o levantamento para ver a lista encolher.
2. Repetir a coleta depois da F3 e depois da F4; meta: lista vazia nas rotas do painel e da API.
3. Quando a lista estiver vazia, avaliar trocar o callback por exceção também em `testing` (opção (a) do plano, D1), para o N+1 novo quebrar o teste.
