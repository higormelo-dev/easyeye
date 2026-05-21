<script setup>
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout                    from '@/Layouts/AppLayout.vue';
import PageHeader                   from '@/Components/Panel/PageHeader.vue';
import SearchInput                  from '@/Components/Panel/SearchInput.vue';
import TablePagination              from '@/Components/Panel/TablePagination.vue';
import ActionDropdown               from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton             from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup              from '@/Components/Panel/ActionIconGroup.vue';
import EntityIntegratorFormModal    from './EntityIntegratorFormModal.vue';
import EntityIntegratorDetailDrawer from './EntityIntegratorDetailDrawer.vue';

/**
 * Listagem de Integradores de um EntityUserIntegrator (Manager SaaS).
 * Substitui a antiga DataTable Yajra mantendo paridade funcional:
 *   - Busca por nome/IP/MAC/código
 *   - Soft delete + restore
 *   - Toggle active/inactive
 *   - Navegação para Equipamentos (próximo nível)
 */
const props = defineProps({
    entity:         { type: Object, required: true },   // { id, code, name }
    userIntegrator: { type: Object, required: true },   // { id, code, name, email }
    items:          { type: Object, required: true },
    filters:        { type: Object, default: () => ({}) },
    t:              { type: Object, default: () => ({}) },
});

// ── Breadcrumbs ──────────────────────────────────────────────────────────────
const breadcrumbs = [
    { label: props.t.breadcrumb_home     ?? 'Dashboard',           url: route('panel.dashboard'),                                             active: false },
    { label: props.t.breadcrumb_entities ?? 'Empresas',            url: route('manager.entities.index'),                                      active: false },
    { label: props.entity.name,                                    url: '#',                                                                  active: false },
    { label: props.t.breadcrumb_users    ?? 'Usuários Integradores', url: route('manager.entities.user-integrators.index', props.entity.id), active: false },
    { label: props.userIntegrator.name,                            url: '#',                                                                  active: false },
    { label: props.t.breadcrumb_current  ?? 'Integradores',        url: '#',                                                                  active: true  },
];

// ── Search server-side com debounce ─────────────────────────────────────────
const search = ref(props.filters.search ?? '');
let searchTimer = null;
watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            route('manager.entities.user-integrators.integrators.index', [props.entity.id, props.userIntegrator.id]),
            { search: val },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 400);
});

// ── Form modal ──────────────────────────────────────────────────────────────
const formOpen    = ref(false);
const editId      = ref(null);
const editDataUrl = ref('');
const updateUrl   = ref('');

function openCreate() {
    editId.value      = null;
    editDataUrl.value = '';
    updateUrl.value   = '';
    formOpen.value    = true;
}

function openEdit(item) {
    editId.value      = item.id;
    editDataUrl.value = item.edit_data_url;
    updateUrl.value   = item.update_url;
    formOpen.value    = true;
}

function closeForm() { formOpen.value = false; }
function onSaved()   { formOpen.value = false; router.reload({ only: ['items'] }); }

// ── Detail drawer ───────────────────────────────────────────────────────────
const detailOpen = ref(false);
const detailUrl  = ref('');

function openDetail(item) {
    detailUrl.value  = item.show_url;
    detailOpen.value = true;
}
function closeDetail() { detailOpen.value = false; }

// ── Actions ─────────────────────────────────────────────────────────────────
function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function toggleActive(item) {
    const res = await fetch(item.activate_url, {
        method: 'PATCH',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ active: !item.active }),
    });
    const json = await res.json();
    toast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) router.reload({ only: ['items'] });
}

async function onDelete(item) {
    if (!confirm(props.t.confirm_delete ?? 'Excluir este integrador?')) return;
    const res = await fetch(item.destroy_url, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    const json = await res.json();
    toast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) router.reload({ only: ['items'] });
}

