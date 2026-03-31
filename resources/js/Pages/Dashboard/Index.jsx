/**
 * Dashboard — Primeira tela migrada de Blade para React/Inertia.
 *
 * Rota: /panel/dashboard
 * Props recebidas do Laravel: stats, greeting, userRole
 */
import { Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Dashboard({ stats, greeting, userRole }) {
    return (
        <AuthenticatedLayout title="Dashboard">
            {/* ═══ Welcome Banner ═══ */}
            <div className="welcome-banner mb-4 mt-4">
                <div className="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div>
                        <h4 className="mb-1">{greeting} 👋</h4>
                        <p>Painel operacional — {stats.entity_name}</p>
                    </div>
                    <div className="d-flex gap-2 flex-wrap" style={{ position: 'relative', zIndex: 1 }}>
                        <Link href="/panel/patients" className="btn btn-sm btn-banner">
                            <i className="fa fa-users me-1"></i> Pacientes
                        </Link>
                        <Link href="/panel/patients/create" className="btn btn-sm btn-banner btn-banner-solid">
                            <i className="fa fa-user-plus me-1"></i> Novo Paciente
                        </Link>
                    </div>
                </div>
            </div>

            {/* ═══ KPIs Operacionais ═══ */}
            <div className="row g-3 mb-3">
                <KpiCard
                    value={stats.total_patients}
                    label="Pacientes"
                    icon="fa fa-users"
                    borderColor="#1976d2"
                    bgColor="#e3f2fd"
                />
                <KpiCard
                    value={stats.today_count}
                    label="Consultas Hoje"
                    icon="fa fa-calendar-check-o"
                    borderColor="#5c6bc0"
                    bgColor="#ede7f6"
                />
                <KpiCard
                    value={stats.total_doctors}
                    label="Médicos Ativos"
                    icon="fa fa-user-md"
                    borderColor="#388e3c"
                    bgColor="#e8f5e9"
                />
                <KpiCard
                    value="—"
                    label="Cirurgias Hoje"
                    icon="fa fa-scissors"
                    borderColor="#7b1fa2"
                    bgColor="#f3e5f5"
                    comingSoon
                />
            </div>

            {/* ═══ KPIs Clínicos/Financeiros ═══ */}
            <div className="row g-3 mb-4">
                <KpiCard
                    value="—"
                    label="Exames Pendentes"
                    icon="fa fa-eye"
                    borderColor="#e65100"
                    bgColor="#fbe9e7"
                    comingSoon
                />
                {userRole !== 'doctor' && (
                    <>
                        <KpiCard
                            value="—"
                            label="Guias Aguardando"
                            icon="fa fa-file-text-o"
                            borderColor="#bf360c"
                            bgColor="#fce4ec"
                            comingSoon
                        />
                        <KpiCard
                            value="—"
                            label="A Receber"
                            icon="fa fa-dollar"
                            borderColor="#00695c"
                            bgColor="#e0f2f1"
                            comingSoon
                        />
                        <KpiCard
                            value="—"
                            label="Satisfação"
                            icon="fa fa-star-o"
                            borderColor="#f57f17"
                            bgColor="#fff8e1"
                            comingSoon
                        />
                    </>
                )}
            </div>

            {/* ═══ Módulos de Acesso Rápido ═══ */}
            <div className="row g-3 mb-4">
                <ModuleShortcut
                    href="/panel/schedules"
                    icon="fa fa-calendar"
                    label="Agenda"
                    bgColor="#e3f2fd"
                    color="#1976d2"
                />
                <ModuleShortcut
                    icon="fa fa-eye"
                    label="Imagens"
                    disabled
                />
                {userRole !== 'doctor' && (
                    <>
                        <ModuleShortcut icon="fa fa-file-text-o" label="Guias TISS" disabled />
                        <ModuleShortcut icon="fa fa-dollar" label="Financeiro" disabled />
                    </>
                )}
                <ModuleShortcut icon="fa fa-medkit" label="Centro Cirúrgico" disabled />
            </div>

            {/* ═══ Pacientes Recentes + Resumo do Dia ═══ */}
            <div className="row g-3 mb-4">
                <div className="col-md-8">
                    <RecentPatients patients={stats.recent_patients} />
                </div>
                <div className="col-md-4">
                    <DaySummary stats={stats} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

/* ─── Sub-componentes ───────────────────────────────────────────────── */

function KpiCard({ value, label, icon, borderColor, bgColor, comingSoon = false }) {
    return (
        <div className="col-6 col-md-3">
            <div
                className={`card stat-card h-100${comingSoon ? ' stat-card-mock' : ''}`}
                style={{ borderTop: `3px solid ${borderColor}` }}
            >
                <div className="card-body d-flex align-items-center gap-3 p-3">
                    <div className="stat-icon" style={{ background: bgColor, color: borderColor }}>
                        <i className={icon}></i>
                    </div>
                    <div>
                        <div className={`stat-value${comingSoon ? ' stat-value-mock' : ''}`}>{value}</div>
                        <div className="stat-label">{label}</div>
                        {comingSoon && <span className="stat-badge-soon">Em breve</span>}
                    </div>
                </div>
            </div>
        </div>
    );
}

function ModuleShortcut({ href, icon, label, bgColor, color, disabled = false }) {
    const content = (
        <>
            {disabled && <span className="badge-soon">Em breve</span>}
            <span
                className="ms-icon"
                style={{
                    background: disabled ? '#f1f5f9' : bgColor,
                    color: disabled ? '#94a3b8' : color,
                }}
            >
                <i className={icon}></i>
            </span>
            <span>{label}</span>
        </>
    );

    if (disabled) {
        return (
            <div className="col-6 col-sm-4 col-md-2">
                <div className="module-shortcut disabled w-100">{content}</div>
            </div>
        );
    }

    return (
        <div className="col-6 col-sm-4 col-md-2">
            <Link href={href} className="module-shortcut w-100">{content}</Link>
        </div>
    );
}

function RecentPatients({ patients }) {
    return (
        <div className="card h-100">
            <div className="card-header d-flex align-items-center justify-content-between">
                <span><i className="fa fa-users me-2 text-primary"></i> Pacientes Recentes</span>
                <Link href="/panel/patients" className="btn btn-sm btn-outline-primary">
                    Ver todos <i className="fa fa-arrow-right ms-1"></i>
                </Link>
            </div>
            <div className="card-body p-0">
                {(!patients || patients.length === 0) ? (
                    <p className="text-muted text-center py-4 mb-0">
                        <i className="fa fa-users fa-2x d-block mb-2"></i>
                        Nenhum paciente cadastrado ainda.
                    </p>
                ) : (
                    <div className="table-responsive">
                        <table className="schedule-table table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Telefone</th>
                                    <th>Código</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {patients.map((patient) => (
                                    <tr key={patient.id}>
                                        <td>
                                            <div className="d-flex align-items-center gap-2">
                                                <div
                                                    className="patient-initial"
                                                    style={{
                                                        background: `#${(patient.person?.full_name || '?')
                                                            .split('')
                                                            .reduce((h, c) => ((h << 5) - h + c.charCodeAt(0)) | 0, 0)
                                                            .toString(16)
                                                            .replace('-', '')
                                                            .slice(0, 6)
                                                            .padStart(6, 'a')}`,
                                                    }}
                                                >
                                                    {(patient.person?.full_name || '?').charAt(0).toUpperCase()}
                                                </div>
                                                <span className="fw-medium">
                                                    {patient.person?.full_name || '—'}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="text-muted small">
                                            {patient.person?.cellphone || patient.person?.telephone || '—'}
                                        </td>
                                        <td>
                                            <code className="text-muted small">{patient.code}</code>
                                        </td>
                                        <td className="text-end">
                                            <Link
                                                href={`/panel/patients/${patient.id}`}
                                                className="btn btn-xs btn-outline-secondary"
                                            >
                                                Ver
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}

function DaySummary({ stats }) {
    const items = [
        { value: stats.today_count, label: 'Total Agendado', color: '#1976d2' },
        { value: stats.attended_today, label: 'Atendidos', color: '#388e3c' },
        { value: stats.pending_today, label: 'Pendentes', color: '#f57f17' },
        { value: stats.cancelled_today, label: 'Cancelados / Faltas', color: '#c62828' },
    ];

    return (
        <div className="card h-100">
            <div className="card-header">
                <i className="fa fa-bar-chart me-2 text-warning"></i> Resumo do Dia
            </div>
            <div className="card-body d-flex flex-column gap-2 p-3">
                {items.map((item, i) => (
                    <div key={i} className="day-summary-stat" style={{ borderLeftColor: item.color }}>
                        <div className="ds-value" style={{ color: item.color }}>{item.value}</div>
                        <div className="ds-label">{item.label}</div>
                    </div>
                ))}
            </div>
        </div>
    );
}
