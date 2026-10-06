import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import ContactLensCalculatorModal from '@/Pages/Panel/MedicalRecords/Components/ContactLensCalculatorModal.vue';
import MedicalRecordViewModal from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordViewModal.vue';
import PreviousRecordsCard from '@/Pages/Panel/MedicalRecords/Components/PreviousRecordsCard.vue';
import {
    CONTACT_LENS_NOTES,
    CONTACT_LENS_PROFILES,
    CONTACT_LENS_VERSION,
    TORIC_MIN_CYLINDER,
    VERTEX_THRESHOLD,
    calculateEye,
    computeContactLens,
    contactLensCompact,
    contactLensInputs,
    contactLensInvalid,
    contactLensSummary,
    formatLens,
    formatPower,
    formatVertexMm,
    isLegacyContactLens,
    omitUnchangedContactLens,
    refractionFromRecord,
    snapAxis,
    snapCylinder,
    snapSphere,
} from '@/Pages/Panel/MedicalRecords/Components/contactLens.js';

/**
 * Lente de contato v2 no prontuário: refração → cálculo → potência de LC
 * SUGERIDA (grade real das lentes), tórica quando o astigmatismo pede. O PHP
 * (ContactLensCalculator) tem de dar exatamente os mesmos números: os dois
 * lados leem tests/fixtures/contact_lens_v2_cases.json.
 *
 * Idioma da tela vem do Inertia (props.locale) — simulado aqui para testar
 * pt-BR e inglês.
 */
const page = vi.hoisted(() => ({ props: { locale: 'pt_BR' } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal()), usePage: () => page }));

const fixture = JSON.parse(readFileSync(resolve(process.cwd(), 'tests/fixtures/contact_lens_v2_cases.json'), 'utf8'));

// Cálculo gravado pela v2: OD tórica (vértice), OE esférica sem vértice.
const calc = computeContactLens({
    vertex_distance_mm: 12,
    profile: 'standard',
    lens_mode: 'auto',
    od: { sphere: -5, cylinder: -2, axis: 180 },
    oe: { sphere: -2.5, cylinder: null, axis: null },
});

// Gravado pela versão 1 (vértice só no esférico + equivalente esférico separado).
const legacy = {
    version: 1,
    vertex_distance_mm: 12,
    vertex_od: -6,
    vertex_oe: 6,
    vertex_od_result: -5.6,
    vertex_oe_result: 6.47,
    se_od_sphere: -2,
    se_od_cylinder: -1,
    se_od_result: -2.5,
    se_oe_sphere: null,
    se_oe_cylinder: null,
    se_oe_result: null,
};

const mountModal = (props = {}) =>
    mount(ContactLensCalculatorModal, {
        props: { open: true, t: {}, ...props },
        global: { stubs: { teleport: true } },
    });

const text = (wrapper, selector) => wrapper.find(selector).text().replace(/\s+/g, ' ').trim();

async function typeEye(wrapper, eye, { sphere, cylinder, axis } = {}) {
    if (sphere !== undefined) await wrapper.find(`#clc-${eye}-sphere`).setValue(String(sphere));
    if (cylinder !== undefined) await wrapper.find(`#clc-${eye}-cylinder`).setValue(String(cylinder));
    if (axis !== undefined) await wrapper.find(`#clc-${eye}-axis`).setValue(String(axis));
}

afterEach(() => {
    vi.unstubAllGlobals();
    page.props.locale = 'pt_BR';
});

describe('Lógica clínica — vetores compartilhados com o PHP (fixture)', () => {
    it('perfis, avisos e constantes são os mesmos do fixture (e, por ele, do PHP)', () => {
        expect(JSON.parse(JSON.stringify(CONTACT_LENS_PROFILES))).toEqual(fixture.profiles);
        expect([...CONTACT_LENS_NOTES]).toEqual(fixture.note_codes);
        expect(CONTACT_LENS_VERSION).toBe(fixture.version);
        expect(VERTEX_THRESHOLD).toBe(fixture.vertex_threshold);
        expect(TORIC_MIN_CYLINDER).toBe(fixture.toric_min_cylinder);
    });

    it.each(fixture.cases.map((c) => [c.name, c]))('%s', (_, c) => {
        expect(calculateEye(c.input.eye, c.input)).toEqual(c.expected);
    });

    it.each(fixture.sphere_grid_cases.map((c) => [`${c.profile}/${c.lens} ${c.value}`, c]))(
        'grade do esférico: %s',
        (_, c) => {
            expect(snapSphere(c.value, CONTACT_LENS_PROFILES[c.profile][c.lens].sphere_ranges)).toBe(c.expected);
        },
    );

    it.each(fixture.cylinder_cases.map((c) => [`${c.profile} ${c.value}`, c]))('cilindro de estoque: %s', (_, c) => {
        expect(snapCylinder(c.value, CONTACT_LENS_PROFILES[c.profile].toric.cylinders)).toBe(c.expected);
    });

    it.each(fixture.axis_cases.map((c) => [`${c.axis}° passo ${c.step}`, c]))('eixo: %s', (_, c) => {
        expect(snapAxis(c.axis, c.step)).toBe(c.expected);
    });
});

