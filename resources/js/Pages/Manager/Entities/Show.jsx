import { router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/UI/PageHeader';
import Badge, { StatusBadge } from '@/Components/UI/Badge';

function InfoRow({ label, value }) {
    if (!value) return null;
    return (
        <div className="row py-2 border-bottom">
            <div className="col-5 text-muted small fw-semibold">{label}</div>
            <div className="col-7 small">{value}</div>
        </div>
    );
}

export default function EntitiesShow({ record }) {
    const handleImpersonate = async (entityUserId) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const res  = await fetch(`/panel/manager/entities/${record.id}/impersonate/${entityUserId}`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const json = await res.json();
        if (json.redirect) {
            window.location.href = json.redirect;
        }
    };

    return (
        <AuthenticatedLayout title={`Empresa: ${record.name}`}>
            <PageHeader
                title={record.name}
                subtitle={<code>{record.code}</code>}
            >
                <a href="/panel/manager/entities" className="btn btn-outline-secondary btn-md">
                    <i className="ti ti-arrow-left me-1"></i> Voltar
                </a>
                <a href={`/panel/manager/entities/${record.id}/edit`} className="btn btn-primary btn-md ms-2">
                    <i className="ti ti-pencil me-1"></i> Editar
                </a>
            </PageHeader>

            <div className="row g-3">
                {/* Dados principais */}
                <div className="col-md-6">
                    <div className="card border-0 shadow-sm h-100">
                        <div className="card-header fw-semibold">
                            <i className="ti ti-building me-1 text-primary"></i> Dados da Empresa
                        </div>
                        <div className="card-body">
                            <InfoRow label="Código" value={record.code} />
                            <InfoRow label="Nome" value={record.name} />
                            <InfoRow label="Subdomínio" value={record.subdomain} />
                            <InfoRow label="E-mail" value={record.email} />
                            <InfoRow label="Telefone" value={record.telephone} />
                            <InfoRow label="Celular" value={record.cellphone} />
                            <InfoRow label="Website" value={record.website} />
                            <InfoRow label="CNPJ" value={record.national_registration} />
                            <InfoRow label="Insc. Estadual" value={record.state_registration} />
                            <InfoRow label="Insc. Municipal" value={record.municipal_registration} />
                            <InfoRow label="Intervalo Agenda" value={record.schedule_interval ? `${record.schedule_interval} min` : null} />
                            <div className="row py-2 border-bottom">
                                <div className="col-5 text-muted small fw-semibold">Tipo</div>
                                <div className="col-7">
                                    <Badge variant={record.is_client ? 'primary' : 'secondary'}>
                                        {record.is_client ? 'Cliente' : 'Sistema'}
                                    </Badge>
                                </div>
                            </div>
                            <div className="row py-2">
                                <div className="col-5 text-muted small fw-semibold">Status</div>
                                <div className="col-7">
                                    <StatusBadge active={record.active} deletedAt={record.deleted_at} />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Endereço */}
                <div className="col-md-6">
                    <div className="card border-0 shadow-sm">
                        <div className="card-header fw-semibold">
                            <i className="ti ti-map-pin me-1 text-primary"></i> Endereço
                        </div>
                        <div className="card-body">
                            <InfoRow label="CEP" value={record.zipcode} />
                            <InfoRow label="Endereço" value={[record.address, record.number].filter(Boolean).join(', ')} />
                            <InfoRow label="Complemento" value={record.complement} />
                            <InfoRow label="Bairro" value={record.district} />
                            <InfoRow label="Cidade/UF" value={[record.city, record.state].filter(Boolean).join(' / ')} />
                            <InfoRow label="País" value={record.country} />
                        </div>
                    </div>

                    {/* Quick links */}
                    <div className="card border-0 shadow-sm mt-3">
                        <div className="card-header fw-semibold">
                            <i className="ti ti-link me-1 text-primary"></i> Ações Rápidas
                        </div>
                        <div className="card-body d-flex flex-wrap gap-2">
                            <a
                                href={`/panel/manager/entities/${record.id}/users`}
                                className="btn btn-outline-secondary btn-sm"
                            >
                                <i className="ti ti-users me-1"></i> Usuários
                            </a>
                            <a
                                href={`/panel/manager/subscriptions?entity=${record.id}`}
                                className="btn btn-outline-info btn-sm"
                            >
                                <i className="ti ti-credit-card me-1"></i> Assinatura
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
