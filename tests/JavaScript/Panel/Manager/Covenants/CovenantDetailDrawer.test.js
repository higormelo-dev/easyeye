import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';
import CovenantDetailDrawer from '@/Pages/Panel/Manager/Covenants/CovenantDetailDrawer.vue';

/**
 * Drawer de detalhes do convênio: dados da operadora vêm da linha do
 * catálogo; o uso pelas clínicas é buscado ao abrir.
 */
vi.mock('axios', () => ({ default: { get: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { locale: 'pt_BR' } }) }));
// A seção de planos tem teste próprio (CovenantPlansSection.test.js).
vi.mock('@/Pages/Panel/Manager/Covenants/CovenantPlansSection.vue', () => ({
    default: { props: ['covenant', 't', 'tp'], template: '<div class="plans-stub" :data-covenant="covenant.id" />' },
}));
vi.mock('@/Components/Panel/OffcanvasPanel.vue', () => ({
    default: {
        props: ['open'],
        emits: ['close'],
        template: `<aside v-if="open"><header><slot name="header" /></header><slot />
            <footer v-if="$slots.footer"><slot name="footer" /></footer></aside>`,
    },
}));

const t = new Proxy({}, { get: (o, k) => (typeof k === 'string' ? k : o[k]) });

const cancelled = {
    id: 'ans-1',
    code: 'CVP-0000000010',
    name: 'OPERADORA X',
    company_name: 'Operadora X Ltda',
    cnpj_formatted: '11.222.333/0001-81',
    ans_registry: '123456',
    ans_modality: 'Medicina de Grupo',
    city: 'Recife',
    uf: 'PE',
    ans_registered_at: '10/05/2001',
    ans_status: 'cancelled',
    ans_cancelled_at: '01/08/2026',
    ans_cancellation_reason: 'Pedido de cancelamento',
    source: 'ans',
    source_label: 'ANS',
    color: '#EF4444',
    table: false,
    active: false,
    synced_at: '03/10/2026 04:31',
    is_particular: false,
};

function mountDrawer(covenant, open = true) {
    return mount(CovenantDetailDrawer, { props: { open, covenant, t } });
}

describe('Manager → Convênios: drawer de detalhes', () => {
    beforeEach(() => vi.clearAllMocks());

    it('mostra dados oficiais, cancelamento na ANS e última sincronização', async () => {
        axios.get.mockResolvedValue({
            data: { data: { clinics: 0, patients: 0, schedules: 0, claims: 0, prices: 0 } },
        });
        const text = mountDrawer(cancelled).text();

        expect(text).toContain('OPERADORA X');
        expect(text).toContain('Operadora X Ltda');
        expect(text).toContain('11.222.333/0001-81');
        expect(text).toContain('Recife / PE');
        expect(text).toContain('cancelled_hint');
        expect(text).toContain('01/08/2026');
        expect(text).toContain('Pedido de cancelamento');
        expect(text).toContain('03/10/2026 04:31');
    });

    it('busca o uso pelas clínicas ao abrir e mostra os contadores', async () => {
        axios.get.mockResolvedValue({
            data: { data: { clinics: 3, patients: 1250, schedules: 4100, claims: 12, prices: 40 } },
        });
        const wrapper = mountDrawer(cancelled);
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('covenants.usage'));
        expect(wrapper.text()).toContain('1.250');
        expect(wrapper.text()).toContain('4.100');
        expect(wrapper.text()).toContain('usage_deactivate_hint');
    });

    it('falha ao buscar o uso mostra aviso sem quebrar o drawer', async () => {
        axios.get.mockRejectedValue(new Error('500'));
        const wrapper = mountDrawer(cancelled);
        await flushPromises();

        expect(wrapper.text()).toContain('usage_failed');
        expect(wrapper.text()).toContain('Operadora X Ltda');
    });

    it('seção de planos para operadora; PARTICULAR não tem planos', async () => {
        axios.get.mockResolvedValue({ data: { data: {} } });

        expect(mountDrawer(cancelled).find('.plans-stub').attributes('data-covenant')).toBe('ans-1');
        expect(
            mountDrawer({ ...cancelled, id: 'p-1', is_particular: true })
                .find('.plans-stub')
                .exists(),
        ).toBe(false);
    });

    it('fechado não busca nada; "editar" no rodapé devolve o convênio', async () => {
        axios.get.mockResolvedValue({ data: { data: {} } });
        mountDrawer(cancelled, false);
        expect(axios.get).not.toHaveBeenCalled();

        const wrapper = mountDrawer(cancelled);
        await wrapper.find('footer .btn-primary').trigger('click');
        expect(wrapper.emitted('edit')[0][0].id).toBe('ans-1');
    });
});