describe('contactLens.js — regras explícitas', () => {
    const eye = (sphere, cylinder = null, axis = null, options = {}) =>
        calculateEye({ sphere, cylinder, axis }, { vertex_distance_mm: 12, ...options });

    it('vértice só acima de ±4,00 D (o -2,43 da tela antiga vinha de aplicar abaixo de 4 D)', () => {
        expect(eye(-2.5)).toMatchObject({
            vertex_applied: false,
            theoretical: { se: -2.5 },
            suggested: { sphere: -2.5 },
        });
        expect(eye(-4)).toMatchObject({ vertex_applied: false, suggested: { sphere: -4 } });
        expect(eye(-4.5)).toMatchObject({
            vertex_applied: true,
            theoretical: { se: -4.27 },
            suggested: { sphere: -4.25 },
        });
    });

    it('vértice por meridiano: -5,00 -2,00 × 180 → -4,72 / -1,74 → tórica -4,75 / -1,75 × 180', () => {
        expect(eye(-5, -2, 180)).toEqual({
            type: 'toric',
            theoretical: { sphere: -4.72, cylinder: -1.74, axis: 180, se: -5.59 },
            suggested: { sphere: -4.75, cylinder: -1.75, axis: 180 },
            vertex_applied: true,
            notes: [],
        });
    });

    it('cilindro positivo é transposto (S + C, −C, eixo ± 90)', () => {
        expect(eye(1, 1.5, 90).theoretical).toEqual({ sphere: 2.5, cylinder: -1.5, axis: 180, se: 1.75 });
        expect(eye(1, 1.5, 30).theoretical.axis).toBe(120);
    });

    it('modos: esférica força o EE; tórica sem cilindro avisa e fica esférica', () => {
        expect(eye(-2.5, -1.25, 180, { lens_mode: 'spherical' })).toMatchObject({
            type: 'spherical',
            suggested: { sphere: -3, cylinder: null, axis: null },
        });
        expect(eye(-3, null, null, { lens_mode: 'toric' })).toMatchObject({
            type: 'spherical',
            notes: ['no_cylinder'],
        });
        expect(eye(-2, -0.5, 90, { lens_mode: 'toric' }).suggested).toEqual({ sphere: -2, cylinder: -0.75, axis: 90 });
    });

    it('tórica sem eixo: sugere esférico/cilindro e pede o eixo', () => {
        expect(eye(-2.5, -1.25)).toMatchObject({
            type: 'toric',
            suggested: { sphere: -2.5, cylinder: -1.25, axis: null },
            notes: ['axis_missing'],
        });
    });

    it('fora da faixa: sem sugestão do componente + aviso; a faixa estendida resolve', () => {
        expect(eye(-16)).toMatchObject({ suggested: null, notes: ['out_of_range'] });
        expect(eye(-16, null, null, { profile: 'extended' }).suggested.sphere).toBe(-13.5);
        expect(eye(-4, -4, 10)).toMatchObject({ suggested: { cylinder: null }, notes: ['cylinder_out_of_range'] });
        expect(eye(-4, -4, 10, { profile: 'extended' }).suggested).toEqual({
            sphere: -3.75,
            cylinder: -3.25,
            axis: 10,
        });
    });

    it('sem esférico ou com cilindro que não é número: sem resultado (nunca ignora o cilindro)', () => {
        expect(eye(null, -1)).toBeNull();
        expect(eye('', -1)).toBeNull();
        expect(calculateEye({ sphere: -2, cylinder: 'abc' })).toBeNull();
        expect(calculateEye({ sphere: '-2,50', cylinder: '  ' })).toMatchObject({ suggested: { sphere: -2.5 } });
    });

    it('cálculo gravado: version 2 com entradas e resultados; nada digitado → null', () => {
        expect(calc).toEqual({
            version: 2,
            vertex_distance_mm: 12,
            profile: 'standard',
            lens_mode: 'auto',
            od: { sphere: -5, cylinder: -2, axis: 180 },
            oe: { sphere: -2.5, cylinder: null, axis: null },
            results: { od: eye(-5, -2, 180), oe: eye(-2.5) },
        });
        expect(computeContactLens({ vertex_distance_mm: 12 })).toBeNull();
        expect(computeContactLens({ od: { cylinder: -1 } })).toBeNull();
        // Distância vazia/0 = 12 mm; perfil/modo desconhecidos = padrão.
        expect(
            computeContactLens({ vertex_distance_mm: 0, profile: 'x', lens_mode: 'y', od: { sphere: -6 } }),
        ).toMatchObject({ vertex_distance_mm: 12, profile: 'standard', lens_mode: 'auto' });
    });

    it('faixas do servidor conferidas antes de salvar (2 casas, eixo inteiro 0–180)', () => {
        expect(contactLensInvalid({ vertex_distance_mm: 0, od: { sphere: -6 } })).toEqual([]);
        expect(
            contactLensInvalid({
                vertex_distance_mm: 3,
                od: { sphere: -41, cylinder: -2, axis: 181 },
                oe: { sphere: -5.385, cylinder: 16, axis: 90.5 },
            }),
        ).toEqual(['vertex_distance_mm', 'od.sphere', 'od.axis', 'oe.sphere', 'oe.cylinder', 'oe.axis']);
    });

    it('edição: cálculo não alterado (inclusive v1) sai do payload; alterado ou removido vai', () => {
        const data = { main_complaint: 'Retorno', contact_lens_calculation: { ...legacy } };

        expect(omitUnchangedContactLens(data, legacy)).toEqual({ main_complaint: 'Retorno' });
        expect(omitUnchangedContactLens({ contact_lens_calculation: calc }, legacy)).toEqual({
            contact_lens_calculation: calc,
        });
        expect(omitUnchangedContactLens({ contact_lens_calculation: null }, calc)).toEqual({
            contact_lens_calculation: null,
        });
        expect(omitUnchangedContactLens({ contact_lens_calculation: null }, null)).toEqual({});
    });

    it('reabrir: v2 volta às entradas; v1 traz o que der (esférico/cilindro) para gravar v2', () => {
        expect(contactLensInputs(calc)).toEqual({
            vertex_distance_mm: 12,
            profile: 'standard',
            lens_mode: 'auto',
            od: { sphere: -5, cylinder: -2, axis: 180 },
            oe: { sphere: -2.5, cylinder: null, axis: null },
        });
        expect(contactLensInputs(legacy)).toMatchObject({
            od: { sphere: -2, cylinder: -1, axis: null }, // o do EE (com cilindro) vence o do vértice
            oe: { sphere: 6, cylinder: null, axis: null }, // só tinha o do vértice
        });
        expect(contactLensInputs(null).od).toEqual({ sphere: null, cylinder: null, axis: null });
        expect(isLegacyContactLens(legacy)).toBe(true);
        expect(isLegacyContactLens(calc)).toBe(false);
    });

    it('copiar refração traz esférico, cilindro e EIXO ("180º", "-0,75")', () => {
        expect(
            refractionFromRecord(
                {
                    dynamic_spherical_right: '+1.50',
                    dynamic_cylindrical_right: '-0,75',
                    dynamic_axis_right: '180º',
                    dynamic_spherical_left: '0.00',
                    dynamic_cylindrical_left: '',
                    dynamic_axis_left: '',
                },
                'dynamic',
            ),
        ).toEqual({
            od: { sphere: 1.5, cylinder: -0.75, axis: 180 },
            oe: { sphere: 0, cylinder: null, axis: null },
            unreadable: [],
        });
    });

    it('refração com texto que não é número ("PL", eixo "90.5") é sinalizada, não vira vazio', () => {
        const r = refractionFromRecord(
            { dynamic_spherical_right: 'PL', dynamic_cylindrical_right: '-1.00', dynamic_axis_right: '90.5' },
            'dynamic',
        );

        expect(r.unreadable).toEqual(['spherical_right', 'axis_right']);
        expect(r.od).toEqual({ sphere: null, cylinder: -1, axis: null });
    });

    it('bloco de refração em branco (tudo "0.00"/"0°", como o servidor) não vira plano', () => {
        expect(
            refractionFromRecord(
                {
                    static_spherical_right: '0.00',
                    static_cylindrical_right: '0,00',
                    static_axis_right: '0°',
                    static_spherical_left: '+0.00',
                    static_cylindrical_left: '',
                    static_axis_left: '0º',
                },
                'static',
            ),
        ).toBeNull();
        expect(refractionFromRecord({}, 'dynamic')).toBeNull();
    });
});

