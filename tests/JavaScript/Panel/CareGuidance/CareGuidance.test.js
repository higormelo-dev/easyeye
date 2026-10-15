import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () =>
    (await import('../Manager/RecallProtocols/support/inertiaMock.js')).buildInertiaMock(),
);
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div class="layout"><h1>{{ title }}</h1><slot /></div>' },
}));

import { resetInertiaMock } from '../Manager/RecallProtocols/support/inertiaMock.js';
import CareGuidanceEditor from '@/Components/Panel/CareGuidance/CareGuidanceEditor.vue';
import CareGuidancePanel from '@/Components/Panel/CareGuidance/CareGuidancePanel.vue';
import SettingsIndex from '@/Pages/Panel/Settings/CareGuidance/Index.vue';
import MedicalRecordProceduresModal from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordProceduresModal.vue';
import t from './support/care_guidance.json';

const global = {
    mocks: { route: (name, params) => `/${name}${params ? '/' + [params].flat().join('/') : ''}` },
    stubs: { Teleport: true, CenteredModal: false },
};

const template = (overrides = {}) => ({
    id: 'tpl-1',
    title: 'Pós-operatório de catarata',
    procedure_id: null,
    procedure_name: null,
    location_label: 'Sala de curativos',
    body: 'Não coce o olho.',
    eye_drops: [{ name: 'Moxifloxacino', how: '1 gota', times_per_day: 4, days: 7 }],
    active: true,
    attachments: [],
    ...overrides,
});

