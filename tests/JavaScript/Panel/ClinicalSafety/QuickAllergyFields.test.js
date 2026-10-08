import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';

/**
 * Fase 2 / G2 — alergias no CADASTRO RÁPIDO (lista de espera e pronto
 * atendimento): texto curto de substâncias OU "nega alergias" (um trava o
 * outro), só o preenchido vai ao servidor, erros do servidor aparecem.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        usePage: () => ({ props: { t_ui: { patient_form: {} } } }),
        useForm: (data) =>
            reactive({
                ...data,
                errors: {},
                processing: false,
                transform: vi.fn(),
                clearErrors: vi.fn(),
                post: vi.fn(),
                put: vi.fn(),
            }),
    };
});

const QuickAllergyFields = (await import('@/Components/Panel/ClinicalSafety/QuickAllergyFields.vue')).default;
const WaitingListFormModal = (await import('@/Pages/Panel/Schedules/WaitingListFormModal.vue')).default;
const UrgentCareFormModal = (await import('@/Pages/Panel/UrgentCare/UrgentCareFormModal.vue')).default;
const { quickAllergyPayload } = await import('@/Support/clinicalSafety.js');

const created = (patient) => Promise.resolve({ ok: true, status: 201, json: () => Promise.resolve({ patient }) });
const bodyOf = (call) => JSON.parse(call[1].body);

beforeEach(() => {
    vi.clearAllMocks();
});

describe('QuickAllergyFields', () => {
    it('campo com rótulo, dica ligada por aria-describedby; digitar trava "nega"; "nega" trava o texto', async () => {
        const w = mount(QuickAllergyFields, { props: { allergies: '', denied: false } });
        const input = w.find('[data-testid="quick-allergies-text"]');
        const deny = w.find('[data-testid="quick-allergies-deny"]');

        expect(w.find(`label[for="${input.attributes('id')}"]`).text()).toContain('Alergias');
        expect(w.find(`#${input.attributes('aria-describedby')}`).text()).toContain('sem registro de alergias');
        expect(w.find('[role="group"]').attributes('aria-labelledby')).toBeTruthy();

        await input.setValue('Dipirona');
        expect(w.emitted('update:allergies').at(-1)).toEqual(['Dipirona']);

        await w.setProps({ allergies: 'Dipirona' });
        expect(deny.attributes('disabled')).toBeDefined();
        expect(deny.attributes('title')).toContain('nega alergias');

        await w.setProps({ allergies: '', denied: true });
        expect(input.attributes('disabled')).toBeDefined();
        expect(deny.attributes('disabled')).toBeUndefined();
    });

    it('erros do servidor aparecem com role=alert (não só cor)', () => {
        const w = mount(QuickAllergyFields, {
            props: { allergies: 'x', errors: { allergies: ['Cada alergia deve ter entre 2 e 160 caracteres ("x").'] } },
        });

        expect(w.find('[role="alert"]').text()).toContain('entre 2 e 160');
        expect(w.find('[data-testid="quick-allergies-text"]').attributes('aria-invalid')).toBe('true');
    });

    it('envio: só o preenchido — alergias OU "nega"; nada = sem registro', () => {
        expect(quickAllergyPayload('  Dipirona; látex ', false)).toEqual({ allergies: 'Dipirona; látex' });
        expect(quickAllergyPayload('', true)).toEqual({ allergies_denied: true });
        expect(quickAllergyPayload('   ', false)).toEqual({});
    });
});

describe('Lista de espera — cadastro rápido com alergias', () => {
    async function mountWaitingList() {
        const wrapper = mount(WaitingListFormModal, {
            props: {
                open: false,
                t: { wl_full_name: 'Nome completo', wl_cellphone: 'Celular', wl_save_link: 'Salvar' },
            },
            global: {
                stubs: {
                    SearchSelect: true,
                    OffcanvasPanel: {
                        props: ['open'],
                        template: '<div><slot name="header" /><slot /><slot name="footer" /></div>',
                    },
                },
                directives: { mask: {} },
            },
        });
        await wrapper.setProps({ open: true });
        wrapper.vm.$.setupState.showQuickReg = true;
        await nextTick();
        return wrapper;
    }

    it('manda as alergias digitadas junto com o cadastro', async () => {
        globalThis.fetch = vi.fn(() => created({ id: 'p1', full_name: 'ANA' }));
        const w = await mountWaitingList();

        await w.find('[data-testid="wl-quick-name"]').setValue('Ana');
        await w.find('[data-testid="wl-quick-cellphone"]').setValue('(11) 97777-6666');
        await w.find('[data-testid="wl-quick"] [data-testid="quick-allergies-text"]').setValue('Dipirona, látex');
        await w.find('[data-testid="wl-quick-save"]').trigger('click');
        await flushPromises();

        expect(bodyOf(globalThis.fetch.mock.calls[0])).toMatchObject({ name: 'Ana', allergies: 'Dipirona, látex' });
        expect(bodyOf(globalThis.fetch.mock.calls[0])).not.toHaveProperty('allergies_denied');
    });

    it('"nega alergias" vai como allergies_denied; 422 do servidor aparece no campo', async () => {
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                ok: false,
                status: 422,
                json: () =>
                    Promise.resolve({
                        errors: { allergies_denied: ['Informe as alergias ou marque "nega alergias" — não os dois.'] },
                    }),
            }),
        );
        const w = await mountWaitingList();

        await w.find('[data-testid="wl-quick-name"]').setValue('Ana');
        await w.find('[data-testid="wl-quick-cellphone"]').setValue('(11) 97777-6666');
        await w.find('[data-testid="quick-allergies-deny"]').setValue(true);
        await w.find('[data-testid="wl-quick-save"]').trigger('click');
        await flushPromises();

        expect(bodyOf(globalThis.fetch.mock.calls[0])).toMatchObject({ allergies_denied: true });
        expect(bodyOf(globalThis.fetch.mock.calls[0])).not.toHaveProperty('allergies');
        expect(w.find('[data-testid="quick-allergies"] [role="alert"]').text()).toContain('não os dois');
    });
});

describe('Pronto atendimento — cadastro rápido com alergias', () => {
    it('manda as alergias digitadas; limpa ao reabrir', async () => {
        globalThis.fetch = vi.fn(() => created({ id: 'p2', full_name: 'BRUNO', code: 'PAC-2', age: 40 }));
        const w = mount(UrgentCareFormModal, {
            props: {
                open: true,
                reasons: [],
                priorities: [],
                canRegisterPatient: true,
                urls: { store: '/panel/urgent-care', patient_search: '/s', patient_quick: '/q' },
                t: { quick_title: 'Cadastro rápido', quick_save: 'Cadastrar', patient_new: 'Novo paciente' },
            },
            global: {
                stubs: {
                    Teleport: true,
                    CenteredModal: { template: '<div><slot name="header" /><slot /><slot name="footer" /></div>' },
                },
            },
        });

        await w.find('[data-testid="uc-quick-open"]').trigger('click');
        await w.find('[data-testid="uc-quick-name"]').setValue('Bruno');
        await w.find('[data-testid="uc-quick-cellphone"]').setValue('11999990000');
        await w.find('[data-testid="uc-quick"] [data-testid="quick-allergies-text"]').setValue('Penicilina');
        await w.find('[data-testid="uc-quick-save"]').trigger('click');
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toBe('/q');
        expect(bodyOf(globalThis.fetch.mock.calls[0])).toMatchObject({ name: 'Bruno', allergies: 'Penicilina' });

        await w.setProps({ open: false });
        await w.setProps({ open: true });
        await w.find('[data-testid="uc-quick-open"]').trigger('click');
        expect(w.find('[data-testid="quick-allergies-text"]').element.value).toBe('');
    });
});