describe('Formatação — no idioma, sinal explícito, incrementos de lente', () => {
    it('dioptria: pt-BR com vírgula, en com ponto, sempre com sinal (zero sem sinal)', () => {
        expect(formatPower(-2.5, 'pt-BR')).toBe('−2,50');
        expect(formatPower(5.25, 'pt_BR')).toBe('+5,25');
        expect(formatPower(-2.5, 'en')).toBe('−2.50');
        expect(formatPower(0, 'pt-BR')).toBe('0,00');
        expect(formatPower(null)).toBe('—');
        expect(formatVertexMm(12.5)).toBe('12,5');
        expect(formatVertexMm(12.5, 'en')).toBe('12.5');
    });

    it('lente: esférica "−2,50 D", tórica "−4,75 / −1,75 × 180°", plano e componente sem sugestão', () => {
        expect(formatLens({ sphere: -2.5, cylinder: null, axis: null }, 'spherical')).toBe('−2,50 D');
        expect(formatLens({ sphere: -4.75, cylinder: -1.75, axis: 180 }, 'toric')).toBe('−4,75 / −1,75 × 180°');
        expect(formatLens({ sphere: 0, cylinder: null, axis: null }, 'spherical')).toBe('Plano (0,00)');
        expect(formatLens({ sphere: -2.5, cylinder: -1.25, axis: null }, 'toric', { locale: 'en' })).toBe(
            '−2.50 / −1.25 × —',
        );
    });
});

