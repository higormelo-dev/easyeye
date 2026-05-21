<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * TablePagination — Paginação Inertia para tabelas server-side.
 *
 * Props:
 *   data          – objeto paginator do Laravel (last_page, current_page,
 *                   from, to, total, prev_page_url, next_page_url, links)
 *   showingFrom   – prefixo "Exibindo"
 *   showingOf     – conector "de"
 *   showingSuffix – sufixo (ex: "empresas", "planos")
 *
 * Não exibe nada quando last_page === 1.
 */
defineProps({
    data:          { type: Object,  required: true },
    showingFrom:   { type: String,  default: 'Exibindo' },
    showingOf:     { type: String,  default: 'de' },
    showingSuffix: { type: String,  default: '' },
});
</script>

<template>
    <div
        v-if="data.last_page > 1"
        class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2"
    >
        <!-- Range label -->
        <p class="text-muted small mb-0">
            {{ showingFrom }} {{ data.from }}–{{ data.to }}
            {{ showingOf }} {{ data.total }} {{ showingSuffix }}
        </p>

        <!-- Page links -->
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <!-- Previous -->
                <li class="page-item" :class="{ disabled: data.current_page === 1 }">
                    <Link
                        class="page-link"
                        :href="data.prev_page_url ?? '#'"
                        preserve-scroll
                        preserve-state
                    ><i class="ti ti-arrow-left"></i></Link>
                </li>

                <!-- Page numbers -->
                <template v-for="link in data.links.slice(1, -1)" :key="link.label">
                    <li class="page-item" :class="{ active: link.active, disabled: !link.url }">
                        <Link
                            class="page-link"
                            :href="link.url ?? '#'"
                            preserve-scroll
                            preserve-state
                            v-html="link.label"
                        />
                    </li>
                </template>

                <!-- Next -->
                <li class="page-item" :class="{ disabled: data.current_page === data.last_page }">
                    <Link
                        class="page-link"
                        :href="data.next_page_url ?? '#'"
                        preserve-scroll
                        preserve-state
                    ><i class="ti ti-arrow-right"></i></Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
