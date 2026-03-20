<fieldset>
    <div class="table-responsive">
        <table class="table">
            <tbody>
                <tr>
                    <th width="30%">{{ __('actions.code') }}</th>
                    <td>
                        {{ $schedule->code }}
                        <button type="button"
                                class="btn btn-link btn-sm p-0 ms-1 text-muted copy-btn"
                                data-copy="{{ $schedule->code }}"
                                title="Copiar código">
                            <i class="fas fa-copy fa-xs"></i>
                        </button>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('actions.patient') }}</th>
                    <td>
                        @if($schedule->patient_id && $schedule->patient)
                            {{ $schedule->patient->person->full_name }}
                            <small class="text-muted">
                                ({{ $schedule->patient->present()->getCode }})
                                <button type="button"
                                        class="btn btn-link btn-sm p-0 ms-1 text-muted copy-btn"
                                        data-copy="{{ $schedule->patient->present()->getCode }}"
                                        title="Copiar código do paciente">
                                    <i class="fas fa-copy fa-xs"></i>
                                </button>
                            </small>
                        @else
                            {{ $schedule->full_name }}
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>{{ __('actions.doctor') }}</th>
                    <td>
                        @if($schedule->doctor)
                            {{ $schedule->doctor->entityUser->user->name }}
                            <small class="text-muted">
                                ({{ $schedule->doctor->code }})
                                <button type="button"
                                        class="btn btn-link btn-sm p-0 ms-1 text-muted copy-btn"
                                        data-copy="{{ $schedule->doctor->code }}"
                                        title="Copiar código do médico">
                                    <i class="fas fa-copy fa-xs"></i>
                                </button>
                            </small>
                            @if($schedule->doctor->record)
                                <br>
                                CRM {{ $schedule->doctor->record }}
                            @endif
                        @else
                            —
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>{{ __('actions.date_time') }}</th>
                    <td>{{ $schedule->date_time->format('d/m/Y H:i') }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.covenant') }}</th>
                    <td>{{ $schedule->covenant?->name ?? __('actions.not_informed') }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.visit_type') }}</th>
                    <td>{{ $schedule->visitType?->name ?? __('actions.not_informed') }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.situation') }}</th>
                    <td>
                        <span class="badge {{ $schedule->situation->badgeClass() }}">
                            {{ $schedule->situation->label() }}
                        </span>
                    </td>
                </tr>
                @if($schedule->telephone)
                    <tr>
                        <th>{{ __('forms.telephone') }}</th>
                        <td>{{ $schedule->telephone }}</td>
                    </tr>
                @endif
                @if($schedule->cellphone)
                    <tr>
                        <th>{{ __('actions.cellphone') }}</th>
                        <td>
                            {{ $schedule->cellphone }}
                            @if($schedule->cellphone_whatsapp)
                                <i class="fab fa-whatsapp text-success ms-1"></i>
                            @endif
                        </td>
                    </tr>
                @endif
                @if($schedule->arrived_at)
                    <tr>
                        <th>{{ __('actions.arrived_at') }}</th>
                        <td>{{ $schedule->arrived_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @endif
                <tr>
                    <th>{{ __('actions.created_at') }}</th>
                    <td>{{ $schedule->created_at->format('d/m/Y H:i') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</fieldset>

