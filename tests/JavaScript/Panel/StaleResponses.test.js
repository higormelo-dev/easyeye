import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick, ref } from 'vue';

/**
 * 2ª revisão Codex, P1-B — resposta ATRASADA de outro paciente.
 *
 * Ordem reproduzida em todos: carga do paciente A pendente → abre o B (que
 * carrega) → a resposta do A chega por último. Antes, a do A sobrescrevia a
 * tela do B (alergias/medicações/alertas do A com a identidade do B; no
 * cadastro, os dados do A no formulário do B). O mock de fetch ignora o
 * AbortController de propósito: simula o pior caso (a resposta chega mesmo).
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        usePage: () => ({ props: { t_ui: {}, auth: { user: { preferences: {} } } } }),
        router: { post: vi.fn(), get: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => {}) },
    };
});

const { default: PatientDetailDrawer } = await import('@/Pages/Panel/Patients/PatientDetailDrawer.vue');
const { default: ScheduleDetailDrawer } = await import('@/Pages/Panel/Schedules/ScheduleDetailDrawer.vue');
const { default: MedicalRecordDetailDrawer } =
    await import('@/Pages/Panel/MedicalRecords/MedicalRecordDetailDrawer.vue');
const { default: MedicalRecordViewModal } =
    await import('@/Pages/Panel/MedicalRecords/Components/MedicalRecordViewModal.vue');
const { useClinicalSafety } = await import('@/composables/useClinicalSafety.js');
const { usePatientForm } = await import('@/Support/usePatientForm.js');
const { latestRequest } = await import('@/Support/latestRequest.js');

/** fetch que só responde quando o teste manda (por URL). */
let pending;
function deferredFetch() {
    pending = [];
    globalThis.fetch = vi.fn(
        (url) =>
            new Promise((resolve) => {
                pending.push({
                    url: String(url),
                    respond: (body, ok = true) =>
                        resolve({ ok, status: ok ? 200 : 500, json: () => Promise.resolve(body) }),
                });
            }),
    );
}
function respond(urlPart, body, nth = 0) {
    const call = pending.filter((p) => p.url.includes(urlPart))[nth];
    if (!call) throw new Error(`sem requisição pendente para ${urlPart}`);
    call.respond(body);
}

const safetyOf = (name) => ({
    allergy_state: 'unknown',
    denied: null,
    allergies: [],
    medications: [{ id: `m-${name}`, name: `REMEDIO DE ${name}`, status: 'active', confirmed: true }],
    risk_alerts: [],
    can_review: false,
});

beforeEach(() => deferredFetch());

describe('latestRequest', () => {
    it('só a última requisição é "atual"; cancel invalida tudo', () => {
        const r = latestRequest();
        const a = r.begin();
        const b = r.begin();
        expect(a.current()).toBe(false);
        expect(b.current()).toBe(true);
        expect(a.signal.aborted).toBe(true);
        r.cancel();
        expect(b.current()).toBe(false);
    });
});

describe('Gaveta do paciente (Pacientes)', () => {
    it('A pendente → fecha → abre B → A chega: a gaveta continua só com o B', async () => {
        const w = mount(PatientDetailDrawer, {
            props: { open: false, patientId: 'A' },
            global: { stubs: { teleport: true } },
        });
        await w.setProps({ open: true });
        // Detalhe do A chega; a segurança clínica do A fica pendente.
        respond('panel.patients.show/A', { data: { id: 'A', full_name: 'PACIENTE A', code: 'PAC-A', active: true } });
        await flushPromises();

        await w.setProps({ open: false });
        await w.setProps({ patientId: 'B', open: true });
        respond('panel.patients.show/B', { data: { id: 'B', full_name: 'PACIENTE B', code: 'PAC-B', active: true } });
        respond('clinical-safety.show/B', { data: safetyOf('B') });
        await flushPromises();

        // A chega por último.
        respond('clinical-safety.show/A', { data: safetyOf('A') });
        await flushPromises();

        expect(w.text()).toContain('PACIENTE B');
        expect(w.text()).toContain('REMEDIO DE B');
        expect(w.text()).not.toContain('REMEDIO DE A');
    });

    it('detalhe do A atrasado também não troca a identidade do B', async () => {
        const w = mount(PatientDetailDrawer, {
            props: { open: false, patientId: 'A' },
            global: { stubs: { teleport: true } },
        });
        await w.setProps({ open: true });
        await w.setProps({ open: false });
        await w.setProps({ patientId: 'B', open: true });
        respond('panel.patients.show/B', { data: { id: 'B', full_name: 'PACIENTE B', code: 'PAC-B', active: true } });
        respond('clinical-safety.show/B', { data: safetyOf('B') });
        await flushPromises();
        respond('panel.patients.show/A', { data: { id: 'A', full_name: 'PACIENTE A', code: 'PAC-A', active: true } });
        respond('clinical-safety.show/A', { data: safetyOf('A') });
        await flushPromises();

        expect(w.text()).toContain('PACIENTE B');
        expect(w.text()).not.toContain('PACIENTE A');
    });
});

