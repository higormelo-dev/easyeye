import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Cid10Table from '@/Pages/Panel/Manager/Cid10/Cid10Table.vue';
import Cid10Cards from '@/Pages/Panel/Manager/Cid10/Cid10Cards.vue';
import Cid10DetailDrawer from '@/Pages/Panel/Manager/Cid10/Cid10DetailDrawer.vue';
import Cid10ReviewDrawer from '@/Pages/Panel/Manager/Cid10/Cid10ReviewDrawer.vue';

/**
 * Manager → CID-10: tabela/cards (selos, oficial ao lado, uso, exclusão
 * travada quando em uso), drawer de detalhes e gaveta de registros a revisar.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({ default: { props: ['data'], template: '<nav />' } }));
vi.mock('@/Components/Panel/OffcanvasPanel.vue', () => ({
    default: {
        props: ['open'],
        emits: ['close'],
        template: `<aside v-if="open"><header><slot name="header" /></header><slot />
            <footer v-if="$slots.footer"><slot name="footer" /></footer></aside>`,
    },
}));

const t = new Proxy(
    {
        usage_hint: ':records prontuário(s) · :exams exame(s) · :clinics clínica(s)',
        edited_hint: 'Oficial: ":official"',
        delete_blocked: 'Em uso por :count',
        detail_title: 'Código :code',
        detail_edited_by: 'Editada por :name em :date',
        review_old_vs_new: 'Mostrava ":old"; oficial ":official"',
    },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);

const edited = {
    id: 'c-1',
    code: 'H25.1',
    description: 'Catarata nuclear (texto da casa)',
    official_description: 'Catarata senil nuclear',
    category: 'Cristalino',
    group_name: 'Transtornos do cristalino',
    chapter: 'VII',
    chapter_name: 'Doenças do olho e anexos',
    is_custom: false,
    is_edited: true,
    edited_at: '05/10/2026 10:00',
    edited_by: 'Ana Admin',
    created_at: '01/10/2026',
    usage: { records: 1200, exams: 34, clinics: 5, links: 2, total: 1234 },
};
const custom = {
    id: 'c-2',
    code: 'H59.7',
    description: 'Complicação pós-operatória',
    official_description: null,
    category: 'Retina',
    chapter: 'VII',
    is_custom: true,
    is_edited: false,
    usage: { records: 0, exams: 0, clinics: 0, links: 0, total: 0 },
};
const codes = { data: [edited, custom], links: [], total: 2 };

describe('Cid10Table', () => {
    it('selos editado/personalizado, oficial embaixo do texto editado, capítulo e uso localizado', () => {
        const wrapper = mount(Cid10Table, { props: { codes, t }, global: { stubs: { teleport: true } } });
        const [first, second] = wrapper.findAll('tbody tr');

        expect(first.find('[data-test="badge-edited"]').attributes('title')).toBe('Oficial: "Catarata senil nuclear"');
        expect(first.find('[data-test="official-text"]').text()).toBe('official_label Catarata senil nuclear');
        expect(first.find('[data-test="badge-custom"]').exists()).toBe(false);
        expect(first.find('[data-test="usage"]').text()).toBe('1.234');
        expect(first.find('[data-test="usage"]').attributes('title')).toBe(
            '1.200 prontuário(s) · 34 exame(s) · 5 clínica(s)',
        );
        expect(first.text()).toContain('VII');

        expect(second.find('[data-test="badge-custom"]').attributes('title')).toBe('custom_hint');
        expect(second.text()).toContain('usage_none');
    });

    it('excluir fica travado (com o motivo) para código em uso', async () => {
        const wrapper = mount(Cid10Table, { props: { codes, t }, global: { stubs: { teleport: true } } });
        const [used, free] = wrapper.findAll('tbody tr');

        await used.find('button[aria-haspopup="menu"]').trigger('click');
        expect(used.find('[data-test="delete"]').attributes('disabled')).toBeDefined();
        expect(used.find('[data-test="delete"]').attributes('title')).toBe('Em uso por 1.236');

        await free.find('button[aria-haspopup="menu"]').trigger('click');
        await free.find('[data-test="delete"]').trigger('click');
        expect(wrapper.emitted('delete')[0][0].id).toBe('c-2');
    });

    it('cabeçalhos ordenáveis emitem a coluna', async () => {
        const wrapper = mount(Cid10Table, { props: { codes, t, filters: { sort: 'code', direction: 'asc' } } });
        await wrapper
            .findAll('th button')
            .find((b) => b.text().includes('col_code'))
            .trigger('click');

        expect(wrapper.emitted('sort')[0][0]).toEqual({ sort: 'code', direction: 'desc' });
    });

    it('lista vazia mostra o aviso', () => {
        expect(mount(Cid10Table, { props: { codes: { data: [], links: [] }, t } }).text()).toContain('empty');
    });
});

describe('Cid10Cards', () => {
    it('mesmos dados em cards: selos, uso e exclusão travada quando em uso', () => {
        const wrapper = mount(Cid10Cards, { props: { codes, t } });
        const [used, free] = wrapper.findAll('[data-code]');

        expect(used.text()).toContain('badge_edited');
        expect(used.text()).toContain('1.200 prontuário(s)');
        expect(
            used.find('button[aria-label="Em uso por 1.236"]').exists() ||
                used.find('[title="Em uso por 1.236"]').exists(),
        ).toBe(true);
        expect(free.text()).toContain('badge_custom');
    });
});

describe('Cid10DetailDrawer', () => {
    it('mostra o texto exibido e o oficial lado a lado, quem editou, classificação e uso (só contagens)', () => {
        const wrapper = mount(Cid10DetailDrawer, { props: { open: true, code: edited, t } });

        expect(wrapper.find('header').text()).toContain('Código H25.1');
        expect(wrapper.find('[data-test="shown"]').text()).toBe('Catarata nuclear (texto da casa)');
        expect(wrapper.find('[data-test="official"]').text()).toBe('Catarata senil nuclear');
        expect(wrapper.text()).toContain('Editada por Ana Admin em 05/10/2026 10:00');
        expect(wrapper.text()).toContain('VII – Doenças do olho e anexos');
        expect(wrapper.find('[data-test="usage-records"]').text()).toBe('1.200');
        expect(wrapper.find('[data-test="usage-links"]').text()).toBe('2');
        expect(wrapper.find('[data-test="drawer-delete"]').attributes('disabled')).toBeDefined();
    });

    it('personalizado: aviso de TISS, sem texto oficial; editar e excluir devolvem o item', async () => {
        const wrapper = mount(Cid10DetailDrawer, { props: { open: true, code: custom, t } });

        expect(wrapper.find('[data-test="custom-warning"]').text()).toBe('custom_hint');
        expect(wrapper.find('[data-test="official"]').text()).toBe('detail_official_none');

        await wrapper.find('[data-test="drawer-edit"]').trigger('click');
        await wrapper.find('[data-test="drawer-delete"]').trigger('click');
        expect(wrapper.emitted('edit')[0][0].id).toBe('c-2');
        expect(wrapper.emitted('delete')[0][0].id).toBe('c-2');
    });
});

describe('Cid10ReviewDrawer', () => {
    const summary = {
        total: 2,
        clinics: [{ entity: 'CLÍNICA VISÃO', records: 1, exams: 1, signed: 1, total: 2 }],
    };
    const records = [
        {
            entity: 'CLÍNICA VISÃO',
            kind: 'record',
            code: 'PMR-000123',
            cid: 'H50.5',
            text: 'Estrabismo paralítico',
            signed: true,
            old_text: 'Estrabismo paralítico',
            official: 'Heteroforia',
        },
        {
            entity: 'CLÍNICA VISÃO',
            kind: 'exam',
            code: 'EXM-0009',
            cid: 'B00.3',
            text: 'Doença ocular herpética',
            signed: false,
        },
    ];

    it('resumo por clínica e lista de registros (clínica, tipo, código, CID, texto, assinado)', () => {
        const wrapper = mount(Cid10ReviewDrawer, { props: { open: true, summary, records, t } });

        expect(wrapper.find('[data-test="review-clinics"]').text()).toContain('CLÍNICA VISÃO');
        const lines = wrapper.findAll('[data-test="review-records"] tbody tr');
        expect(lines).toHaveLength(2);
        expect(lines[0].text()).toContain('review_kind_record');
        expect(lines[0].text()).toContain('PMR-000123');
        expect(lines[0].text()).toContain('yes');
        expect(lines[0].findAll('td')[3].attributes('title')).toBe(
            'Mostrava "Estrabismo paralítico"; oficial "Heteroforia"',
        );
        expect(lines[1].text()).toContain('review_kind_exam');
        expect(lines[1].text()).toContain('no');
    });

    it('lista ainda não carregada mostra "carregando"; sem registros mostra o vazio', () => {
        expect(
            mount(Cid10ReviewDrawer, { props: { open: true, summary, records: null, t } })
                .find('[data-test="review-loading"]')
                .exists(),
        ).toBe(true);
        expect(
            mount(Cid10ReviewDrawer, { props: { open: true, summary: { total: 0, clinics: [] }, records: [], t } })
                .find('[data-test="review-empty"]')
                .exists(),
        ).toBe(true);
    });
});