describe('Resumos — potência SUGERIDA por olho (v2) e formato antigo (v1)', () => {
    it('prontuário: "LC sugerida — OD … (tórica) · OE … (esférica)"', () => {
        expect(contactLensSummary(calc, {}, 'pt-BR')).toEqual([
            {
                key: 'suggested',
                label: 'LC sugerida',
                value: 'OD −4,75 / −1,75 × 180° (tórica)  ·  OE −2,50 (esférica)',
            },
        ]);
        expect(contactLensSummary(null)).toEqual([]);
    });

    it('visualização (detalhado): teórico com vértice, linha de lentes e avisos', () => {
        const withNote = computeContactLens({ od: { sphere: -2.5, cylinder: -1.25 }, oe: { sphere: -16 } });
        const rows = contactLensSummary(withNote, {}, 'pt-BR', { detailed: true });

        expect(rows.map((r) => r.key)).toEqual(['suggested', 'theoretical', 'profile', 'notes']);
        expect(rows[0]).toEqual({
            key: 'suggested',
            label: 'Lente de contato sugerida',
            value: 'OD −2,50 / −1,25 × — (tórica)  ·  OE sem lente nesta linha',
        });
        expect(rows[1].label).toBe('Cálculo teórico (vértice 12 mm)');
        expect(rows[1].value).toBe(
            'OD −2,50 / −1,25 × — — sem compensação de vértice (até ±4,00 D)  ·  OE −13,42 D — vértice aplicado (acima de ±4,00 D)',
        );
        expect(rows[2].value).toBe('Padrão de mercado');
        expect(rows[3].value).toBe(
            'OD: Informe o eixo para completar a lente tórica.  ·  OE: Fora da faixa comum — considere a faixa estendida ou consulte o fabricante.',
        );
    });

    it('resumo traduzido (inglês: OS, ponto decimal, rótulos das traduções)', () => {
        const t = {
            od: 'OD',
            oe: 'OS',
            contact_lens_suggested_short: 'Suggested CL',
            contact_lens_type_toric: 'Toric',
            contact_lens_type_spherical: 'Spherical',
        };

        expect(contactLensSummary(calc, t, 'en')[0]).toEqual({
            key: 'suggested',
            label: 'Suggested CL',
            value: 'OD −4.75 / −1.75 × 180° (toric)  ·  OS −2.50 (spherical)',
        });
    });

    it('painel "Consultas anteriores": uma linha "LC" com a sugerida por olho', () => {
        expect(contactLensCompact(calc, {}, 'pt-BR')).toEqual([
            {
                key: 'suggested',
                tag: 'LC',
                label: 'Lente de contato sugerida — Padrão de mercado',
                value: 'OD −4,75 / −1,75 × 180° (tórica) | OE −2,50 (esférica)',
            },
        ]);
        expect(contactLensCompact(null)).toEqual([]);
    });

    it('v1 continua no formato antigo (vértice entrada → resultado; SE)', () => {
        expect(contactLensSummary(legacy, { od: 'OD', oe: 'OS' })).toEqual([
            {
                key: 'vertex',
                label: 'Esférico → lente de contato (vértice 12 mm)',
                value: 'OD: -6.00 → -5.60  ·  OS: +6.00 → +6.47',
            },
            { key: 'se', label: 'Equivalente esférico', value: 'OD: -2.50' },
        ]);
        expect(contactLensSummary({ ...legacy, vertex_distance_mm: 12.5 }, {}, 'pt-BR')[0].label).toBe(
            'Esférico → lente de contato (vértice 12,5 mm)',
        );
        expect(contactLensCompact(legacy, { od: 'OD', oe: 'OS', contact_lens_short: 'CL' })).toEqual([
            {
                key: 'vertex',
                tag: 'CL',
                label: 'Esférico → lente de contato (vértice 12 mm) (versão anterior)',
                value: 'OD -5.60 | OS +6.47',
            },
            { key: 'se', tag: 'SE', label: 'Equivalente esférico (versão anterior)', value: 'OD -2.50' },
        ]);
        // Visualização (detalhado): mesmos valores, rótulo legado.
        expect(
            contactLensSummary(legacy, { contact_lens_legacy: 'previous version' }, 'en', { detailed: true }),
        ).toEqual([
            {
                key: 'vertex',
                label: 'Esférico → lente de contato (vértice 12 mm) (previous version)',
                value: 'OD: -6.00 → -5.60  ·  OE: +6.00 → +6.47',
            },
            { key: 'se', label: 'Equivalente esférico (previous version)', value: 'OD: -2.50' },
        ]);
    });
});

