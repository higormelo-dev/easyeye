{{-- Modal de criação/edição de parceiro --}}
<div class="modal fade" id="partnerModal" tabindex="-1" aria-labelledby="partnerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="partnerModalLabel">
                    <span x-text="isEdit ? '{{ __('actions.partners.edit') }}' : '{{ __('actions.partners.new') }}'"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    {{-- Nome --}}
                    <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">{{ __('actions.partners.name') }} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm"
                               x-model="form.name"
                               :class="{ 'is-invalid': errors.name }"
                               placeholder="Nome completo / razão social">
                        <div class="invalid-feedback" x-text="errors.name?.[0]"></div>
                    </div>

                    {{-- E-mail --}}
                    <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">{{ __('actions.partners.email') }} <span class="text-danger">*</span></label>
                        <input type="email" class="form-control form-control-sm"
                               x-model="form.email"
                               :disabled="isEdit"
                               :class="{ 'is-invalid': errors.email }"
                               placeholder="email@parceiro.com">
                        <div class="invalid-feedback" x-text="errors.email?.[0]"></div>
                        <div class="form-text" x-show="!isEdit">Um e-mail de definição de senha será enviado automaticamente.</div>
                    </div>

                    {{-- Tipo --}}
                    <div class="col-12 col-md-4">
                        <label class="form-label form-label-sm">{{ __('actions.partners.type') }} <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm"
                                x-model="form.type"
                                :class="{ 'is-invalid': errors.type }"
                                @change="onTypeChange()">
                            <option value="">{{ __('actions.partners.select_placeholder') }}</option>
                            @foreach($partnerTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback" x-text="errors.type?.[0]"></div>
                    </div>

                    {{-- Comissão --}}
                    <div class="col-12 col-md-4">
                        <label class="form-label form-label-sm">{{ __('actions.partners.commission_rate') }}</label>
                        <div class="input-group input-group-sm">
                            <input type="number" step="0.01" min="0" max="100"
                                   class="form-control form-control-sm"
                                   x-model="form.commission_rate"
                                   :class="{ 'is-invalid': errors.commission_rate }"
                                   placeholder="0.00">
                            <span class="input-group-text">%</span>
                            <div class="invalid-feedback" x-text="errors.commission_rate?.[0]"></div>
                        </div>
                    </div>

                    {{-- Documento --}}
                    <div class="col-12 col-md-4">
                        <label class="form-label form-label-sm">{{ __('actions.partners.document') }}</label>
                        <input type="text" class="form-control form-control-sm"
                               x-model="form.document"
                               :class="{ 'is-invalid': errors.document }"
                               placeholder="00.000.000/0000-00">
                        <div class="invalid-feedback" x-text="errors.document?.[0]"></div>
                    </div>

                    {{-- Status (somente no edit) --}}
                    <div class="col-12 col-md-4" x-show="isEdit">
                        <label class="form-label form-label-sm">{{ __('actions.partners.status') }}</label>
                        <select class="form-select form-select-sm" x-model="form.status">
                            <option value="active">{{ __('actions.partners.status_active') }}</option>
                            <option value="inactive">{{ __('actions.partners.status_inactive') }}</option>
                            <option value="suspended">{{ __('actions.partners.status_suspended') }}</option>
                        </select>
                    </div>

                    {{-- Observações --}}
                    <div class="col-12">
                        <label class="form-label form-label-sm">{{ __('actions.partners.notes') }}</label>
                        <textarea class="form-control form-control-sm" rows="2"
                                  x-model="form.notes"
                                  :class="{ 'is-invalid': errors.notes }"
                                  placeholder="Observações internas..."></textarea>
                        <div class="invalid-feedback" x-text="errors.notes?.[0]"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary btn-sm" @click="save()" :disabled="saving">
                    <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                    <span x-text="isEdit ? '{{ __('actions.partners.save_changes') }}' : '{{ __('actions.partners.register_partner') }}'"></span>
                </button>
            </div>
        </div>
    </div>
</div>
