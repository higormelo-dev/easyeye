/**
 * Alpine component: slotPicker(apiUrl)
 *
 * Renders a date selector + slot grid for the schedule form modal.
 *
 * Communication with the parent crudForm:
 *   - Receives doctor_id via x-modelable + x-model (no $parent needed)
 *   - Writes date_time back via $dispatch('slot-selected', { datetime })
 *   - Receives edit-mode date_time via window event 'schedule-edit-init'
 *
 * Usage:
 *   <div x-data="slotPicker(apiUrl)"
 *        x-modelable="doctorId"
 *        x-model="form.doctor_id"
 *        @slot-selected.window="form.date_time = $event.detail.datetime">
 */
export default (apiUrl) => ({
    doctorId:         '',   // bound from parent via x-modelable + x-model
    selectedDate:     '',
    selectedDatetime: '',   // local selection state (replaces $parent.form.date_time reads)
    slots:            [],
    loadingSlots:     false,
    hasSchedule:      false,
    interval:         15,   // effective interval in minutes (doctor → entity → 15)
    _prevDoctorId:    null,
    _editInitHandler: null,

    init() {
        // Listen for edit-mode init event (dispatched by crudForm.fill())
        this._editInitHandler = (e) => {
            const dt = e.detail?.date_time ?? '';
            this.selectedDatetime = dt;
            if (dt.length >= 10) {
                this.selectedDate = dt.substring(0, 10);
            }
        };
        window.addEventListener('schedule-edit-init', this._editInitHandler);

        // Watch doctorId changes (replaces x-effect="watchDoctor($parent.form.doctor_id)")
        this.$watch('doctorId', (newId) => this.watchDoctor(newId));

        // Fetch slots whenever the selected date changes
        this.$watch('selectedDate', () => this.fetchSlots());
    },

    destroy() {
        if (this._editInitHandler) {
            window.removeEventListener('schedule-edit-init', this._editInitHandler);
        }
    },

    /**
     * Resets slots and selected date when the doctor selection changes.
     */
    watchDoctor(doctorId) {
        if (doctorId === this._prevDoctorId) return;
        this._prevDoctorId = doctorId;

        this.slots            = [];
        this.hasSchedule      = false;
        this.selectedDate     = '';
        this.selectedDatetime = '';
    },

    async fetchSlots() {
        const doctorId = this.doctorId;

        if (! doctorId || ! this.selectedDate) {
            this.slots       = [];
            this.hasSchedule = false;
            return;
        }

        this.loadingSlots = true;
        this.slots        = [];

        try {
            const url = `${apiUrl}?doctor_id=${encodeURIComponent(doctorId)}&date=${encodeURIComponent(this.selectedDate)}`;
            const res = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
            });

            if (! res.ok) throw new Error('Erro ao buscar horários.');

            const data       = await res.json();
            this.slots       = data.slots ?? [];
            this.hasSchedule = data.has_schedule ?? false;
            this.interval    = data.interval ?? 15;

            // If editing and the pre-selected slot is no longer in the list, clear it
            if (this.selectedDatetime && ! this.slots.some(s => s.datetime === this.selectedDatetime)) {
                this.selectedDatetime = '';
            }
        } catch {
            this.slots       = [];
            this.hasSchedule = false;
        } finally {
            this.loadingSlots = false;
        }
    },

    selectSlot(slot) {
        if (! slot.available) return;
        this.selectedDatetime = slot.datetime;
        this.$dispatch('slot-selected', { datetime: slot.datetime });
    },

    isSelected(slot) {
        return slot.datetime === this.selectedDatetime;
    },

    /**
     * Generates time options for doctors without a work schedule.
     * Uses the effective interval (from API) and a default 07:00–19:00 range.
     */
    generateDefaultTimes() {
        const times = [];
        const step  = this.interval;
        let   h     = 7,
              m     = 0;

        while (h < 19 || (h === 19 && m === 0)) {
            const hh       = String(h).padStart(2, '0');
            const mm       = String(m).padStart(2, '0');
            const time     = `${hh}:${mm}`;
            const datetime = `${this.selectedDate}T${time}`;
            times.push({ time, datetime });

            m += step;
            if (m >= 60) { m -= 60; h += 1; }
        }

        return times;
    },
});
