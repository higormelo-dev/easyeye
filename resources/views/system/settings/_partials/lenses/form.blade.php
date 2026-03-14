<div class="col-md-3">
    <label class="form-label">{{ __('forms.away') }} <span class="text-danger">*</span></label>
    <select class="form-select" x-model="form.away">
        <option :value="true">{{ __('forms.yes') }}</option>
        <option :value="false">{{ __('forms.no') }}</option>
    </select>
</div>
<div class="col-md-3">
    <label class="form-label">{{ __('forms.near') }} <span class="text-danger">*</span></label>
    <select class="form-select" x-model="form.near">
        <option :value="true">{{ __('forms.yes') }}</option>
        <option :value="false">{{ __('forms.no') }}</option>
    </select>
</div>
