# EasyEye — Copilot

Siga [AGENTS.md](../AGENTS.md), fonte compartilhada de instruções, comandos e limites. Leia as [invariantes detalhadas](../docs/internal/engineering/domain-invariants.md) do domínio afetado antes de alterar regras clínicas, financeiras ou de concorrência.

Stack: PHP 8.4, Laravel 12, PostgreSQL/Eloquent, Vue 3 com Composition API e `<script setup>`, Inertia.js 3, Vite, Bootstrap 5 e Tailwind. Páginas em `resources/js/Pages/`; componentes em `resources/js/Components/`. Blade permanece nos entry-points e templates server-side/PDF. O antigo scaffold Blade/Alpine/Yajra não é o padrão atual do produto.

- Código em inglês; UI traduzida segundo o padrão do módulo (`lang/pt_BR`, `lang/en` e props de tradução).
- Controllers validam/orquestram; serviços/domínios cuidam das regras/transações; modelos/traits mantêm persistência, auditoria, versionamento e assinatura.
- Tenant por `entity_id`, entidade ativa em `selected_entity_id`; aplique gates e ownership também em jobs/APIs. Use enums existentes para status e roles.
- Preserve logs/auditoria e exceções deliberadas de escrita após assinatura. Não generalize observers silenciosos para controles que devem falhar fechados, como provas de governança clínica.
- Use RTK em comandos. Checks: `rtk npm run lint`, `rtk npm run quality:rules`, `rtk npm test`, `rtk proxy php vendor/bin/pint --test`. [Qualidade](../docs/quality.md) documenta cobertura e dívida.
- Somente `easyeye_test` em testes com banco; sem PHP `--parallel`, bancos extras ou contorno do guard. Preflight e autorização para recriar schema vêm antes de Pest/RefreshDatabase.
- `composer dev` inicia worker/Reverb; Docker pode aplicar migrations. Consulte [onboarding](../docs/onboarding.md), sem iniciar serviços como check passivo.
- Não conectar novos serviços nem enviar código/dados/segredos do projeto a terceiros. Preserve configurações e mudanças de outras sessões.

[PRODUCT](../PRODUCT.md), [PRD](../PRD.md), [SPECS](../SPECS.md), [DESIGN](../DESIGN.md) e [setup](../docs/setup.md) complementam as instruções. Memória antiga precisa ser conferida no código atual.
