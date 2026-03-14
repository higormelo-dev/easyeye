<div class="col-md-4">
    <label class="form-label">{{ __('forms.color') }} <span class="text-danger">*</span></label>
    <input type="color" class="form-control form-control-color w-100"
           x-model="form.color" autocomplete="off">
</div>
<div class="col-md-4">
    <label class="form-label">{{ __('forms.table') }} <span class="text-danger">*</span></label>
    <select class="form-select" x-model="form.table">
        <option :value="true">{{ __('forms.yes') }}</option>
        <option :value="false">{{ __('forms.no') }}</option>
    </select>
</div>
