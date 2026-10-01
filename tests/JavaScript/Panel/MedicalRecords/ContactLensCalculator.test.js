import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import ContactLensCalculatorModal from '@/Pages/Panel/MedicalRecords/Components/ContactLensCalculatorModal.vue';
import MedicalRecordViewModal from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordViewModal.vue';
import PreviousRecordsCard from '@/Pages/Panel/MedicalRecords/Components/PreviousRecordsCard.vue';
import {
    computeContactLens, contactLensCompact, contactLensOutOfRange, contactLensSummary, refractionFromRecord,
    sphericalEquivalent, vertexConvert,
} from '@/Pages/Panel/MedicalRecords/Components/contactLens.js';

/**
 * Cálculo de lentes de contato no prontuário (saiu do Gerenciador de
 * Imagens): mesmas fórmulas de antes, resultado vinculado à consulta e
 * exibido no prontuário e na visualização da consulta.
 */

const calc = computeContactLens({
    vertex_distance_mm: 12, vertex_od: -6, vertex_oe: 6, se_od_sphere: -2, se_od_cylinder: -1,
});

const mountModal = (props = {}) => mount(ContactLensCalculatorModal, {
    props: { open: true, t: {}, ...props },
    global: { stubs: { teleport: true } },
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('contactLens.js — mesmas fórmulas e arredondamento do servidor', () => {
    it('vértice: -6.00 a 12 mm vira -5.60; +6.00 vira +6.47; plano é 0; vazio é null', () => {
        expect(vertexConvert(-6, 12)).toBe(-5.6);
        expect(vertexConvert(6, 12)).toBe(6.47);
        expect(vertexConvert(0, 12)).toBe(0);
        expect(vertexConvert('', 12)).toBeNull();
        expect(vertexConvert(-6, '')).toBe(-5.6); // distância vazia = 12 mm
    });

    it('equivalente esférico: SE = esférico + cilindro/2 (Math.round: -2.375 → -2.37)', () => {
        expect(sphericalEquivalent(-2, -1)).toBe(-2.5);
        expect(sphericalEquivalent(1.5, null)).toBe(1.5);
        expect(sphericalEquivalent(-2.25, -0.25)).toBe(-2.37);
    });

    it('paridade com o servidor: só espaços é vazio; cilindro inválido não gera SE', () => {
        expect(vertexConvert('   ', 12)).toBeNull();
        expect(sphericalEquivalent(-2, '  ')).toBe(-2);
        expect(sphericalEquivalent(-2, 'abc')).toBeNull();
    });

    it('nada digitado não gera cálculo; faixa do servidor é conferida antes de salvar', () => {
        expect(computeContactLens({ vertex_distance_mm: 12 })).toBeNull();
        expect(calc).toMatchObject({ version: 1, vertex_od_result: -5.6, vertex_oe_result: 6.47, se_od_result: -2.5, se_oe_result: null });

        expect(contactLensOutOfRange({ vertex_distance_mm: 0, vertex_od: -6 })).toEqual([]);
        expect(contactLensOutOfRange({ vertex_distance_mm: 3, vertex_od: -41, se_oe_cylinder: 16 }))
            .toEqual(['vertex_distance_mm', 'vertex_od', 'se_oe_cylinder']);
    });

    it('refração do prontuário ("+1.50", "-0,75", "0.00") vira número', () => {
        const r = refractionFromRecord({
            dynamic_spherical_right: '+1.50', dynamic_cylindrical_right: '-0,75',
            dynamic_spherical_left: '0.00', dynamic_cylindrical_left: '',
        }, 'dynamic');

        expect(r).toEqual({ od: { sphere: 1.5, cylinder: -0.75 }, oe: { sphere: 0, cylinder: null } });
    });

    it('bloco de refração em branco (tudo "0.00"/"0°", como o servidor) não vira plano', () => {
        expect(refractionFromRecord({
            static_spherical_right: '0.00', static_cylindrical_right: '0,00', static_axis_right: '0°',
            static_spherical_left: '+0.00', static_cylindrical_left: '', static_axis_left: '0º',
        }, 'static')).toBeNull();
        expect(refractionFromRecord({}, 'dynamic')).toBeNull();
    });

    it('resumo traduzido: rótulo com a distância e olhos de cada idioma', () => {
        const rows = contactLensSummary(calc, {
            od: 'OD', oe: 'OS', contact_lens_vertex_label: 'Contact lens (vertex :mm mm)', contact_lens_se_title: 'Spherical equivalent',
        });

        expect(rows).toEqual([
            { key: 'vertex', label: 'Contact lens (vertex 12 mm)', value: 'OD: -6.00 → -5.60  ·  OS: +6.00 → +6.47' },
            { key: 'se', label: 'Spherical equivalent', value: 'OD: -2.50' },
        ]);
        expect(contactLensSummary(null)).toEqual([]);
    });

    it('versão curta do painel: só o resultado por olho, sigla traduzida e rótulo completo', () => {
        expect(contactLensCompact(calc, { od: 'OD', oe: 'OS', contact_lens_short: 'CL' })).toEqual([
            { key: 'vertex', tag: 'CL', label: 'Esférico → lente de contato (vértice 12 mm)', value: 'OD -5.60 | OS +6.47' },
            { key: 'se', tag: 'SE', label: 'Equivalente esférico', value: 'OD -2.50' },
        ]);
        expect(contactLensCompact({ ...calc, vertex_od_result: null, vertex_oe_result: null }))
            .toEqual([{ key: 'se', tag: 'SE', label: 'Equivalente esférico', value: 'OD -2.50' }]);
        expect(contactLensCompact(null)).toEqual([]);
    });
});

describe('ContactLensCalculatorModal', () => {
    it('calcula na hora e "Usar no prontuário" devolve entradas + resultados', async () => {
        const wrapper = mountModal();

        await wrapper.find('#clc-vertex-od').setValue('-6');
        await wrapper.find('#clc-se-od-sph').setValue('-2');
        await wrapper.find('#clc-se-od-cyl').setValue('-1');

        expect(wrapper.find('[data-result="vertex-od"]').text()).toBe('-5.60');
        expect(wrapper.find('[data-result="vertex-oe"]').text()).toBe('—');
        expect(wrapper.find('[data-result="se-od"]').text()).toBe('-2.50');

        await wrapper.find('[data-action="apply"]').trigger('click');

        expect(wrapper.emitted('apply')[0][0]).toMatchObject({
            vertex_distance_mm: 12, vertex_od: -6, vertex_od_result: -5.6, se_od_result: -2.5, se_oe_result: null,
        });
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('sem nada digitado não deixa usar no prontuário', () => {
        expect(mountModal().find('[data-action="apply"]').attributes('disabled')).toBeDefined();
    });

    it('copia esférico/cilindro da refração dinâmica ou estática do prontuário', async () => {
        const wrapper = mountModal({
            record: {
                dynamic_spherical_right: '-2.00', dynamic_cylindrical_right: '-1.00',
                dynamic_spherical_left: '+1.50', dynamic_cylindrical_left: '0.00',
                static_spherical_right: '-6.00', static_cylindrical_right: '0.00',
                static_spherical_left: '+6.00', static_cylindrical_left: '0.00',
            },
        });

        await wrapper.find('[data-copy="dynamic"]').trigger('click');
        expect(wrapper.find('[data-result="se-od"]').text()).toBe('-2.50');
        expect(wrapper.find('[data-result="se-oe"]').text()).toBe('1.50');

        await wrapper.find('[data-copy="static"]').trigger('click');
        expect(wrapper.find('[data-result="vertex-od"]').text()).toBe('-5.60');
        expect(wrapper.find('[data-result="vertex-oe"]').text()).toBe('6.47');
    });

    it('refração em branco no prontuário: avisa e não copia nada', async () => {
        const wrapper = mountModal({
            modelValue: calc,
            record: { dynamic_spherical_right: '0.00', dynamic_cylindrical_right: '0.00', dynamic_axis_right: '0°' },
        });

        await wrapper.find('[data-copy="dynamic"]').trigger('click');

        expect(wrapper.find('[data-copy-empty]').exists()).toBe(true);
        expect(wrapper.find('#clc-vertex-od').element.value).toBe('-6');
        expect(wrapper.find('[data-result="vertex-od"]').text()).toBe('-5.60');
    });

    it('fora da faixa aceita pelo servidor: campo destacado e "Usar" bloqueado', async () => {
        const wrapper = mountModal();

        await wrapper.find('#clc-vertex-od').setValue('-50');

        expect(wrapper.find('#clc-vertex-od').classes()).toContain('is-invalid');
        expect(wrapper.find('#clc-vertex-od').attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('[data-out-of-range]').exists()).toBe(true);
        expect(wrapper.find('[data-action="apply"]').attributes('disabled')).toBeDefined();
    });

    it('reabre com o cálculo gravado e permite remover do prontuário', async () => {
        const wrapper = mountModal({ modelValue: calc });

        expect(wrapper.find('#clc-vertex-od').element.value).toBe('-6');
        expect(wrapper.find('[data-result="vertex-oe"]').text()).toBe('6.47');

        await wrapper.find('[data-action="remove"]').trigger('click');
        expect(wrapper.emitted('remove')).toHaveLength(1);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('prontuário assinado: só consulta (campos travados, sem usar/remover/copiar)', () => {
        const wrapper = mountModal({ modelValue: calc, readonly: true, t: { contact_lens_locked: 'Signed record: calculation is read-only.' } });

        expect(wrapper.find('fieldset').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Signed record: calculation is read-only.');
        expect(wrapper.find('[data-action="apply"]').exists()).toBe(false);
        expect(wrapper.find('[data-action="remove"]').exists()).toBe(false);
        expect(wrapper.find('[data-copy="dynamic"]').exists()).toBe(false);
        expect(wrapper.find('[data-result="vertex-od"]').text()).toBe('-5.60');
    });

    it('assinado mostra o resultado GRAVADO (o do PDF), sem recalcular', () => {
        const wrapper = mountModal({ modelValue: { ...calc, vertex_od_result: -5.55 }, readonly: true });

        expect(wrapper.find('[data-result="vertex-od"]').text()).toBe('-5.55');
    });

    it('Esc fecha mesmo com o foco fora do diálogo; ao fechar, o foco volta ao botão', async () => {
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();

        const wrapper = mount(ContactLensCalculatorModal, {
            props: { open: false, t: {} },
            global: { stubs: { teleport: true } },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });

        document.body.focus();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('close')).toHaveLength(1);

        await wrapper.setProps({ open: false });
        expect(document.activeElement).toBe(opener);

        wrapper.unmount();
        opener.remove();
    });

    it('textos vêm das traduções (inglês: OS para olho esquerdo)', () => {
        const wrapper = mountModal({ t: { contact_lens_title: 'Contact lens calculation', od: 'OD', oe: 'OS' } });

        expect(wrapper.find('#contactLensCalcTitle').text()).toBe('Contact lens calculation');
        expect(wrapper.text()).toContain('OS → SE');
    });
});

describe('Consulta posterior — visualização do prontuário', () => {
    it('mostra o cálculo vinculado na seção Refração', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            json: async () => ({ main_complaint: 'Adaptação de lente de contato', contact_lens_calculation: calc }),
        })));

        const wrapper = mount(MedicalRecordViewModal, {
            props: { open: true, record: { show_url: '/panel/patients/1/medicalrecords/1' }, t: {} },
            global: { stubs: { teleport: true } },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Esférico → lente de contato (vértice 12 mm)');
        expect(wrapper.text()).toMatch(/OD: -6\.00 → -5\.60\s+·\s+OE: \+6\.00 → \+6\.47/);
        expect(wrapper.text()).toMatch(/Equivalente esférico\s*OD: -2\.50/);
    });

    it('painel "Consultas anteriores" mostra o resultado sem abrir a consulta (só quando houve cálculo)', () => {
        const wrapper = mount(PreviousRecordsCard, {
            props: {
                t: {},
                records: [
                    { id: 'a', created_at_formatted: '01/09/2026', doctor_name: 'Dra. A', summary: { contact_lens: calc } },
                    { id: 'b', created_at_formatted: '01/08/2026', doctor_name: 'Dr. B', summary: { contact_lens: null } },
                ],
            },
        });

        const [first, second] = wrapper.findAll('li');
        const lines = first.findAll('[data-contact-lens]');

        expect(lines.map((line) => line.find('.prev-records__tag').text())).toEqual(['LC', 'SE']);
        expect(lines[0].text()).toContain('OD -5.60 | OE +6.47');
        expect(lines[0].attributes('title')).toBe('Esférico → lente de contato (vértice 12 mm)');
        expect(lines[1].text()).toContain('OD -2.50');
        expect(second.find('[data-contact-lens]').exists()).toBe(false);
    });
});

describe('Local de acesso: Prontuário (não mais o Gerenciador de Imagens)', () => {
    const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8');
    const form = read('resources/js/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue');

    it('prontuário: botão só para médico, resultado no form e modal travado quando assinado', () => {
        expect(form).toContain('contact_lens_calculation: r?.contact_lens_calculation ?? null');
        expect(form).toMatch(/<button\s+v-if="isDoctor"[^>]*\s+data-contact-lens-open\s+:disabled="isLocked && !contactLensRows\.length"/);
        expect(form).toMatch(/<ContactLensCalculatorModal[^>]*:readonly="isLocked"[^>]*@apply="applyContactLens"[^>]*@remove="removeContactLens"/);
        expect(form).toMatch(/v-if="contactLensRows\.length"[^>]*data-contact-lens-summary/);
    });

    it('Gerenciador de Imagens não tem mais a calculadora', () => {
        const eyeImages = read('resources/js/Pages/Panel/EyeImages/Index.vue');

        expect(eyeImages).not.toContain('LensCalculatorModal');
        expect(eyeImages).not.toContain('lens_calc_');
        expect(existsSync(resolve(process.cwd(), 'resources/js/Pages/Panel/EyeImages/LensCalculatorModal.vue'))).toBe(false);
    });
});
