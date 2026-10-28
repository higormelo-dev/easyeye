import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import MedicalRecordForm from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue';
import ClinicalSafetyModal from '@/Components/Panel/ClinicalSafety/ClinicalSafetyModal.vue';
import { summary } from '../ClinicalSafety/fixtures.js';

/**
 * Prontuário × segurança clínica (Fase 2):
 *  - alerta fixo no topo (alergias + risco das medicações) com "Revisar";
 *  - anamnese: lista estruturada de medicações + texto livre como observação;
 *  - uso de LC por consulta, pré-preenchido da última;
 *  - receituário: item que bate com alergia só entra com motivo; a emissão
 *    leva os itens estruturados e o motivo; 422 do servidor pede o motivo e
 *    reenvia.
 */
const inertia = vi.hoisted(() => ({ submits: [] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        usePage: () => ({ props: { auth: { user: { preferences: {} } }, flash: {}, errors: {}, locale: 'pt_BR' } }),
        router: { on: vi.fn(() => () => {}), visit: vi.fn(), reload: vi.fn() },
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        useForm: (data) => {
            const form = actual.useForm(data);
            for (const method of ['put', 'post']) {
                form[method] = vi.fn((url) => inertia.submits.push({ method, url, data: form.data() }));
            }
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
    MedicalRecordFileUploadModal: true,
    MedicalRecordImagingModal: true,
    MedicalRecordProceduresModal: true,
    ContactLensCalculatorModal: true,
    PdfPreviewModal: true,
};

const AMOXIL = { id: 'amx', name: 'AMOXIL', concentration: '500 MG', active_ingredient: 'AMOXICILINA TRI-HIDRATADA' };
const TIMOPTOL = { id: 'tim', name: 'TIMOPTOL', active_ingredient: 'MALEATO DE TIMOLOL' };

let issued;
let serverConflicts;

function mockFetch() {
    issued = [];
    globalThis.fetch = vi.fn((url, init = {}) => {
        const json = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
        if (url.startsWith('/medicines/search')) return json(200, url.includes('timop') ? [TIMOPTOL] : [AMOXIL]);
        if (url === '/format-line') {
            // Linha real do backend leva o NOME do remédio (MedicationPrescriptionService::formatLine) —
            // só o id aqui não dava pra reconciliar texto × item (14ª revisão Codex, P2).
            const { medicine_id } = JSON.parse(init.body);
            const name = [AMOXIL, TIMOPTOL].find((m) => m.id === medicine_id)?.name ?? medicine_id;

            return json(200, { line: `- ${name}\n` });
        }
        if (url.startsWith('/presets')) return json(200, { recents: [], favorites: [] });
        if (url === '/qa/medication-prescription') {
            const body = JSON.parse(init.body);
            issued.push(body);
            if (serverConflicts && !body.allergy_override_reason)
                return json(422, { message: 'conflito', allergy_conflicts: serverConflicts });
            return json(201, { id: 'doc', title: 'Receita', pdf_url: '/pdf/doc' });
        }
        return json(200, {});
    });
}

function mountForm({ edit = true, safety = summary(), previous = null, record = {} } = {}) {
    return mount(MedicalRecordForm, {
        props: {
            patient: { id: 'p1', full_name: 'Maria' },
            medicalrecord: edit ? { id: 'r1', doctor_id: 'd1', is_locked: false, ...record } : null,
            isEdit: edit,
            isDoctor: true,
            currentDoctorId: 'd1',
            catalogs: {},
            clinicalSafety: safety,
            contactLensUsePrevious: previous,
            urls: {
                store: '/store',
                update: '/update',
                list: '/list',
                schedules: '/agenda',
                quick_action_template: '/qa/__ACTION__',
                medicine_search: '/medicines/search',
                medication_format: '/format-line',
                medication_presets: '/presets',
                medication_presets_use: '/presets/use',
            },
            t: {},
        },
        global: { stubs: STUBS },
    });
}

async function pick(w, query) {
    const input = w.find('input[placeholder*="Nome comercial"]');
    await input.setValue(query);
    await new Promise((r) => setTimeout(r, 300));
    await flushPromises();
    const item = w
        .findAll('li.list-group-item-action')
        .find((li) => li.text().includes(query.toUpperCase().slice(0, 4)));
    await item.trigger('mousedown');
}

describe('prontuário — alerta e anamnese', () => {
    beforeEach(() => {
        inertia.submits.length = 0;
        serverConflicts = null;
        mockFetch();
    });

    it('alerta fixo no topo com "Revisar" (abre o bloco de revisão)', async () => {
        const w = mountForm();
        const header = w.find('[data-testid="pmr-safety-header"]');

        expect(header.text()).toContain('ALERGIA:');
        expect(header.text()).toContain('Dipirona');
        expect(header.text()).toContain('Alfa-1 bloqueadores');

        await header.find('[data-testid="cs-review"]').trigger('click');
        expect(w.findComponent(ClinicalSafetyModal).props('open')).toBe(true);
    });

    it('perfil sem acesso a Pacientes (clinicalSafety null): sem alerta nem bloco', () => {
        const w = mountForm({ safety: null });

        expect(w.find('[data-testid="pmr-safety-header"]').exists()).toBe(false);
        expect(w.findComponent(ClinicalSafetyModal).exists()).toBe(false);
    });

    it('anamnese: medicações ATIVAS da lista do paciente + texto livre como observação', () => {
        const meds = mountForm().find('[data-testid="pmr-medications"]');

        expect(meds.text()).toContain('SECOTEX 0,4 MG');
        expect(meds.text()).toContain('XARELTO 20 MG');
        expect(meds.text()).not.toContain('Prednisona');
        expect(meds.find('textarea[name="medications_in_use"]').exists()).toBe(true);
        expect(meds.text()).toContain('texto livre');
    });

    it('uso de LC: novo atendimento pré-preenchido com o da última consulta (e vai no salvar)', async () => {
        const w = mountForm({
            edit: false,
            previous: {
                date: '01/09/2026',
                value: {
                    uses: true,
                    type: 'soft',
                    wear: 'daily_wear',
                    hours_per_day: 10,
                    replacement: 'monthly',
                    sleeps_with: false,
                    complaints: 'Ardor',
                },
            },
        });
        const block = w.find('[data-testid="pmr-contact-lens-use"]');

        expect(block.text()).toContain('01/09/2026');
        expect(block.find('[data-testid="cl-use-yes"]').element.checked).toBe(true);
        expect(block.find('#cl-use-type').element.value).toBe('soft');

        await w.find('textarea[name="main_complaint"]').setValue('Ardor com a lente');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(inertia.submits.at(-1).data.contact_lens_use).toMatchObject({
            uses: true,
            type: 'soft',
            replacement: 'monthly',
            sleeps_with: false,
        });
    });

    it('uso de LC: trocar pra "não informado" depois de pré-preenchido limpa os detalhes (3ª revisão Codex, P2)', async () => {
        const w = mountForm({
            edit: false,
            previous: {
                date: '01/09/2026',
                value: {
                    uses: true,
                    type: 'soft',
                    wear: 'daily_wear',
                    hours_per_day: 10,
                    replacement: 'monthly',
                    sleeps_with: false,
                    complaints: 'Ardor',
                },
            },
        });
        const block = w.find('[data-testid="pmr-contact-lens-use"]');
        expect(block.find('#cl-use-type').element.value).toBe('soft');

        // Médico não confirma "Sim" com o paciente — volta pra "Não informado".
        // Os campos escondidos (type/wear/hours_per_day…) não podiam continuar
        // no form só porque vieram pré-preenchidos: o servidor via detalhe
        // preenchido e normalizava uses de volta pra true.
        await block.find('[data-testid="cl-use-unknown"]').setValue(true);
        expect(w.find('#cl-use-type').exists()).toBe(false);

        await w.find('textarea[name="main_complaint"]').setValue('Sem queixa com LC');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(inertia.submits.at(-1).data.contact_lens_use).toEqual({
            uses: null,
            type: '',
            wear: '',
            hours_per_day: '',
            replacement: '',
            sleeps_with: null,
            complaints: '',
        });
    });

    it('uso de LC: "não usa" esconde os detalhes; edição sem registro não pré-preenche', async () => {
        const w = mountForm({ record: { contact_lens_use: null } });
        const block = w.find('[data-testid="pmr-contact-lens-use"]');

        expect(block.text()).not.toContain('Pré-preenchido');
        expect(block.find('#cl-use-type').exists()).toBe(false);
        await block.find('[data-testid="cl-use-yes"]').setValue(true);
        expect(w.find('#cl-use-type').exists()).toBe(true);
        await w.find('[data-testid="cl-use-no"]').setValue(true);
        expect(w.find('#cl-use-type').exists()).toBe(false);
    });
});

describe('receituário — checagem de alergia', () => {
    beforeEach(() => {
        inertia.submits.length = 0;
        serverConflicts = null;
        mockFetch();
    });

    it('alergias à vista no receituário', async () => {
        const w = mountForm();
        await w.find('[data-testid="pmr-medication-btn"]').trigger('click');
        await flushPromises();

        const modalBanner = w.findAll('[data-testid="clinical-alert-banner"]').at(-1);
        expect(modalBanner.text()).toContain('Dipirona');
        expect(modalBanner.find('[data-testid="cs-risk-alerts"]').exists()).toBe(false);
    });

    it('item da mesma classe da alergia: alerta com alergia e reação; só entra com motivo; emissão leva itens e motivo', async () => {
        const w = mountForm();
        await w.find('[data-testid="pmr-medication-btn"]').trigger('click');
        await flushPromises();
        await pick(w, 'amoxil');

        const warning = w.find('[data-testid="rx-item-allergy"]');
        expect(warning.attributes('role')).toBe('alert');
        expect(warning.text()).toContain('Penicilina');
        expect(warning.text()).toContain('Urticária');
        expect(warning.text()).toContain('mesma classe');
        expect(w.find('[data-testid="rx-add"]').attributes('disabled')).toBeDefined();

        await w.find('[data-testid="rx-item-reason"]').setValue('Teste cutâneo negativo em 2025');
        expect(w.find('[data-testid="rx-add"]').attributes('disabled')).toBeUndefined();
        await w.find('[data-testid="rx-add"]').trigger('click');
        await flushPromises();

        await w.find('[data-testid="rx-emit"]').trigger('click');
        await flushPromises();

        expect(issued).toHaveLength(1);
        expect(issued[0].items).toEqual([
            { medicine_id: 'amx', name: 'AMOXIL', active_ingredient: 'AMOXICILINA TRI-HIDRATADA' },
        ]);
        expect(issued[0].allergy_override_reason).toBe('Teste cutâneo negativo em 2025');
    });

    it('removeu o remédio do texto DEPOIS de adicionar ao receituário: item reconciliado, não envia mais ele (14ª revisão Codex, P2)', async () => {
        const w = mountForm();
        await w.find('[data-testid="pmr-medication-btn"]').trigger('click');
        await flushPromises();
        await pick(w, 'timoptol');
        await w.find('[data-testid="rx-add"]').trigger('click');
        await flushPromises();

        const content = w
            .findAll('textarea')
            .find((t) => t.attributes('placeholder') === 'Linhas formatadas aparecem aqui.');
        expect(content.element.value).toContain('TIMOPTOL');

        // Trocou o Timoptol por outra coisa no texto, sem tirar o item adicionado.
        await content.setValue('Vitamina C 500mg se necessário');

        await w.find('[data-testid="rx-emit"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-testid="allergy-override-modal"]').exists()).toBe(false);
        expect(issued).toHaveLength(1);
        expect(issued[0].items).toEqual([]);
        expect(issued[0]).not.toHaveProperty('allergy_override_reason');
    });

    it('alergia só no TEXTO livre: pede o motivo antes de emitir; voltar não emite', async () => {
        const w = mountForm();
        await w.find('[data-testid="pmr-medication-btn"]').trigger('click');
        await flushPromises();
        const content = w
            .findAll('textarea')
            .find((t) => t.attributes('placeholder') === 'Linhas formatadas aparecem aqui.');
        await content.setValue('Dipirona 500 mg se dor');

        await w.find('[data-testid="rx-emit"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-testid="allergy-override-modal"]').text()).toContain('Dipirona');
        expect(issued).toHaveLength(0);

        await w.find('[data-testid="allergy-override-cancel"]').trigger('click');
        await flushPromises();
        expect(issued).toHaveLength(0);

        await w.find('[data-testid="rx-emit"]').trigger('click');
        await flushPromises();
        await w.find('[data-testid="allergy-override-reason"]').setValue('Dose única supervisionada');
        await w.find('[data-testid="allergy-override-confirm"]').trigger('click');
        await flushPromises();

        expect(issued).toHaveLength(1);
        expect(issued[0].allergy_override_reason).toBe('Dose única supervisionada');
    });

    it('sem conflito: emite direto, sem motivo', async () => {
        const w = mountForm();
        await w.find('[data-testid="pmr-medication-btn"]').trigger('click');
        await flushPromises();
        await pick(w, 'timoptol');
        expect(w.find('[data-testid="rx-item-allergy"]').exists()).toBe(false);
        await w.find('[data-testid="rx-add"]').trigger('click');
        await flushPromises();
        await w.find('[data-testid="rx-emit"]').trigger('click');
        await flushPromises();

        expect(issued).toHaveLength(1);
        expect(issued[0]).not.toHaveProperty('allergy_override_reason');
        expect(w.find('[data-testid="allergy-override-modal"]').exists()).toBe(false);
    });

    it('conflito que só o servidor acha (nome comercial): 422 → motivo → reenvia', async () => {
        serverConflicts = [
            {
                allergy_id: 'al-x',
                substance: 'Diclofenaco',
                reaction: 'Urticária',
                match: 'brand',
                matched: 'VOLTAREN',
            },
        ];
        const w = mountForm({ safety: summary({ check: { stopwords: [], allergies: [] } }) });
        await w.find('[data-testid="pmr-medication-btn"]').trigger('click');
        await flushPromises();
        const content = w
            .findAll('textarea')
            .find((t) => t.attributes('placeholder') === 'Linhas formatadas aparecem aqui.');
        await content.setValue('Voltaren 50 mg 12/12h');
        await w.find('[data-testid="rx-emit"]').trigger('click');
        await flushPromises();

        expect(issued).toHaveLength(1);
        expect(w.find('[data-testid="allergy-override-modal"]').text()).toContain('VOLTAREN');
        await w.find('[data-testid="allergy-override-reason"]').setValue('Sem alternativa — paciente orientada');
        await w.find('[data-testid="allergy-override-confirm"]').trigger('click');
        await flushPromises();

        expect(issued).toHaveLength(2);
        expect(issued[1].allergy_override_reason).toBe('Sem alternativa — paciente orientada');
    });
});

describe('página do prontuário — alerta no celular', () => {
    it('acima das colunas (só em telas pequenas), mesmo estado do formulário; "Revisar" abre o bloco do formulário', async () => {
        const Create = (await import('@/Pages/Panel/MedicalRecords/Create.vue')).default;
        const w = mount(Create, {
            props: {
                patient: { id: 'p1', full_name: 'Maria' },
                catalogs: {},
                urls: { store: '/store', list: '/list', create: '/create', schedules: '/agenda' },
                clinicalSafety: summary(),
                isDoctor: true,
                t: {},
            },
            global: {
                stubs: {
                    ...STUBS,
                    AppLayout: { template: '<div><slot /></div>' },
                    PatientInfoSidebar: true,
                    PreviousRecordsCard: true,
                    MedicalRecordViewModal: true,
                    ScheduleFlowGuard: true,
                    AttendanceTimer: true,
                },
            },
        });

        const mobile = w.find('[data-testid="pmr-safety-mobile"]');
        expect(mobile.classes()).toContain('d-lg-none');
        expect(mobile.text()).toContain('Dipirona');
        expect(w.find('[data-testid="pmr-safety-header"]').classes()).toContain('d-none');

        const form = w.findComponent(MedicalRecordForm);
        expect(form.props('safetyStore').summary.allergy_state).toBe('has');

        await mobile.find('[data-testid="cs-review"]').trigger('click');
        expect(w.findComponent(ClinicalSafetyModal).props('open')).toBe(true);
    });
});
