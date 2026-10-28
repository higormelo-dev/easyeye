import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import MedicalRecordViewModal from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordViewModal.vue';
import PreviousRecordsCard from '@/Pages/Panel/MedicalRecords/Components/PreviousRecordsCard.vue';
import MedicalRecordDetailDrawer from '@/Pages/Panel/MedicalRecords/MedicalRecordDetailDrawer.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import { computeContactLens } from '@/Pages/Panel/MedicalRecords/Components/contactLens.js';

/**
 * Visualização da consulta (modal, drawer da lista e painel "Consultas
 * anteriores"): todo rótulo vem das traduções (`t` = actions.medical_records).
 *
 * `t` aqui devolve «chave» para qualquer chave pedida — rótulo que aparece
 * sem «» é texto fixo no componente. As chaves usadas são conferidas nos dois
 * idiomas por tests/Feature/MedicalRecord/RecordViewTranslationsTest.php.
 */
const keyT = () =>
    new Proxy(
        {},
        {
            get: (_, key) => (typeof key === 'string' && /^[a-z][a-z0-9_]*$/.test(key) ? `«${key}»` : undefined),
        },
    );

const KEY = /^«[a-z0-9_]+»:?$/;

// Rótulos que eram fixos em português (os dados do fixture são em inglês).
const PORTUGUESE =
    /\b(Queixa|HDA|Cirurgias|Medica|Diab|Hipertenso|Glaucomatoso|familiar|Acuidade|Motilidade|Tonometria|Horário|Paquimetria|Gonioscopia|Cover|Visão|Ponto|Esférico|Cilíndrico|Eixo|Adição|Lente|Biomicroscopia|Fundoscopia|Observação|Conduta|Retorno|Anamnese|Exame|Refração|Achados|Diagnóstico|IA|OE|AV|Ref|Ad|PIO|Assinado|Assinatura|Informações|Documentos|Hora|Tipo|Título|Ações|dias|Carregando|Ver|Editar|Visualizar|Prontuário|Sim|Não|por|travado|Nenhum)\b/;

const detail = {
    code: 'PR-1',
    created_at_formatted: '01/09/2026 10:00',
    doctor_name: 'Dr. Smith',
    main_complaint: 'Blurred vision',
    hda: 'Two weeks',
    ocular_surgical_history: 'LASIK',
    medications_in_use: 'Timolol',
    diabetic: false,
    diabetic_family: true,
    hypertensive: true,
    hypertensive_family: false,
    glaucomatous: true,
    glaucomatous_family: true,
    visual_acuity_type: 'Snellen',
    ocular_motility: 'Full',
    tonometer_right: 14,
    tonometer_left: 15,
    tonometer_time: '10:00',
    pachymetry_right: 540,
    pachymetry_left: 545,
    gonioscopy_right: 'Open',
    gonioscopy_left: 'Open',
    cover_test_type: 'Ortho',
    color_vision_type: 'Normal',
    near_point_convergence: '6 cm',
    visual_acuity_without_correction_right: '20/40',
    visual_acuity_without_correction_left: '20/50',
    visual_acuity_with_correction_right: '20/20',
    visual_acuity_with_correction_left: '20/25',
    dynamic_spherical_right: '-1.50',
    dynamic_spherical_left: '-1.25',
    dynamic_cylindrical_right: '-0.50',
    dynamic_cylindrical_left: '-0.25',
    dynamic_axis_right: '180',
    dynamic_axis_left: '10',
    static_spherical_right: '-1.25',
    static_spherical_left: '-1.00',
    static_cylindrical_right: '-0.50',
    static_cylindrical_left: '-0.25',
    static_axis_right: '175',
    static_axis_left: '15',
    addition_type: '+2.00',
    lens_away: 'Single vision',
    lens_near: 'Bifocal',
    biomicroscopy_right: 'Clear',
    biomicroscopy_left: 'Clear',
    fundoscopy_right: 'Normal',
    fundoscopy_left: 'Normal',
    observation_general: 'Stable',
    observation_of_lenses: 'Soft lens',
    // Lente de contato v2 (OD fora da faixa padrão → linha de avisos também).
    contact_lens_calculation: computeContactLens({
        od: { sphere: -16 },
        oe: { sphere: -5, cylinder: -2, axis: 180 },
    }),
    diagnosis_cids: [{ code: 'H52.1', description: 'Myopia' }],
    clinical_conduct: 'Glasses',
    follow_up_days: 30,
    is_signed: true,
    is_locked: true,
    signed_at_formatted: '01/09/2026 11:00',
    signed_by_name: 'Dr. Smith',
    documentations: [
        {
            id: 'd1',
            type_label: 'Report',
            title: 'Report 1',
            doctor_name: 'Dr. Smith',
            created_at: '01/09/2026 10:30',
            is_ai: true,
            ai_workflow_label: null,
            pdf_url: '/pdf/d1',
            shared_with_patient: false,
            document_share_id: null,
        },
    ],
    pdf_url: '/pdf',
    edit_url: '/edit',
};

