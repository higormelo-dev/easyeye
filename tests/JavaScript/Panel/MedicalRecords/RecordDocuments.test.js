import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import MedicalRecordForm from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue';
import PersonalTemplatesBar from '@/Components/Panel/Documents/PersonalTemplatesBar.vue';
import ProcedureRequestModal from '@/Components/Panel/Documents/ProcedureRequestModal.vue';

/**
 * Prontuário × documentos (Fase 2 / Bloco B):
 *  - receita com data alternativa e modelo pessoal: o reenvio com o motivo
 *    da alergia mantém a data e o modelo;
 *  - botões dos atestados novos (acompanhante, aptidão visual) e erro do
 *    servidor no campo;
 *  - pedido de exames: kits do convênio, CIDs do diagnóstico;
 *  - editor de documentos: modelo pessoal no mesmo seletor; trocar a data
 *    refaz a prévia; lista mostra a data impressa.
 */
const DOCUMENTS = {
    date: { today: '2026-10-07', min: '2026-09-07', max: '2026-11-06' },
    arrived_at: '08:15',
    covenant: { id: 'c1', name: 'UNIMED' },
    dilation_hours: { default: 4, min: 1, max: 24 },
    request_limits: { items: 20, indications: 5, cids: 4 },
};

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        usePage: () => ({
            props: {
                auth: { user: { preferences: {} } },
                flash: {},
                errors: {},
                locale: 'pt_BR',
                documents: DOCUMENTS,
            },
        }),
        router: { on: vi.fn(() => () => {}), visit: vi.fn(), reload: vi.fn() },
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        useForm: (data) => {
            const form = actual.useForm(data);
            form.put = vi.fn();
            form.post = vi.fn();
            return form;
        },
    };
});

enableAutoUnmount(afterEach);

const STUBS = {
    teleport: true,
    AiAssistantPanel: true,
    TinyMceEditor: true,
    SearchSelect: true,
    AcuitySelect: true,
    Cid10Picker: true,
    MedicalRecordFileUploadModal: true,
    MedicalRecordImagingModal: true,
    MedicalRecordProceduresModal: true,
    ContactLensCalculatorModal: true,
    PdfPreviewModal: true,
};

let requests;
let rxConflicts;
let companionErrors;

function respond(status, body) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

function mockFetch() {
    requests = [];
    globalThis.fetch = vi.fn((url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : null;
        requests.push({ url, method: init.method ?? 'GET', body });
        if (url.startsWith('/presets')) return respond(200, { recents: [], favorites: [] });
        if (url.startsWith('/tpl') && (init.method ?? 'GET') === 'GET')
            return respond(200, {
                data: [
                    {
                        id: 'tpl-rx',
                        kind: 'prescription',
                        name: 'Pós-op catarata',
                        body: 'Colírio de moxifloxacino 4x/dia',
                        payload: {
                            items: [{ medicine_id: 'm1', name: 'VIGAMOX', active_ingredient: 'MOXIFLOXACINO' }],
                        },
                    },
                ],
            });
        if (url === '/qa/medication-prescription') {
            if (rxConflicts && !body.allergy_override_reason)
                return respond(422, { message: 'conflito', allergy_conflicts: rxConflicts });
            return respond(201, { id: 'doc', title: 'Receita', pdf_url: '/pdf/doc' });
        }
        if (url === '/qa/companion-certificate') {
            if (companionErrors) return respond(422, { message: 'erro', errors: companionErrors });
            return respond(201, { id: 'doc2', title: 'Atestado de Acompanhante', pdf_url: '/pdf/doc2' });
        }
        if (url === '/kits')
            return respond(200, {
                covenant: { id: 'c1', name: 'UNIMED' },
                kits: [
                    {
                        id: 'k1',
                        name: 'Glaucoma',
                        origin: 'global',
                        items: [{ procedure_id: null, code: '40103137', name: 'Campimetria', in_catalog: false }],
                    },
                ],
            });
        if (url === '/qa/procedure-request') return respond(201, { id: 'doc3', title: 'Pedido', pdf_url: '/pdf/doc3' });
        if (url === '/templates')
            return respond(200, [
                {
                    report_setting_id: 'personal',
                    report_setting_title: 'Meus modelos',
                    personal: true,
                    contents: [
                        {
                            id: 'tpl:t9',
                            template_id: 't9',
                            base_id: 'base-1',
                            label: 'Relatório perícia',
                            personal: true,
                        },
                        // Modelo PESSOAL de RECEITA com item do catálogo —
                        // "Salvar como modelo" a partir daqui tem que levar
                        // esse vínculo adiante (3ª revisão Codex, P1).
                        {
                            id: 'tpl:t10',
                            template_id: 't10',
                            base_id: 'base-1',
                            label: 'Olho seco',
                            personal: true,
                            items: [{ medicine_id: 'm9', name: 'LACRIMA PLUS', active_ingredient: 'DEXTRANA' }],
                        },
                    ],
                },
                {
                    report_setting_id: 'rs1',
                    report_setting_title: 'RELATÓRIO',
                    contents: [{ id: 'base-1', label: 'Declaração' }],
                },
            ]);
        if (url === '/preview') return respond(200, { content: `<p>prévia ${body.document_date ?? 'hoje'}</p>` });
        return respond(200, {});
    });
}

