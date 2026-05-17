<script setup>
import { computed } from 'vue';
import SortableTh          from '@/Components/Panel/SortableTh.vue';
import BillingStateBadge   from '@/Components/Panel/BillingStateBadge.vue';
import TablePagination     from '@/Components/Panel/TablePagination.vue';

const props = defineProps({
    subscriptions: { type: Object, required: true },
    filters:       { type: Object, default: () => ({}) },
    t:             { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'view', 'edit', 'activate', 'trial', 'cancel', 'block']);

const currentSort = computed(() => props.filters.sort ?? 'created_at');
const currentDir  = computed(() => props.filters.direction ?? 'desc');
</script>

<template>
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <SortableTh
                        col-key="entity_name"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >{{ t.col_entity }}</SortableTh>

                    <SortableTh
                        col-key="plan_name"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >{{ t.col_plan }}</SortableTh>

                    <th class="text-center" style="min-width:90px;">{{ t.col_status }}</th>
                    <th class="text-center" style="min-width:100px;">{{ t.col_billing_state }}</th>
                    <th class="text-center" style="min-width:90px;">{{ t.col_gateway }}</th>

                    <SortableTh
                        col-key="next_billing_at"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >{{ t.col_next_billing }}</SortableTh>

                    <SortableTh
                        col-key="starts_at"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >{{ t.col_starts_at }}</SortableTh>

                    <SortableTh
                        col-key="ends_at"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >{{ t.col_ends_at }}</SortableTh>

                    <th class="text-end">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <!-- Empty state -->
                <tr v-if="subscriptions.data.length === 0">
                    <td colspan="9" class="text-center text-muted py-5">
                        <i class="fas fa-file-contract fs-1 d-block mb-2 opacity-25"></i>
                        {{ t.empty_list }}
                    </td>
                </tr>

                <tr
                    v-for="s in subscriptions.data"
                    :key="s.id"
                    :class="{ 'table-warning': s.needs_attention }"
                >
                    <!-- Empresa -->
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span
                                class="avatar-xs rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                :class="s.entity_active ? 'bg-success-subtle' : 'bg-danger-subtle'"
                                style="width:28px;height:28px;"
                            >
                                <i
                                    class="fas fa-file-contract fs-11"
                                    :class="s.entity_active ? 'text-success' : 'text-danger'"
                                ></i>
                            </span>
                            <div>
                                <div class="fw-medium" style="font-size:.875rem;">{{ s.entity_name }}</div>
                                <div v-if="s.needs_attention" class="text-danger" style="font-size:.7rem;">
                                    <i class="ti ti-alert-triangle me-1"></i>{{ t.attention_badge }}
                                </div>
                            </div>
                        </div>
                    </td>

                    <!-- Plano -->
                    <td class="text-muted small">{{ s.plan_name }}</td>

                    <!-- Status -->
                    <td class="text-center">
                        <span class="badge" :class="s.status_badge">{{ s.status_label }}</span>
                    </td>

                    <!-- Estado cobrança -->
                    <td class="text-center">
                        <BillingStateBadge :badge="s.billing_state_badge" :label="s.billing_state_label" :state="s.billing_state" />
                    </td>

                    <!-- Gateway -->
                    <td class="text-center">
                        <span v-if="s.gateway" class="badge badge-soft-primary text-uppercase">{{ s.gateway }}</span>
                        <span v-else class="text-muted">—</span>
                    </td>

                    <!-- Próxima cobrança -->
                    <td class="small">{{ s.next_billing_at ?? '—' }}</td>

                    <!-- Início -->
                    <td class="small">{{ s.starts_at ?? '—' }}</td>

                    <!-- Vencimento -->
                    <td class="small">{{ s.ends_at ?? '∞' }}</td>

                    <!-- Ações -->
                    <td class="text-end">
                        <div class="d-flex align-items-center justify-content-end gap-1">
                            <button
                                class="btn btn-sm btn-outline-secondary"
                                :title="t.action_view"
                                @click="$emit('view', s.id)"
                            ><i class="ti ti-eye"></i></button>

                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                    <i class="ti ti-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:180px;">
                                    <li>
                                        <button class="dropdown-item rounded-1" @click="$emit('edit', s.id)">
                                            <i class="ti ti-edit me-1"></i> {{ t.action_edit }}
                                        </button>
                                    </li>
                                    <li>
                                        <button class="dropdown-item rounded-1 text-success" @click="$emit('activate', s)">
                                            <i class="ti ti-player-play me-1"></i> {{ t.action_activate }}
                                        </button>
                                    </li>
                                    <li>
                                        <button class="dropdown-item rounded-1 text-info" @click="$emit('trial', s)">
                                            <i class="ti ti-clock-play me-1"></i> {{ t.action_trial }}
                                        </button>
                                    </li>
                                    <li v-if="s.is_accessible">
                                        <button class="dropdown-item rounded-1 text-warning" @click="$emit('cancel', s)">
                                            <i class="ti ti-ban me-1"></i> {{ t.action_cancel }}
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider my-1"></li>
                                    <li>
                                        <button class="dropdown-item rounded-1" @click="$emit('block', s)">
                                            <i :class="`ti me-1 ${s.entity_active ? 'ti-lock' : 'ti-lock-open'}`"></i>
                                            {{ s.entity_active ? t.action_block : t.action_unblock }}
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

    <TablePagination
        :data="subscriptions"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
    />
</template>
