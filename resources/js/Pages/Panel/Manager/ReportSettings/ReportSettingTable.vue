<script setup>
import { computed } from 'vue';
import SortableTh      from '@/Components/Panel/SortableTh.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';

const props = defineProps({
    reportSettings: { type: Object, required: true },
    filters:        { type: Object, default: () => ({}) },
    t:              { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'edit', 'preview', 'publish', 'archive', 'delete']);

const currentSort = computed(() => props.filters.sort ?? 'title');
const currentDir  = computed(() => props.filters.direction ?? 'asc');
</script>

<template>
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <SortableTh
                        col-key="title"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >{{ t.col_title }}</SortableTh>

                    <SortableTh
                        col-key="category"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >{{ t.col_category }}</SortableTh>

                    <th class="text-center" style="min-width:70px;">{{ t.col_paper_size }}</th>
                    <th class="text-center" style="min-width:90px;">{{ t.col_header }}</th>
                    <th class="text-center" style="min-width:90px;">{{ t.col_signature }}</th>
                    <th class="text-center" style="min-width:80px;">{{ t.col_footer }}</th>

                    <SortableTh
                        col-key="status"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >{{ t.col_status }}</SortableTh>

                    <SortableTh
                        col-key="version"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="text-center"
                        @sort="$emit('sort', $event)"
                    >{{ t.col_version }}</SortableTh>

                    <th class="text-center" style="min-width:70px;">{{ t.col_adoptions }}</th>
                    <th class="text-end" style="min-width:130px;">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="reportSettings.data.length === 0">
                    <td colspan="10" class="text-center text-muted py-5">
                        <i class="ti ti-file-off fs-1 mb-2 d-block opacity-50"></i>
                        {{ t.empty_list }}
                    </td>
                </tr>

                <tr v-for="r in reportSettings.data" :key="r.id">
                    <td>
                        <div class="fw-semibold">{{ r.title }}</div>
                        <div v-if="r.description" class="text-muted small text-truncate" style="max-width:220px;">{{ r.description }}</div>
                    </td>

                    <td class="text-muted small">{{ r.category ?? '—' }}</td>

                    <td class="text-center">
                        <span class="badge badge-soft-secondary rounded fs-12">{{ r.paper_size ?? '—' }}</span>
                    </td>

                    <td class="text-center">
                        <span
                            :class="r.show_header
                                ? 'badge badge-soft-primary rounded text-primary border border-primary fs-12'
                                : 'badge badge-soft-secondary rounded fs-12'"
                        >{{ r.show_header ? t.badge_yes : t.badge_no }}</span>
                    </td>

                    <td class="text-center">
                        <span
                            :class="r.show_signature
                                ? 'badge badge-soft-primary rounded text-primary border border-primary fs-12'
                                : 'badge badge-soft-secondary rounded fs-12'"
                        >{{ r.show_signature ? t.badge_yes : t.badge_no }}</span>
                    </td>

                    <td class="text-center">
                        <span
                            :class="r.show_footer
                                ? 'badge badge-soft-primary rounded text-primary border border-primary fs-12'
                                : 'badge badge-soft-secondary rounded fs-12'"
                        >{{ r.show_footer ? t.badge_yes : t.badge_no }}</span>
                    </td>

                    <td>
                        <span class="badge" :class="r.status_badge">{{ r.status_label }}</span>
                    </td>

                    <td class="text-center text-muted small">v{{ r.version }}</td>

                    <td class="text-center text-muted small">{{ r.adopted_count }}</td>

                    <td class="text-end">
                        <div class="d-flex justify-content-end gap-1">
                            <button
                                class="btn btn-xs btn-outline-secondary"
                                :title="t.action_preview"
                                @click="$emit('preview', r)"
                            ><i class="ti ti-file-search"></i></button>

                            <button
                                class="btn btn-xs btn-outline-secondary"
                                :title="t.action_edit"
                                @click="$emit('edit', r.id)"
                            ><i class="ti ti-edit"></i></button>

                            <div class="dropdown">
                                <button class="btn btn-xs btn-outline-secondary" data-bs-toggle="dropdown">
                                    <i class="ti ti-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:160px;">
                                    <li v-if="r.status === 'draft' || r.status === 'archived'">
                                        <button
                                            class="dropdown-item rounded-1 text-success"
                                            @click="$emit('publish', r)"
                                        >
                                            <i class="ti ti-send me-1"></i> {{ t.action_publish }}
                                        </button>
                                    </li>
                                    <li v-if="r.status === 'published'">
                                        <button
                                            class="dropdown-item rounded-1 text-warning"
                                            @click="$emit('archive', r)"
                                        >
                                            <i class="ti ti-archive me-1"></i> {{ t.action_archive }}
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider my-1"></li>
                                    <li>
                                        <button
                                            class="dropdown-item rounded-1 text-danger"
                                            @click="$emit('delete', r)"
                                        >
                                            <i class="ti ti-trash me-1"></i> {{ t.action_delete }}
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <TablePagination :data="reportSettings" />
</template>