function mountForm(record = {}) {
    return mount(MedicalRecordForm, {
        props: {
            patient: { id: 'p1', full_name: 'Maria' },
            medicalrecord: {
                id: 'r1',
                doctor_id: 'd1',
                is_locked: false,
                diagnosis_cids: [{ code: 'H40.1', description: 'Glaucoma' }],
                documentations: [
                    {
                        id: 'old',
                        type_label: 'Atestado',
                        title: 'Atestado',
                        created_at: '07/10/2026 10:00',
                        document_date: '20/09/2026',
                        pdf_url: '/pdf/old',
                    },
                ],
                ...record,
            },
            isEdit: true,
            isDoctor: true,
            currentDoctorId: 'd1',
            catalogs: {},
            clinicalSafety: null,
            urls: {
                store: '/store',
                update: '/update',
                list: '/list',
                schedules: '/agenda',
                quick_action_template: '/qa/__ACTION__',
                medication_presets: '/presets',
                personal_templates: '/tpl',
                personal_template_template: '/tpl/__ID__',
                exam_kits: '/kits',
                templates: '/templates',
                template_preview: '/preview',
                store_doc: '/docs',
            },
            t: {},
        },
        global: { stubs: STUBS },
    });
}

beforeEach(() => {
    rxConflicts = null;
    companionErrors = null;
    mockFetch();
});

describe('receituário — data alternativa e modelo pessoal', () => {
    it('reenvio com o motivo da alergia mantém a data alternativa e o modelo usado', async () => {
        rxConflicts = [
            { allergy_id: 'a1', substance: 'Moxifloxacino', reaction: 'Urticária', match: 'brand', matched: 'VIGAMOX' },
        ];
        const w = mountForm();
        await w.find('[data-testid="pmr-medication-btn"]').trigger('click');
        await flushPromises();

        await w.find('[data-testid="personal-templates-select"]').setValue('tpl-rx');
        await flushPromises();
        await w.find('[data-testid="document-date-input"]').setValue('2026-10-01');
        await w.find('[data-testid="rx-emit"]').trigger('click');
        await flushPromises();

        await w.find('[data-testid="allergy-override-reason"]').setValue('Teste de provocação negativo');
        await w.find('[data-testid="allergy-override-confirm"]').trigger('click');
        await flushPromises();

        const sent = requests.filter((r) => r.url === '/qa/medication-prescription');
        expect(sent).toHaveLength(2);
        for (const r of sent) {
            expect(r.body).toMatchObject({
                content: 'Colírio de moxifloxacino 4x/dia',
                document_date: '2026-10-01',
                template_id: 'tpl-rx',
                items: [{ medicine_id: 'm1', name: 'VIGAMOX', active_ingredient: 'MOXIFLOXACINO' }],
            });
        }
        expect(sent[1].body.allergy_override_reason).toBe('Teste de provocação negativo');
    });
});

describe('atestados novos na barra do prontuário', () => {
    it('acompanhante: abre o modal, emite e mostra o erro do servidor no campo', async () => {
        companionErrors = { companion_name: ['Informe o nome completo do acompanhante.'] };
        const w = mountForm();
        await w.find('[data-testid="companion-certificate-btn"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-testid="certificate-title"]').text()).toContain('Atestado de acompanhante');
        // Chegada do paciente pré-preenche o "das".
        expect(w.find('[data-testid="cert-time-from"]').element.value).toBe('08:15');

        await w.find('[data-testid="cert-companion-name"]').setValue('Ana Lima');
        await w.find('[data-testid="certificate-emit"]').trigger('click');
        await flushPromises();

        expect(requests.find((r) => r.url === '/qa/companion-certificate').body).toMatchObject({
            companion_name: 'Ana Lima',
        });
        expect(w.text()).toContain('Informe o nome completo do acompanhante.');
    });

    it('aptidão visual também tem botão próprio', async () => {
        const w = mountForm();
        await w.find('[data-testid="visual-fitness-certificate-btn"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-testid="certificate-title"]').text()).toContain('Atestado de aptidão visual');
    });
});

