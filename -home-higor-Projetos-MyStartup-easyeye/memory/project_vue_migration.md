---
name: vue-inertia-migration
description: Status e padrão da migração do painel Blade/Alpine.js para Vue3+Inertia.js
metadata:
  type: project
---

Migração incremental do painel `/panel/*` de Blade+Alpine.js+Yajra DataTables para Vue3+Inertia.js.

**Why:** Melhorar performance, eliminar reload de página, componentizar UI e remover dependência de jQuery/DataTables.

**How to apply:** Seguir o padrão estabelecido em Pacientes para cada módulo novo.

## Status

| Módulo | Backend | Vue | Observações |
|---|---|---|---|
| Auth pages | ✓ | ✓ | Migrado anteriormente |
| Dashboard | ✓ | ✓ | Migrado com polling real-time 30s |
| Pacientes | ✓ | ✓ | Migrado — template base |
| Médicos | ⏳ | ⏳ | Próximo |
| Agenda | ⏳ | ⏳ | Complexo — calendário |
| Prontuários | ⏳ | ⏳ | Muito complexo — compliance |
| Financeiro | ⏳ | ⏳ | |
| Configurações | ⏳ | ⏳ | Muitas sub-páginas simples |
| Usuários | ⏳ | ⏳ | |
| Manager | ⏳ | ⏳ | |

## Padrão de migração por módulo

### Backend
1. Remover `PatientsDataTable $dataTable` do `index()`
2. Adicionar `Request $request` e `use Inertia\{Inertia, Response}`
3. Trocar `$dataTable->render(...)` por `Inertia::render('Panel/{Module}/Index', [...])`
4. Usar `->paginate(15)->withQueryString()` + `->through(fn($r) => $this->toTableRow($r))`
5. Manter `cards()`, `editData()`, `search()`, `store()`, `update()`, `destroy()` inalterados
6. Props lazy com `fn () =>` para covenants, lookups (só avaliados quando pedidos)

### Frontend
- `Panel/{Module}/Index.vue` — orquestrador: header, search bar, view toggle, CRUD modal
- `Panel/{Module}/{Module}Table.vue` — tabela paginada com colunas ordenáveis via `router.get()`
- `Panel/{Module}/{Module}Cards.vue` — cards usando `fetch()` no endpoint `cards`
- `Panel/{Module}/{Module}FormModal.vue` — offcanvas direita + `useForm()` Inertia

### Padrão de search/sort
```js
router.get(route('panel.patients.index'), { search, sort, direction }, { preserveState: true, preserveScroll: true })
```

### Padrão de form submission
```js
form.post(route('panel.patients.store'), {
    preserveScroll: true,
    onSuccess: () => emit('close'),
})
```

### Padrão offcanvas
- `<Teleport to="body">` com backdrop + painel deslizante direita
- `<transition name="slide-right">` para animação
- Tabs por seção (Pessoal, Clínico, Contato, Endereço)
- `tabHasErrors` computed para indicar aba com erro
- CEP lookup via ViaCEP em blur

## Arquivos-chave
- `app/Http/Controllers/PatientsController.php` — modelo de migração de controller
- `resources/js/Pages/Panel/Patients/` — 4 arquivos Vue de referência
- `resources/js/composables/useDashboardPolling.js` — polling Inertia para dados real-time
