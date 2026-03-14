<div class="row g-3">
    <div class="col-12">
        <label class="form-label">{{ __('forms.name') }} <span class="text-danger">*</span></label>
        <input type="text" class="form-control" :class="{ 'is-invalid': hasError('name') }"
               x-model="form.name" maxlength="200" autocomplete="off">
        <div class="invalid-feedback" x-text="firstError('name')"></div>
    </div>

    @includeIf('system.settings._partials.' . $viewSlot . '.form')

    <div class="col-md-4" x-show="editing">
        <label class="form-label">{{ __('forms.active') }} <span class="text-danger">*</span></label>
        <select class="form-select" x-model="form.active">
            <option :value="true">{{ __('forms.yes') }}</option>
            <option :value="false">{{ __('forms.no') }}</option>
        </select>
    </div>
</div>
