{{--
    Modal de criação/edição de agendamento.
    Deve ser incluído dentro de uma página que tenha o componente Alpine `crudForm` no wrapper.

    Exemplo de uso na index.blade.php:

    <div x-data="crudForm({
        storeUrl: '{{ route('panel.schedules.store') }}',
        fields: { entity_id: '', doctor_id: '', patient_id: '', full_name: '', date_time: '',
                  telephone: '', cellphone: '', cellphone_whatsapp: false,
                  covenant_id: '', visit_id: '', situation: 1 },
        onSuccess: () => $dispatch('schedule-saved')
    })">
        @include('system.schedules._form-modal')
    </div>
--}}
<x-crud-modal id="scheduleModal" title="Agendamento">
    <x-slot:body>
        <div class="row g-3">

            {{-- Médico --}}
            <div class="col-md-6">
                <label class="form-label">Médico <span class="text-danger">*</span></label>
                <select class="form-select" :class="{ 'is-invalid': hasError('doctor_id') }" x-model="form.doctor_id">
                    <option value="">Selecione...</option>
                    @foreach($doctors as $doctor)
                        <option value="{{ $doctor->id }}">{{ $doctor->user_name }}</option>
                    @endforeach
                </select>
                <div class="invalid-feedback" x-text="firstError('doctor_id')"></div>
            </div>

            {{-- Data e Hora --}}
            {{--
                x-modelable="doctorId" + x-model="form.doctor_id":
                  crudForm.form.doctor_id ──► slotPicker.doctorId (two-way via x-modelable)
                @slot-selected: slotPicker dispatches this event → pai seta form.date_time
            --}}
            <div class="col-md-6"
                 x-data="slotPicker(@js(route('panel.schedules.slots')))"
                 x-modelable="doctorId"
                 x-model="form.doctor_id">

                <label class="form-label">Data e Hora <span class="text-danger">*</span></label>

                {{-- Seletor de data --}}
                <input type="date"
                       class="form-control mb-2"
                       x-model="selectedDate"
                       :disabled="! doctorId"
                       :min="new Date().toISOString().substring(0, 10)">

                {{-- Carregando --}}
                <div x-show="loadingSlots" x-cloak class="text-muted small py-1">
                    <span class="spinner-border spinner-border-sm me-1"></span> Carregando horários…
                </div>

                {{-- Grid de slots (médico com escala) --}}
                <template x-if="! loadingSlots && hasSchedule && slots.length > 0">
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        <template x-for="slot in slots" :key="slot.datetime">
                            <button type="button"
                                    class="btn btn-xs px-2 py-1"
                                    style="font-size:.78rem; min-width:52px;"
                                    :class="{
                                        'btn-primary':   isSelected(slot),
                                        'btn-outline-primary':   ! isSelected(slot) && slot.available,
                                        'btn-outline-secondary opacity-50 text-decoration-line-through': ! slot.available
                                    }"
                                    :disabled="! slot.available"
                                    @click="selectSlot(slot)"
                                    x-text="slot.time">
                            </button>
                        </template>
                    </div>
                </template>

                {{-- Médico não atende neste dia --}}
                <template x-if="! loadingSlots && hasSchedule && slots.length === 0 && selectedDate">
                    <p class="text-muted small mt-1 mb-0">
                        <i class="fas fa-calendar-times me-1"></i> Médico não atende neste dia.
                    </p>
                </template>

                {{-- Fallback: médico sem escala configurada — slots gerados pelo intervalo (07:00–19:00) --}}
                <template x-if="! loadingSlots && ! hasSchedule && selectedDate">
                    <div>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            <template x-for="slot in generateDefaultTimes()" :key="slot.datetime">
                                <button type="button"
                                        class="btn btn-xs px-2 py-1"
                                        style="font-size:.78rem; min-width:52px;"
                                        :class="selectedDatetime === slot.datetime
                                            ? 'btn-primary'
                                            : 'btn-outline-primary'"
                                        @click="selectedDatetime = slot.datetime; $dispatch('slot-selected', { datetime: slot.datetime })"
                                        x-text="slot.time">
                                </button>
                            </template>
                        </div>
                        <small class="text-muted mt-2 d-block">
                            <i class="fas fa-info-circle me-1"></i>
                            Intervalo de <span x-text="interval"></span> min (sem escala definida)
                        </small>
                    </div>
                </template>

            </div>
            {{-- Erro de data_time fora do x-data do slotPicker → escopo do crudForm --}}
            <div class="col-12 mt-n2 pb-0"
                 x-show="hasError('date_time')"
                 x-cloak>
                <div class="invalid-feedback d-block" x-text="firstError('date_time')"></div>
            </div>

            {{-- Paciente (busca + cadastro rápido) --}}
            <div class="col-md-12"
                 x-data="patientSearch(
                     @js(route('panel.patients.search')),
                     @js(route('panel.patients.quick'))
                 )"
                 x-init="$nextTick(() => document.getElementById('scheduleModal').addEventListener('hidden.bs.modal', () => reset()))">

                <label class="form-label">Paciente</label>

                {{-- Paciente já selecionado --}}
                <template x-if="selectedPatient">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-info text-dark fs-6 fw-normal px-3 py-2"
                              x-text="selectedPatient.full_name + ' (' + selectedPatient.code + ')'"></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="clear()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </template>

                {{-- Campo de busca --}}
                <div x-show="!selectedPatient" class="position-relative">
                    <input type="text"
                           class="form-control"
                           x-model="query"
                           @input.debounce.300ms="search()"
                           @blur="closeResults()"
                           placeholder="Buscar por nome, CPF ou celular…">

                    {{-- Spinner --}}
                    <span x-show="searching" x-cloak
                          class="position-absolute top-50 end-0 translate-middle-y pe-3">
                        <span class="spinner-border spinner-border-sm text-secondary"></span>
                    </span>

                    {{-- Dropdown: resultados --}}
                    <ul x-show="showResults && results.length" x-cloak
                        class="list-group position-absolute w-100 shadow-sm"
                        style="z-index:1055;max-height:220px;overflow-y:auto">
                        <template x-for="p in results" :key="p.id">
                            <li class="list-group-item list-group-item-action"
                                style="cursor:pointer"
                                @mousedown.prevent="select(p)">
                                <span x-text="p.full_name"></span>
                                <small class="text-muted ms-2" x-text="p.code"></small>
                            </li>
                        </template>
                        <li class="list-group-item list-group-item-action text-primary"
                            style="cursor:pointer"
                            @mousedown.prevent="prefillCreate()">
                            <i class="fas fa-plus-circle me-1"></i>
                            Cadastrar "<span x-text="query"></span>"
                        </li>
                    </ul>

                    {{-- Dropdown: sem resultados --}}
                    <ul x-show="showResults && !results.length && query.length >= 2 && !searching" x-cloak
                        class="list-group position-absolute w-100 shadow-sm"
                        style="z-index:1055">
                        <li class="list-group-item list-group-item-action text-primary"
                            style="cursor:pointer"
                            @mousedown.prevent="prefillCreate()">
                            <i class="fas fa-plus-circle me-1"></i>
                            Cadastrar "<span x-text="query"></span>"
                        </li>
                    </ul>
                </div>

                {{-- Formulário de cadastro rápido --}}
                <div x-show="showCreate" x-cloak class="card card-body bg-light mt-2 p-3">
                    <p class="mb-2 fw-semibold small">Cadastro rápido de paciente</p>
                    <div class="row g-2">
                        <div class="col-md-7">
                            <input type="text"
                                   class="form-control form-control-sm"
                                   x-model="newPatient.name"
                                   placeholder="Nome completo *">
                        </div>
                        <div class="col-md-5">
                            <input type="text"
                                   class="form-control form-control-sm"
                                   x-model="newPatient.cellphone"
                                   x-mask-br="'cel'" maxlength="15"
                                   placeholder="Celular *">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button"
                                class="btn btn-sm btn-primary"
                                :disabled="creating"
                                @click="createAndLink()">
                            <span x-show="creating" class="spinner-border spinner-border-sm me-1"></span>
                            Salvar e vincular
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                @click="showCreate = false">
                            Cancelar
                        </button>
                    </div>
                </div>

            </div>

            {{-- Nome completo (preenchido pela busca ou manualmente) --}}
            <div class="col-md-12">
                <label class="form-label">Nome completo <span class="text-danger">*</span></label>
                <input type="text"
                       class="form-control"
                       :class="{ 'is-invalid': hasError('full_name') }"
                       x-model="form.full_name"
                       placeholder="Nome do paciente">
                <div class="invalid-feedback" x-text="firstError('full_name')"></div>
            </div>

            {{-- Convênio --}}
            <div class="col-md-6">
                <label class="form-label">Convênio</label>
                <select class="form-select" x-model="form.covenant_id">
                    <option value="">Nenhum</option>
                    @foreach($covenants as $covenant)
                        <option value="{{ $covenant->id }}">{{ $covenant->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Tipo de visita --}}
            <div class="col-md-6">
                <label class="form-label">Tipo de consulta</label>
                <select class="form-select" x-model="form.visit_id">
                    <option value="">Nenhum</option>
                    @foreach($visitTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Telefone --}}
            <div class="col-md-6">
                <label class="form-label">Telefone</label>
                <input type="text" class="form-control" x-model="form.telephone" x-mask-br="'tel'" maxlength="14" placeholder="(00) 0000-0000">
            </div>

            {{-- Celular --}}
            <div class="col-md-6">
                <label class="form-label">Celular</label>
                <input type="text" class="form-control" x-model="form.cellphone" x-mask-br="'cel'" maxlength="15" placeholder="(00) 00000-0000">
            </div>

            {{-- WhatsApp --}}
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="scheduleWhatsapp" x-model="form.cellphone_whatsapp">
                    <label class="form-check-label" for="scheduleWhatsapp">
                        <i class="fab fa-whatsapp text-success"></i> Celular é WhatsApp
                    </label>
                </div>
            </div>

            {{-- Observações --}}
            <div class="col-md-12">
                <label class="form-label">Observações</label>
                <textarea class="form-control"
                          x-model="form.notes"
                          rows="2"
                          placeholder="Informações adicionais sobre o agendamento..."></textarea>
            </div>

        </div>
    </x-slot:body>
</x-crud-modal>
