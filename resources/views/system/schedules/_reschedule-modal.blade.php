{{--
    Modal de reagendamento.
    Uso: incluir dentro da mesma página que carrega schedules.js
    O JS gerencia a abertura (btn-reschedule) e o submit (#btn-reschedule-save).
--}}
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">

            <div class="modal-header bg-info">
                <h5 class="modal-title text-white" id="rescheduleModalLabel">
                    <i class="fas fa-calendar-alt me-1"></i> {{ __('schedules.reschedule_title') }}
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ __('schedules.reschedule_cancel') }}"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" id="reschedule-schedule-id">

                <div class="mb-3">
                    <label class="form-label">{{ __('schedules.reschedule_datetime') }} <span class="text-danger">*</span></label>
                    <input type="datetime-local" class="form-control" id="reschedule-date-time">
                    <div class="invalid-feedback" id="reschedule-date-error"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ __('schedules.reschedule_doctor') }}</label>
                    <select class="form-select" id="reschedule-doctor-id">
                        {{-- Opções preenchidas via JS --}}
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> {{ __('schedules.reschedule_cancel') }}
                </button>
                <button type="button" class="btn btn-info" id="btn-reschedule-save">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="reschedule-spinner"></span>
                    <i class="far fa-save me-1"></i> {{ __('schedules.reschedule_save') }}
                </button>
            </div>

        </div>
    </div>
</div>
