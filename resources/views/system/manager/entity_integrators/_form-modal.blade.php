<x-crud-modal id="integratorModal" title="Integrador">
    <x-slot:body>
        <div class="row g-3">

            <div class="col-12">
                <label class="form-label">Nome <span class="text-danger">*</span></label>
                <input type="text" class="form-control" :class="{ 'is-invalid': hasError('name') }"
                       x-model="form.name" maxlength="100" autocomplete="off">
                <div class="invalid-feedback" x-text="firstError('name')"></div>
            </div>

            <div class="col-md-6">
                <label class="form-label">IP</label>
                <input type="text" class="form-control" :class="{ 'is-invalid': hasError('ip') }"
                       x-model="form.ip" maxlength="15" placeholder="192.168.1.1" autocomplete="off">
                <div class="invalid-feedback" x-text="firstError('ip')"></div>
            </div>

            <div class="col-md-6">
                <label class="form-label">MAC</label>
                <input type="text" class="form-control" :class="{ 'is-invalid': hasError('mac') }"
                       x-model="form.mac" maxlength="17" placeholder="00:1B:44:11:3A:B7"
                       style="text-transform: uppercase;" autocomplete="off">
                <div class="invalid-feedback" x-text="firstError('mac')"></div>
            </div>

            <div class="col-md-4" x-show="editing">
                <label class="form-label">{{ __('forms.active') }}</label>
                <select class="form-select" x-model="form.active">
                    <option :value="true">{{ __('forms.yes') }}</option>
                    <option :value="false">{{ __('forms.no') }}</option>
                </select>
            </div>

        </div>
    </x-slot:body>
</x-crud-modal>
