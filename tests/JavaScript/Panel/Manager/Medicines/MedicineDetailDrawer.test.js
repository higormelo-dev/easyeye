import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import MedicineDetailDrawer from '@/Pages/Panel/Manager/Medicines/MedicineDetailDrawer.vue';

/**
 * Drawer de detalhes do medicamento (mesmo padrão de Manager → Planos):
 * dados já vêm da linha do catálogo; campos vazios não aparecem.
 */
vi.mock('@/Components/Panel/OffcanvasPanel.vue', () => ({
    default: {
        props: ['open'],
        emits: ['close'],
        template: `<aside v-if="open"><header><slot name="header" /></header><slot />
            <footer v-if="$slots.footer"><slot name="footer" /></footer></aside>`,
    },
}));

const t = new Proxy({}, { get: (o, k) => (typeof k === 'string' ? k : o[k]) });

const cmed = {
    id: 'cmed-1',
    name: 'PREDOPTIC',
    concentration: '10 MG/ML',
    active_ingredient: 'acetato de prednisolona',
    form: 'suspensão oftálmica',
    laboratory: 'GEOLAB',
    anvisa_registration: '1000000010011',
    ean: '7890000000011',
    source: 'cmed',
    source_label: 'CMED/Anvisa',
    is_ophthalmic: true,
    is_marketed: false,
    cmed_situation: 'not_marketed',
    active: true,
    synced_at: '02/10/2026 22:00',
};

function mountDrawer(medicine) {
    return mount(MedicineDetailDrawer, { props: { open: true, medicine, t } });
}

describe('Manager → Medicamentos: drawer de detalhes', () => {
    it('mostra cadastro da CMED (registro, EAN, situação na CMED, última importação)', () => {
        const text = mountDrawer(cmed).text();

        expect(text).toContain('PREDOPTIC');
        expect(text).toContain('acetato de prednisolona');
        expect(text).toContain('1000000010011');
        expect(text).toContain('7890000000011');
        // Situação na CMED (rótulo + explicação), separada do status Ativo/Inativo.
        expect(text).toContain('col_cmed_situation');
        expect(text).toContain('not_marketed');
        expect(text).toContain('not_marketed_hint');
        expect(text).toContain('status_active');
        expect(text).toContain('02/10/2026 22:00');
        expect(text).toContain('edit_posology');
    });

    it('sem posologia sugerida mostra o aviso; com posologia lista os campos', () => {
        expect(mountDrawer(cmed).text()).toContain('posology_empty');

        const text = mountDrawer({
            id: 'man-1',
            name: 'TOBRAMICINA',
            source: 'manual',
            source_label: 'Curado',
            active: true,
            dosage: '1 gota',
            frequency: 'de 4/4h',
        }).text();

        expect(text).toContain('1 gota');
        expect(text).toContain('de 4/4h');
        expect(text).not.toContain('posology_empty');
        // Item curado não tem dados de importação.
        expect(text).not.toContain('detail_synced_at');
        expect(text).not.toContain('detail_marketed');
    });

    it('ações ficam no rodapé (cabeçalho só com título e badges): editar devolve o item, fechar fecha', async () => {
        const wrapper = mountDrawer(cmed);

        expect(wrapper.find('header button').exists()).toBe(false);
        const [close, edit] = wrapper.findAll('footer button');
        expect(edit.text()).toBe('edit_posology');

        await edit.trigger('click');
        expect(wrapper.emitted('edit')[0][0].id).toBe('cmed-1');

        await close.trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('sem item selecionado não mostra rodapé', () => {
        expect(mountDrawer(null).find('footer').exists()).toBe(false);
    });
});
