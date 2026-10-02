import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import MedicalRecordForm from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue';
import ScheduleFlowGuard from '@/Pages/Panel/MedicalRecords/Components/ScheduleFlowGuard.vue';

/**
 * Sair do prontuário com alterações não salvas (inclusive o cálculo de lentes
 * de contato, que vive no form) pede confirmação — links/menu do Inertia,
 * Voltar do navegador, recarregar/fechar a aba. Salvar, Finalizar/Dilatar/
 * Exame e o reload após aprovar laudo de IA são da própria tela e não
 * perguntam; "Continuar atendimento" salva o digitado antes de sair.
 *
 * useForm é o real (isDirty de verdade); put/post e o router são simulados e
 * disparam o `before` de forma síncrona, como o Inertia. O payload registrado
 * passa pelo transform do form (o que iria ao servidor). Concluir um envio
 * segue a ordem do Inertia: props novos (preserveState) → onSuccess → onFinish.
 */
const inertia = vi.hoisted(() => ({ handlers: {}, submits: [], visits: [], reloads: [] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    const fireBefore = (visit) => inertia.handlers.before?.({ detail: { visit } });

    return {
        ...actual,
        usePage: () => ({ props: { auth: { user: { preferences: {} } }, flash: {}, errors: {}, locale: 'pt_BR' } }),
        router: {
            on: vi.fn((type, callback) => {
                inertia.handlers[type] = callback;
                return () => {
                    if (inertia.handlers[type] === callback) delete inertia.handlers[type];
                };
            }),
            visit: vi.fn((url) => inertia.visits.push({ url, before: fireBefore({ method: 'get' }) })),
            reload: vi.fn((options) =>
                inertia.reloads.push({ before: fireBefore({ method: 'get', only: options?.only ?? [] }) }),
            ),
        },
        useForm: (data) => {
            const form = actual.useForm(data);
            let transform = (value) => value;
            const setTransform = form.transform.bind(form);
            form.transform = (callback) => {
                transform = callback;
                return setTransform(callback);
            };
            for (const method of ['put', 'post']) {
                form[method] = vi.fn((url, options) =>
                    inertia.submits.push({
                        method,
                        url,
                        options,
                        data: form.data(),
                        payload: transform(form.data()),
                        before: fireBefore({ method }),
                    }),
                );
            }
            return form;
        },
    };
});

enableAutoUnmount(afterEach);

const T = {
    unsaved_leave_confirm: 'Há alterações não salvas. Sair?',
    drafts_leave_confirm: 'Há texto não registrado. Sair mesmo assim?',
};

const calc = { vertex_distance_mm: 12, vertex_od: -6, vertex_od_result: -5.6 };

const fireBefore = (visit = { method: 'get' }) => inertia.handlers.before?.({ detail: { visit } });

const STUBS = {
    teleport: true,
    AiAssistantPanel: { emits: ['approved', 'inserted', 'close'], template: '<div class="ai-panel-stub" />' },
    TinyMceEditor: true,
    SearchSelect: true,
    AcuitySelect: true,
    MedicalRecordFileUploadModal: true,
    MedicalRecordImagingModal: true,
    MedicalRecordProceduresModal: true,
    ContactLensCalculatorModal: true,
    PdfPreviewModal: true,
};

const record = (over = {}) => ({ id: 'r1', main_complaint: 'Baixa visual', is_locked: false, ...over });

function mountForm({ edit = false, medicalrecord, ...over } = {}) {
    return mount(MedicalRecordForm, {
        props: {
            patient: { id: 'p1', full_name: 'Paciente Teste' },
            medicalrecord: medicalrecord ?? (edit ? record() : null),
            isEdit: edit,
            isDoctor: true,
            currentDoctorId: 'd1',
            catalogs: {},
            urls: {
                store: '/store',
                update: '/update',
                list: '/list',
                schedules: '/agenda',
                evolutions_store: '/evo',
                lens_format: '/lens',
                quick_action_template: '/qa/__ACTION__',
            },
            t: T,
            ...over,
        },
        global: { stubs: STUBS },
    });
}

async function typeComplaint(wrapper, text = 'Dor ocular') {
    await wrapper.find('[name="main_complaint"]').setValue(text);
    await flushPromises();
}

async function typeEvolutionDraft(wrapper) {
    await wrapper.find('[title="Evolução"]').trigger('click');
    await wrapper.find('textarea[placeholder*="evolução"]').setValue('Melhora da hiperemia');
    await flushPromises();
}

// Resposta do servidor: props novos (Edit não remonta) → onSuccess → onFinish.
async function completeSubmit(wrapper, newRecord = null) {
    if (newRecord) await wrapper.setProps({ medicalrecord: newRecord });
    const { options } = inertia.submits.at(-1);
    options.onSuccess();
    options.onFinish();
    await flushPromises();
}

const lensModal = (wrapper) => wrapper.findComponent({ name: 'ContactLensCalculatorModal' });

beforeEach(() => {
    inertia.handlers = {};
    inertia.submits = [];
    inertia.visits = [];
    inertia.reloads = [];
    window.confirm = vi.fn(() => false);
    window.history.replaceState(null, '', '/panel/patients/p1/medicalrecords/create');
    globalThis.fetch = vi.fn(async (url) => ({
        ok: true,
        json: async () => (url === '/lens' ? { value: '95º' } : { id: 'doc1', pdf_url: null }),
    }));
});

describe('Prontuário — sair com alterações não salvas', () => {
    it('abrir sem mexer não pergunta, nem vindo da Agenda (?schedule_id=)', async () => {
        window.history.replaceState(null, '', '/panel/patients/p1/medicalrecords/create?schedule_id=s1');
        mountForm();
        await flushPromises();

        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);

        expect(fireBefore()).toBeUndefined();
        expect(unload.defaultPrevented).toBe(false);
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('editou e tenta sair: pergunta; "Cancelar" mantém a tela', async () => {
        const wrapper = mountForm();
        await flushPromises();
        await typeComplaint(wrapper);

        expect(fireBefore()).toBe(false);
        expect(window.confirm).toHaveBeenCalledWith(T.unsaved_leave_confirm);

        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(true);
    });

    it('Salvar é da própria tela: não pergunta e leva o schedule_id da Agenda', async () => {
        window.history.replaceState(null, '', '/panel/patients/p1/medicalrecords/create?schedule_id=s1');
        const wrapper = mountForm();
        await flushPromises();
        await typeComplaint(wrapper);

        await wrapper.find('form').trigger('submit');

        expect(inertia.submits).toHaveLength(1);
        expect(inertia.submits[0].before).toBeUndefined();
        expect(inertia.submits[0].data.schedule_id).toBe('s1');
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('salvo: sair não pergunta mais; o que foi digitado durante o envio continua pendente', async () => {
        const wrapper = mountForm({ edit: true });
        await flushPromises();
        await typeComplaint(wrapper, 'Dor ocular');
        await wrapper.find('form').trigger('submit');

        await typeComplaint(wrapper, 'Dor ocular e fotofobia');
        await completeSubmit(wrapper, record({ main_complaint: 'Dor ocular' }));

        expect(fireBefore()).toBe(false);

        await typeComplaint(wrapper, 'Dor ocular');
        expect(fireBefore()).toBeUndefined();
    });

    it('Finalizar recusado pelo servidor (volta ao mesmo prontuário) não deixa aviso falso', async () => {
        const wrapper = mountForm({ edit: true, scheduleFlow: { id: 's1', situation: 6, update_url: '/sit' } });
        await flushPromises();
        await typeComplaint(wrapper);

        wrapper.vm.submitFlow('finish');
        expect(inertia.submits[0].data.flow_action).toBe('finish');
        await completeSubmit(wrapper, record({ main_complaint: 'Dor ocular' }));

        expect(fireBefore()).toBeUndefined();
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('aplicar o cálculo de lentes de contato conta como alteração', async () => {
        const wrapper = mountForm({ edit: true });
        await flushPromises();

        lensModal(wrapper).vm.$emit('apply', calc);
        await flushPromises();

        expect(fireBefore()).toBe(false);
    });

    it('remover o cálculo depois de salvá-lo na mesma tela chega ao servidor', async () => {
        const wrapper = mountForm({ edit: true, medicalrecord: record({ contact_lens_calculation: null }) });
        await flushPromises();

        lensModal(wrapper).vm.$emit('apply', calc);
        await wrapper.find('form').trigger('submit');
        expect(inertia.submits[0].payload.contact_lens_calculation).toEqual(calc);
        await completeSubmit(wrapper, record({ contact_lens_calculation: calc }));

        lensModal(wrapper).vm.$emit('remove');
        await flushPromises();
        expect(fireBefore()).toBe(false);
        await wrapper.find('form').trigger('submit');

        expect(inertia.submits[1].payload).toHaveProperty('contact_lens_calculation', null);
    });

    it('passar pelo eixo salvo não reformata nem marca alteração; valor digitado é formatado', async () => {
        const wrapper = mountForm({ edit: true, medicalrecord: record({ dynamic_axis_right: '90' }) });
        await flushPromises();
        const axis = wrapper.find('[name="dynamic_axis_right"]');

        await axis.trigger('blur');
        await flushPromises();

        expect(globalThis.fetch).not.toHaveBeenCalledWith('/lens', expect.anything());
        expect(fireBefore()).toBeUndefined();

        await axis.setValue('95');
        await axis.trigger('blur');
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledWith('/lens', expect.anything());
        expect(axis.element.value).toBe('95º');
    });

    it('evolução digitada e não registrada conta como alteração', async () => {
        const wrapper = mountForm({ edit: true });
        await flushPromises();
        await typeEvolutionDraft(wrapper);

        expect(fireBefore()).toBe(false);
    });

    it('Salvar simples mantém a evolução digitada (a tela não remonta): não pergunta e ela segue pendente', async () => {
        const wrapper = mountForm({ edit: true });
        await flushPromises();
        await typeComplaint(wrapper);
        await typeEvolutionDraft(wrapper);

        await wrapper.find('form').trigger('submit');
        expect(inertia.submits).toHaveLength(1);
        expect(window.confirm).not.toHaveBeenCalled();

        await completeSubmit(wrapper, record({ main_complaint: 'Dor ocular' }));
        expect(fireBefore()).toBe(false);
    });

    it('Finalizar com evolução não registrada avisa que ela será descartada; "Cancelar" não envia', async () => {
        const wrapper = mountForm({ edit: true, scheduleFlow: { id: 's1', situation: 6, update_url: '/sit' } });
        await flushPromises();
        await typeEvolutionDraft(wrapper);

        wrapper.vm.submitFlow('finish');

        expect(window.confirm).toHaveBeenCalledWith(T.drafts_leave_confirm);
        expect(inertia.submits).toHaveLength(0);
    });

    it('receita alterada e não emitida conta como alteração; depois de emitida, não', async () => {
        const wrapper = mountForm({ edit: true });
        await flushPromises();
        await wrapper.find('[title="Receituário de Medicamentos"]').trigger('click');
        const recipe = wrapper.find('textarea[placeholder="Linhas formatadas aparecem aqui."]');

        await recipe.setValue('Colírio X — 1 gota 8/8h');
        expect(fireBefore()).toBe(false);

        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('Emitir Receita'))
            .trigger('click');
        await flushPromises();
        expect(globalThis.fetch).toHaveBeenCalledWith('/qa/medication-prescription', expect.anything());
        expect(fireBefore()).toBeUndefined();

        await wrapper.find('[title="Receituário de Medicamentos"]').trigger('click');
        await wrapper
            .find('textarea[placeholder="Linhas formatadas aparecem aqui."]')
            .setValue('Colírio X — 1 gota 6/6h');
        expect(fireBefore()).toBe(false);
    });

    it('aprovar laudo de IA recarrega o prontuário sem perguntar', async () => {
        const wrapper = mountForm({ edit: true, ai: { enabled: true } });
        await flushPromises();
        await typeComplaint(wrapper);

        wrapper.findComponent('.ai-panel-stub').vm.$emit('approved');

        expect(inertia.reloads).toHaveLength(1);
        expect(inertia.reloads[0].before).toBeUndefined();
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('"Continuar atendimento": sem alterações só sai; com alterações salva e depois sai', async () => {
        const wrapper = mountForm({ edit: true });
        await flushPromises();

        wrapper.vm.saveAndLeave('/list');
        expect(inertia.submits).toHaveLength(0);
        expect(inertia.visits.map((v) => v.url)).toEqual(['/list']);

        await typeComplaint(wrapper);
        wrapper.vm.saveAndLeave('/list');

        expect(inertia.submits).toHaveLength(1);
        expect(inertia.submits[0].data.flow_action).toBe('save');

        inertia.submits[0].options.onSuccess();

        expect(inertia.visits.at(-1)).toEqual({ url: '/list', before: undefined });
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('"Continuar atendimento" com evolução não registrada avisa antes de salvar e sair', async () => {
        const wrapper = mountForm({ edit: true });
        await flushPromises();
        await typeComplaint(wrapper);
        await typeEvolutionDraft(wrapper);

        wrapper.vm.saveAndLeave('/list');

        expect(window.confirm).toHaveBeenCalledWith(T.drafts_leave_confirm);
        expect(inertia.submits).toHaveLength(0);
    });
});

describe('Guard de saída do atendimento (Agenda)', () => {
    const mountGuard = (props = {}) =>
        mount(ScheduleFlowGuard, {
            props: {
                flow: { id: 's1', situation: 6, update_url: '/situation' },
                isDoctor: true,
                exitUrl: '/list',
                finishUrl: '/agenda',
                submitFlow: vi.fn(),
                t: { flow_continue: 'Continuar atendimento' },
                ...props,
            },
            global: { stubs: { teleport: true } },
        });

    async function chooseContinue(wrapper) {
        wrapper.vm.requestExit();
        await flushPromises();
        const option = wrapper.findAll('.sfg-option').find((b) => b.text().includes('Continuar atendimento'));
        await option.trigger('click');
    }

    it('"Continuar atendimento" usa o sair-salvando do prontuário', async () => {
        const leave = vi.fn();
        await chooseContinue(mountGuard({ leave }));

        expect(leave).toHaveBeenCalledWith('/list');
        expect(inertia.visits).toHaveLength(0);
    });

    it('sem o sair-salvando (outra tela), "Continuar" só sai', async () => {
        await chooseContinue(mountGuard());

        expect(inertia.visits.map((v) => v.url)).toEqual(['/list']);
    });

    it('páginas do prontuário ligam o "Continuar" ao sair-salvando', () => {
        for (const page of ['Create.vue', 'Edit.vue']) {
            const source = readFileSync(
                resolve(__dirname, '../../../../resources/js/Pages/Panel/MedicalRecords', page),
                'utf8',
            );

            expect(source).toMatch(/:leave="\(url\) => recordForm\?\.saveAndLeave\(url\)"/);
        }
    });
});
