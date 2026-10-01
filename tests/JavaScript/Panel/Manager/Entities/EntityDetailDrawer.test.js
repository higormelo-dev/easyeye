import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import EntityDetailDrawer from '@/Pages/Panel/Manager/Entities/EntityDetailDrawer.vue';

/**
 * manager.entities.show devolve telefone/CNPJ como gravados (só dígitos — o
 * Entity::setAttribute e o EntityRequest normalizam). O drawer formata na
 * exibição, mas só tamanhos conhecidos: número estrangeiro e CNPJ alfanumérico
 * (IN RFB 2.229/2024) aparecem exatamente como gravados.
 */
const t = {
    detail_telephone: 'Telefone',
    detail_cellphone: 'Celular',
    detail_national_registration: 'CNPJ / CPF',
};

function mockEntity(overrides = {}) {
    globalThis.fetch = vi.fn(() =>
        Promise.resolve({
            ok: true,
            json: () =>
                Promise.resolve({
                    data: {
                        id: 'ent-1',
                        code: 'ENT-0000000002',
                        name: 'CLINICA TESTE',
                        active: true,
                        telephone: '6133334444',
                        cellphone: '61999998888',
                        national_registration: '11222333000181',
                        zipcode: '01310100',
                        ...overrides,
                    },
                }),
        }),
    );
}

async function mountOpenDrawer() {
    // Monta fechado e abre: o fetch só roda no watch de `open` (sem immediate).
    const wrapper = mount(EntityDetailDrawer, {
        props: { open: false, entityId: 'ent-1', t },
        global: { stubs: { teleport: true } },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
    return wrapper;
}

function valueOf(wrapper, label) {
    const row = wrapper.findAll('.detail-row').find((r) => r.find('.detail-label').text() === label);
    return row.find('.detail-value').text();
}

describe('EntityDetailDrawer — exibição de telefone e CNPJ/CPF', () => {
    it('formata telefone fixo, celular e CNPJ gravados só com dígitos', async () => {
        mockEntity();
        const wrapper = await mountOpenDrawer();

        expect(valueOf(wrapper, 'Telefone')).toBe('(61) 3333-4444');
        expect(valueOf(wrapper, 'Celular')).toBe('(61) 99999-8888');
        expect(valueOf(wrapper, 'CNPJ / CPF')).toBe('11.222.333/0001-81');
    });

    it('formata CPF de clínica pessoa física', async () => {
        mockEntity({ national_registration: '12345678909' });
        const wrapper = await mountOpenDrawer();

        expect(valueOf(wrapper, 'CNPJ / CPF')).toBe('123.456.789-09');
    });

    it('mantém CNPJ alfanumérico e número com DDI como gravados', async () => {
        mockEntity({ national_registration: '12ABC345000195', cellphone: '5561999998888' });
        const wrapper = await mountOpenDrawer();

        expect(valueOf(wrapper, 'CNPJ / CPF')).toBe('12ABC345000195');
        expect(valueOf(wrapper, 'Celular')).toBe('5561999998888');
    });

    it('mostra travessão quando não há telefone nem documento', async () => {
        mockEntity({ telephone: null, cellphone: '', national_registration: null });
        const wrapper = await mountOpenDrawer();

        expect(valueOf(wrapper, 'Telefone')).toBe('—');
        expect(valueOf(wrapper, 'Celular')).toBe('—');
        expect(valueOf(wrapper, 'CNPJ / CPF')).toBe('—');
    });
});
