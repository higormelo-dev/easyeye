import { useState, useEffect, useCallback, useRef } from 'react';
import { router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import PageHeader from '@/Components/UI/PageHeader';
import Modal from '@/Components/UI/Modal';
import FormInput, { FormSelect, FormSwitch } from '@/Components/UI/FormInput';
import { FormTextarea } from '@/Components/UI/FormInput';
import Badge from '@/Components/UI/Badge';

const situationBadge = {
    1: { label: 'Agendado', variant: 'info' },
    2: { label: 'Confirmado', variant: 'primary' },
    3: { label: 'Aguardando', variant: 'warning' },
    4: { label: 'Em Atendimento', variant: 'info' },
    5: { label: 'Atendido', variant: 'success' },
    6: { label: 'Não Compareceu', variant: 'danger' },
    7: { label: 'Cancelado', variant: 'danger' },
};

const bouts = [
    { value: 1, label: 'Todos' },
    { value: 2, label: 'Manhã' },
    { value: 3, label: 'Tarde' },
    { value: 4, label: 'Noite' },
];

const emptyForm = {
    patient_id: '', doctor_id: '', covenant_id: '', visit_id: '',
    full_name: '', date_time: '', telephone: '', cellphone: '',
    cellphone_whatsapp: false, notes: '', recurrence_type: '', recurrence_until: '',
};

export default function SchedulesIndex({ doctors, covenants, visitTypes, loggedDoctor }) {
    const [date, setDate] = useState(todayFormatted());
    const [doctorFilter, setDoctorFilter] = useState(loggedDoctor ? loggedDoctor.id : 'tudo');
    const [bout, setBout] = useState(1);
    const [search, setSearch] = useState('');
    const [schedules, setSchedules] = useState([]);
    const [events, setEvents] = useState([]);
    const [loading, setLoading] = useState(true);
    const [showModal, setShowModal] = useState(false);
    const [form, setForm] = useState({ ...emptyForm });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [selectedIds, setSelectedIds] = useState([]);
    const [showDetailModal, setShowDetailModal] = useState(false);
    const [detailSchedule, setDetailSchedule] = useState(null);

    // Patient search
    const [patientSearch, setPatientSearch] = useState('');
    const [patientResults, setPatientResults] = useState([]);
    const [selectedPatient, setSelectedPatient] = useState(null);

    const onChange = (name, value) => setForm((f) => ({ ...f, [name]: value }));

    const fetchSchedules = useCallback(async () => {
        setLoading(true);
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const params = new FormData();
            params.append('date', date);
            params.append('doctor', doctorFilter);
            params.append('bout', bout);
            params.append('search', search);

            const res = await fetch('/panel/schedules/ajaxlist', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Inertia-Partial-Data': 'schedules',
                },
                body: params,
            });

            const data = await res.json();
            setSchedules(data.items || []);
        } catch (err) {
            console.error('Fetch error:', err);
        } finally {
            setLoading(false);
        }
    }, [date, doctorFilter, bout, search]);

    useEffect(() => {
        fetchSchedules();
    }, [fetchSchedules]);

    // Patient search for modal
    useEffect(() => {
        if (patientSearch.length < 2) {
            setPatientResults([]);
            return;
        }
        const timer = setTimeout(async () => {
            try {
                const res = await fetch(`/panel/patients/search?q=${encodeURIComponent(patientSearch)}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                setPatientResults(data);
            } catch { /* ignore */ }
        }, 300);
        return () => clearTimeout(timer);
    }, [patientSearch]);

    const selectPatient = (patient) => {
        setSelectedPatient(patient);
        setForm((f) => ({
            ...f,
            patient_id: patient.id,
            full_name: patient.full_name,
            cellphone: patient.cellphone || '',
            telephone: patient.telephone || '',
        }));
        setPatientSearch('');
        setPatientResults([]);
    };

    const openCreate = () => {
        setForm({ ...emptyForm, date_time: `${isoDate(date)}T${defaultTime()}` });
        setSelectedPatient(null);
        setErrors({});
        setShowModal(true);
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const res = await fetch('/panel/schedules', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(form),
            });
            const data = await res.json();

            if (!res.ok) {
                if (res.status === 422 && data.errors) {
                    setErrors(data.errors);
                } else {
                    alert(data.message || 'Erro ao salvar');
                }
                return;
            }

            setShowModal(false);
            fetchSchedules();
        } catch (err) {
            console.error(err);
        } finally {
            setProcessing(false);
        }
    };

    const viewDetail = async (scheduleId) => {
        try {
            const res = await fetch(`/panel/schedules/${scheduleId}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setDetailSchedule(json.data);
            setShowDetailModal(true);
        } catch { /* ignore */ }
    };

    const updateSituation = async (scheduleId, situation, notes = null) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            await fetch(`/panel/schedules/${scheduleId}/situation`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ situation, notes }),
            });
            fetchSchedules();
            setShowDetailModal(false);
        } catch { /* ignore */ }
    };

    const navigateDate = (delta) => {
        const d = parseDate(date);
        d.setDate(d.getDate() + delta);
        setDate(formatDate(d));
    };

    return (
        <AuthenticatedLayout title="Agendamentos">
            <FlashMessages />

            <PageHeader title="Agendamentos">
                <button className="btn btn-primary btn-md fs-13" onClick={openCreate}>
                    <i className="ti ti-plus me-1"></i> Novo Agendamento
                </button>
            </PageHeader>

            {/* Filters */}
            <div className="card border-0 shadow-sm mb-3">
                <div className="card-body py-2">
                    <div className="row g-2 align-items-center">
                        <div className="col-auto">
                            <button className="btn btn-sm btn-outline-secondary" onClick={() => navigateDate(-1)}>
                                <i className="ti ti-chevron-left"></i>
                            </button>
                        </div>
                        <div className="col-auto">
                            <input
                                type="date"
                                className="form-control form-control-sm"
                                value={isoDate(date)}
                                onChange={(e) => setDate(formatFromIso(e.target.value))}
                            />
                        </div>
                        <div className="col-auto">
                            <button className="btn btn-sm btn-outline-secondary" onClick={() => navigateDate(1)}>
                                <i className="ti ti-chevron-right"></i>
                            </button>
                        </div>
                        <div className="col-auto">
                            <button className="btn btn-sm btn-outline-primary" onClick={() => setDate(todayFormatted())}>
                                Hoje
                            </button>
                        </div>
                        {!loggedDoctor && (
                            <div className="col-auto">
                                <select
                                    className="form-select form-select-sm"
                                    value={doctorFilter}
                                    onChange={(e) => setDoctorFilter(e.target.value)}
                                >
                                    <option value="tudo">Todos os médicos</option>
                                    {doctors.map((d) => (
                                        <option key={d.id} value={d.id}>{d.user_name || d.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <div className="col-auto">
                            <div className="btn-group btn-group-sm">
                                {bouts.map((b) => (
                                    <button
                                        key={b.value}
                                        className={`btn btn-${bout === b.value ? 'primary' : 'outline-secondary'}`}
                                        onClick={() => setBout(b.value)}
                                    >
                                        {b.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="col">
                            <div className="input-group input-group-sm">
                                <span className="input-group-text bg-white"><i className="ti ti-search fs-12"></i></span>
                                <input
                                    type="text"
                                    className="form-control border-start-0"
                                    placeholder="Buscar paciente..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Schedule List */}
            {loading ? (
                <div className="text-center py-5">
                    <div className="spinner-border text-primary"><span className="visually-hidden">Carregando...</span></div>
                </div>
            ) : schedules.length > 0 ? (
                <div className="row g-3">
                    {schedules.map(item => (
                        <div key={`${item.type}-${item.id}`} className="col-md-6 col-lg-4">
                            {item.type === 'schedule' ? (
                                <div className="card h-100 shadow-sm" style={{ borderLeft: `4px solid ${item.covenant_color || '#0d6efd'}` }}>
                                    <div className="card-body">
                                        <div className="d-flex justify-content-between align-items-start mb-2">
                                            <h6 className="card-title mb-0 text-truncate fw-bold">{item.time} — {item.full_name}</h6>
                                            <span className={`badge bg-${item.situation_badge}`}>{item.situation_label}</span>
                                        </div>
                                        <p className="card-text small text-muted mb-1">
                                            <i className="ti ti-stethoscope me-1"></i> {item.doctor_name}
                                        </p>
                                        <p className="card-text small text-muted mb-1">
                                            <i className="ti ti-bookmark me-1"></i> {item.covenant_name} · {item.visit_name}
                                        </p>
                                        <div className="mt-3 text-end">
                                            <button className="btn btn-sm btn-light border" onClick={() => viewDetail(item.id)}>
                                                Ações / Detalhes
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="card h-100 shadow-sm bg-light" style={{ borderLeft: '4px solid #6c757d' }}>
                                    <div className="card-body">
                                        <div className="d-flex justify-content-between align-items-start mb-2">
                                            <h6 className="card-title mb-0 text-truncate fw-bold text-dark">{item.time} — {item.title}</h6>
                                            <span className="badge bg-secondary">Evento</span>
                                        </div>
                                        {item.doctor_name && (
                                            <p className="card-text small text-muted mb-1"><i className="ti ti-user me-1"></i> {item.doctor_name}</p>
                                        )}
                                        <p className="card-text small text-dark mb-0">{item.description}</p>
                                    </div>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            ) : (
                <div className="text-center text-muted py-5">
                    <i className="ti ti-calendar-off fs-1 d-block mb-2"></i>
                    Nenhum compromisso para este dia.
                </div>
            )}

            {/* Modal New Schedule */}
            <Modal show={showModal} onClose={() => setShowModal(false)} title="Novo Agendamento" size="lg" footer={
                <>
                    <button className="btn btn-secondary" onClick={() => setShowModal(false)}>Cancelar</button>
                    <button className="btn btn-primary" onClick={handleSubmit} disabled={processing}>
                        {processing ? <><span className="spinner-border spinner-border-sm me-1"></span> Salvando...</> : <><i className="ti ti-check me-1"></i> Salvar</>}
                    </button>
                </>
            }>
                <form onSubmit={handleSubmit}>
                    {/* Patient Search */}
                    <div className="mb-3">
                        <label className="form-label">Paciente <span className="text-danger">*</span></label>
                        {selectedPatient ? (
                            <div className="d-flex align-items-center gap-2">
                                <span className="fw-medium">{selectedPatient.full_name}</span>
                                <code className="small">{selectedPatient.code}</code>
                                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => {
                                    setSelectedPatient(null);
                                    setForm((f) => ({ ...f, patient_id: '', full_name: '' }));
                                }}>
                                    <i className="ti ti-x"></i>
                                </button>
                            </div>
                        ) : (
                            <div className="position-relative">
                                <input
                                    type="text"
                                    className={`form-control${errors?.patient_id ? ' is-invalid' : ''}`}
                                    placeholder="Digite nome, CPF ou código do paciente..."
                                    value={patientSearch}
                                    onChange={(e) => setPatientSearch(e.target.value)}
                                    autoFocus
                                />
                                {patientResults.length > 0 && (
                                    <ul className="list-group position-absolute w-100 shadow" style={{ zIndex: 1050, maxHeight: 200, overflowY: 'auto' }}>
                                        {patientResults.map((p) => (
                                            <li key={p.id} className="list-group-item list-group-item-action cursor-pointer" onClick={() => selectPatient(p)}>
                                                <strong>{p.full_name}</strong> <code className="ms-2">{p.code}</code>
                                                {p.cellphone && <span className="text-muted ms-2 small">{p.cellphone}</span>}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {errors?.patient_id && <div className="invalid-feedback">{errors.patient_id}</div>}
                            </div>
                        )}
                    </div>

                    <div className="row">
                        <div className="col-md-6">
                            <FormSelect label="Médico" name="doctor_id" value={form.doctor_id} onChange={onChange} error={errors?.doctor_id}
                                options={doctors.map((d) => ({ value: d.id, label: d.user_name || d.name }))} required />
                        </div>
                        <div className="col-md-6">
                            <FormInput label="Data e Hora" name="date_time" type="datetime-local" value={form.date_time} onChange={onChange} error={errors?.date_time} required />
                        </div>
                    </div>
                    <div className="row">
                        <div className="col-md-6">
                            <FormSelect label="Convênio" name="covenant_id" value={form.covenant_id} onChange={onChange}
                                options={covenants.map((c) => ({ value: c.id, label: c.name }))} />
                        </div>
                        <div className="col-md-6">
                            <FormSelect label="Tipo de Visita" name="visit_id" value={form.visit_id} onChange={onChange}
                                options={visitTypes.map((v) => ({ value: v.id, label: v.name }))} />
                        </div>
                    </div>
                    <div className="row">
                        <div className="col-md-6">
                            <FormInput label="Telefone" name="telephone" value={form.telephone} onChange={onChange} />
                        </div>
                        <div className="col-md-6">
                            <FormInput label="Celular" name="cellphone" value={form.cellphone} onChange={onChange} />
                        </div>
                    </div>
                    <FormSwitch label="WhatsApp" name="cellphone_whatsapp" checked={form.cellphone_whatsapp} onChange={onChange} className="mb-3" />
                    <FormTextarea label="Observações" name="notes" value={form.notes} onChange={onChange} rows={2} />

                    {/* Recurrence */}
                    <h6 className="fw-semibold mt-3 mb-2 text-muted"><i className="ti ti-repeat me-1"></i> Recorrência (opcional)</h6>
                    <div className="row">
                        <div className="col-md-6">
                            <FormSelect label="Tipo" name="recurrence_type" value={form.recurrence_type} onChange={onChange}
                                options={{ weekly: 'Semanal', monthly: 'Mensal' }} placeholder="Sem recorrência" />
                        </div>
                        <div className="col-md-6">
                            <FormInput label="Até" name="recurrence_until" type="date" value={form.recurrence_until} onChange={onChange} />
                        </div>
                    </div>
                </form>
            </Modal>

            {/* Detail Modal */}
            <Modal show={showDetailModal} onClose={() => setShowDetailModal(false)} title={`Agendamento ${detailSchedule?.code || ''}`}>
                {detailSchedule && (
                    <div>
                        <InfoRow label="Paciente" value={detailSchedule.patient?.person?.full_name || detailSchedule.full_name} />
                        <InfoRow label="Médico" value={detailSchedule.doctor?.entity_user?.user?.name} />
                        <InfoRow label="Data/Hora" value={detailSchedule.date_time} />
                        <InfoRow label="Convênio" value={detailSchedule.covenant?.name} />
                        <InfoRow label="Tipo de Visita" value={detailSchedule.visit_type?.name} />
                        <InfoRow label="Observações" value={detailSchedule.notes} />
                        <div className="mb-2">
                            <span className="text-muted small d-block">Situação</span>
                            <Badge variant={situationBadge[detailSchedule.situation]?.variant || 'secondary'}>
                                {situationBadge[detailSchedule.situation]?.label || detailSchedule.situation}
                            </Badge>
                        </div>

                        {/* Quick status buttons */}
                        <hr />
                        <div className="d-flex flex-wrap gap-2">
                            <button className="btn btn-sm btn-outline-primary" onClick={() => updateSituation(detailSchedule.id, 2)}>
                                <i className="ti ti-check me-1"></i> Confirmar
                            </button>
                            <button className="btn btn-sm btn-outline-warning" onClick={() => updateSituation(detailSchedule.id, 3)}>
                                <i className="ti ti-clock me-1"></i> Chegou
                            </button>
                            <button className="btn btn-sm btn-outline-success" onClick={() => updateSituation(detailSchedule.id, 5)}>
                                <i className="ti ti-stethoscope me-1"></i> Atendido
                            </button>
                            <button className="btn btn-sm btn-outline-danger" onClick={() => {
                                const reason = prompt('Motivo do cancelamento:');
                                if (reason !== null) updateSituation(detailSchedule.id, 7, reason);
                            }}>
                                <i className="ti ti-x me-1"></i> Cancelar
                            </button>
                        </div>
                    </div>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}

function InfoRow({ label, value }) {
    return (
        <div className="mb-2">
            <span className="text-muted small d-block">{label}</span>
            <span className="fw-medium">{value || '—'}</span>
        </div>
    );
}

// Date helpers (dd/mm/yyyy format)
function todayFormatted() {
    const d = new Date();
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

function formatDate(d) {
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

function parseDate(str) {
    const [dd, mm, yyyy] = str.split('/');
    return new Date(yyyy, mm - 1, dd);
}

function isoDate(str) {
    const [dd, mm, yyyy] = str.split('/');
    return `${yyyy}-${mm}-${dd}`;
}

function formatFromIso(iso) {
    const [yyyy, mm, dd] = iso.split('-');
    return `${dd}/${mm}/${yyyy}`;
}

function defaultTime() {
    const now = new Date();
    return `${pad(now.getHours())}:${pad(Math.ceil(now.getMinutes() / 15) * 15 % 60)}`;
}

function pad(n) { return String(n).padStart(2, '0'); }
