import { describe, it, expect, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * @hidden.bs.modal (ponto = modificador pro Vue, não nome de evento) nunca
 * batia com o evento REAL que o Bootstrap dispara ao esconder o modal —
 * fechar pelo Esc/backdrop/botão nativo (`data-bs-dismiss`) nunca emitia
 * 'close' pro pai. Achado via auditoria de encerramento, EE-13 (lint
 * vue/valid-v-on). Simula o Bootstrap real disparando o CustomEvent no
 * elemento raiz do modal, sem passar pelo onConfirm/bsModal?.hide() do
 * próprio componente — é exatamente o caminho que estava quebrado.
 */
function dispatchBootstrapHidden(el) {
    el.dispatchEvent(new Event('hidden.bs.modal', { bubbles: false }));
}

const SlotPickerStub = {
    props: ['modelValue', 'allowOccupied'],
    template: '<div class="slot-picker" />',
    methods: { setDate() {}, reset() {} },
};
const SearchSelectStub = {
    props: ['modelValue', 'options'],
    template: '<select class="ss"></select>',
};

beforeEach(() => {
    window.bootstrap = {
        Modal: class {
            show() {}
            hide() {}
        },
    };
    globalThis.bootstrap = window.bootstrap;
});

describe('Modais de agenda — dismiss nativo do Bootstrap emite close', () => {
    it('BulkCancelModal: Esc/backdrop nativo (hidden.bs.modal) emite close', async () => {
        const BulkCancelModal = (await import('@/Pages/Panel/Schedules/BulkCancelModal.vue')).default;
        const wrapper = mount(BulkCancelModal, {
            props: { open: false, selectedIds: [], t: {} },
            global: { stubs: { teleport: true } },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        dispatchBootstrapHidden(wrapper.find('#bulkCancelModal').element);

        expect(wrapper.emitted('close')).toBeTruthy();
        wrapper.unmount();
    });

    it('BulkRescheduleModal: Esc/backdrop nativo (hidden.bs.modal) emite close', async () => {
        const BulkRescheduleModal = (await import('@/Pages/Panel/Schedules/BulkRescheduleModal.vue')).default;
        const wrapper = mount(BulkRescheduleModal, {
            props: { open: false, selectedIds: [], t: { reschedule_datetime: 'Data e hora' } },
            global: { stubs: { teleport: true } },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        dispatchBootstrapHidden(wrapper.find('#bulkRescheduleModal').element);

        expect(wrapper.emitted('close')).toBeTruthy();
        wrapper.unmount();
    });

    it('CancelModal: Esc/backdrop nativo (hidden.bs.modal) emite close', async () => {
        const CancelModal = (await import('@/Pages/Panel/Schedules/CancelModal.vue')).default;
        const wrapper = mount(CancelModal, {
            props: { item: null, t: {} },
            global: { stubs: { teleport: true } },
            attachTo: document.body,
        });
        await wrapper.setProps({ item: { id: 's1', situation_url: '/s1', time: '09:00', name: 'MARIA' } });
        await flushPromises();

        dispatchBootstrapHidden(wrapper.find('#cancelScheduleModal').element);

        expect(wrapper.emitted('close')).toBeTruthy();
        wrapper.unmount();
    });

    it('RescheduleModal: Esc/backdrop nativo (hidden.bs.modal) emite close', async () => {
        const RescheduleModal = (await import('@/Pages/Panel/Schedules/RescheduleModal.vue')).default;
        const wrapper = mount(RescheduleModal, {
            props: { item: null, doctors: [], t: {} },
            global: { stubs: { SlotPicker: SlotPickerStub, SearchSelect: SearchSelectStub, teleport: true } },
            attachTo: document.body,
        });
        await wrapper.setProps({
            item: { id: 's1', doctor_id: 'd1', is_fit_in: false, reschedule_url: '/reschedule/s1' },
        });
        await flushPromises();

        dispatchBootstrapHidden(wrapper.find('#rescheduleModal').element);

        expect(wrapper.emitted('close')).toBeTruthy();
        wrapper.unmount();
    });
});
