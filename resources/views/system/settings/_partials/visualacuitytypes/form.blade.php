<div class="col-md-3">
    <label class="form-label">{{ __('forms.scale') }} <span class="text-danger">*</span></label>
    <input type="number" class="form-control" :class="{ 'is-invalid': hasError('scale') }"
           x-model="form.scale" min="0" max="999" autocomplete="off">
    <div class="invalid-feedback" x-text="firstError('scale')"></div>
</div>
