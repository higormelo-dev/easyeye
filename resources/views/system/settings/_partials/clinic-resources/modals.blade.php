{{--
    Modal de disponibilidade (escala + bloqueios) para salas/equipamentos.
    Incluído via @includeIf em system.settings.index quando viewSlot = 'clinic-resources'.
--}}
<div class="modal fade"
     id="resourceScheduleModal"
     tabindex="-1"
     aria-labelledby="resourceScheduleModalLabel"
     aria-hidden="true"
     x-data="resourceScheduleModalData()">

    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">

            {{-- Header --}}
            <div class="modal-header border-bottom px-4 py-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-calendar-alt text-info me-1"></i>
                    <h5 class="modal-title mb-0 fw-semibold fs-6" id="resourceScheduleModalLabel">
                        Disponibilidade
                        <span x-show="resourceName" x-cloak class="fw-normal text-muted">
                            — <span x-text="resourceName"></span>
                            <small class="text-secondary ms-1" x-text="resourceTypeLabel"></small>
                        </span>
                    </h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            {{-- Loading --}}
            <div x-show="loading" x-cloak class="modal-body text-center py-5">
                <div class="spinner-border text-secondary" role="status"></div>
                <p class="mt-2 text-muted small">Carregando disponibilidade...</p>
            </div>

            {{-- Body --}}
            <div x-show="!loading" x-cloak class="modal-body p-0">
                <div class="row g-0">

                    {{-- ══ Coluna esquerda — Escala semanal ══ --}}
                    <div class="col-lg-8 border-end p-4">

                        <p class="text-muted small mb-4">
                            <i class="fas fa-info-circle me-1"></i>
                            Defina os dias e horários em que este recurso está disponível para uso.
                            Se nenhuma escala for definida, o recurso será considerado disponível a qualquer horário.
                        </p>

                        {{-- Grade de dias --}}
                        <template x-for="(day, dayIndex) in days" :key="day.day">
                            <div class="card border-0 shadow-sm rounded-3 mb-2 overflow-hidden">

                                <div class="d-flex align-items-center px-3 py-2 gap-2"
                                     :style="day.active ? 'background:#fff;' : 'background:#f8f9fa;'">
                                    <div class="form-check form-switch mb-0 flex-grow-1">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               :id="'rs-day-' + day.day"
                                               x-model="day.active"
                                               style="cursor:pointer; width:2.2em; height:1.2em;">
                                        <label class="form-check-label fw-semibold ms-1"
                                               :for="'rs-day-' + day.day"
                                               x-text="day.name"
                                               :class="day.active ? 'text-dark' : 'text-muted'"
                                               style="cursor:pointer;"></label>
                                    </div>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-info rounded-pill px-3 flex-shrink-0"
                                            style="font-size:.78rem; white-space:nowrap;"
                                            @click="addRange(dayIndex)">
                                        <i class="fas fa-plus me-1" style="font-size:.7rem;"></i>
                                        Adicionar horário
                                    </button>
                                </div>

                                <div x-show="day.active" class="px-3 pb-3 pt-1 bg-white">
                                    <template x-for="(range, rangeIndex) in day.ranges" :key="rangeIndex">
                                        <div class="d-flex align-items-center gap-2 mt-2">
                                            <span class="text-muted small flex-shrink-0">De</span>
                                            <input type="time" class="form-control form-control-sm" style="max-width:120px;" x-model="range.starts_at">
                                            <span class="text-muted small flex-shrink-0">até</span>
                                            <input type="time" class="form-control form-control-sm" style="max-width:120px;" x-model="range.ends_at">
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger flex-shrink-0"
                                                    x-show="day.ranges.length > 1"
                                                    @click="removeRange(dayIndex, rangeIndex)">
                                                <i class="fas fa-times" style="font-size:.75rem;"></i>
                                            </button>
                                        </div>
                                    </template>
                                </div>

                                <div x-show="!day.active" class="px-3 pb-2 bg-light">
                                    <small class="text-muted">Indisponível neste dia.</small>
                                </div>

                            </div>
                        </template>

                    </div>

                    {{-- ══ Coluna direita — Bloqueios ══ --}}
                    <div class="col-lg-4 p-4">

                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <strong class="text-dark" style="font-size:.9rem;">Bloqueios</strong>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary d-flex align-items-center justify-content-center rounded-circle flex-shrink-0"
                                    style="width:28px; height:28px; padding:0;"
                                    @click="showBlockForm = !showBlockForm">
                                <i :class="showBlockForm ? 'fas fa-times' : 'fas fa-plus'" style="font-size:.72rem;"></i>
                            </button>
                        </div>

                        <div x-show="showBlockForm" x-cloak class="mb-3 p-3 rounded-3 border" style="background:#f8f9fa;">
                            <div class="row g-2">
                                <div class="col-12">
                                    <select class="form-select form-select-sm" x-model="blockForm.type">
                                        <option value="absence">Indisponível</option>
                                        <option value="holiday">Feriado</option>
                                        <option value="meeting">Manutenção</option>
                                        <option value="other">Outro</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Início <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control form-control-sm"
                                           :class="{ 'is-invalid': blockErrors.starts_at }"
                                           x-model="blockForm.starts_at">
                                    <div class="invalid-feedback" x-text="blockErrors.starts_at?.[0]"></div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Fim <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control form-control-sm"
                                           :class="{ 'is-invalid': blockErrors.ends_at }"
                                           x-model="blockForm.ends_at">
                                    <div class="invalid-feedback" x-text="blockErrors.ends_at?.[0]"></div>
                                </div>
                                <div class="col-12">
                                    <input type="text" class="form-control form-control-sm"
                                           x-model="blockForm.reason" placeholder="Motivo (opcional)">
                                </div>
                                <div class="col-12">
                                    <button type="button" class="btn btn-sm btn-danger w-100"
                                            @click="storeBlock()" :disabled="storingBlock">
                                        <span x-show="storingBlock" class="spinner-border spinner-border-sm me-1"></span>
                                        Adicionar Bloqueio
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div x-show="blocks.length === 0 && !showBlockForm" class="text-center py-4">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success text-white mb-2"
                                 style="width:48px; height:48px;">
                                <i class="fas fa-check fa-lg"></i>
                            </div>
                            <p class="text-muted small mb-0">Nenhum bloqueio futuro.</p>
                        </div>

                        <template x-for="block in blocks" :key="block.id">
                            <div class="d-flex align-items-start gap-2 py-2 border-bottom">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold text-dark" style="font-size:.8rem;" x-text="block.type_label"></div>
                                    <div class="text-muted" style="font-size:.75rem;">
                                        <span x-text="block.starts_at"></span>
                                        <i class="fas fa-arrow-right mx-1" style="font-size:.6rem; opacity:.5;"></i>
                                        <span x-text="block.ends_at"></span>
                                    </div>
                                    <div class="text-secondary" style="font-size:.75rem;" x-show="block.reason" x-text="block.reason"></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" @click="destroyBlock(block.id)">
                                    <i class="fas fa-trash" style="font-size:.72rem;"></i>
                                </button>
                            </div>
                        </template>

                    </div>

                </div>{{-- /row --}}
            </div>{{-- /body --}}

            {{-- Footer --}}
            <div x-show="!loading" x-cloak class="modal-footer border-top px-4 py-3">
                <span x-show="saveError" x-cloak class="text-danger small me-auto" x-text="saveError"></span>
                <span x-show="saveSuccess" x-cloak class="text-success small me-auto">
                    <i class="fas fa-check-circle me-1"></i> Disponibilidade salva.
                </span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-info text-white px-4" @click="save()" :disabled="saving">
                    <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                    Salvar Disponibilidade
                </button>
            </div>

        </div>
    </div>
</div>