beforeEach(() => {
    resetInertiaMock();
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    globalThis.route = global.mocks.route;
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('CareGuidanceEditor', () => {
    const editor = (props = {}) =>
        mount(CareGuidanceEditor, { props: { open: false, template: null, t, ...props }, global });

    async function open(props = {}) {
        const w = editor(props);
        await w.setProps({ open: true });
        await flushPromises();

        return w;
    }

    it('modelo novo: campos vazios, ativo, e avisa que PDF só depois de salvar', async () => {
        const w = await open();

        expect(w.get('[data-testid="guidance-title"]').element.value).toBe('');
        expect(w.text()).toContain(t.settings.save_first);
        expect(w.find('[data-testid="guidance-file"]').exists()).toBe(false);
    });

    it('envia o payload limpo: título aparado, colírio sem nome some, números viram número', async () => {
        const w = await open();

        await w.get('[data-testid="guidance-title"]').setValue('  Pós de pterígio  ');
        await w.get('[data-testid="guidance-body"]').setValue('Use o colírio.');
        await w.get('[data-testid="guidance-add-drop"]').trigger('click');
        await w.get('[data-testid="guidance-add-drop"]').trigger('click');

        const inputs = w.findAll('[data-testid="guidance-drops"] input');
        await inputs[0].setValue('Lubrificante');
        await inputs[2].setValue('3');
        await inputs[3].setValue('14');

        await w.get('form').trigger('submit');

        const [payload] = w.emitted('save').at(-1);

        expect(payload.title).toBe('Pós de pterígio');
        expect(payload.procedure_id).toBeNull();
        expect(payload.location_label).toBeNull();
        expect(payload.eye_drops).toEqual([{ name: 'Lubrificante', how: null, times_per_day: 3, days: 14 }]);
    });

    it('edição traz os dados do modelo e o limite de colírios desabilita o "adicionar"', async () => {
        const w = await open({ template: template(), maxDrops: 1 });

        expect(w.get('[data-testid="guidance-title"]').element.value).toBe('Pós-operatório de catarata');
        expect(w.get('[data-testid="guidance-location"]').element.value).toBe('Sala de curativos');
        expect(w.get('[data-testid="guidance-add-drop"]').attributes('disabled')).toBeDefined();
    });

    it('PDFs: lista os anexos, anexa pelo input e remove; no limite desabilita o envio', async () => {
        const attachments = [{ id: 'a1', name: 'preparo.pdf', size_bytes: 2048 }];
        const w = await open({ template: template({ attachments }), maxAttachments: 1 });

        expect(w.text()).toContain('preparo.pdf');
        expect(w.get('[data-testid="guidance-file"]').attributes('disabled')).toBeDefined();

        await w.get('button[aria-label^="Remover PDF"]').trigger('click');
        expect(w.emitted('detach')[0][0]).toMatchObject({ id: 'a1' });

        const w2 = await open({ template: template(), maxAttachments: 5 });
        const input = w2.get('[data-testid="guidance-file"]');
        const file = new File(['%PDF-1.4'], 'novo.pdf', { type: 'application/pdf' });
        Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
        await input.trigger('change');

        expect(w2.emitted('attach')[0][0]).toBe(file);
    });

    it('mostra o erro do servidor no campo (título, PDF)', async () => {
        const w = await open({
            template: template(),
            errors: { title: ['Informe o título.'], file: ['O arquivo não é um PDF.'] },
        });

        expect(w.text()).toContain('Informe o título.');
        expect(w.get('[data-testid="guidance-file-error"]').text()).toBe('O arquivo não é um PDF.');
    });
});

describe('Configurações › Orientações (página)', () => {
    const mountPage = (templates = [template()]) =>
        mount(SettingsIndex, { props: { templates, maxAttachments: 5, maxDrops: 10, t }, global });

    it('lista os modelos com procedimento, local, colírios e PDFs; vazio mostra o aviso', () => {
        const w = mountPage([template({ attachments: [{ id: 'a', name: 'preparo.pdf', size_bytes: 10 }] })]);

        expect(w.text()).toContain('Pós-operatório de catarata');
        expect(w.text()).toContain(t.settings.any_procedure);
        expect(w.text()).toContain('Sala de curativos');
        expect(w.text()).toContain('Moxifloxacino');
        expect(w.text()).toContain('preparo.pdf');

        expect(mountPage([]).find('[data-testid="guidance-empty"]').exists()).toBe(true);
    });

    it('criar: POST do payload, o modelo novo entra na lista e o editor continua aberto para anexar PDFs', async () => {
        const created = template({ id: 'novo', title: 'Novo modelo' });
        const fetchMock = vi
            .fn()
            .mockResolvedValue({ ok: true, json: async () => ({ message: 'Modelo salvo.', data: created }) });
        vi.stubGlobal('fetch', fetchMock);

        const w = mountPage([]);
        await w.get('[data-testid="guidance-new"]').trigger('click');
        await w.get('[data-testid="guidance-title"]').setValue('Novo modelo');
        await w.get('[data-testid="guidance-body"]').setValue('Texto.');
        await w.get('form').trigger('submit');
        await flushPromises();

        expect(fetchMock.mock.calls[0][1].method).toBe('POST');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({ title: 'Novo modelo', body: 'Texto.' });
        expect(w.find('[data-testid="guidance-novo"]').exists()).toBe(true);
        expect(w.find('[data-testid="guidance-file"]').exists()).toBe(true);
    });

    it('recusa do servidor (422) aparece no campo e nada entra na lista', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                json: async () => ({ message: 'x', errors: { title: ['Informe o título.'] } }),
            }),
        );

        const w = mountPage([]);
        await w.get('[data-testid="guidance-new"]').trigger('click');
        await w.get('[data-testid="guidance-body"]').setValue('Texto.');
        await w.get('form').trigger('submit');
        await flushPromises();

        expect(w.text()).toContain('Informe o título.');
        expect(w.find('[data-testid="guidance-empty"]').exists()).toBe(true);
    });

    it('excluir pede confirmação e remove da lista', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ message: 'Modelo excluído.' }) });
        vi.stubGlobal('fetch', fetchMock);

        const w = mountPage();
        await w.get('[data-testid="guidance-delete"]').trigger('click');

        expect(w.text()).toContain('Excluir o modelo "Pós-operatório de catarata"?');
        expect(fetchMock).not.toHaveBeenCalled();

        await w.get('[data-testid="guidance-delete-confirm"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');
        expect(w.find('[data-testid="guidance-tpl-1"]').exists()).toBe(false);
        expect(w.find('[data-testid="guidance-empty"]').exists()).toBe(true);
    });
});