const stubFetch = (response) =>
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => response),
    );

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('Visualização da consulta (modal) — textos das traduções', () => {
    const mountView = async (response = { ok: true, json: async () => detail }) => {
        stubFetch(response);
        const wrapper = mount(MedicalRecordViewModal, {
            props: { open: true, record: { show_url: '/show', code: 'PR-1' }, t: keyT() },
            global: { stubs: { teleport: true } },
        });
        await flushPromises();
        return wrapper;
    };

    it('rótulos, seções e olhos vêm das traduções — nada fixo em português', async () => {
        const wrapper = await mountView();
        const labels = wrapper.findAll('dt').map((dt) => dt.text());

        expect(labels.length).toBeGreaterThan(20);
        labels.forEach((label) => expect(label).toMatch(KEY));
        // Lente de contato v2: sugerida, teórico, linha e avisos — rótulos e textos traduzidos.
        [
            'contact_lens_suggested',
            'contact_lens_theoretical_label',
            'contact_lens_profile',
            'contact_lens_notes',
        ].forEach((key) => expect(labels).toContain(`«${key}»`));
        expect(wrapper.text()).toContain('«contact_lens_note_out_of_range»');
        expect(wrapper.text()).toContain('«contact_lens_type_toric»');
        expect(new Set(labels).size).toBe(labels.length);
        ['tab_anamnesis', 'tab_exam', 'tab_refraction', 'tab_findings', 'diagnosis_conduct'].forEach((key) =>
            expect(wrapper.text()).toContain(`«${key}»`),
        );
        expect(wrapper.text()).toMatch(/«od»: 14\s+·\s+«oe»: 15/);
        expect(wrapper.text()).toContain('30 «days»');
        expect(wrapper.text()).not.toMatch(PORTUGUESE);
    });

    it('histórico familiar aparece mesmo sem a condição no paciente', async () => {
        const wrapper = await mountView();
        const value = (key) => wrapper.findAll('dt').find((dt) => dt.text() === `«${key}»`)?.element.nextElementSibling;

        expect(value('diabetic')?.textContent).toBe('«family»');
        expect(value('hypertensive')?.textContent).toBe('«self»');
        expect(value('glaucomatous')?.textContent).toBe('«self» · «family»');
    });

    it('botões só com ícone têm nome acessível; selo de IA traduzido', async () => {
        const wrapper = await mountView();

        expect(wrapper.find('.btn-close').attributes('aria-label')).toBe('«close»');
        expect(wrapper.find('a.btn-outline-secondary[href="/pdf/d1"]').attributes('aria-label')).toBe('«open_pdf»');
        expect(wrapper.find('[data-ai-badge]').text()).toBe('«ai_badge»');
        expect(wrapper.find('[data-ai-badge]').attributes('title')).toBe('«ai_generated»');
    });

    it('falha ao carregar mostra mensagem traduzida, não o erro técnico', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const wrapper = await mountView({ ok: false, status: 500, json: async () => ({}) });

        expect(wrapper.find('.alert-danger').text()).toContain('«view_error»');
        expect(wrapper.text()).not.toContain('HTTP 500');
    });
});

