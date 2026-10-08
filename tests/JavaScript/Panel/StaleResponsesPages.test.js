import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, shallowMount, flushPromises } from '@vue/test-utils';

/**
 * 2ª revisão Codex, P1-B (varredura) — mesma ordem nas telas grandes: carga
 * do paciente/agendamento A pendente → B carregado → A chega por último.
 * Agenda (editar agendamento), Imagens de exames (imagens do paciente) e
 * histórico do assistente de IA (por paciente).
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/composables/useDashboardPolling.js', () => ({ useDashboardPolling: () => {} }));
vi.mock('@/Components/Panel/MiniCalendar.vue', () => ({ default: { template: '<div />' } }));
for (const name of [
    'ScheduleCard',
    'EventCard',
    'RescheduleModal',
    'CancelModal',
    'BulkCancelModal',
    'BulkRescheduleModal',
    'NoticesPanel',
    'WaitingListPanel',
    'WaitingListFormModal',
    'ScheduleDetailDrawer',
    'CashEntryModal',
    'CalendarView',
    'ScheduleFormModal',
]) {
    vi.doMock(`@/Pages/Panel/Schedules/${name}.vue`, () => ({ default: { template: '<div />' } }));
}

const SchedulesIndex = (await import('@/Pages/Panel/Schedules/Index.vue')).default;
const EyeImagesIndex = (await import('@/Pages/Panel/EyeImages/Index.vue')).default;
const AiAssistantPanel = (await import('@/Components/Panel/AiAssistantPanel.vue')).default;

let pending;
function deferred(url) {
    return new Promise((resolve) => pending.push({ url: String(url), resolve }));
}
const settle = (part, body) => {
    const call = pending.find((p) => p.url.includes(part));
    if (!call) throw new Error(`sem requisição para ${part}`);
    call.resolve(body);
};
const jsonResponse = (body) => ({ ok: true, status: 200, json: () => Promise.resolve(body) });

beforeEach(() => {
    pending = [];
    window.localStorage.clear();
    window.history.replaceState({}, '', '/');
});

describe('Agenda: editar agendamento', () => {
    it('clicou em editar A e logo em B: o formulário abre com o B mesmo se o A chegar depois', async () => {
        globalThis.fetch = vi.fn((url) => deferred(url));
        const w = mount(SchedulesIndex, {
            props: { filters: { date: '2026-10-07', doctor: 'tudo', bout: 1 }, t: {}, doctors: [], scheduleItems: [] },
            global: { mocks: { route: globalThis.route } },
        });
        const { openEdit } = w.vm.$.setupState;
        const a = openEdit({ show_url: '/sched/A' });
        const b = openEdit({ show_url: '/sched/B' });
        settle('/sched/B', jsonResponse({ data: { id: 'B', patient_name: 'PACIENTE B' } }));
        await b;
        settle('/sched/A', jsonResponse({ data: { id: 'A', patient_name: 'PACIENTE A' } }));
        await a;
        await flushPromises();

        expect(w.vm.$.setupState.editSchedule.id).toBe('B');
    });
});

describe('Imagens de exames: imagens do paciente escolhido', () => {
    it('as imagens atrasadas do A não aparecem com o B selecionado', async () => {
        globalThis.fetch = vi.fn((url) => deferred(url));
        const w = shallowMount(EyeImagesIndex, {
            props: {
                entity: { id: 'e' },
                patients: [],
                urls: { patient_urls: '/patient/__ID__/urls', image_url: '/exam/__ID__/url' },
            },
            global: { stubs: { Teleport: true }, renderStubDefaultSlot: true },
        });
        const { selectPatient } = w.vm.$.setupState;
        const a = selectPatient({ id: 'A', person: { full_name: 'A' }, exams: [] });
        const b = selectPatient({ id: 'B', person: { full_name: 'B' }, exams: [] });
        settle('/patient/B/urls', jsonResponse({ urls: { 'exam-b': '/b.jpg' }, thumb_urls: {} }));
        await b;
        settle('/patient/A/urls', jsonResponse({ urls: { 'exam-a': '/a.jpg' }, thumb_urls: {} }));
        await a;

        expect(w.vm.$.setupState.examUrls).toEqual({ 'exam-b': '/b.jpg' });
        expect(w.vm.$.setupState.urlsLoading).toBe(false);
    });
});

describe('Assistente de IA: histórico por paciente', () => {
    it('histórico atrasado do A não entra no painel do B', async () => {
        window.axios = { get: vi.fn((url) => deferred(url)), post: vi.fn() };
        const ai = {
            urls: { by_patient: '/ai/by-patient/__ID__', show: '/ai/__ID__', estimate: '/e', store: '/s' },
            balance: { available: 10 },
            modes: [{ value: 'validated' }],
            workflows: ['record_assist'],
            default_workflow: 'record_assist',
            assistant: {},
            workflow_labels: {},
        };
        const w = mount(AiAssistantPanel, {
            props: {
                open: true,
                ai,
                context: { patient_id: 'A', medical_record_id: 'rA', workflow_default: 'record_assist' },
            },
            global: {
                stubs: {
                    OffcanvasPanel: { template: '<div><slot name="header" /><slot /><slot name="footer" /></div>' },
                },
            },
        });
        await flushPromises();
        await w.setProps({ context: { patient_id: 'B', medical_record_id: 'rB', workflow_default: 'record_assist' } });
        const b = w.vm.$.setupState.fetchHistory();
        settle('/ai/by-patient/B', { data: { data: [{ id: 'hB', preview: 'HISTÓRICO DO B' }] } });
        await b;
        settle('/ai/by-patient/A', { data: { data: [{ id: 'hA', preview: 'HISTÓRICO DO A' }] } });
        await flushPromises();

        expect(w.vm.$.setupState.history.map((h) => h.id)).toEqual(['hB']);
    });
});
