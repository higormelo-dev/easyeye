import { Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import Badge, { StatusBadge } from '@/Components/UI/Badge';

export default function UserShow({ record }) {
    const user = record.user;

    return (
        <AuthenticatedLayout title={`Usuário — ${user?.name}`}>
            <FlashMessages />

            <nav aria-label="breadcrumb" className="mb-3">
                <ol className="breadcrumb mb-0">
                    <li className="breadcrumb-item"><Link href="/panel/dashboard">Dashboard</Link></li>
                    <li className="breadcrumb-item"><Link href="/panel/accesscontrol/users">Usuários</Link></li>
                    <li className="breadcrumb-item active">{user?.name}</li>
                </ol>
            </nav>

            <div className="row g-3">
                <div className="col-md-4">
                    <div className="card border-0 shadow-sm">
                        <div className="card-body text-center">
                            <img
                                src="/system/images/team.png"
                                alt={user?.name}
                                className="rounded-circle mb-3"
                                width="96"
                                height="96"
                            />
                            <h5 className="fw-bold mb-1">{user?.name}</h5>
                            <p className="text-muted mb-2">{user?.email}</p>
                            <div className="mb-2">
                                <Badge variant="info">{record.rule_label || record.rule}</Badge>
                            </div>
                            <StatusBadge active={record.active} deletedAt={record.deleted_at} />
                        </div>
                    </div>
                </div>

                <div className="col-md-8">
                    <div className="card border-0 shadow-sm">
                        <div className="card-header bg-transparent">
                            <h6 className="mb-0 fw-semibold"><i className="ti ti-info-circle me-1"></i> Informações</h6>
                        </div>
                        <div className="card-body">
                            <div className="row">
                                <div className="col-md-6">
                                    <InfoRow label="Nome" value={user?.name} />
                                </div>
                                <div className="col-md-6">
                                    <InfoRow label="E-mail" value={user?.email} />
                                </div>
                                <div className="col-md-6">
                                    <InfoRow label="Papel" value={record.rule_label || record.rule} />
                                </div>
                                <div className="col-md-6">
                                    <InfoRow label="Membro desde" value={record.created_at_formatted || record.created_at} />
                                </div>
                            </div>
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
            <span className="fw-medium">{value || '—'}</span>
        </div>
    );
}
