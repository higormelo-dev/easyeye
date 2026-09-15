import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ScheduleDetailDrawer from '@/Pages/Panel/Schedules/ScheduleDetailDrawer.vue';

/**
 * Botão de copiar "só o número" do CÓDIGO DO PACIENTE — para colar direto em
 * campos de ID puramente numéricos do software nativo de aparelhos (ex.:
 * OCULUS Patient Data Management), sem o operador precisar apagar
 * prefixo/traço na mão a cada exame. Ver ScheduleDetailDrawer.vue —
 * integrador não participa dessa etapa em nenhum momento; é só clínica ↔
 * EasyEye ↔ programa do aparelho.
 *
 * Deliberadamente SEM equivalente no código do AGENDAMENTO: esse campo de ID
 * do aparelho é a chave de busca na base de dados local dele, e só o código
 * do paciente é estável entre visitas. Um botão de "só número" ali
 * incentivaria colar o código errado — o aparelho nunca encontraria o
 * cadastro de uma visita anterior (código de agendamento novo a cada
 * consulta) e o operador criaria um paciente duplicado na base local a
 * cada retorno. Ver o teste de guarda no fim do arquivo.
 */
describe('ScheduleDetailDrawer — copiar código', () => {
    beforeEach(() => {
        vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(() => Promise.resolve()) } });
    });

    function mockSchedule(overrides = {}) {
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    data: {
                        id: 'sched-1',
                        code: 'SDL-0000000741',
                        patient_code: 'PAC-0000000123',
                        patient_name: 'Maria Silva',
                        doctor_code: 'DOC-0000000005',
                        doctor_name: 'Dr. João',
                        date_time: '2026-09-15 10:00',
                        situation_label: 'Confirmado',
                        situation_badge: 'badge-success',
                        situation_icon: 'fa-check',
                        resources: [],
                        situation_logs: [],
                        ...overrides,
                    },
                }),
            }),
        );
    }

    async function mountOpenDrawer() {
        // OffcanvasPanel usa <Teleport to="body">; sem stubar, o conteúdo sai
        // da árvore do wrapper e find()/findAll() não o alcançam.
        //
        // O componente só busca o detalhe no `watch(() => props.open, ...)`
        // (sem `immediate: true`) — montar já com open:true não dispara o
        // watcher. Monta fechado e abre depois, como o uso real (o pai
        // alterna `open` de false pra true).
        const wrapper = mount(ScheduleDetailDrawer, {
            props: { open: false, scheduleId: 'sched-1', t: {} },
            global: { stubs: { teleport: true } },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        return wrapper;
    }

    it('copia o código do agendamento com prefixo e traço (formato humano)', async () => {
        mockSchedule();
        const wrapper = await mountOpenDrawer();

        const scheduleRow = wrapper
            .findAll('.detail-row')
            .find((row) => row.text().includes('SDL-0000000741'));
        await scheduleRow.find('button').trigger('click');

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('SDL-741');
    });

    /**
     * Guarda de regressão: o código do agendamento NUNCA deve ganhar um
     * botão de "copiar só número" — ele muda a cada consulta, e colar esse
     * número no campo de ID do software do aparelho faria o operador criar
     * um paciente duplicado na base local a cada retorno (ver comentário no
     * topo do arquivo e em numericCode() no componente).
     */
    it('não oferece copiar-só-número no código do agendamento', async () => {
        mockSchedule();
        const wrapper = await mountOpenDrawer();

        const scheduleRow = wrapper
            .findAll('.detail-row')
            .find((row) => row.text().includes('SDL-0000000741'));

        expect(scheduleRow.findAll('button')).toHaveLength(1);
    });

    it('copia só os dígitos do código do paciente', async () => {
        mockSchedule();
        const wrapper = await mountOpenDrawer();

        const patientRow = wrapper
            .findAll('.detail-row')
            .find((row) => row.text().includes('PAC-0000000123'));
        const numericButton = patientRow.findAll('button')[1];
        await numericButton.trigger('click');

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('123');
    });

    it('cai para dígitos puros quando o código não segue o padrão PREFIXO-NÚMERO', async () => {
        mockSchedule({ patient_code: 'AB12-CD34' });
        const wrapper = await mountOpenDrawer();

        const patientRow = wrapper
            .findAll('.detail-row')
            .find((row) => row.text().includes('AB12-CD34'));
        const numericButton = patientRow.findAll('button')[1];
        await numericButton.trigger('click');

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('1234');
    });

    it('não copia nada quando o código está ausente', async () => {
        mockSchedule({ patient_code: null });
        const wrapper = await mountOpenDrawer();

        // Sem patient_code, a linha inteira (v-if="schedule.patient_code") não renderiza.
        const hasPatientCodeRow = wrapper
            .findAll('.detail-row')
            .some((row) => row.text().includes('PAC-'));
        expect(hasPatientCodeRow).toBe(false);
    });
});