async function onRestore(item) {
    if (!confirm(props.t.confirm_restore ?? 'Restaurar este integrador?')) return;
    const res = await fetch(item.restore_url, {
        method: 'PUT',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    const json = await res.json();
    toast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) router.reload({ only: ['items'] });
}

function toast(msg, type = 'success') {
    if (!msg) return;
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error'   && window.showErrorToast)   return window.showErrorToast(msg);
    alert(msg);
}
</script>

<template>
    <AppLayout :title="t.page_title ?? 'Integradores'" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader
                :title="t.page_title ?? 'Integradores'"
                :subtitle="`${entity.name} — ${userIntegrator.name}`"
                :total="items.total"
            >
                <template #actions>
                    <Link
                        :href="route('manager.entities.user-integrators.index', entity.id)"
                        class="btn btn-outline-secondary btn-sm"
                    >
                        <i class="ti ti-arrow-left me-1"></i>{{ t.btn_back ?? 'Voltar' }}
                    </Link>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ti ti-plus me-1"></i>{{ t.btn_new ?? 'Novo' }}
                    </button>
                </template>
            </PageHeader>

            <!-- Toolbar -->
            <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
                <SearchInput
                    v-model="search"
                    :placeholder="t.search_placeholder ?? 'Buscar...'"
                    style="min-width: 280px;"
                />
            </div>

            <!-- Tabela -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.col_registered_at ?? 'Cadastro' }}</th>
                                <th>{{ t.col_code           ?? 'Código' }}</th>
                                <th>{{ t.col_name           ?? 'Nome' }}</th>
                                <th>{{ t.col_ip             ?? 'IP' }}</th>
                                <th>{{ t.col_mac            ?? 'MAC' }}</th>
                                <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                                <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="items.data.length === 0">
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="ti ti-plug fs-1 d-block mb-2"></i>
                                    {{ t.empty_list ?? 'Nenhum registro.' }}
                                </td>
                            </tr>
                            <tr
                                v-for="i in items.data"
                                :key="i.id"
                                :class="{ 'table-secondary opacity-75': i.deleted }"
                            >
                                <td class="text-muted small">{{ i.created_at }}</td>
                                <td><code class="text-muted small">{{ i.code }}</code></td>
                                <td class="fw-medium">{{ i.name }}</td>
                                <td><code class="small">{{ i.ip || '—' }}</code></td>
                                <td><code class="small">{{ i.mac || '—' }}</code></td>
                                <td class="text-center">
                                    <span v-if="i.deleted"
                                          class="badge badge-soft-secondary rounded fs-13 fw-medium">
                                        {{ t.status_deleted ?? 'Removido' }}
                                    </span>
                                    <span v-else-if="i.active"
                                          class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium">
                                        {{ t.status_active ?? 'Ativo' }}
                                    </span>
                                    <span v-else
                                          class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium">
                                        {{ t.status_inactive ?? 'Inativo' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <ActionIconGroup align="end" gap="tight">
                                        <ActionIconButton
                                            v-if="i.mode === 'restore'"
                                            icon="ti ti-recycle"
                                            :title="t.action_restore ?? 'Restaurar'"
                                            @click="onRestore(i)"
                                        />

                                        <template v-else>
                                            <ActionIconButton
                                                icon="ti ti-eye"
                                                :title="t.action_view ?? 'Ver'"
                                                @click="openDetail(i)"
                                            />
                                            <ActionIconButton
                                                icon="ti ti-device-laptop"
                                                :title="t.action_equipments ?? 'Equipamentos'"
                                                variant="info"
                                                :href="i.equipments_url"
                                            />
                                            <ActionDropdown
                                                btn-class="ee-action-icon ee-action-icon--default"
                                                icon="ti ti-dots-vertical"
                                            >
                                                <li>
                                                    <button class="dropdown-item rounded-1" @click="openEdit(i)">
                                                        <i class="ti ti-edit me-1"></i> {{ t.action_edit ?? 'Editar' }}
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded-1" @click="toggleActive(i)">
                                                        <i :class="`ti me-1 ${i.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                                        {{ i.active ? (t.action_deactivate ?? 'Desativar') : (t.action_activate ?? 'Ativar') }}
                                                    </button>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button class="dropdown-item rounded-1 text-danger" @click="onDelete(i)">
                                                        <i class="ti ti-trash me-1"></i> {{ t.action_delete ?? 'Excluir' }}
                                                    </button>
                                                </li>
                                            </ActionDropdown>
                                        </template>
                                    </ActionIconGroup>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <TablePagination :data="items" class="mt-3" />

            <!-- Modais -->
            <EntityIntegratorFormModal
                :open="formOpen"
                :entity-id="entity.id"
                :user-integrator-id="userIntegrator.id"
                :item-id="editId"
                :edit-data-url="editDataUrl"
                :update-url="updateUrl"
                :t="t"
                @close="closeForm"
                @saved="onSaved"
            />

            <EntityIntegratorDetailDrawer
                :open="detailOpen"
                :show-url="detailUrl"
                :t="t"
                @close="closeDetail"
                @edit="(item) => openEdit(item)"
            />
        </div>
    </AppLayout>
</template>
