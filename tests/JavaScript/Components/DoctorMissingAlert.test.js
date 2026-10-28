import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import DoctorMissingAlert from '@/Components/Panel/DoctorMissingAlert.vue';

/**
 * Perfil médico sem cadastro de médico ativo: prontuário e Gerenciador de
 * Imagens mostram o aviso (o servidor barra os atos clínicos e manda
 * isDoctor=false, que já esconde as ações).
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

describe('DoctorMissingAlert', () => {
    it('mostra o texto traduzido vindo do servidor, como alerta acessível', () => {
        const text = 'Seu usuário não está vinculado a um cadastro de médico ativo — peça ao administrador.';
        const alert = mount(DoctorMissingAlert, { props: { text } }).find('[data-testid="doctor-missing-alert"]');

        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toBe(text);
    });
});

describe('Prontuários (índice) com médico sem cadastro', () => {
    it('mostra o aviso e esconde "Novo prontuário"', async () => {
        vi.doMock('@/Components/Panel/PageHeader.vue', () => ({
            default: { template: '<div><slot name="actions" /></div>' },
        }));
        const Index = (await import('@/Pages/Panel/MedicalRecords/Index.vue')).default;
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({ ok: true, json: () => Promise.resolve({ data: [], meta: { total: 0, last_page: 1 } }) }),
        );

        const wrapper = mount(Index, {
            props: {
                patient: { id: 'p1', full_name: 'Paciente' },
                urls: { ajax_list: '/list', create: '/create', patients: '/patients', schedules: '/schedules' },
                isDoctor: false,
                doctorMissing: true,
                doctorMissingText: 'Sem cadastro de médico ativo.',
                t: { new_record: 'Novo prontuário' },
            },
            global: {
                stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
                mocks: { route: globalThis.route },
            },
        });

        expect(wrapper.find('[data-testid="doctor-missing-alert"]').text()).toBe('Sem cadastro de médico ativo.');
        expect(wrapper.find('a[href="/create"]').exists()).toBe(false);
    });
});
