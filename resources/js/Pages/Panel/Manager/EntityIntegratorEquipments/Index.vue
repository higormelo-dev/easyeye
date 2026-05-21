<script setup>
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout                       from '@/Layouts/AppLayout.vue';
import PageHeader                      from '@/Components/Panel/PageHeader.vue';
import SearchInput                     from '@/Components/Panel/SearchInput.vue';
import TablePagination                 from '@/Components/Panel/TablePagination.vue';
import ActionIconButton                from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup                 from '@/Components/Panel/ActionIconGroup.vue';
import EntityIntegratorEquipmentDetailDrawer from './EntityIntegratorEquipmentDetailDrawer.vue';

/**
 * Listagem (read-only) de Equipamentos de um Integrador (Manager SaaS).
 * Equipamentos são criados pelo próprio integrador via API — esta tela apenas inspeciona.
 */
const props = defineProps({
    entity:         { type: Object, required: true },
    userIntegrator: { type: Object, required: true },
    integrator:     { type: Object, required: true },
    items:          { type: Object, required: true },
    filters:        { type: Object, default: () => ({}) },
    t:              { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: props.t.breadcrumb_home        ?? 'Dashboard',           url: route('panel.dashboard'),                                                                            active: false },
    { label: props.t.breadcrumb_entities    ?? 'Empresas',            url: route('manager.entities.index'),                                                                     active: false },
    { label: props.entity.name,                                       url: '#',                                                                                                 active: false },
    { label: props.t.breadcrumb_users       ?? 'Usuários Integradores', url: route('manager.entities.user-integrators.index', props.entity.id),                                active: false },
    { label: props.userIntegrator.name,                               url: '#',                                                                                                 active: false },
    { label: props.t.breadcrumb_integrators ?? 'Integradores',        url: route('manager.entities.user-integrators.integrators.index', [props.entity.id, props.userIntegrator.id]), active: false },
    { label: props.integrator.name,                                   url: '#',                                                                                                 active: false },
    { label: props.t.breadcrumb_current     ?? 'Equipamentos',        url: '#',                                                                                                 active: true  },
];

const search = ref(props.filters.search ?? '');
let searchTimer = null;
watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            route('manager.entities.user-integrators.integrators.equipments.index', [
                props.entity.id, props.userIntegrator.id, props.integrator.id,
            ]),
            { search: val },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 400);
});

const detailOpen = ref(false);
const detailUrl  = ref('');

function openDetail(item) {
    detailUrl.value  = item.show_url;
    detailOpen.value = true;
}
function closeDetail() { detailOpen.value = false; }
</script>

<template>
    <AppLayout :title="t.page_title ?? 'Equipamentos'" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader
                :title="t.page_title ?? 'Equipamentos'"
                :subtitle="`${integrator.name} (${integrator.code})`"
                :total="items.total"
            >
                <template #actions>
                    <Link
                        :href="route('manager.entities.user-integrators.integrators.index', [entity.id, userIntegrator.id])"
                        class="btn btn-outline-secondary btn-sm"
                    >
                        <i class="ti ti-arrow-left me-1"></i>{{ t.btn_back ?? 'Voltar' }}
                    </Link>
                </template>
            </PageHeader>

            <!-- Read-only banner -->
            <div class="alert alert-info d-flex align-items-center small py-2 mb-3">
                <i class="ti ti-info-circle me-2 fs-5"></i>
                <span>{{ t.readonly_note }}</span>
            </div>

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
                                <th>{{ t.col_serial         ?? 'Nº Série' }}</th>
                                <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                                <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="items.data.length === 0">
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="ti ti-device-laptop fs-1 d-block mb-2"></i>
                                    {{ t.empty_list ?? 'Nenhum registro.' }}
                                </td>
                            </tr>
                            <tr
                                v-for="e in items.data"
                                :key="e.id"
                                :class="{ 'table-secondary opacity-75': e.deleted }"
                            >
                                <td class="text-muted small">{{ e.created_at }}</td>
                                <td><code class="text-muted small">{{ e.code }}</code></td>
                                <td class="fw-medium">{{ e.name }}</td>
                                <td><code class="small">{{ e.ip || '—' }}</code></td>
                                <td><code class="small">{{ e.mac || '—' }}</code></td>
                                <td><code class="small">{{ e.serial_number || '—' }}</code></td>
                                <td class="text-center">
                                    <span v-if="e.deleted"
                                          class="badge badge-soft-secondary rounded fs-13 fw-medium">
                                        {{ t.status_deleted ?? 'Removido' }}
                                    </span>
                                    <span v-else-if="e.active"
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
                                            icon="ti ti-eye"
                                            :title="t.action_view ?? 'Ver'"
                                            @click="openDetail(e)"
                                        />
                                    </ActionIconGroup>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <TablePagination :data="items" class="mt-3" />

            <EntityIntegratorEquipmentDetailDrawer
                :open="detailOpen"
                :show-url="detailUrl"
                :t="t"
                @close="closeDetail"
            />
        </div>
    </AppLayout>
</template>
