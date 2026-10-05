import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Display from '@/Pages/CallPanel/Display.vue';
import Dashboard from '@/Pages/PatientPortal/Dashboard.vue';
import Clinic from '@/Pages/PatientPortal/Clinic.vue';

/**
 * Clínica com o acesso bloqueado fora do painel: a TV de chamada mostra
 * "serviço indisponível" sem nome de paciente (e volta sozinha quando o feed
 * libera); o portal do paciente avisa, de forma neutra, que está só para
 * consulta — documentos seguem.
 */
vi.mock('@/Layouts/PatientPortalLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const texts = {
    calling: 'Chamando',
    idle: 'Aguardando chamadas…',
    history: 'Últimas chamadas',
    unavailable_title: 'Serviço indisponível no momento',
    unavailable_body: 'Procure a recepção.',
};

function feed(body) {
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(body) }));
}

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
});

describe('CallPanel/Display', () => {
    it('bloqueada: "serviço indisponível" e nenhum paciente na tela', async () => {
        feed({ data: [], unavailable: true });
        wrapper = mount(Display, { props: { clinic: 'Clínica Visão', feed_url: '/feed', unavailable: true, texts } });
        await flushPromises();

        expect(wrapper.get('[data-test="call-panel-unavailable"]').text()).toContain('Serviço indisponível no momento');
        expect(wrapper.text()).not.toContain('Chamando');
    });

    it('volta sozinha quando o feed libera (acesso regularizado)', async () => {
        vi.useFakeTimers();
        feed({ data: [], unavailable: true });
        wrapper = mount(Display, { props: { clinic: 'Clínica Visão', feed_url: '/feed', unavailable: true, texts } });
        await flushPromises();

        feed({
            data: [{ id: 'c1', patient: 'JOAO DA TV', doctor: 'DRA ANA', called_at: '10:00' }],
            unavailable: false,
        });
        await vi.advanceTimersByTimeAsync(4000);
        await flushPromises();

        expect(wrapper.find('[data-test="call-panel-unavailable"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('JOAO DA TV');
    });
});

describe('Portal do paciente — só consulta', () => {
    const readOnly = {
        badge: 'Somente consulta',
        notice: 'Agendamento online indisponível no momento; entre em contato com a clínica.',
    };

    it('Minhas Clínicas: aviso neutro só na clínica bloqueada', () => {
        wrapper = mount(Dashboard, {
            props: {
                readOnly,
                clinics: [
                    { entity_id: 'a', name: 'Clínica A', clinic_url: '/a', read_only: true },
                    { entity_id: 'b', name: 'Clínica B', clinic_url: '/b', read_only: false },
                ],
            },
        });

        const notices = wrapper.findAll('[data-test="portal-read-only"]');
        expect(notices).toHaveLength(1);
        expect(notices[0].text()).toContain('Agendamento online indisponível');
        expect(wrapper.text()).not.toMatch(/pagamento|assinatura/i);
    });

    it('Clínica: documentos e "Baixar meus dados" seguem, com o aviso', () => {
        wrapper = mount(Clinic, {
            props: {
                clinicName: 'Clínica A',
                lgpdExportUrl: '/export',
                readOnly: true,
                readOnlyNotice: readOnly.notice,
                documents: [
                    {
                        id: 'd1',
                        type: 'laudo',
                        title: 'Laudo',
                        type_label: 'Laudo',
                        view_url: '/v',
                        download_url: '/d',
                    },
                ],
            },
            global: { mocks: { route: globalThis.route } },
        });

        expect(wrapper.get('[data-test="portal-read-only"]').text()).toContain('Agendamento online indisponível');
        expect(wrapper.find('a[href="/export"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/d"]').exists()).toBe(true);
    });
});