describe('Segurança clínica (aba Clínico do cadastro — mesmo bloco para A e depois B)', () => {
    it('leitura: a resposta do A não entra no B', async () => {
        const id = ref('A');
        const store = useClinicalSafety(id);
        const loadA = store.load();
        id.value = 'B';
        const loadB = store.load();
        respond('clinical-safety.show/B', { data: safetyOf('B') });
        await loadB;
        respond('clinical-safety.show/A', { data: safetyOf('A') });
        await loadA;

        expect(store.summary.medications[0].name).toBe('REMEDIO DE B');
        expect(store.loading).toBe(false);
    });

    it('ação do A concluída depois de trocar para o B não mistura a lista', async () => {
        const id = ref('A');
        const store = useClinicalSafety(id, safetyOf('A'));
        const action = store.addMedication({ name: 'X' });
        id.value = 'B';
        store.summary = safetyOf('B');
        respond('medications.store', { data: safetyOf('A') });
        await action;

        expect(store.summary.medications[0].name).toBe('REMEDIO DE B');
    });
});

describe('Cadastro do paciente (Pacientes e Agenda): dados de edição', () => {
    it('editData do A atrasado não preenche o formulário do B', async () => {
        const pf = usePatientForm({ genders: [], maritalStatuses: [], statesOfBrazil: [] });
        const loadA = pf.loadEditData('A');
        pf.resetForm();
        const loadB = pf.loadEditData('B');
        respond('editData/B', { data: { name: 'PACIENTE B', email: 'b@x.test' } });
        await loadB;
        respond('editData/A', { data: { name: 'PACIENTE A', email: 'a@x.test' } });
        await loadA;

        expect(pf.form.name).toBe('PACIENTE B');
        expect(pf.form.email).toBe('b@x.test');
    });
});

describe('Gaveta do agendamento', () => {
    it('agendamento do A atrasado não aparece na gaveta do B', async () => {
        const w = mount(ScheduleDetailDrawer, {
            props: { open: false, scheduleId: 'sA', t: {} },
            global: { stubs: { teleport: true } },
        });
        await w.setProps({ open: true });
        await w.setProps({ open: false });
        await w.setProps({ scheduleId: 'sB', open: true });
        const base = { resources: [], situation_logs: [], situation_label: 'Confirmado' };
        respond('schedules.show/sB', { data: { ...base, id: 'sB', patient_name: 'PACIENTE B', code: 'SDL-B' } });
        await flushPromises();
        respond('schedules.show/sA', { data: { ...base, id: 'sA', patient_name: 'PACIENTE A', code: 'SDL-A' } });
        await flushPromises();

        expect(w.text()).toContain('PACIENTE B');
        expect(w.text()).not.toContain('PACIENTE A');
    });
});

describe('Prontuário: gaveta e visualização', () => {
    const recordJson = (name) => ({
        id: name,
        code: `PR-${name}`,
        patient_name: name,
        main_complaint: `QUEIXA DE ${name}`,
    });

    it('gaveta: prontuário do A atrasado não substitui o do B', async () => {
        const w = mount(MedicalRecordDetailDrawer, {
            props: { open: false, record: { show_url: '/show/A' }, patient: { id: 'p' }, t: {} },
            global: { stubs: { teleport: true, ShareWithPatientToggle: true, PdfPreviewModal: true } },
        });
        await w.setProps({ open: true });
        await w.setProps({ open: false });
        await w.setProps({ record: { show_url: '/show/B' }, open: true });
        respond('/show/B', recordJson('B'));
        await flushPromises();
        respond('/show/A', recordJson('A'));
        await flushPromises();

        expect(w.text()).toContain('QUEIXA DE B');
        expect(w.text()).not.toContain('QUEIXA DE A');
    });

    it('modal de visualização: trocar de prontuário aberto descarta o anterior', async () => {
        const w = mount(MedicalRecordViewModal, {
            props: { open: true, record: { show_url: '/show/A', code: 'PR-A' }, t: {} },
            global: { stubs: { teleport: true } },
        });
        await nextTick();
        await w.setProps({ record: { show_url: '/show/B', code: 'PR-B' } });
        respond('/show/B', recordJson('B'));
        await flushPromises();
        respond('/show/A', recordJson('A'));
        await flushPromises();

        expect(w.text()).toContain('QUEIXA DE B');
        expect(w.text()).not.toContain('QUEIXA DE A');
    });
});
