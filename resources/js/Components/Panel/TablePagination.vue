<script setup>
import { Link } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * TablePagination — Paginação Inertia para tabelas server-side.
 *
 * Props:
 *   data          – objeto paginator do Laravel (last_page, current_page,
 *                   from, to, total, prev_page_url, next_page_url, links)
 *   showingFrom   – prefixo "Exibindo"
 *   showingOf     – conector "de"
 *   showingSuffix – sufixo (ex: "empresas", "planos")
 *   ariaLabel / previousLabel / nextLabel – rótulos acessíveis (opcionais,
 *                   traduzidos pelo chamador) da <nav> e das setas
 *
 * Não exibe nada quando last_page === 1.
 */
defineProps({
    data: { type: Object, required: true },
    showingFrom: { type: String, default: 'Exibindo' },
    showingOf: { type: String, default: 'de' },
    showingSuffix: { type: String, default: '' },
    ariaLabel: { type: String, default: 'Paginação' },
    previousLabel: { type: String, default: 'Anterior' },
    nextLabel: { type: String, default: 'Próxima' },
});

// Números no formato do idioma (1234 → "1.234" em pt-BR).
const { number } = useLocaleFormat();
</script>

<template>
    <div v-if="data.last_page > 1" class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2">
        <!-- Range label -->
        <p class="text-muted small mb-0">
            {{ showingFrom }} {{ number(data.from) }}–{{ number(data.to) }} {{ showingOf }} {{ number(data.total) }}
            {{ showingSuffix }}
        </p>

        <!-- Page links -->
        <nav :aria-label="ariaLabel">
            <ul class="pagination pagination-sm mb-0">
                <!-- Previous -->
                <li class="page-item" :class="{ disabled: data.current_page === 1 }">
                    <Link
                        class="page-link"
                        :href="data.prev_page_url ?? '#'"
                        :aria-label="previousLabel"
                        preserve-scroll
                        preserve-state
                        ><i class="ti ti-arrow-left" aria-hidden="true"></i
                    ></Link>
                </li>

                <!-- Page numbers -->
                <template v-for="link in data.links.slice(1, -1)" :key="link.label">
                    <li class="page-item" :class="{ active: link.active, disabled: !link.url }">
                        <Link
                            class="page-link"
                            :href="link.url ?? '#'"
                            :aria-current="link.active ? 'page' : undefined"
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
                        :aria-label="nextLabel"
                        preserve-scroll
                        preserve-state
                        ><i class="ti ti-arrow-right" aria-hidden="true"></i
                    ></Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