describe('CareGuidancePanel (prontuário)', () => {
    const state = (overrides = {}) => ({
        data: {
            can_issue: true,
            templates: [
                template({ id: 'tpl-1' }),
                template({ id: 'tpl-2', title: 'Geral', body: 'Texto geral.', eye_drops: [] }),
            ],
            issues: [],
            max_drops: 10,
            t,
            ...overrides,
        },
    });

    const issued = {
        id: 'iss-1',
        title: 'Pós-operatório de catarata',
        issued_at: '2026-03-10T10:00:00-03:00',
        doctor_name: 'Dra. Ana',
        pdf_url: '/pdf/iss-1',
        attachments: [{ index: 0, name: 'preparo.pdf', url: '/att/iss-1/0' }],
    };

    async function mountPanel(payload, post = null) {
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url, init = {}) =>
                init.method === 'POST' ? post(url, init) : { ok: true, json: async () => payload },
            ),
        );
        const w = mount(CareGuidancePanel, { props: { url: '/g' }, global });
        await flushPromises();

        return w;
    }

    it('mostra o que já foi entregue (PDF e anexos) e o formulário do médico', async () => {
        const w = await mountPanel(state({ issues: [issued] }));

        expect(w.get('[data-testid="guidance-issue-iss-1"]').text()).toContain('Dra. Ana');
        expect(w.get('[data-testid="guidance-issue-iss-1"] a').attributes('href')).toBe('/pdf/iss-1');
        expect(w.html()).toContain('/att/iss-1/0');
        expect(w.find('[data-testid="guidance-form"]').exists()).toBe(true);
    });

    it('recepção (sem can_issue) só vê o que foi entregue, sem formulário', async () => {
        const w = await mountPanel(state({ can_issue: false, templates: [], issues: [issued] }));

        expect(w.find('[data-testid="guidance-form"]').exists()).toBe(false);
        expect(w.find('[data-testid="guidance-no-templates"]').exists()).toBe(false);
        expect(w.find('[data-testid="guidance-issues"]').exists()).toBe(true);
    });

    it('sem modelo que sirva, o médico vê o aviso para cadastrar', async () => {
        const w = await mountPanel(state({ templates: [] }));

        expect(w.get('[data-testid="guidance-no-templates"]').text()).toBe(t.issue.none);
    });

    it('emitir só habilita com modelo escolhido; envia os ajustes e abre o PDF', async () => {
        const open = vi.fn();
        vi.stubGlobal('open', open);
        const post = vi.fn(async () => ({
            ok: true,
            json: async () => ({ message: 'Orientação emitida.', data: issued }),
        }));

        const w = await mountPanel(state(), post);

        const button = w.get('[data-testid="guidance-issue"]');
        expect(button.attributes('disabled')).toBeDefined();

        await w.get('#guidance-template').setValue('tpl-1');
        expect(button.attributes('disabled')).toBeUndefined();
        // Escolher o modelo traz o texto dele para ajustar.
        expect(w.get('#guidance-body').element.value).toBe('Não coce o olho.');

        await w.get('#guidance-body').setValue('Texto ajustado.');
        await button.trigger('click');
        await flushPromises();

        const body = JSON.parse(post.mock.calls[0][1].body);

        expect(body).toMatchObject({
            template_id: 'tpl-1',
            body: 'Texto ajustado.',
            location_label: 'Sala de curativos',
        });
        expect(body.eye_drops).toEqual([{ name: 'Moxifloxacino', how: '1 gota', times_per_day: 4, days: 7 }]);
        expect(open).toHaveBeenCalledWith('/pdf/iss-1', '_blank', 'noopener');
        expect(w.get('[data-testid="guidance-notice"]').text()).toBe('Orientação emitida.');
        expect(w.find('[data-testid="guidance-issue-iss-1"]').exists()).toBe(true);
    });

    it('um só modelo vem escolhido; recusa do servidor fica na tela e não abre PDF', async () => {
        const open = vi.fn();
        vi.stubGlobal('open', open);
        const post = vi.fn(async () => ({
            ok: false,
            json: async () => ({ message: 'x', errors: { template_id: ['O modelo é de outro procedimento.'] } }),
        }));

        const w = await mountPanel(state({ templates: [template({ id: 'tpl-1' })] }), post);

        expect(w.get('#guidance-template').element.value).toBe('tpl-1');

        await w.get('[data-testid="guidance-issue"]').trigger('click');
        await flushPromises();

        expect(w.get('[data-testid="guidance-error"]').text()).toBe('O modelo é de outro procedimento.');
        expect(open).not.toHaveBeenCalled();
    });

    it('falha ao carregar mostra o erro com a opção de tentar de novo', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({ ok: false, json: async () => ({}) })),
        );
        const w = mount(CareGuidancePanel, { props: { url: '/g' }, global });
        await flushPromises();

        expect(w.find('[data-testid="guidance-load-error"]').exists()).toBe(true);
    });
});

