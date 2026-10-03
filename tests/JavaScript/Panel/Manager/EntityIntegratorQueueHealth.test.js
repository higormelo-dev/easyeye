import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import Index from '@/Pages/Panel/Manager/EntityIntegratorQueueHealth/Index.vue';

describe('capture operational health', () => {
    it('shows prequeue refusals and originals pending archive instead of declaring the queue healthy', () => {
        const route = vi.spyOn(globalThis, 'route').mockImplementation((name) => `/_routes/${name}`);
        const wrapper = shallowMount(Index, {
            props: {
                entity: { id: 'entity-1', name: 'Synthetic clinic' },
                userIntegrator: { id: 'user-1', name: 'Integrator user' },
                integrator: { id: 'integrator-1', name: 'Synthetic integrator' },
                equipmentNames: { 'remote-equipment': { name: 'Synthetic OCT', code: 'EIQ-1' } },
                health: {
                    pending_count: 0,
                    failed_count: 0,
                    blocked_count: 0,
                    sent_last_24h_count: 0,
                    problems: [],
                    synced_at: new Date().toISOString(),
                    operational: {
                        version: '0.1.0',
                        heartbeat_at: '2026-10-02T12:45:00Z',
                        capabilities: {
                            folder_capture: true,
                            dicom_storage: false,
                            dicom_mwl: false,
                            ocr: true,
                            rpa: false,
                        },
                        watcher_state: 'unknown',
                        mwl_state: 'unknown',
                        ingest_pending: 0,
                        quarantined: 0,
                        acquisition_rejected: 1,
                        unconfirmed_sent: 2,
                        originals_pending_remote_archive: 3,
                        oldest_pending_at: null,
                        disk_free_bytes: 1048576,
                        next_action: 'preservar originais locais; arquivamento remoto pendente',
                        devices: [
                            {
                                equipment_id: 1,
                                remote_equipment_id: 'remote-equipment',
                                rejected_count: 1,
                                last_observed_at: null,
                                last_accepted_at: null,
                                issue: 'source_or_spool_unavailable',
                            },
                        ],
                    },
                },
            },
            global: { renderStubDefaultSlot: true },
        });
        expect(wrapper.find('[data-testid="capture-health"]').text()).toContain('Recusas antes da fila: 1');
        expect(wrapper.text()).toContain('Originais locais sem arquivamento remoto: 3');
        expect(wrapper.text()).toContain('Verificar pasta de origem');
        expect(wrapper.text()).toContain('Monitor de pastas: Desconhecido');
        expect(wrapper.text()).toContain('Worklist: Desconhecido');
        expect(wrapper.text()).toContain('Recepção DICOM: indisponível');
        expect(wrapper.text()).toContain('Synthetic OCT (EIQ-1)');
        expect(wrapper.text()).not.toContain('Invalid Date');
        expect(wrapper.text()).not.toContain('Fila de envios sem bloqueios');
        wrapper.unmount();
        route.mockRestore();
    });
});
