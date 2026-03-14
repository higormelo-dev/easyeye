<div class="col-md-4">
    <label class="form-label">{{ __('forms.category') }}</label>
    <select class="form-select" :class="{ 'is-invalid': hasError('category') }" x-model="form.category">
        <option value="">{{ __('forms.select') }}...</option>
        @foreach($categories as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>
    <div class="invalid-feedback" x-text="firstError('category')"></div>
</div>
