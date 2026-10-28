import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import RuleFormModal from '@/Pages/Panel/Financial/DoctorPayouts/RuleFormModal.vue';
import { t, doctors } from './fixtures.js';

/**
 * Regra de repasse (criar/editar): percentual × valor fixo pelo cálculo,
 * "Aplicar a" filtrado pelo tipo de serviço, no máximo UM id de item no
 * payload, convênio só com pagador "Convênio", "Todos os tipos" só ao criar
 * e erros do servidor por campo.
 */

// useForm reativo com transform(): o envio registra o payload TRANSFORMADO,
// como o Inertia faria.
const inertia = vi.hoisted(() => ({ forms: [], sent: [] }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        usePage: () => ({ props: { locale: 'pt_BR' } }),
        useForm: (data) => {
            const initial = { ...data };
            const fields = Object.keys(data);
            let transformer = (value) => value;
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                data: () => Object.fromEntries(fields.map((field) => [field, form[field]])),
                transform: (callback) => {
                    transformer = callback;
                    return form;
                },
                reset: () => Object.assign(form, initial),
                clearErrors: () => {
                    form.errors = {};
                },
                post: vi.fn((url, options) =>
                    inertia.sent.push({ method: 'post', url, data: transformer(form.data()), options }),
                ),
                put: vi.fn((url, options) =>
                    inertia.sent.push({ method: 'put', url, data: transformer(form.data()), options }),
                ),
            });
            inertia.forms.push(form);

            return form;
        },
    };
});

vi.mock('@/Components/Panel/OffcanvasPanel.vue', () => ({
    default: {
        props: ['open', 'width'],
        emits: ['close'],
        template:
            '<div v-if="open" class="offcanvas-stub"><header><slot name="header" /></header><slot /><footer><slot name="footer" /></footer></div>',
    },
}));

const options = {
    doctors,
    visit_types: [
        { id: 'vt-c', name: 'Consulta retorno', service_type: 'consultation' },
        { id: 'vt-e', name: 'Mapeamento de retina', service_type: 'exam' },
        { id: 'vt-p', name: 'Yag laser (agenda)', service_type: 'procedure' },
    ],
    procedures: [{ id: 'pr1', code: '30310016', name: 'Facectomia' }],
    exam_types: [{ id: 'ex1', name: 'OCT' }],
    covenants: [
        { id: 'c1', name: 'Unimed', particular: false, own: false },
        { id: 'c0', name: 'PARTICULAR', particular: true, own: false },
        { id: 'c2', name: 'Convênio Prefeitura', particular: true, own: true },
    ],
};

const routes = { store: '/doctor-payouts/rules', update: '/doctor-payouts/rules/__ID__' };

const editRule = {
    id: 'r9',
    doctor_id: 'd1',
    doctor_name: 'Dra. Ana Lima',
    service_type: 'procedure',
    item_kind: 'procedure',
    item_id: 'pr1',
    item_name: 'Facectomia',
    payer_scope: 'covenant',
    covenant_id: 'c1',
    covenant_name: 'Unimed',
    calculation: 'fixed',
    percentage: null,
    fixed_amount: 80,
    valid_from: '2026-01-01',
    valid_until: null,
    active: true,
    notes: 'Tabela 2026',
    is_general: false,
};

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    inertia.forms.length = 0;
    inertia.sent.length = 0;
});

async function mountModal(props = {}) {
    wrapper = mount(RuleFormModal, { props: { open: false, rule: null, options, routes, t, ...props } });
    await wrapper.setProps({ open: true });
    await nextTick();

    return wrapper;
}

const form = () => inertia.forms.at(-1);
const itemGroups = (w) =>
    w.findAll('#rule_item optgroup').map((group) => ({
        label: group.attributes('label'),
        options: group.findAll('option').map((option) => option.text()),
    }));

async function submit(w) {
    await w.find('[data-test="rule-submit"]').trigger('click');

    return inertia.sent.at(-1);
}