describe('MedicalRecordProceduresModal — botão Orientações', () => {
    const urls = {
        medicalrecordprocedures_index: '/procs',
        care_guidance_template: '/g/__PROCEDURE_ID__',
    };
    const procs = [
        {
            id: 'mrp-1',
            procedure_id: 'c1',
            procedure_name: 'Facectomia',
            status: 'done',
            status_label: 'Executado',
            doctor_name: 'Dra. Ana',
            created_at: '10/03/2026',
        },
        {
            id: 'mrp-2',
            procedure_id: 'c2',
            procedure_name: 'Curativo',
            status: 'cancelled',
            status_label: 'Cancelado',
            doctor_name: 'Dra. Ana',
            created_at: '10/03/2026',
        },
    ];

    async function openModal(extraUrls = {}) {
        const calls = [];
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url) => {
                calls.push(url);

                if (url === '/procs') return { ok: true, json: async () => ({ data: procs }) };
                if (url === '/g/mrp-1')
                    return {
                        ok: true,
                        json: async () => ({ data: { can_issue: false, templates: [], issues: [], max_drops: 10, t } }),
                    };

                return { ok: false, json: async () => ({}) };
            }),
        );
        const w = mount(MedicalRecordProceduresModal, {
            props: { show: false, urls: { ...urls, ...extraUrls }, isDoctor: false, isLocked: false, t: {} },
            global,
        });
        await w.setProps({ show: true });
        await flushPromises();

        return { w, calls };
    }

    it('procedimento executado tem o botão; cancelado não; abrir carrega as orientações DAQUELE procedimento', async () => {
        const { w, calls } = await openModal();

        expect(w.find('[data-testid="guidance-toggle-mrp-1"]').exists()).toBe(true);
        expect(w.find('[data-testid="guidance-toggle-mrp-2"]').exists()).toBe(false);
        expect(w.find('[data-testid="guidance-panel"]').exists()).toBe(false);

        await w.get('[data-testid="guidance-toggle-mrp-1"]').trigger('click');
        await flushPromises();

        expect(calls).toContain('/g/mrp-1');
        expect(w.find('[data-testid="guidance-panel"]').exists()).toBe(true);

        await w.get('[data-testid="guidance-toggle-mrp-1"]').trigger('click');
        expect(w.find('[data-testid="guidance-panel"]').exists()).toBe(false);
    });

    it('sem a URL de orientações (prontuário ainda não salvo) o botão não aparece', async () => {
        const { w } = await openModal({ care_guidance_template: undefined });

        expect(w.find('[data-testid="guidance-toggle-mrp-1"]').exists()).toBe(false);
    });
});
