{{-- Extra fields for Clinic Resources (type + description) --}}

<div class="col-md-6">
    <label class="form-label">Tipo <span class="text-danger">*</span></label>
    <select class="form-select" :class="{ 'is-invalid': hasError('type') }" x-model="form.type">
        <option value="room">Sala</option>
        <option value="equipment">Equipamento</option>
    </select>
    <div class="invalid-feedback" x-text="firstError('type')"></div>
</div>

<div class="col-12">
    <label class="form-label">Descrição</label>
    <input type="text"
           class="form-control"
           :class="{ 'is-invalid': hasError('description') }"
           x-model="form.description"
           maxlength="500"
           placeholder="Ex: Sala com OCT Zeiss Cirrus 5000">
    <div class="invalid-feedback" x-text="firstError('description')"></div>
</div>
