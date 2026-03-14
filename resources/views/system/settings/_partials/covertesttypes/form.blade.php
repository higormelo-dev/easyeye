<div class="col-md-4">
    <label class="form-label">{{ __('forms.abbreviation') }}</label>
    <input type="text" class="form-control" :class="{ 'is-invalid': hasError('abbreviation') }"
           x-model="form.abbreviation" maxlength="200" autocomplete="off">
    <div class="invalid-feedback" x-text="firstError('abbreviation')"></div>
</div>
