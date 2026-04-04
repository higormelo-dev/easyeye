<x-crud-modal id="cashEntryModal" title="Lançamento financeiro" saveLabel="Salvar lançamento">
    <x-slot:body>
        <div class="row g-2">
            <div class="col-12">
                <label class="form-label small fw-semibold">Data</label>
                <input type="date"
                       x-model="form.entry_date"
                       :class="['form-control form-control-sm', hasError('entry_date') ? 'is-invalid' : '']">
                <div class="invalid-feedback" x-show="hasError('entry_date')" x-text="firstError('entry_date')"></div>
            </div>

            <div class="col-12">
                <label class="form-label small fw-semibold">Descrição</label>
                <input type="text"
                       x-model="form.description"
                       :class="['form-control form-control-sm', hasError('description') ? 'is-invalid' : '']">
                <div class="invalid-feedback" x-show="hasError('description')" x-text="firstError('description')"></div>
            </div>

            <div class="col-6">
                <label class="form-label small fw-semibold">Tipo</label>
                <select x-model="form.type"
                        :class="['form-select form-select-sm', hasError('type') ? 'is-invalid' : '']">
                    <option value="income">Receita</option>
                    <option value="expense">Despesa</option>
                </select>
                <div class="invalid-feedback" x-show="hasError('type')" x-text="firstError('type')"></div>
            </div>

            <div class="col-6">
                <label class="form-label small fw-semibold">Status</label>
                <select x-model="form.status"
                        :class="['form-select form-select-sm', hasError('status') ? 'is-invalid' : '']">
                    <option value="paid">Pago</option>
                    <option value="pending">Pendente</option>
                    <option value="cancelled">Cancelado</option>
                </select>
                <div class="invalid-feedback" x-show="hasError('status')" x-text="firstError('status')"></div>
            </div>

            <div class="col-12">
                <label class="form-label small fw-semibold">Categoria</label>
                <select x-model="form.category_id"
                        :class="['form-select form-select-sm', hasError('category_id') ? 'is-invalid' : '']">
                    <option value="">Sem categoria</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <div class="invalid-feedback" x-show="hasError('category_id')" x-text="firstError('category_id')"></div>
            </div>

            <div class="col-12">
                <label class="form-label small fw-semibold">Valor (R$)</label>
                <input type="number"
                       min="0"
                       step="0.01"
                       x-model="form.amount"
                       :class="['form-control form-control-sm', hasError('amount') ? 'is-invalid' : '']">
                <div class="invalid-feedback" x-show="hasError('amount')" x-text="firstError('amount')"></div>
            </div>

            <div class="col-12">
                <label class="form-label small fw-semibold">Forma de pagamento</label>
                <input type="text"
                       x-model="form.payment_method"
                       placeholder="pix, cartão, transferência..."
                       :class="['form-control form-control-sm', hasError('payment_method') ? 'is-invalid' : '']">
                <div class="invalid-feedback" x-show="hasError('payment_method')" x-text="firstError('payment_method')"></div>
            </div>

            <div class="col-12">
                <label class="form-label small fw-semibold">Observações</label>
                <textarea x-model="form.notes"
                          rows="4"
                          :class="['form-control form-control-sm', hasError('notes') ? 'is-invalid' : '']"></textarea>
                <div class="invalid-feedback" x-show="hasError('notes')" x-text="firstError('notes')"></div>
            </div>
        </div>
    </x-slot:body>
</x-crud-modal>
