import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import Cid10Picker from '@/Components/Panel/Cid10Picker.vue';
import CidField from '@/Pages/Panel/Financial/Billing/CidField.vue';

/**
 * Cid10Picker (busca CID-10 do prontuário, IA, exames externos e guias do
 * faturamento). Antes: textos fixos em português, input sem rótulo ligado,
 * lista sem papéis de combobox/listbox, botão de remover sem nome, Esc fechava
 * o modal inteiro e cores fixas nos chips (ilegíveis no tema escuro).
 */
const page = vi.hoisted(() => ({ props: {} }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

const RESULTS = [
    { code: 'H40.1', description: 'Glaucoma primário de ângulo aberto' },
    { code: 'H40.2', description: 'Glaucoma primário de ângulo fechado' },
];

const EN = {
    placeholder: 'Search by code or diagnosis…',
    search_label: 'Search diagnosis (ICD-10)',
    suggestions: 'Diagnosis suggestions',
    most_used: 'Most used',
    custom: 'Custom',
    create: "Add new diagnosis: ':term'",
    primary: 'Primary diagnosis',
    mark_primary: 'Mark as primary diagnosis',
    primary_toggle: 'Primary diagnosis: :item',
    remove: 'Remove :item',
    searching: 'Searching…',
    results_one: ':count result',
    results_other: ':count results',
    no_results: 'No diagnosis found.',
};

let wrapper;

function respondWith(list) {
    globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => list }));
}

function mountPicker(props = {}) {
    wrapper = mount(Cid10Picker, {
        attachTo: document.body,
        props: { searchUrl: '/cid10/search', ...props },
    });

    return wrapper;
}

async function typeQuery(value) {
    await wrapper.get('input').setValue(value);
    await flushPromises();
}

const input = () => wrapper.get('input');
const listbox = () => wrapper.find('[role="listbox"]');

beforeEach(() => {
    page.props = {};
    respondWith(RESULTS);
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
});

describe('Cid10Picker (combobox acessível)', () => {
    it('rótulo ligado ao campo; combobox fechado com aria-controls', () => {
        mountPicker({ label: 'Diagnóstico' });

        expect(wrapper.get(`label[for="${input().attributes('id')}"]`).text()).toBe('Diagnóstico');
        expect(input().attributes('role')).toBe('combobox');
        expect(input().attributes('aria-autocomplete')).toBe('list');
        expect(input().attributes('aria-expanded')).toBe('false');
        expect(input().attributes('aria-controls')).toBeTruthy();
        expect(input().attributes('aria-label')).toBeUndefined();
    });

    it('sem rótulo visível, o campo tem nome acessível próprio', () => {
        mountPicker();

        expect(input().attributes('aria-label')).toBe('Buscar diagnóstico (CID-10)');
    });

    it('busca abre listbox com opções; setas movem aria-activedescendant e Enter escolhe', async () => {
        mountPicker();
        await typeQuery('glau');

        expect(input().attributes('aria-expanded')).toBe('true');
        expect(listbox().attributes('id')).toBe(input().attributes('aria-controls'));
        const options = listbox().findAll('[role="option"]');
        expect(options).toHaveLength(2);

        await input().trigger('keydown', { key: 'ArrowDown' });
        expect(input().attributes('aria-activedescendant')).toBe(options[0].attributes('id'));
        expect(options[0].attributes('aria-selected')).toBe('true');
        expect(options[1].attributes('aria-selected')).toBe('false');

        await input().trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual([
            { code: 'H40.1', description: RESULTS[0].description },
        ]);
        expect(listbox().exists()).toBe(false);
        expect(input().attributes('aria-expanded')).toBe('false');
    });

    it('Esc com a lista aberta fecha só a lista (não chega ao modal); com ela fechada, o Esc segue', async () => {
        const modalEsc = vi.fn();
        document.body.addEventListener('keydown', modalEsc);
        mountPicker();
        await typeQuery('glau');

        await input().trigger('keydown', { key: 'Escape' });
        expect(listbox().exists()).toBe(false);
        expect(modalEsc).not.toHaveBeenCalled();

        await input().trigger('keydown', { key: 'Escape' });
        expect(modalEsc).toHaveBeenCalledTimes(1);
        document.body.removeEventListener('keydown', modalEsc);
    });

    it('Tab para outro campo e clique fora fecham a lista; clique na própria lista não', async () => {
        const other = document.createElement('button');
        document.body.appendChild(other);
        mountPicker();

        await typeQuery('glau');
        listbox().element.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        await nextTick();
        expect(listbox().exists()).toBe(true);

        await input().trigger('blur', { relatedTarget: other });
        expect(listbox().exists()).toBe(false);

        await typeQuery('glauc');
        other.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        await nextTick();
        expect(listbox().exists()).toBe(false);
        other.remove();
    });

    it('região de status anuncia a busca e o total (e quando nada é encontrado)', async () => {
        mountPicker();
        const status = () => wrapper.get('[role="status"]').text();

        await typeQuery('glau');
        expect(status()).toBe('2 resultados');

        respondWith([]);
        await typeQuery('xyzw');
        expect(status()).toBe('Nenhum diagnóstico encontrado.');
    });

    it('vínculos externos: id, rótulo, descrição e aria-invalid vão para o input', () => {
        mountPicker({
            inputId: 'cid-x',
            ariaLabelledby: 'cid-x-label',
            ariaDescribedby: 'cid-x-hint cid-x-error',
            invalid: true,
        });

        expect(input().attributes('id')).toBe('cid-x');
        expect(input().attributes('aria-labelledby')).toBe('cid-x-label');
        expect(input().attributes('aria-describedby')).toBe('cid-x-hint cid-x-error');
        expect(input().attributes('aria-invalid')).toBe('true');
        expect(input().attributes('aria-label')).toBeUndefined();
    });
});

