import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Absenteeism({ doctors, filters, results }) {
    const { userRule } = usePage().props;
    const isDoctor = userRule === 'doctor';

    const today = new Date().toISOString().slice(0, 10);
    const firstOfMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1)
        .toISOString().slice(0, 10);

    const [form, setForm] = useState({
        date_from:  filters?.date_from  ?? firstOfMonth,
        date_until: filters?.date_until ?? today,
        doctor_id:  filters?.doctor_id  ?? '',
    });

    function handleChange(e) {
        setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));
    }

    function applyFilters(e) {
        e.preventDefault();
        const params = Object.fromEntries(
            Object.entries(form).filter(([, v]) => v !== '')
        );
        router.get('/panel/reports/absenteeism', params, { preserveState: true });
    }

    function clearFilters() {
        router.get('/panel/reports/absenteeism', {});
    }

    return (
        <AuthenticatedLayout title="Relatório de Absenteísmo">
            <nav aria-label="breadcrumb" className="mb-3">
                <ol className="breadcrumb mb-0">
                    <li className="breadcrumb-item"><Link href="/panel/dashboard">Dashboard</Link></li>
                    <li className="breadcrumb-item"><Link href="/panel/reports">Relatórios</Link></li>
                    <li className="breadcrumb-item active">Absenteísmo</li>
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
                            <div className="col-md-3">
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
                        )}

                        <div className="col-auto ms-auto">
                            <button type="submit" className="btn btn-warning btn-sm">
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
                        <div className="col-6 col-md-3">
                            <div className="card text-center border-0 shadow-sm h-100">
                                <div className="card-body py-3">
                                    <div className="fs-4 fw-bold">{results.summary.total_period}</div>
                                    <div className="text-muted small">Total no período</div>
                                </div>
                            </div>
                        </div>
                        <div className="col-6 col-md-3">
                            <div className="card text-center border-0 shadow-sm h-100 border-top border-warning border-3">
                                <div className="card-body py-3">
                                    <div className="fs-4 fw-bold text-warning">{results.summary.noshow}</div>
                                    <div className="text-muted small">Faltaram</div>
                                </div>
                            </div>
                        </div>
                        <div className="col-6 col-md-3">
                            <div className="card text-center border-0 shadow-sm h-100 border-top border-danger border-3">
                                <div className="card-body py-3">
                                    <div className="fs-4 fw-bold text-danger">{results.summary.cancelled}</div>
                                    <div className="text-muted small">Cancelados</div>
                                </div>
                            </div>
                        </div>
                        <div className="col-6 col-md-3">
                            <div className="card text-center border-0 shadow-sm h-100 border-top border-danger border-3">
                                <div className="card-body py-3">
                                    <div className="fs-4 fw-bold text-danger">{results.summary.absenteeism_rate}%</div>
                                    <div className="text-muted small">Taxa de absenteísmo</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* ── Tabela detalhada ── */}
                    <div className="card border-0 shadow-sm">
                        <div className="card-header bg-transparent fw-semibold small text-uppercase text-muted">
                            Detalhe ({results.schedules.length} registros)
                        </div>

                        {results.schedules.length === 0 ? (
                            <div className="card-body text-center text-muted py-4">
                                <i className="fas fa-check-circle fa-2x text-success mb-2"></i>
                                <p className="mb-0">Nenhuma falta ou cancelamento no período.</p>
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
                                            <th>Situação</th>
                                            <th>Motivo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {results.schedules.map(s => (
                                            <tr key={s.id}>
                                                <td className="text-muted" style={{ fontSize: '.8rem' }}>{s.code}</td>
                                                <td style={{ fontSize: '.85rem', whiteSpace: 'nowrap' }}>{s.date_time}</td>
                                                <td style={{ fontSize: '.85rem' }}>{s.patient_name}</td>
                                                <td style={{ fontSize: '.85rem' }}>{s.doctor_name}</td>
                                                <td>
                                                    <span className={`badge ${s.situation_badge}`} style={{ fontSize: '.72rem' }}>
                                                        {s.situation_label}
                                                    </span>
                                                </td>
                                                <td className="text-muted" style={{ fontSize: '.8rem' }}>
                                                    {s.cancellation_reason}
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