describe('Painel "Consultas anteriores" — textos das traduções', () => {
    const record = {
        id: 'a',
        created_at_formatted: '01/09/2026',
        created_at_time: '10:00',
        doctor_name: 'Dr. Smith',
        is_signed: true,
        main_complaint: 'Blurred vision',
        summary: {
            av_sc: 'OD 20/40 | OS 20/50',
            av_cc: 'OD 20/20 | OS 20/25',
            refraction_od: '-1.50 / -0.50 × 180',
            refraction_oe: '-1.25',
            addition: '+2.00',
            pio: 'OD 14 | OS 15 mmHg',
            contact_lens: null,
            diagnoses: ['H52.1 – Myopia'],
            conduct: 'Glasses',
        },
    };
    const mountCard = () => mount(PreviousRecordsCard, { props: { t: keyT(), records: [record] } });

    it('siglas e olhos vêm das traduções', async () => {
        const wrapper = mountCard();
        await wrapper.find('li').trigger('click');

        const tags = wrapper.findAll('.prev-records__tag, .prev-records__eye').map((el) => el.text());

        expect(tags).toEqual([
            '«av_sc_short»',
            '«av_cc_short»',
            '«refraction_short»',
            '«od»:',
            '«oe»:',
            '«addition_short»:',
            '«iop_short»',
        ]);
        expect(wrapper.text()).not.toMatch(PORTUGUESE);
    });

    it('cabeçalho minimiza pelo teclado e informa se está aberto', async () => {
        const wrapper = mountCard();
        const header = wrapper.find('.prev-records__header');

        expect(header.attributes('tabindex')).toBe('0');
        expect(header.attributes('aria-expanded')).toBe('true');

        await header.trigger('keydown', { key: 'Enter' });

        expect(header.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('ul').attributes('style')).toContain('display: none');

        await header.trigger('keydown', { key: ' ' });

        expect(header.attributes('aria-expanded')).toBe('true');
    });

    it('consulta informa se está expandida', async () => {
        const wrapper = mountCard();
        const item = wrapper.find('li');

        expect(item.attributes('aria-expanded')).toBe('false');
        await item.trigger('keydown', { key: 'Enter' });
        expect(item.attributes('aria-expanded')).toBe('true');
    });

    it('Enter em "Ver prontuário completo" abre a consulta sem recolher o item', async () => {
        const wrapper = mountCard();
        const item = wrapper.find('li');
        await item.trigger('click');

        await item.find('button').trigger('keydown', { key: 'Enter' });

        expect(item.attributes('aria-expanded')).toBe('true');
    });
});

describe('Drawer de detalhes (lista de prontuários) — textos das traduções', () => {
    const OffcanvasStub = defineComponent({
        props: {
            open: Boolean,
            loading: Boolean,
            loadingLabel: { type: String, default: '' },
            width: { type: Number, default: 0 },
        },
        setup(props, { slots }) {
            return () => h('div', { 'data-loading-label': props.loadingLabel }, [slots.header?.(), slots.default?.()]);
        },
    });

    const mountDrawer = async () => {
        stubFetch({ ok: true, json: async () => detail });
        const wrapper = mount(MedicalRecordDetailDrawer, {
            props: {
                open: false,
                record: { show_url: '/show', pdf_url: '/pdf', edit_url: '/edit' },
                patient: { id: 'p1' },
                t: keyT(),
            },
            global: {
                stubs: {
                    OffcanvasPanel: OffcanvasStub,
                    ShareWithPatientToggle: true,
                    PdfPreviewModal: true,
                    ActionIconButton: true,
                },
            },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        return wrapper;
    };

    it('rótulos, abas e cabeçalhos da tabela vêm das traduções', async () => {
        const wrapper = await mountDrawer();
        const labels = wrapper
            .findAll('.detail-label, .detail-section__title, .nav-link, th, td.fw-medium')
            .map((el) => el.text().replace(/\s*\d+$/, ''))
            .filter(Boolean);

        expect(labels.length).toBeGreaterThan(25);
        labels.forEach((label) => expect(label).toMatch(KEY));
        expect(wrapper.find('[data-loading-label]').attributes('data-loading-label')).toBe('«loading»');
        expect(wrapper.findComponent(ActionIconButton).props('title')).toBe('«view_document»');
        expect(wrapper.text()).toContain('30 «days»');
        expect(wrapper.text()).toMatch(/«od»: 14 \/ «oe»: 15/);
        expect(wrapper.text()).not.toMatch(PORTUGUESE);
    });

    it('abas expõem o estado selecionado', async () => {
        const wrapper = await mountDrawer();
        const [info, docs] = wrapper.findAll('[role="tab"]');

        expect(info.attributes('aria-selected')).toBe('true');
        await docs.trigger('click');
        expect(docs.attributes('aria-selected')).toBe('true');
        expect(info.attributes('aria-selected')).toBe('false');
    });
});