describe('Cid10Picker (chips)', () => {
    const selected = [
        { code: 'H40.1', description: 'Glaucoma', is_primary: true },
        { code: null, custom_diagnosis_id: 'c1', description: 'Pós-operatório tardio', is_primary: false },
    ];

    it('remover e marcar principal têm nome acessível; principal usa aria-pressed', () => {
        mountPicker({ modelValue: selected, primaryToggle: true });

        const removes = wrapper.findAll('[data-test="cid-remove"]');
        expect(removes.map((b) => b.attributes('aria-label'))).toEqual([
            'Remover H40.1 – Glaucoma',
            'Remover Pós-operatório tardio',
        ]);

        const stars = wrapper.findAll('[data-test="cid-primary"]');
        expect(stars[0].attributes('aria-pressed')).toBe('true');
        expect(stars[1].attributes('aria-pressed')).toBe('false');
        expect(stars[1].attributes('aria-label')).toBe('Diagnóstico principal: Pós-operatório tardio');
    });

    it('cores dos chips pelo tema (subtle do Bootstrap), sem cor fixa inline', () => {
        mountPicker({ modelValue: selected, primaryToggle: true });

        const chips = wrapper.findAll('[data-test="cid-chip"]');
        expect(chips[0].classes()).toContain('bg-warning-subtle');
        expect(chips[1].classes()).toContain('bg-primary-subtle');
        chips.forEach((chip) => expect(chip.attributes('style') ?? '').not.toMatch(/#[0-9a-f]{3,6}/i));
    });
});

describe('Cid10Picker (idioma)', () => {
    it('textos vêm de t_ui.cid10 (idioma do usuário)', async () => {
        page.props = { t_ui: { cid10: EN } };
        respondWith([{ custom_diagnosis_id: 'c9', code: null, description: 'Custom thing' }]);
        mountPicker({ allowCustomEntry: true, modelValue: [{ code: 'H40.1', description: 'Glaucoma' }] });

        expect(input().attributes('placeholder')).toBe(EN.placeholder);
        expect(wrapper.get('[data-test="cid-remove"]').attributes('aria-label')).toBe('Remove H40.1 – Glaucoma');

        await typeQuery('thing x');
        expect(listbox().text()).toContain('Custom');
        expect(wrapper.get('[data-test="cid-create"]').text()).toContain("Add new diagnosis: 'thing x'");
        expect(wrapper.get('[role="status"]').text()).toBe('1 result');
    });

    it('placeholder do pai tem prioridade; sem t_ui cai no português', () => {
        mountPicker({ placeholder: 'Buscar CID da guia' });
        expect(input().attributes('placeholder')).toBe('Buscar CID da guia');

        wrapper.unmount();
        mountPicker();
        expect(input().attributes('placeholder')).toBe('Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…');
    });
});

describe('CidField (guias do faturamento)', () => {
    it('rótulo, dica e erro chegam ao input do picker (antes: só no grupo)', () => {
        wrapper = mount(CidField, {
            attachTo: document.body,
            props: {
                id: 'bf-cid',
                searchUrl: '/cid10',
                label: 'CID (indicação clínica)',
                hint: 'Letra + 2 dígitos',
                error: 'CID inválido',
            },
        });

        const field = wrapper.get('input');
        expect(field.attributes('id')).toBe('bf-cid-input');
        expect(wrapper.get(`#${field.attributes('aria-labelledby')}`).text()).toBe('CID (indicação clínica)');
        expect(wrapper.get('label[for="bf-cid-input"]').exists()).toBe(true);
        expect(field.attributes('aria-describedby')).toBe('bf-cid-hint bf-cid-error');
        expect(field.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('[data-test="bf-cid-field"]').attributes('aria-invalid')).toBeUndefined();
    });
});