describe('Financial/DoctorPayouts/RuleFormModal', () => {
    it('criar: título, rótulos ligados aos campos e percentual por padrão', async () => {
        const w = await mountModal();

        expect(w.find('header').text()).toBe('New rule');
        expect(w.find('label[for="rule_doctor_id"]').text()).toBe('Doctor');
        expect(w.findAll('#rule_doctor_id option').map((o) => o.text())).toEqual([
            'All doctors',
            'Dra. Ana Lima',
            'Dr. Beto Reis (inactive)',
        ]);
        expect(w.find('label[for="rule_item"]').text()).toBe('Apply to');
        expect(w.find('#rule_percentage').exists()).toBe(true);
        expect(w.find('#rule_fixed_amount').exists()).toBe(false);
        expect(w.find('legend').text()).toBe('Calculation');
    });

    it('cálculo "Valor fixo" troca o percentual pelo campo de valor (e volta)', async () => {
        const w = await mountModal();

        await w.find('[data-test="calculation-fixed"]').setValue(true);
        expect(w.find('#rule_percentage').exists()).toBe(false);
        expect(w.find('#rule_fixed_amount').exists()).toBe(true);
        expect(w.find('label[for="rule_fixed_amount"]').text()).toBe('Fixed amount per item');

        await w.find('[data-test="calculation-percentage"]').setValue(true);
        expect(w.find('#rule_percentage').exists()).toBe(true);
        expect(w.find('#rule_fixed_amount').exists()).toBe(false);
    });

    it('"Aplicar a" lista só os itens do tipo de serviço escolhido', async () => {
        const w = await mountModal();

        expect(itemGroups(w)).toEqual([{ label: 'Visit type', options: ['Consulta retorno'] }]);
        expect(w.find('#rule_item option').text()).toBe('All items of the type');

        await w.find('#rule_service_type').setValue('exam');
        expect(itemGroups(w)).toEqual([
            { label: 'Exam type', options: ['OCT'] },
            { label: 'Visit type', options: ['Mapeamento de retina'] },
        ]);

        await w.find('#rule_service_type').setValue('procedure');
        expect(itemGroups(w)).toEqual([
            { label: 'Procedure', options: ['30310016 — Facectomia'] },
            { label: 'Visit type', options: ['Yag laser (agenda)'] },
        ]);
    });

    it('"Todos os tipos" (só ao criar): sem item específico e com a dica', async () => {
        const w = await mountModal();

        expect(w.findAll('#rule_service_type option').map((o) => o.attributes('value'))).toEqual([
            'consultation',
            'exam',
            'procedure',
            'all',
        ]);

        await w.find('#rule_service_type').setValue('all');
        expect(w.find('#rule_item').exists()).toBe(false);
        expect(w.find('[data-test="all-types-hint"]').text()).toBe('Creates one rule for each type.');
        expect(w.find('#rule_service_type').attributes('aria-describedby')).toBe('rule_all_types_hint');
    });

    it('envia só UM id de item, o valor só do cálculo escolhido e nulos no que não se aplica', async () => {
        const w = await mountModal();

        await w.find('#rule_service_type').setValue('exam');
        await w.find('#rule_item').setValue('exam_type:ex1');
        await w.find('#rule_percentage').setValue('60');

        const sent = await submit(w);
        expect(sent.method).toBe('post');
        expect(sent.url).toBe('/doctor-payouts/rules');
        expect(sent.options).toEqual(expect.objectContaining({ preserveScroll: true }));
        expect(sent.data).toEqual({
            doctor_id: null,
            service_type: 'exam',
            visit_type_id: null,
            procedure_id: null,
            exam_type_id: 'ex1',
            payer_scope: 'any',
            covenant_id: null,
            calculation: 'percentage',
            percentage: 60,
            fixed_amount: null,
            valid_from: null,
            valid_until: null,
            active: true,
            notes: null,
            participants: [],
        });
        expect(sent.data).not.toHaveProperty('item');
    });

    it('tipo de atendimento do exame vai em visit_type_id (e nenhum outro id)', async () => {
        const w = await mountModal();

        await w.find('#rule_service_type').setValue('exam');
        await w.find('#rule_item').setValue('visit_type:vt-e');

        const { data } = await submit(w);
        expect([data.visit_type_id, data.procedure_id, data.exam_type_id]).toEqual(['vt-e', null, null]);
    });

    it('trocar o tipo de serviço limpa o item que não pertence mais a ele', async () => {
        const w = await mountModal();

        await w.find('#rule_service_type').setValue('exam');
        await w.find('#rule_item').setValue('visit_type:vt-e');
        await w.find('#rule_service_type').setValue('procedure');

        expect(form().item).toBe('');
        const { data } = await submit(w);
        expect([data.visit_type_id, data.procedure_id, data.exam_type_id]).toEqual([null, null, null]);
    });

    it('convênio específico só com pagador "Convênio"; convênio da clínica sem ANS em grupo próprio; "PARTICULAR" global fora', async () => {
        const w = await mountModal();

        expect(w.find('#rule_covenant_id').exists()).toBe(false);

        await w.find('#rule_payer_scope').setValue('covenant');
        expect(w.findAll('#rule_covenant_id option').map((o) => o.text())).toEqual([
            'Any insurance',
            'Unimed',
            'Convênio Prefeitura',
        ]);
        expect(w.find('#rule_covenant_id optgroup[data-group="without_ans"]').attributes('label')).toBe(
            'No ANS registry (treated as private in billing)',
        );
        expect(w.find('#rule_covenant_id optgroup[data-group="without_ans"]').text()).toContain('Convênio Prefeitura');

        await w.find('#rule_covenant_id').setValue('c1');
        await w.find('#rule_payer_scope').setValue('particular');
        const { data } = await submit(w);
        expect(data.payer_scope).toBe('particular');
        expect(data.covenant_id).toBeNull();
    });

    it('editar: preenche com a regra, sem "Todos os tipos", e envia PUT para a regra', async () => {
        const w = await mountModal({ rule: editRule });

        expect(w.find('header').text()).toBe('Edit rule');
        expect(w.find('#rule_doctor_id').element.value).toBe('d1');
        expect(w.find('#rule_service_type').element.value).toBe('procedure');
        expect(w.find('#rule_item').element.value).toBe('procedure:pr1');
        expect(w.find('#rule_covenant_id').element.value).toBe('c1');
        expect(w.find('#rule_fixed_amount').exists()).toBe(true);
        expect(w.find('#rule_valid_from').element.value).toBe('2026-01-01');
        expect(w.find('#rule_notes').element.value).toBe('Tabela 2026');
        expect(w.findAll('#rule_service_type option').map((o) => o.attributes('value'))).toEqual([
            'consultation',
            'exam',
            'procedure',
        ]);

        const sent = await submit(w);
        expect(sent.method).toBe('put');
        expect(sent.url).toBe('/doctor-payouts/rules/r9');
        expect(sent.data).toEqual(
            expect.objectContaining({
                doctor_id: 'd1',
                service_type: 'procedure',
                procedure_id: 'pr1',
                visit_type_id: null,
                exam_type_id: null,
                payer_scope: 'covenant',
                covenant_id: 'c1',
                calculation: 'fixed',
                fixed_amount: 80,
                percentage: null,
                valid_from: '2026-01-01',
                valid_until: null,
                notes: 'Tabela 2026',
            }),
        );
    });

    it('editar regra cujo item saiu das listas (inativo): o item continua selecionado', async () => {
        const w = await mountModal({ rule: { ...editRule, item_id: 'pr-old', item_name: 'Procedimento antigo' } });

        expect(w.find('#rule_item').element.value).toBe('procedure:pr-old');
        expect(itemGroups(w)[0].options).toContain('Procedimento antigo');
    });

    it('erros do servidor aparecem em cada campo, ligados por aria-describedby', async () => {
        const w = await mountModal();

        form().errors = {
            procedure_id: 'The chosen item does not match the service type.',
            valid_from: 'There is already an active rule with the same scope.',
            percentage: 'The percentage field is required.',
        };
        await nextTick();

        expect(w.find('#rule_item').attributes('aria-invalid')).toBe('true');
        expect(w.find('#rule_item_error').text()).toBe('The chosen item does not match the service type.');
        expect(w.find('#rule_valid_from').attributes('aria-describedby')).toBe(
            'rule_validity_hint rule_valid_from_error',
        );
        expect(w.find('#rule_valid_from_error').text()).toBe('There is already an active rule with the same scope.');
        expect(w.find('#rule_percentage').classes()).toContain('is-invalid');
        expect(w.find('#rule_percentage_error').text()).toBe('The percentage field is required.');
    });

    it('salvo com sucesso: fecha o painel', async () => {
        const w = await mountModal();
        form().post.mockImplementation((url, opts) => opts.onSuccess?.());

        await submit(w);

        expect(w.emitted('close')).toHaveLength(1);
    });

    it('divisão (E4): regra percentual mostra quanto fica com a clínica e monta os participantes', async () => {
        const w = await mountModal();

        await w.find('#rule_percentage').setValue('60');
        expect(w.find('[data-test="split-clinic"]').text()).toBe('The clinic keeps 40%.');
        expect(w.find('[data-test="split-sum"]').exists()).toBe(false);

        // 1º participante = executor; o 2º já nasce como médico fixo.
        await w.find('[data-test="split-add"]').trigger('click');
        await w.find('[data-test="split-add"]').trigger('click');
        const rows = w.findAll('[data-test="split-row"]');
        expect(rows.map((row) => row.find('[data-test="split-role"]').element.value)).toEqual(['executor', 'doctor']);
        expect(rows[0].find('[data-test="split-doctor"]').exists()).toBe(false);

        await rows[0].find('[data-test="split-percentage"]').setValue('40');
        await w.findAll('[data-test="split-row"]')[1].find('[data-test="split-doctor"]').setValue('d1');
        await w.findAll('[data-test="split-row"]')[1].find('[data-test="split-percentage"]').setValue('50');

        const sum = w.find('[data-test="split-sum"]');
        expect(sum.text()).toBe('Total: 90% (must be 100%)');
        expect(sum.classes()).toContain('text-danger');

        await w.findAll('[data-test="split-row"]')[1].find('[data-test="split-percentage"]').setValue('60');
        expect(w.find('[data-test="split-sum"]').classes()).toContain('text-success');

        const { data } = await submit(w);
        expect(data.participants).toEqual([
            { role: 'executor', doctor_id: null, percentage: '40' },
            { role: 'doctor', doctor_id: 'd1', percentage: '60' },
        ]);
    });

    it('divisão (E4): remover participante e regra de valor fixo não leva participantes', async () => {
        const w = await mountModal();

        await w.find('[data-test="split-add"]').trigger('click');
        await w.find('[data-test="split-add"]').trigger('click');
        await w.findAll('[data-test="split-remove"]')[0].trigger('click');
        expect(
            w.findAll('[data-test="split-row"]').map((row) => row.find('[data-test="split-role"]').element.value),
        ).toEqual(['doctor']);

        await w.find('[data-test="calculation-fixed"]').setValue(true);
        expect(w.find('[data-test="split-editor"]').exists()).toBe(false);

        const { data } = await submit(w);
        expect(data.participants).toEqual([]);
    });

    it('divisão (E4): editar carrega os participantes e mostra os erros do servidor por linha', async () => {
        const w = await mountModal({
            rule: {
                ...editRule,
                calculation: 'percentage',
                percentage: 60,
                fixed_amount: null,
                participants: [
                    { role: 'doctor', doctor_id: 'd1', percentage: 60 },
                    { role: 'executor', doctor_id: null, percentage: 40 },
                ],
            },
        });

        const rows = w.findAll('[data-test="split-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].find('[data-test="split-doctor"]').element.value).toBe('d1');
        expect(w.find('[data-test="split-sum"]').text()).toBe('Total: 100% (must be 100%)');

        form().errors = {
            participants: 'The shares must add up to 100%.',
            'participants.0.doctor_id': 'The doctor is invalid.',
        };
        await nextTick();

        expect(w.find('[data-test="split-error"]').text()).toBe('The shares must add up to 100%.');
        expect(
            w.findAll('[data-test="split-row"]')[0].find('[data-test="split-doctor"]').attributes('aria-invalid'),
        ).toBe('true');
        expect(w.findAll('[data-test="split-row"]')[0].text()).toContain('The doctor is invalid.');

        const { data } = await submit(w);
        expect(data.participants).toEqual([
            { role: 'doctor', doctor_id: 'd1', percentage: 60 },
            { role: 'executor', doctor_id: null, percentage: 40 },
        ]);
    });

    it('editar: "corrigir" (padrão) não cria vigência; "nova vigência a partir de" envia a data e trava o início', async () => {
        const w = await mountModal({ rule: editRule });

        expect(w.find('[data-test="change-mode"]').text()).toContain('When the change applies');
        expect(w.find('#rule_change_fix').element.checked).toBe(true);
        expect((await submit(w)).data).not.toHaveProperty('effective_from');

        await w.find('#rule_change_new').setValue(true);
        expect(w.find('#rule_valid_from').attributes('disabled')).toBeDefined();
        await w.find('[data-test="effective-from"]').setValue('2026-10-01');

        const sent = await submit(w);
        expect(sent.method).toBe('put');
        expect(sent.data.effective_from).toBe('2026-10-01');
        expect(sent.data.change_mode).toBe('new'); // o servidor exige a data nesse modo

        // Data apagada: vai o modo com a data nula (o servidor recusa; não vira correção).
        await w.find('[data-test="effective-from"]').setValue('');
        expect((await submit(w)).data).toEqual(expect.objectContaining({ change_mode: 'new', effective_from: null }));
    });

    it('criar não oferece "nova vigência"', async () => {
        const w = await mountModal();

        expect(w.find('[data-test="change-mode"]').exists()).toBe(false);
        expect((await submit(w)).data).not.toHaveProperty('effective_from');
    });

    it('duplicar: abre como NOVA regra com os campos da outra, sem vigência, e envia POST', async () => {
        const w = await mountModal({ template: editRule });

        expect(w.find('header').text()).toBe('New rule');
        expect(w.find('#rule_item').element.value).toBe('procedure:pr1');
        expect(w.find('#rule_valid_from').element.value).toBe('');

        const sent = await submit(w);
        expect(sent.method).toBe('post');
        expect(sent.data).toEqual(
            expect.objectContaining({ procedure_id: 'pr1', calculation: 'fixed', fixed_amount: 80, valid_from: null }),
        );
    });

    it('"Aplicar a" explica de onde vem a lista (procedimentos) e como vale o tipo de exame', async () => {
        const w = await mountModal();

        expect(w.find('[data-test="item-hint"]').exists()).toBe(false); // consulta

        await w.find('#rule_service_type').setValue('procedure');
        expect(w.find('[data-test="item-hint"]').text()).toBe('List of procedures.');
        expect(w.find('#rule_item').attributes('aria-describedby')).toContain('rule_item_hint');

        await w.find('#rule_service_type').setValue('exam');
        expect(w.find('[data-test="item-hint"]').text()).toBe('Exam type applies to the equipment exam.');
    });
});
