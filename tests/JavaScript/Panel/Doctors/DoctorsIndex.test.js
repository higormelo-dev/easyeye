import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import DoctorsIndex from '@/Pages/Panel/Doctors/Index.vue';

/**
 * Página de médicos no layout de Panel/Patients/Index: total no cabeçalho,
 * alternância tabela/cards persistida, textos via `t` e busca que preserva
 * a ordenação.
 */

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'totalLabel', 'view'],
        emits: ['set-view'],
        template: `<div>
            <span class="total">{{ totalLabel }} {{ total }}</span>
            <button class="to-cards" @click="$emit('set-view', 'cards')" />
            <slot name="actions" />
        </div>`,
    },
}));
vi.mock('@/Components/Panel/SearchInput.vue', () => ({
    default: {
        props: ['modelValue', 'placeholder'],
        emits: ['update:modelValue'],
        template:
            '<input class="search" :placeholder="placeholder" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
    },
}));
vi.mock('@/Pages/Panel/Doctors/DoctorTable.vue', () => ({
    default: { props: ['doctors', 't'], template: '<div class="table-stub">{{ doctors.data.length }}</div>' },
}));
vi.mock('@/Pages/Panel/Doctors/DoctorCards.vue', () => ({
    default: { props: ['cardsUrl', 'search'], template: '<div class="cards-stub">{{ search }}</div>' },
}));
vi.mock('@/Pages/Panel/Doctors/DoctorFormModal.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Doctors/DoctorDetailDrawer.vue', () => ({ default: { template: '<div />' } }));

const t = {
    page_title: 'Doctors',
    total_label: 'Total:',
    btn_import: 'Import',
    btn_new: 'New doctor',
    search_placeholder: 'Search by name...',
};

let wrapper;

beforeEach(() => {
    window.localStorage.clear();
    vi.mocked(router.get).mockClear();
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
});

function mountPage(filters = { search: '', sort: 'created_at', direction: 'desc' }) {
    wrapper = mount(DoctorsIndex, {
        props: { doctors: { data: [{ id: 'd1' }, { id: 'd2' }], total: 42 }, filters, t },
        // route() no template vem do plugin ZiggyVue no app real.
        global: { mocks: { route: globalThis.route } },
    });

    return wrapper;
}

describe('Doctors/Index', () => {
    it('usa os textos traduzidos e mostra o total geral no cabeçalho', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Doctors');
        expect(w.find('.total').text()).toBe('Total: 42');
        expect(w.text()).toContain('Import');
        expect(w.text()).toContain('New doctor');
        expect(w.find('.search').attributes('placeholder')).toBe('Search by name...');
    });

    it('alterna para cards e guarda a preferência no navegador', async () => {
        const w = mountPage({ search: 'ana', sort: 'created_at', direction: 'desc' });

        await w.find('.to-cards').trigger('click');

        expect(w.find('.table-stub').exists()).toBe(false);
        expect(w.find('.cards-stub').text()).toBe('ana');
        expect(window.localStorage.getItem('doctors_view')).toBe('cards');
    });

    it('a busca espera parar de digitar e preserva a ordenação atual', async () => {
        vi.useFakeTimers();
        const w = mountPage({ search: '', sort: 'cellphone', direction: 'asc' });

        await w.find('.search').setValue('ana');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/_routes/panel.doctors.index',
            { search: 'ana', sort: 'cellphone', direction: 'asc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });
});