describe('pedido de exames', () => {
    it('kits do convênio do atendimento e CIDs do diagnóstico do prontuário', async () => {
        const w = mountForm();
        const btn = w.findAll('button').find((b) => b.attributes('title') === 'Solicitação de Procedimentos');
        await btn.trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Kits para o convênio UNIMED');
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');
        await w.find('[data-testid="request-emit"]').trigger('click');
        await flushPromises();

        expect(requests.find((r) => r.url === '/qa/procedure-request').body).toEqual({
            items: [{ name: 'Campimetria', code: '40103137', kit_id: 'k1' }],
            kit_ids: ['k1'],
            cids: ['H40.1'],
        });
    });
});

describe('editor de Documentações', () => {
    it('modelo pessoal no mesmo seletor dos modelos da clínica; trocar a data refaz a prévia', async () => {
        const w = mountForm();
        // Lista de documentos: data impressa ao lado da emissão.
        await w.vm.$.setupState.openDocumentationsModal();
        await flushPromises();
        expect(w.find('[data-testid="doc-printed-date"]').text()).toContain('20/09/2026');

        await w.vm.$.setupState.openNewDoc();
        await flushPromises();

        const select = w.find('[data-testid="doc-template-select"]');
        expect(select.findAll('optgroup')[0].attributes('label')).toBe('Meus modelos');
        await select.setValue('tpl:t9');
        await flushPromises();

        const preview = requests.filter((r) => r.url === '/preview');
        expect(preview.at(-1).body).toEqual({ report_setting_content_id: 'base-1', template_id: 't9' });

        await w.findAll('[data-testid="document-date-input"]').at(-1).setValue('2026-09-30');
        await flushPromises();
        expect(requests.filter((r) => r.url === '/preview').at(-1).body).toMatchObject({ document_date: '2026-09-30' });
    });

    it('"Salvar como modelo" no editor leva o prontuário e a data (o servidor tira os dados do paciente)', async () => {
        const w = mountForm();
        await w.vm.$.setupState.openNewDoc();
        await flushPromises();
        await w.find('[data-testid="doc-template-select"]').setValue('base-1');
        await w.findAll('[data-testid="document-date-input"]').at(-1).setValue('2026-09-30');
        await flushPromises();

        const editorBar = w.findAllComponents(PersonalTemplatesBar).find((b) => !b.props('kind'));
        expect(editorBar.props('format')).toBeNull();
        expect(editorBar.props('context')).toEqual({ medical_record_id: 'r1', document_date: '2026-09-30' });

        await editorBar.find('[data-testid="personal-templates-save-as"]').trigger('click');
        await editorBar.find('[data-testid="personal-templates-name"]').setValue('Relatório padrão');
        await editorBar.find('[data-testid="personal-templates-confirm"]').trigger('click');
        await flushPromises();

        expect(requests.find((r) => r.url === '/tpl' && r.method === 'POST').body).toMatchObject({
            kind: 'report',
            name: 'Relatório padrão',
            report_setting_content_id: 'base-1',
            medical_record_id: 'r1',
            document_date: '2026-09-30',
        });
    });

    it('renomear modelo pessoal no editor também leva o prontuário — o servidor confere o nome contra dado do paciente (9ª revisão Codex, P2)', async () => {
        const w = mountForm();
        await w.vm.$.setupState.openNewDoc();
        await flushPromises();
        await w.findAll('[data-testid="document-date-input"]').at(-1).setValue('2026-09-30');
        // Modelo pessoal já selecionado (o fixture de GET /tpl devolve "tpl-rx").
        w.vm.$.setupState.docForm.template_id = 'tpl-rx';
        await flushPromises();

        const editorBar = w.findAllComponents(PersonalTemplatesBar).find((b) => !b.props('kind'));
        await editorBar.find('[data-testid="personal-templates-rename"]').trigger('click');
        await editorBar.find('[data-testid="personal-templates-name"]').setValue('Novo nome');
        await editorBar.find('[data-testid="personal-templates-confirm"]').trigger('click');
        await flushPromises();

        expect(requests.find((r) => r.url === '/tpl/tpl-rx' && r.method === 'PUT').body).toMatchObject({
            name: 'Novo nome',
            medical_record_id: 'r1',
            document_date: '2026-09-30',
        });
    });

    it('"Salvar como modelo" a partir de um modelo de RECEITA existente leva os itens do catálogo (3ª revisão Codex, P1)', async () => {
        const w = mountForm();
        await w.vm.$.setupState.openNewDoc();
        await flushPromises();

        await w.find('[data-testid="doc-template-select"]').setValue('tpl:t10');
        await flushPromises();

        const editorBar = w.findAllComponents(PersonalTemplatesBar).find((b) => !b.props('kind'));
        await editorBar.find('[data-testid="personal-templates-save-as"]').trigger('click');
        await editorBar.find('[data-testid="personal-templates-name"]').setValue('Olho seco (cópia)');
        await editorBar.find('[data-testid="personal-templates-confirm"]').trigger('click');
        await flushPromises();

        expect(requests.find((r) => r.url === '/tpl' && r.method === 'POST').body).toMatchObject({
            payload: { items: [{ medicine_id: 'm9', name: 'LACRIMA PLUS', active_ingredient: 'DEXTRANA' }] },
        });
    });

    it('texto editado depois da prévia não leva os itens — vínculo errado seria pior que nenhum (3ª revisão Codex, P1)', async () => {
        const w = mountForm();
        await w.vm.$.setupState.openNewDoc();
        await flushPromises();

        await w.find('[data-testid="doc-template-select"]').setValue('tpl:t10');
        await flushPromises();
        // Médico reescreve o texto depois da prévia — o vínculo com
        // LACRIMA PLUS não é mais garantido.
        w.vm.$.setupState.docForm.content = '<p>texto totalmente reescrito pelo médico</p>';
        await flushPromises();

        const editorBar = w.findAllComponents(PersonalTemplatesBar).find((b) => !b.props('kind'));
        await editorBar.find('[data-testid="personal-templates-save-as"]').trigger('click');
        await editorBar.find('[data-testid="personal-templates-name"]').setValue('Outra coisa');
        await editorBar.find('[data-testid="personal-templates-confirm"]').trigger('click');
        await flushPromises();

        expect(requests.find((r) => r.url === '/tpl' && r.method === 'POST').body.payload).toBeUndefined();
    });

    it('modais rápidos só listam modelos em texto + itens e levam o prontuário', async () => {
        const w = mountForm();
        await w.find('[data-testid="pmr-medication-btn"]').trigger('click');
        await flushPromises();
        expect(requests.map((r) => r.url)).toContain('/tpl?kind=prescription&format=text');

        const rxBar = w.findAllComponents(PersonalTemplatesBar).find((b) => b.props('kind') === 'prescription');
        expect(rxBar.props('format')).toBe('text');
        expect(rxBar.props('context')).toEqual({ medical_record_id: 'r1' });
        expect(w.findComponent(ProcedureRequestModal).props('templateContext')).toEqual({ medical_record_id: 'r1' });
    });

    it('prévia atrasada de outro modelo/data não sobrescreve a atual (2ª revisão, P1-B)', async () => {
        const w = mountForm();
        await w.vm.$.setupState.openNewDoc();
        await flushPromises();

        const base = globalThis.fetch;
        const previews = [];
        globalThis.fetch = vi.fn((url, init = {}) => {
            if (url !== '/preview') return base(url, init);
            const body = JSON.parse(init.body);
            return new Promise((resolve) =>
                previews.push(() =>
                    resolve({
                        ok: true,
                        status: 200,
                        json: () => Promise.resolve({ content: `<p>prévia ${body.document_date ?? 'hoje'}</p>` }),
                    }),
                ),
            );
        });

        await w.find('[data-testid="doc-template-select"]').setValue('base-1');
        await w.findAll('[data-testid="document-date-input"]').at(-1).setValue('2026-09-30');
        expect(previews).toHaveLength(2);

        previews[1]();
        await flushPromises();
        previews[0]();
        await flushPromises();

        expect(w.vm.$.setupState.docForm.content).toBe('<p>prévia 2026-09-30</p>');
    });
});