describe('ContactLensCalculatorModal — simples, para usar na consulta', () => {
    it('digita a refração: destaque com a potência sugerida, selo do tipo e teórico pequeno', async () => {
        const wrapper = mountModal();

        await typeEye(wrapper, 'od', { sphere: '-5', cylinder: '-2', axis: '180' });
        await typeEye(wrapper, 'oe', { sphere: '-2.5' });

        expect(text(wrapper, '[data-suggested="od"]')).toBe('−4,75 / −1,75 × 180°');
        expect(text(wrapper, '[data-type="od"]')).toBe('Tórica');
        expect(text(wrapper, '[data-theoretical="od"]')).toBe(
            'Cálculo teórico: −4,72 / −1,74 × 180° · vértice aplicado (acima de ±4,00 D)',
        );
        expect(wrapper.find('[data-result="od"]').classes()).toContain('clc-result--complete');

        expect(text(wrapper, '[data-suggested="oe"]')).toBe('−2,50 D');
        expect(text(wrapper, '[data-type="oe"]')).toBe('Esférica');
        expect(text(wrapper, '[data-theoretical="oe"]')).toBe(
            'Cálculo teórico: −2,50 D · sem compensação de vértice (até ±4,00 D)',
        );
    });

    it('"Usar no prontuário" emite o cálculo v2 (entradas + resultados) e fecha', async () => {
        const wrapper = mountModal();
        await typeEye(wrapper, 'od', { sphere: '-5', cylinder: '-2', axis: '180' });
        await typeEye(wrapper, 'oe', { sphere: '-2.5' });

        await wrapper.find('[data-action="apply"]').trigger('click');

        expect(wrapper.emitted('apply')[0][0]).toEqual(calc);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('sem nada digitado: sem destaque e não deixa usar no prontuário', () => {
        const wrapper = mountModal();

        expect(text(wrapper, '[data-suggested="od"]')).toBe('—');
        expect(wrapper.find('[data-result="od"]').text()).toContain('Digite o esférico da refração.');
        expect(wrapper.find('[data-action="apply"]').attributes('disabled')).toBeDefined();
    });

    it('copia esférico, cilindro e eixo da refração dinâmica ou estática', async () => {
        const wrapper = mountModal({
            record: {
                dynamic_spherical_right: '-2,50',
                dynamic_cylindrical_right: '-1,25',
                dynamic_axis_right: '175º',
                dynamic_spherical_left: '+1.00',
                dynamic_cylindrical_left: '+1.50',
                dynamic_axis_left: '90º',
                static_spherical_right: '-6.00',
                static_cylindrical_right: '0.00',
                static_axis_right: '0º',
                static_spherical_left: '+6.00',
                static_cylindrical_left: '0.00',
                static_axis_left: '0º',
            },
        });

        await wrapper.find('[data-copy="dynamic"]').trigger('click');
        expect(wrapper.find('#clc-od-axis').element.value).toBe('175');
        expect(text(wrapper, '[data-suggested="od"]')).toBe('−2,50 / −1,25 × 180°');
        // Cilindro positivo transposto: +2,50 / −1,50 × 180 → cilindro de estoque −1,25.
        expect(text(wrapper, '[data-suggested="oe"]')).toBe('+2,50 / −1,25 × 180°');

        await wrapper.find('[data-copy="static"]').trigger('click');
        expect(text(wrapper, '[data-suggested="od"]')).toBe('−5,50 D');
        expect(text(wrapper, '[data-suggested="oe"]')).toBe('+6,50 D');
        // Sem cilindro, o "0º" do bloco não vira eixo.
        expect(wrapper.find('#clc-od-axis').element.value).toBe('');

        // A refração copiada fica marcada até o médico editar um olho.
        expect(wrapper.find('[data-copy="static"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('[data-copy="dynamic"]').attributes('aria-pressed')).toBe('false');
        await typeEye(wrapper, 'od', { sphere: '-6.25' });
        expect(wrapper.find('[data-copy="static"]').attributes('aria-pressed')).toBe('false');
    });

    it('refração em branco no prontuário: avisa e não copia nada', async () => {
        const wrapper = mountModal({
            modelValue: calc,
            record: { dynamic_spherical_right: '0.00', dynamic_cylindrical_right: '0.00', dynamic_axis_right: '0°' },
        });

        await wrapper.find('[data-copy="dynamic"]').trigger('click');

        expect(wrapper.find('[data-copy-notice]').text()).toBe('Essa refração ainda não foi preenchida no prontuário.');
        expect(wrapper.find('#clc-od-sphere').element.value).toBe('-5');
        expect(text(wrapper, '[data-suggested="od"]')).toBe('−4,75 / −1,75 × 180°');
    });

    it('refração com valor que não é número: avisa e não copia', async () => {
        const wrapper = mountModal({ record: { dynamic_spherical_right: 'PL', dynamic_cylindrical_right: '-1.00' } });

        await wrapper.find('[data-copy="dynamic"]').trigger('click');

        expect(wrapper.find('[data-copy-notice]').text()).toContain('não é número');
        expect(wrapper.find('#clc-od-cylinder').element.value).toBe('');
    });

    it('opções: linha estendida e tipo de lente mudam a sugestão na hora', async () => {
        const wrapper = mountModal();
        await typeEye(wrapper, 'od', { sphere: '-4', cylinder: '-4', axis: '10' });

        expect(text(wrapper, '[data-suggested="od"]')).toBe('−3,75 / — × 10°');
        expect(wrapper.find('[data-result="od"]').classes()).toContain('clc-result--partial');
        expect(text(wrapper, '[data-notes="od"]')).toBe(
            'Cilindro acima da linha padrão — considere a faixa estendida ou consulte o fabricante.',
        );

        await wrapper.find('#clc-profile').setValue('extended');
        expect(text(wrapper, '[data-suggested="od"]')).toBe('−3,75 / −3,25 × 10°');
        expect(wrapper.find('[data-notes="od"]').exists()).toBe(false);

        await wrapper.find('#clc-mode').setValue('spherical');
        expect(text(wrapper, '[data-type="od"]')).toBe('Esférica');
        expect(text(wrapper, '[data-suggested="od"]')).toBe('−5,50 D');
        expect(text(wrapper, '[data-theoretical="od"]')).toContain('−5,56 D (equivalente esférico)');

        await wrapper.find('[data-action="apply"]').trigger('click');
        expect(wrapper.emitted('apply')[0][0]).toMatchObject({ profile: 'extended', lens_mode: 'spherical' });
    });

    it('avisos curtos: fora da faixa, informe o eixo, plano', async () => {
        const wrapper = mountModal();
        await typeEye(wrapper, 'od', { sphere: '-16' });
        await typeEye(wrapper, 'oe', { sphere: '-2.5', cylinder: '-1.25' });

        expect(text(wrapper, '[data-suggested="od"]')).toBe('Sem lente nesta linha');
        expect(wrapper.find('[data-result="od"]').classes()).toContain('clc-result--none');
        expect(text(wrapper, '[data-notes="od"]')).toContain('Fora da faixa comum');
        expect(text(wrapper, '[data-suggested="oe"]')).toBe('−2,50 / −1,25 × —');
        expect(text(wrapper, '[data-notes="oe"]')).toBe('Informe o eixo para completar a lente tórica.');

        await typeEye(wrapper, 'od', { sphere: '0.12' });
        expect(text(wrapper, '[data-suggested="od"]')).toBe('Plano (0,00)');
        expect(text(wrapper, '[data-notes="od"]')).toBe('Plano: confira se o modelo escolhido tem potência 0,00.');
    });

    it('em inglês: textos das traduções e números com ponto', async () => {
        page.props.locale = 'en';
        const wrapper = mountModal({
            t: {
                contact_lens_title: 'Contact lens calculation',
                contact_lens_suggested: 'Suggested contact lens',
                contact_lens_type_spherical: 'Spherical',
                contact_lens_theoretical: 'Theoretical value',
                contact_lens_vertex_not_applied: 'no vertex compensation (up to ±4.00 D)',
                od: 'OD',
                oe: 'OS',
            },
        });
        await typeEye(wrapper, 'oe', { sphere: '-2.5' });

        expect(wrapper.find('#contactLensCalcTitle').text()).toBe('Contact lens calculation');
        expect(text(wrapper, '[data-suggested="oe"]')).toBe('−2.50 D');
        expect(text(wrapper, '[data-type="oe"]')).toBe('Spherical');
        expect(text(wrapper, '[data-theoretical="oe"]')).toBe(
            'Theoretical value: −2.50 D · no vertex compensation (up to ±4.00 D)',
        );
        expect(text(wrapper, '[data-result="oe"]')).toContain('OS: Suggested contact lens');
    });

    it('fora da faixa aceita pelo servidor (ou eixo fracionado): destaca e bloqueia "Usar"', async () => {
        const wrapper = mountModal();
        await typeEye(wrapper, 'od', { sphere: '-50' });

        expect(wrapper.find('#clc-od-sphere').classes()).toContain('is-invalid');
        expect(wrapper.find('#clc-od-sphere').attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('[data-invalid]').exists()).toBe(true);
        expect(wrapper.find('[data-action="apply"]').attributes('disabled')).toBeDefined();

        await typeEye(wrapper, 'od', { sphere: '-2', cylinder: '-1', axis: '90.5' });
        expect(wrapper.find('#clc-od-axis').classes()).toContain('is-invalid');
        expect(wrapper.find('[data-action="apply"]').attributes('disabled')).toBeDefined();

        await typeEye(wrapper, 'od', { axis: '90', cylinder: '-1.125' });
        expect(wrapper.find('#clc-od-cylinder').classes()).toContain('is-invalid');
        expect(wrapper.find('[data-action="apply"]').attributes('disabled')).toBeDefined();
    });

    it('texto que o campo numérico não reconhece não vira "vazio": destaca e bloqueia "Usar"', async () => {
        const wrapper = mountModal();
        await typeEye(wrapper, 'od', { sphere: '-2' });
        // Para "-1,5-" num type=number o navegador entrega '' com validity.badInput.
        // Busca o campo de novo depois do evento: o stub de Teleport recria o elemento.
        const typeCylinder = async (value, badInput) => {
            const field = wrapper.find('#clc-od-cylinder');
            Object.defineProperty(field.element, 'validity', { configurable: true, value: { badInput } });
            await field.setValue(value);
            return wrapper.find('#clc-od-cylinder');
        };

        let cyl = await typeCylinder('', true);

        expect(cyl.classes()).toContain('is-invalid');
        expect(cyl.attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('[data-action="apply"]').attributes('disabled')).toBeDefined();

        // Nova digitação válida limpa a marca e o cálculo volta a considerar o cilindro.
        cyl = await typeCylinder('-1', false);

        expect(cyl.classes()).not.toContain('is-invalid');
        expect(wrapper.find('[data-action="apply"]').attributes('disabled')).toBeUndefined();
        expect(text(wrapper, '[data-suggested="od"]')).toBe('−2,00 / −0,75 × —');
    });

    it('acessibilidade: campos rotulados com o olho, resultado anunciado, grupos rotulados', () => {
        const wrapper = mountModal();

        for (const eye of ['od', 'oe']) {
            for (const field of ['sphere', 'cylinder', 'axis']) {
                const label = wrapper.find(`label[for="clc-${eye}-${field}"]`);
                expect(label.exists()).toBe(true);
                expect(label.text()).toMatch(eye === 'od' ? /^OD / : /^OE /);
            }
            const result = wrapper.find(`[data-result="${eye}"]`);
            expect(result.attributes('aria-live')).toBe('polite');
            expect(result.attributes('aria-atomic')).toBe('true');
            expect(wrapper.find(`[data-eye="${eye}"]`).attributes('aria-labelledby')).toBe(`clc-${eye}-title`);
        }
        expect(wrapper.find('label[for="clc-distance"]').exists()).toBe(true);
        expect(wrapper.find('label[for="clc-mode"]').exists()).toBe(true);
        expect(wrapper.find('label[for="clc-profile"]').exists()).toBe(true);
        expect(wrapper.find('[data-options]').attributes('aria-label')).toBe('Opções do cálculo');
        expect(wrapper.find('[role="group"]').attributes('aria-labelledby')).toBe('clc-copy-label');
        expect(wrapper.find('#clc-copy-label').text()).toBe('Copiar refração:');
    });

    it('tela simples: sem as seções técnicas antigas; rodapé de lente de teste e LIO fora de escopo', () => {
        const wrapper = mountModal();

        expect(wrapper.text()).not.toContain('Conversão de distância ao vértice');
        expect(wrapper.find('h6.fw-semibold').exists()).toBe(false);
        expect(wrapper.find('[data-footnote]').text()).toContain(
            'Sugestão para lente de teste — confirme com sobre-refração e com a tabela do fabricante.',
        );
        expect(wrapper.find('[data-footnote]').text()).toContain('Não calcula LIO');
    });

    it('reabre com o cálculo gravado e permite remover do prontuário', async () => {
        const wrapper = mountModal({ modelValue: calc });

        expect(wrapper.find('#clc-od-sphere').element.value).toBe('-5');
        expect(wrapper.find('#clc-od-axis').element.value).toBe('180');
        expect(text(wrapper, '[data-suggested="oe"]')).toBe('−2,50 D');

        await wrapper.find('[data-action="remove"]').trigger('click');
        expect(wrapper.emitted('remove')).toHaveLength(1);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('prontuário assinado: só consulta, mostrando o resultado GRAVADO (sem recalcular)', () => {
        const signed = {
            ...calc,
            results: {
                ...calc.results,
                od: { ...calc.results.od, suggested: { sphere: -4.5, cylinder: -1.75, axis: 180 } },
            },
        };
        const wrapper = mountModal({
            modelValue: signed,
            readonly: true,
            t: { contact_lens_locked: 'Signed record: calculation is read-only.' },
        });

        expect(wrapper.find('fieldset').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Signed record: calculation is read-only.');
        expect(wrapper.find('[data-action="apply"]').exists()).toBe(false);
        expect(wrapper.find('[data-action="remove"]').exists()).toBe(false);
        expect(wrapper.find('[data-copy="dynamic"]').exists()).toBe(false);
        expect(text(wrapper, '[data-suggested="od"]')).toBe('−4,50 / −1,75 × 180°');
    });

    it('assinado com cálculo da versão 1: mostra como está (formato antigo), sem calculadora', () => {
        const wrapper = mountModal({ modelValue: legacy, readonly: true });

        expect(wrapper.find('[data-legacy]').text()).toContain('(versão anterior)');
        expect(wrapper.find('[data-legacy]').text()).toContain('OD: -6.00 → -5.60');
        expect(wrapper.find('[data-eye="od"]').exists()).toBe(false);
    });

    it('edição de prontuário com v1: traz os valores, avisa e, ao usar, grava v2', async () => {
        const wrapper = mountModal({ modelValue: legacy });

        expect(wrapper.find('[data-legacy]').text()).toContain('versão anterior da calculadora');
        expect(wrapper.find('#clc-od-sphere').element.value).toBe('-2');
        expect(wrapper.find('#clc-od-cylinder').element.value).toBe('-1');
        expect(wrapper.find('#clc-oe-sphere').element.value).toBe('6');
        expect(text(wrapper, '[data-suggested="od"]')).toBe('−2,00 / −0,75 × —');

        await typeEye(wrapper, 'od', { axis: '180' });
        await wrapper.find('[data-action="apply"]').trigger('click');

        const applied = wrapper.emitted('apply')[0][0];
        expect(applied.version).toBe(2);
        expect(applied.od).toEqual({ sphere: -2, cylinder: -1, axis: 180 });
        expect(applied.results.oe.suggested).toEqual({ sphere: 6.5, cylinder: null, axis: null });
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

        // Ao abrir, o foco vai para o título do diálogo (não para um botão de ação).
        await flushPromises();
        expect(document.activeElement?.id).toBe('contactLensCalcTitle');

        document.body.focus();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('close')).toHaveLength(1);

        await wrapper.setProps({ open: false });
        expect(document.activeElement).toBe(opener);

        wrapper.unmount();
        opener.remove();
    });

    it('Esc dado dentro de outro diálogo aberto por cima não fecha a calculadora', async () => {
        const wrapper = mount(ContactLensCalculatorModal, {
            props: { open: true, t: {} },
            global: { stubs: { teleport: true } },
            attachTo: document.body,
        });
        await flushPromises();
        const other = document.createElement('div');
        other.setAttribute('role', 'dialog');
        const field = document.createElement('input');
        other.appendChild(field);
        document.body.appendChild(other);

        field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(wrapper.emitted('close')).toBeUndefined();

        wrapper
            .find('#clc-od-sphere')
            .element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(wrapper.emitted('close')).toHaveLength(1);

        wrapper.unmount();
        other.remove();
    });
});

describe('Consulta posterior — visualização do prontuário e painel lateral', () => {
    const mountView = async (contactLens) => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: true,
                json: async () => ({
                    main_complaint: 'Adaptação de lente de contato',
                    contact_lens_calculation: contactLens,
                }),
            })),
        );
        const wrapper = mount(MedicalRecordViewModal, {
            props: { open: true, record: { show_url: '/panel/patients/1/medicalrecords/1' }, t: {} },
            global: { stubs: { teleport: true } },
        });
        await flushPromises();
        return wrapper;
    };

    it('v2: sugerida por olho em destaque + teórico, linha de lentes', async () => {
        const wrapper = await mountView(calc);
        const value = (label) =>
            wrapper
                .findAll('dt')
                .find((dt) => dt.text() === label)
                ?.element.nextElementSibling?.textContent.replace(/\s+/g, ' ')
                .trim();

        expect(value('Lente de contato sugerida')).toBe('OD −4,75 / −1,75 × 180° (tórica) · OE −2,50 (esférica)');
        expect(value('Cálculo teórico (vértice 12 mm)')).toBe(
            'OD −4,72 / −1,74 × 180° — vértice aplicado (acima de ±4,00 D) · OE −2,50 D — sem compensação de vértice (até ±4,00 D)',
        );
        expect(value('Linha de lentes')).toBe('Padrão de mercado');
    });

    it('v1: formato antigo, como estava', async () => {
        const wrapper = await mountView(legacy);

        expect(wrapper.text()).toContain('Esférico → lente de contato (vértice 12 mm) (versão anterior)');
        expect(wrapper.text()).toMatch(/OD: -6\.00 → -5\.60\s+·\s+OE: \+6\.00 → \+6\.47/);
        expect(wrapper.text()).toMatch(/Equivalente esférico \(versão anterior\)\s*OD: -2\.50/);
    });

    it('painel "Consultas anteriores": v2 numa linha "LC"; v1 no formato antigo; sem cálculo, nada', () => {
        const wrapper = mount(PreviousRecordsCard, {
            props: {
                t: {},
                records: [
                    {
                        id: 'a',
                        created_at_formatted: '01/10/2026',
                        doctor_name: 'Dra. A',
                        summary: { contact_lens: calc },
                    },
                    {
                        id: 'b',
                        created_at_formatted: '01/09/2026',
                        doctor_name: 'Dr. B',
                        summary: { contact_lens: legacy },
                    },
                    {
                        id: 'c',
                        created_at_formatted: '01/08/2026',
                        doctor_name: 'Dr. C',
                        summary: { contact_lens: null },
                    },
                ],
            },
        });

        const [v2, v1, none] = wrapper.findAll('li');
        const v2Lines = v2.findAll('[data-contact-lens]');

        expect(v2Lines).toHaveLength(1);
        expect(v2Lines[0].find('.prev-records__tag').text()).toBe('LC');
        expect(v2Lines[0].text()).toContain('OD −4,75 / −1,75 × 180° (tórica) | OE −2,50 (esférica)');
        expect(v2Lines[0].attributes('title')).toBe('Lente de contato sugerida — Padrão de mercado');

        expect(v1.findAll('[data-contact-lens]').map((line) => line.find('.prev-records__tag').text())).toEqual([
            'LC',
            'SE',
        ]);
        expect(v1.find('[data-contact-lens="vertex"]').text()).toContain('OD -5.60 | OE +6.47');
        expect(none.find('[data-contact-lens]').exists()).toBe(false);
    });

    it('painel em inglês: sigla, olhos e números do idioma', () => {
        const wrapper = mount(PreviousRecordsCard, {
            props: {
                t: { od: 'OD', oe: 'OS', contact_lens_short: 'CL', contact_lens_type_toric: 'Toric' },
                records: [{ id: 'a', created_at_formatted: '01/10/2026', summary: { contact_lens: calc } }],
            },
        });
        page.props.locale = 'en';
        const english = mount(PreviousRecordsCard, {
            props: {
                t: { od: 'OD', oe: 'OS', contact_lens_short: 'CL', contact_lens_type_toric: 'Toric' },
                records: [{ id: 'a', created_at_formatted: '01/10/2026', summary: { contact_lens: calc } }],
            },
        });

        expect(wrapper.find('[data-contact-lens] .prev-records__tag').text()).toBe('CL');
        expect(english.find('[data-contact-lens]').text()).toContain('OD −4.75 / −1.75 × 180° (toric)');
        expect(english.find('[data-contact-lens]').text()).toContain('OS −2.50');
    });
});

describe('SSR (o Inertia renderiza o prontuário no servidor)', () => {
    it.each([false, true])('o modal renderiza sem document e sem erro (open=%s)', async (open) => {
        const errors = [];
        const app = createSSRApp({ render: () => h(ContactLensCalculatorModal, { open, t: {} }) });
        // O watcher é async: no servidor o erro não derruba o render, vai para o errorHandler (log).
        app.config.errorHandler = (err) => errors.push(err);

        // No servidor não existe document; o watcher `immediate` roda lá.
        vi.stubGlobal('document', undefined);
        await expect(renderToString(app, {})).resolves.toBeTypeOf('string');
        await flushPromises();

        expect(errors).toEqual([]);
    });
});

describe('Local de acesso: Prontuário (não mais o Gerenciador de Imagens)', () => {
    const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8');
    const form = read('resources/js/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue');

    it('prontuário: botão só para médico, resultado no form e modal travado quando assinado', () => {
        expect(form).toContain('contact_lens_calculation: r?.contact_lens_calculation ?? null');
        expect(form).toMatch(
            /<button\s+v-if="isDoctor"[^>]*\s+data-contact-lens-open\s+:disabled="isLocked && !contactLensRows\.length"/,
        );
        expect(form).toMatch(
            /<ContactLensCalculatorModal[^>]*:readonly="isLocked"[^>]*@apply="applyContactLens"[^>]*@remove="removeContactLens"/,
        );
        expect(form).toMatch(/v-if="contactLensRows\.length"[^>]*data-contact-lens-summary/);
        // v1 no resumo do prontuário: marcado como versão anterior.
        expect(form).toContain("(${tt('contact_lens_legacy', 'versão anterior')})");
        expect(form).toMatch(/data-contact-lens-summary>\s*<span class="pmr-label d-block">\{\{ contactLensTitle \}\}/);
        // Edição não reenvia cálculo inalterado (valor legado não trava o save).
        expect(form).toMatch(
            /props\.isEdit \? omitUnchangedContactLens\(data, props\.medicalrecord\?\.contact_lens_calculation\)/,
        );
    });

    it('Gerenciador de Imagens não tem mais a calculadora', () => {
        const eyeImages = read('resources/js/Pages/Panel/EyeImages/Index.vue');

        expect(eyeImages).not.toContain('LensCalculatorModal');
        expect(eyeImages).not.toContain('lens_calc_');
        expect(existsSync(resolve(process.cwd(), 'resources/js/Pages/Panel/EyeImages/LensCalculatorModal.vue'))).toBe(
            false,
        );
    });
});
