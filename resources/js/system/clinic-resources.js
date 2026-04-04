import "jquery";
import { initSettingDatatable } from './setting.js';
import { showSuccessToast, showErrorToast } from './auxiliary_functions.js';

$(function () {
    initSettingDatatable({ tableId: 'clinic_resources_datatable', prefix: 'resources' });

    // Open resource work-schedule modal
    $(document).on('click', '.btn-resource-schedule', function () {
        const id = $(this).data('id');
        window.dispatchEvent(new CustomEvent('open-resource-schedule', { detail: { resourceId: id } }));
    });
});

/**
 * Alpine component: resourceScheduleModalData()
 *
 * Manages the resource availability (work schedule + blocks) modal.
 */
window.resourceScheduleModalData = function () {
    return {
        loading:       false,
        saving:        false,
        saveError:     null,
        saveSuccess:   false,
        storingBlock:  false,
        showBlockForm: false,

        resourceId:        null,
        resourceName:      '',
        resourceTypeLabel: '',

        days:   [],
        blocks: [],

        syncUrl:          '',
        storeBlockUrl:    '',
        destroyBlockBase: '',

        blockForm:   { type: 'absence', starts_at: '', ends_at: '', reason: '' },
        blockErrors: {},

        init() {
            window.addEventListener('open-resource-schedule', (e) => {
                this.openFor(e.detail.resourceId);
            });
        },

        async openFor(resourceId) {
            this.resourceId  = resourceId;
            this.loading     = true;
            this.saveError   = null;
            this.saveSuccess = false;

            bootstrap.Modal.getOrCreateInstance(
                document.getElementById('resourceScheduleModal')
            ).show();

            try {
                const res  = await fetch(`/panel/resources/${resourceId}/work-schedule/data`, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json();

                this.resourceName      = data.resource.name;
                this.resourceTypeLabel = data.resource.type_label;
                this.days              = data.days;
                this.blocks            = data.blocks;
                this.syncUrl           = data.sync_url;
                this.storeBlockUrl     = data.store_block_url;
                this.destroyBlockBase  = data.destroy_block_base;
            } catch {
                showErrorToast('Erro ao carregar dados do recurso.');
            } finally {
                this.loading = false;
            }
        },

        addRange(dayIndex) {
            if (! this.days[dayIndex].active) {
                this.days[dayIndex].active = true;
            }
            this.days[dayIndex].ranges.push({ starts_at: '08:00', ends_at: '18:00' });
        },

        removeRange(dayIndex, rangeIndex) {
            this.days[dayIndex].ranges.splice(rangeIndex, 1);
            if (this.days[dayIndex].ranges.length === 0) {
                this.days[dayIndex].active = false;
            }
        },

        async save() {
            this.saving     = true;
            this.saveError  = null;
            this.saveSuccess = false;

            try {
                const res = await fetch(this.syncUrl, {
                    method:  'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ days: this.days }),
                });

                const data = await res.json();

                if (! res.ok) {
                    this.saveError = data.message ?? 'Erro ao salvar.';
                    return;
                }

                this.saveSuccess = true;
                showSuccessToast(data.message);
                setTimeout(() => {
                    bootstrap.Modal.getInstance(
                        document.getElementById('resourceScheduleModal')
                    )?.hide();
                    this.saveSuccess = false;
                }, 1200);
            } catch {
                this.saveError = 'Erro de conexão.';
            } finally {
                this.saving = false;
            }
        },

        async storeBlock() {
            this.storingBlock = true;
            this.blockErrors  = {};

            try {
                const res  = await fetch(this.storeBlockUrl, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(this.blockForm),
                });
                const data = await res.json();

                if (! res.ok) {
                    this.blockErrors = data.errors ?? {};
                    return;
                }

                this.blocks.push(data.data);
                this.blockForm     = { type: 'absence', starts_at: '', ends_at: '', reason: '' };
                this.showBlockForm = false;
                showSuccessToast(data.message);
            } catch {
                showErrorToast('Erro ao criar bloqueio.');
            } finally {
                this.storingBlock = false;
            }
        },

        async destroyBlock(blockId) {
            try {
                const res = await fetch(`${this.destroyBlockBase}/${blockId}`, {
                    method:  'DELETE',
                    headers: {
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                const data = await res.json();

                if (! res.ok) {
                    showErrorToast(data.message ?? 'Erro ao remover.');
                    return;
                }

                this.blocks = this.blocks.filter(b => b.id !== blockId);
                showSuccessToast(data.message);
            } catch {
                showErrorToast('Erro de conexão.');
            }
        },
    };
};

// Register Alpine component
if (window.Alpine) {
    window.Alpine.data('resourceScheduleModalData', window.resourceScheduleModalData);
}
