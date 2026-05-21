<script setup>
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout         from '@/Layouts/AppLayout.vue';
import PageHeader        from '@/Components/Panel/PageHeader.vue';
import SearchInput       from '@/Components/Panel/SearchInput.vue';
import ActionDropdown    from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton  from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup   from '@/Components/Panel/ActionIconGroup.vue';

/**
 * Modelos de documentação clínica (receituários, atestados, laudos).
 *
 * - Templates próprios da clínica (entity_id != null) — totalmente editáveis
 * - Templates globais adotados (source_version controla atualizações disponíveis)
 *   → reimport puxa nova versão do template global
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    categories:  { type: Array,  default: () => [] },
    items:       { type: Array,  default: () => [] },
    urls:        { type: Object, required: true },
});

const search        = ref('');
const categoryId    = ref('');

const filteredItems = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.items.filter(item => {
        if (categoryId.value && item.category !== categoryId.value) {
            // categoria vem como nome, então só filtramos quando o ID === nome
            // (na prática, vamos comparar por category name no select abaixo)
            return false;
        }
        if (q && !(item.title?.toLowerCase().includes(q))) return false;
        return true;
    });
});

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function onDelete(item) {
    if (!confirm(`Excluir "${item.title}"?`)) return;
    await fetch(item.destroy_url, {
        method:  'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    router.reload({ only: ['items'] });
}

async function onReimport(item) {
    if (!item.reimport_url) return;
    if (!confirm('Reimportar versão atualizada do template global? Suas alterações locais serão sobrescritas.')) return;
    await fetch(item.reimport_url, {
        method:  'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    router.reload({ only: ['items'] });
}

function openPreview(item) {
    window.open(item.preview_url, '_blank');
}
</script>

<template>
    <AppLayout title="Modelos de documentação" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader
                title="Modelos de documentação"
                :subtitle="`${items.length} modelos`"
            >
                <template #actions>
                    <Link :href="urls.create" class="btn btn-primary btn-sm">
                        <i class="ti ti-plus me-1"></i>Novo modelo
                    </Link>
                </template>
            </PageHeader>

            <!-- Toolbar -->
            <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
                <SearchInput v-model="search" placeholder="Buscar por título..." style="min-width: 280px;" />
            </div>

            <!-- Lista -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Título</th>
                                <th>Categoria</th>
                                <th class="text-center">Papel</th>
                                <th class="text-center">Cabeçalho</th>
                                <th class="text-center">Assinatura</th>
                                <th class="text-center">Origem</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="filteredItems.length === 0">
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="ti ti-file-text fs-1 d-block mb-2 opacity-25"></i>
                                    Nenhum modelo cadastrado.
                                </td>
                            </tr>
                            <tr v-for="item in filteredItems" :key="item.id">
                                <td class="fw-medium">{{ item.title }}</td>
                                <td class="text-muted">{{ item.category || '—' }}</td>
                                <td class="text-center"><code class="small">{{ item.paper_size }}</code></td>
                                <td class="text-center">
                                    <i v-if="item.show_header" class="ti ti-check text-success"></i>
                                    <i v-else class="ti ti-minus text-muted"></i>
                                </td>
                                <td class="text-center">
                                    <i v-if="item.show_signature" class="ti ti-check text-success"></i>
                                    <i v-else class="ti ti-minus text-muted"></i>
                                </td>
                                <td class="text-center">
                                    <span v-if="item.is_adopted" class="badge badge-soft-info rounded fs-11">
                                        <i class="ti ti-cloud-download me-1"></i>Adotado
                                        <span v-if="item.has_update" class="badge bg-warning text-dark ms-1">Atualização disponível</span>
                                    </span>
                                    <span v-else class="badge badge-soft-secondary rounded fs-11">Próprio</span>
                                </td>
                                <td class="text-end">
                                    <ActionIconGroup align="end" gap="tight">
                                        <ActionIconButton
                                            icon="ti ti-eye"
                                            title="Pré-visualizar"
                                            @click="openPreview(item)"
                                        />
                                        <Link
                                            :href="item.edit_url"
                                            class="btn btn-sm btn-ghost"
                                            title="Editar"
                                        >
                                            <i class="ti ti-edit"></i>
                                        </Link>
                                        <ActionDropdown
                                            btn-class="ee-action-icon ee-action-icon--default"
                                            icon="ti ti-dots-vertical"
                                        >
                                            <li v-if="item.reimport_url">
                                                <button class="dropdown-item rounded-1" @click="onReimport(item)">
                                                    <i class="ti ti-refresh me-1"></i>Reimportar template global
                                                </button>
                                            </li>
                                            <li v-if="item.reimport_url"><hr class="dropdown-divider"></li>
                                            <li>
                                                <button class="dropdown-item rounded-1 text-danger" @click="onDelete(item)">
                                                    <i class="ti ti-trash me-1"></i>Excluir
                                                </button>
                                            </li>
                                        </ActionDropdown>
                                    </ActionIconGroup>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
