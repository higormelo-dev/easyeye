import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const { __submitted, __router } = await import('@inertiajs/vue3');
const Index = (await import('@/Pages/Panel/Settings/SurgeryFacilities/Index.vue')).default;

const layout = { template: '<div><slot /></div>' };
const modal = {
    template: '<div v-if="open" data-test="modal"><slot name="header" /><slot /><slot name="footer" /></div>',
    props: ['open', 'size'],
};
const ui = new Proxy({}, { get: (_, key) => String(key) });

const props = (over = {}) => ({
    breadcrumbs: [],
    facilities: [
        {
            id: 'f1',
            name: 'Clínica própria',
            type: 'own_clinic',
            type_label: 'Clínica própria',
            cnes: '1234567',
            active: true,
            rooms: [{ id: 'r1', name: 'Sala 1', active: true, clinic_resource_id: 'res1' }],
        },
    ],
    types: [
        { value: 'own_clinic', label: 'Clínica própria' },
        { value: 'hospital', label: 'Hospital' },
    ],
    resources: [{ id: 'res1', name: 'Centro cirúrgico' }],
    urls: {
        store: '/fac',
        update: '/fac/__ID__',
        destroy: '/fac/__ID__',
        room_store: '/fac/__ID__/rooms',
        room_update: '/fac/rooms/__ID__',
        room_destroy: '/fac/rooms/__ID__',
    },
    t: { facilities: { title: 'Locais', subtitle: 'Onde se opera' }, ui: { facilities: ui } },
    ...over,
});

const mountIndex = (p) => mount(Index, { props: p, global: { stubs: { AppLayout: layout, CenteredModal: modal } } });

beforeEach(() => {
    __submitted.length = 0;
    __router.put.mockClear();
    __router.delete.mockClear();
    window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true })) };
});

describe('Configurações > locais e salas cirúrgicas', () => {
    it('lista o local com suas salas e o vínculo com a sala da Agenda', () => {
        const wrapper = mountIndex(props());

        expect(wrapper.find('[data-testid="facility-card"]').text()).toContain('Clínica própria');
        expect(wrapper.find('[data-testid="room-row"]').text()).toContain('Sala 1');
        expect(wrapper.find('[data-testid="room-row"]').text()).toContain('Centro cirúrgico');
    });

    it('sem local cadastrado mostra o estado vazio', () => {
        const wrapper = mountIndex(props({ facilities: [] }));

        expect(wrapper.find('[data-testid="facility-empty"]').exists()).toBe(true);
    });

    it('cria um local (POST) com campos vazios como null', async () => {
        const wrapper = mountIndex(props());

        await wrapper.find('[data-testid="facility-new"]').trigger('click');
        await wrapper.find('[data-testid="facility-name"]').setValue('Hospital São Lucas');
        await wrapper.find('#fac-type').setValue('hospital');
        await wrapper.find('#fac-cnes').setValue('7654321');
        await wrapper.find('[data-testid="facility-save"]').trigger('click');

        const sent = __submitted.at(-1);
        expect(sent).toMatchObject({ method: 'post', url: '/fac' });
        expect(sent.payload).toMatchObject({
            name: 'Hospital São Lucas',
            type: 'hospital',
            cnes: '7654321',
            phone: null,
            notes: null,
        });
    });

    it('edita o local (PUT no id certo)', async () => {
        const wrapper = mountIndex(props());

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'edit')
            .trigger('click');
        await wrapper.find('[data-testid="facility-name"]').setValue('Clínica renomeada');
        await wrapper.find('[data-testid="facility-save"]').trigger('click');

        expect(__submitted.at(-1)).toMatchObject({ method: 'put', url: '/fac/f1' });
    });

    it('cria sala no local; sala de clínica própria oferece o vínculo com a Agenda', async () => {
        const wrapper = mountIndex(props());

        await wrapper.find('[data-testid="room-new"]').trigger('click');
        expect(wrapper.find('#room-resource').exists()).toBe(true);

        await wrapper.find('[data-testid="room-name"]').setValue('Sala 2');
        await wrapper.find('#room-resource').setValue('res1');
        await wrapper.find('[data-testid="room-save"]').trigger('click');

        const sent = __submitted.at(-1);
        expect(sent).toMatchObject({ method: 'post', url: '/fac/f1/rooms' });
        expect(sent.payload).toMatchObject({ name: 'Sala 2', clinic_resource_id: 'res1' });
    });

    it('sala de hospital não oferece vínculo com a Agenda', async () => {
        const wrapper = mountIndex(
            props({
                facilities: [
                    { id: 'f2', name: 'Hospital', type: 'hospital', type_label: 'Hospital', active: true, rooms: [] },
                ],
            }),
        );

        await wrapper.find('[data-testid="room-new"]').trigger('click');

        expect(wrapper.find('#room-resource').exists()).toBe(false);
    });

    it('excluir pede confirmação antes de chamar o servidor', async () => {
        const wrapper = mountIndex(props());

        await wrapper.find('[data-testid="room-row"] .btn-outline-danger').trigger('click');
        await flushPromises();

        expect(window.Swal.fire).toHaveBeenCalled();
        expect(__router.delete).toHaveBeenCalledWith('/fac/rooms/r1', expect.any(Object));

        window.Swal.fire.mockResolvedValueOnce({ isConfirmed: false });
        __router.delete.mockClear();
        await wrapper.find('[data-testid="room-row"] .btn-outline-danger').trigger('click');
        await flushPromises();

        expect(__router.delete).not.toHaveBeenCalled();
    });

    it('ativar/desativar manda só o campo active', async () => {
        const wrapper = mountIndex(props());

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'deactivate')
            .trigger('click');

        expect(__router.put).toHaveBeenCalledWith('/fac/f1', { active: false }, expect.any(Object));
    });
});
