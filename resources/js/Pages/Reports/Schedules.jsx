import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Schedules({ doctors, covenants, situations, filters, results }) {
    const { userRule } = usePage().props;
    const isDoctor = userRule === 'doctor';

    const today = new Date().toISOString().slice(0, 10);
    const firstOfMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1)
        .toISOString().slice(0, 10);

    const [form, setForm] = useState({
        date_from:   filters?.date_from   ?? firstOfMonth,
        date_until:  filters?.date_until  ?? today,
        doctor_id:   filters?.doctor_id   ?? '',
        covenant_id: filters?.covenant_id ?? '',
        situation:   filters?.situation   ?? '',
    });

    function handleChange(e) {
        setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));
    }

    function applyFilters(e) {
        e.preventDefault();
        const params = Object.fromEntries(
            Object.entries(form).filter(([, v]) => v !== '')
        );
        router.get('/panel/reports/schedules', params, { preserveState: true });
    }

    function clearFilters() {
        router.get('/panel/reports/schedules', {});
    }

    return (
        <AuthenticatedLayout title="Relatório de Produção">
            <nav aria-label="breadcrumb" className="mb-3">
                <ol className="breadcrumb mb-0">
                    <li className="breadcrumb-item"><Link href="/panel/dashboard">Dashboard</Link></li>
                    <li className="breadcrumb-item"><Link href="/panel/reports">Relatórios</Link></li>
                    <li className="breadcrumb-item active">Produção</li>
                </ol>
            </nav>

            {/* ── Filtros ── */}
            <div className="card mb-4 border-0 shadow-sm">
                <div className="card-body">
                    <form onSubmit={applyFilters} className="row g-3 align-items-end">
                        <div className="col-md-3">
                            <label className="form-label small fw-semibold">
                                Data início <span className="text-danger">*</span>
                            </label>
                            <input
                                type="date" name="date_from"
                                className="form-control form-control-sm"
                                value={form.date_from}
                                onChange={handleChange}
                                required
                            />
                        </div>

                        <div className="col-md-3">
                            <label className="form-label small fw-semibold">
                                Data fim <span className="text-danger">*</span>
                            </label>
                            <input
                                type="date" name="date_until"
                                className="form-control form-control-sm"
                                value={form.date_until}
                                onChange={handleChange}
                                required
                            />
                        </div>

                        {!isDoctor && (
                            <>
                                <div className="col-md-2">
                                    <label className="form-label small fw-semibold">Médico</label>
                                    <select
                                        name="doctor_id"
                                        className="form-select form-select-sm"
                                        value={form.doctor_id}
                                        onChange={handleChange}
                                    >
                                        <option value="">Todos</option>
                                        {doctors.map(d => (
                                            <option key={d.id} value={d.id}>{d.name}</option>
                                        ))}
                                    </select>
                                </div>

                                <div className="col-md-2">
                                    <label className="form-label small fw-semibold">Convênio</label>
                                    <select
                                        name="covenant_id"
                                        className="form-select form-select-sm"
                                        value={form.covenant_id}
                                        onChange={handleChange}
                                    >
                                        <option value="">Todos</option>
                                        {covenants.map(c => (
                                            <option key={c.id} value={c.id}>{c.name}</option>
                                        ))}
                                    </select>
                                </div>

                                <div className="col-md-2">
                                    <label className="form-label small fw-semibold">Situação</label>
                                    <select
                                        name="situation"
                                        className="form-select form-select-sm"
                                        value={form.situation}
                                        onChange={handleChange}
                                    >
                                        <option value="">Todas</option>
                                        {situations.map(s => (
                                            <option key={s.value} value={s.value}>{s.label}</option>
                                        ))}
                                    </select>
                                </div>
                            </>
                        )}

                        <div className="col-auto ms-auto">
                            <button type="submit" className="btn btn-info btn-sm">
                                <i className="fas fa-search me-1"></i> Filtrar
                            </button>
                            <button
                                type="button"
                                className="btn btn-outline-secondary btn-sm ms-1"
                                onClick={clearFilters}
                            >
                                <i className="fas fa-times me-1"></i> Limpar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            {results && (
                <>
                    {/* ── Cards de resumo ── */}
                    <div className="row g-3 mb-4">
                        <SummaryCard value={results.summary.total} label="Total" />
                        <SummaryCard value={results.summary.attended} label="Atendidos" color="success" />
                        <SummaryCard value={results.summary.cancelled} label="Cancelados" color="danger" />
                        <SummaryCard value={results.summary.noshow} label="Faltaram" color="warning" />
                        <SummaryCard value={results.summary.pending} label="Pendentes" color="secondary" />
                        <SummaryCard
                            value={`${results.summary.attendance_rate}%`}
                            label="Comparecimento"
                            color="info"
                        />
                    </div>

                    {/* ── Breakdown por médico ── */}
                    {results.by_doctor.length > 0 && (
                        <div className="card mb-4 border-0 shadow-sm">
                            <div className="card-header bg-transparent fw-semibold small text-uppercase text-muted">
                                Por Médico
                            </div>
                            <div className="table-responsive">
                                <table className="table table-sm table-hover mb-0">
                                    <thead className="table-light">
                                        <tr>
                                            <th>Médico</th>
                                            <th className="text-center">Total</th>
                                            <th className="text-center text-success">Atendidos</th>
                                            <th className="text-center text-warning">Faltaram</th>
                                            <th className="text-center text-danger">Cancelados</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {results.by_doctor.map((row, i) => (
                                            <tr key={i}>
                                                <td>{row.doctor_name}</td>
                                                <td className="text-center fw-semibold">{row.total}</td>
                                                <td className="text-center text-success">{row.attended}</td>
                                                <td className="text-center text-warning">{row.noshow}</td>
                                                <td className="text-center text-danger">{row.cancelled}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* ── Tabela detalhada ── */}
                    <div className="card border-0 shadow-sm">
                        <div className="card-header bg-transparent d-flex align-items-center justify-content-between">
                            <span className="fw-semibold small text-uppercase text-muted">
                                Detalhe ({results.schedules.length} registros)
                            </span>
                        </div>

                        {results.schedules.length === 0 ? (
                            <div className="card-body text-center text-muted py-4">
                                <i className="fas fa-calendar-times fa-2x mb-2"></i>
                                <p className="mb-0">Nenhum agendamento encontrado para o período.</p>
                            </div>
                        ) : (
                            <div className="table-responsive">
                                <table className="table table-sm table-hover mb-0">
                                    <thead className="table-light">
                                        <tr>
                                            <th>Código</th>
                                            <th>Data/Hora</th>
                                            <th>Paciente</th>
                                            <th>Médico</th>
                                            <th>Convênio</th>
                                            <th>Situação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {results.schedules.map(s => (
                                            <tr key={s.id}>
                                                <td className="text-muted" style={{ fontSize: '.8rem' }}>{s.code}</td>
                                                <td style={{ fontSize: '.85rem', whiteSpace: 'nowrap' }}>{s.date_time}</td>
                                                <td style={{ fontSize: '.85rem' }}>{s.patient_name}</td>
                                                <td style={{ fontSize: '.85rem' }}>{s.doctor_name}</td>
                                                <td style={{ fontSize: '.85rem' }}>{s.covenant_name}</td>
                                                <td>
                                                    <span className={`badge ${s.situation_badge}`} style={{ fontSize: '.72rem' }}>
                                                        {s.situation_label}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </>
            )}
        </AuthenticatedLayout>
    );
}

function SummaryCard({ value, label, color }) {
    return (
        <div className="col-6 col-md-2">
            <div className={`card text-center border-0 shadow-sm h-100${color ? ` border-top border-${color} border-3` : ''}`}>
                <div className="card-body py-3">
                    <div className={`fs-4 fw-bold${color ? ` text-${color}` : ''}`}>{value}</div>
                    <div className="text-muted small">{label}</div>
                </div>
            </div>
        </div>
    );
}
