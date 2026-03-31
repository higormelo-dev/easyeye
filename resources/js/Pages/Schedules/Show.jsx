import { Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import PageHeader from '@/Components/UI/PageHeader';
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

export default function SchedulesShow({ record }) {
    const activeBadge = situationBadge[record.situation] || { label: record.situation, variant: 'secondary' };

    return (
        <AuthenticatedLayout title={`Agendamento ${record.code}`}>
            <FlashMessages />

            <nav aria-label="breadcrumb" className="mb-3">
                <ol className="breadcrumb mb-0">
                    <li className="breadcrumb-item"><Link href="/panel/dashboard">Dashboard</Link></li>
                    <li className="breadcrumb-item"><Link href="/panel/schedules">Agendamentos</Link></li>
                    <li className="breadcrumb-item active">{record.code}</li>
                </ol>
            </nav>

            <PageHeader title={`Agendamento ${record.code}`}>
                <div className="d-flex gap-2">
                    <Link href="/panel/schedules" className="btn btn-outline-secondary btn-sm">
                        <i className="ti ti-arrow-left me-1"></i> Voltar
                    </Link>
                </div>
            </PageHeader>

            <div className="row">
                <div className="col-md-8">
                    <div className="card border-0 shadow-sm mb-4">
                        <div className="card-header bg-white pb-0 border-bottom-0">
                            <h5 className="card-title mb-0">Detalhes</h5>
                        </div>
                        <div className="card-body">
                            <div className="row g-3">
                                <div className="col-md-6">
                                    <InfoRow label="Paciente" value={record.patient?.person?.full_name || record.full_name} />
                                </div>
                                <div className="col-md-6">
                                    <InfoRow label="Situação" value={<Badge variant={activeBadge.variant}>{activeBadge.label}</Badge>} />
                                </div>
                                <div className="col-md-6">
                                    <InfoRow label="Data/Hora" value={new Date(record.date_time).toLocaleString()} />
                                </div>
                                <div className="col-md-6">
                                    <InfoRow label="Médico" value={record.doctor?.entity_user?.user?.name || 'Não Informado'} />
                                </div>
                                <div className="col-md-6">
                                    <InfoRow label="Convênio" value={record.covenant?.name || 'Particular'} />
                                </div>
                                <div className="col-md-6">
                                    <InfoRow label="Tipo de Visita" value={record.visit_type?.name || 'Padrão'} />
                                </div>
                                <div className="col-md-6">
                                    <InfoRow label="Telefone" value={record.telephone || '—'} />
                                </div>
                                <div className="col-md-6">
                                    <InfoRow label="Celular" value={
                                        record.cellphone ? (
                                            <>
                                                {record.cellphone}
                                                {record.cellphone_whatsapp && <i className="ti ti-brand-whatsapp text-success ms-1"></i>}
                                            </>
                                        ) : '—'
                                    } />
                                </div>
                                <div className="col-12">
                                    <InfoRow label="Observações" value={record.notes || 'Nenhuma observação'} />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="col-md-4">
                    <div className="card border-0 shadow-sm">
                        <div className="card-header bg-white pb-0 border-bottom-0">
                            <h5 className="card-title mb-0">Histórico de Situações</h5>
                        </div>
                        <div className="card-body">
                            {record.situation_logs && record.situation_logs.length > 0 ? (
                                <div className="timeline-sm">
                                    {record.situation_logs.map(log => (
                                        <div key={log.id} className="mb-3 border-start border-3 ps-3 border-secondary">
                                            <p className="mb-0 fw-medium small">
                                                {situationBadge[log.to_situation]?.label || log.to_situation}
                                                {log.notes && <span className="text-muted d-block mt-1">"{log.notes}"</span>}
                                            </p>
                                            <small className="text-muted">{new Date(log.created_at).toLocaleString()}</small>
                                            <small className="d-block text-muted">Por: {log.entity_user?.user?.name || 'Sistema'}</small>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-muted small">Nenhum histórico registrado.</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function InfoRow({ label, value }) {
    return (
        <div className="mb-2">
            <span className="text-muted small d-block">{label}</span>
            <div className="fw-medium">{value}</div>
        </div>
    );
}
