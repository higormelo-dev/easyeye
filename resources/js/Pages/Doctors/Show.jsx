import { Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import { StatusBadge } from '@/Components/UI/Badge';

export default function DoctorShow({ record }) {
    const person = record.person;
    const user = record.entity_user?.user;

    return (
        <AuthenticatedLayout title={`Médico — ${person?.full_name || record.code}`}>
            <FlashMessages />

            {/* Breadcrumb */}
            <nav aria-label="breadcrumb" className="mb-3">
                <ol className="breadcrumb mb-0">
                    <li className="breadcrumb-item"><Link href="/panel/dashboard">Dashboard</Link></li>
                    <li className="breadcrumb-item"><Link href="/panel/doctors">Médicos</Link></li>
                    <li className="breadcrumb-item active">{person?.full_name || record.code}</li>
                </ol>
            </nav>

            <div className="row g-3">
                {/* Perfil */}
                <div className="col-md-4">
                    <div className="card border-0 shadow-sm">
                        <div className="card-body text-center">
                            <img
                                src={record.photo_url || '/system/images/team.png'}
                                alt={person?.full_name}
                                className="rounded-circle mb-3"
                                width="96"
                                height="96"
                                style={{ objectFit: 'cover' }}
                            />
                            <h5 className="fw-bold mb-1">{person?.full_name}</h5>
                            <p className="text-muted mb-2">{user?.email}</p>
                            <StatusBadge active={record.active} />
                            <div className="mt-3">
                                <code className="d-block mb-1">{record.code}</code>
                                {record.record && (
                                    <span className="text-muted small">CRM: {record.record} {record.record_specialty && `— ${record.record_specialty}`}</span>
                                )}
                            </div>
                            {record.color && (
                                <div className="mt-2 d-flex align-items-center justify-content-center gap-2">
                                    <span className="rounded-circle d-inline-block" style={{ width: 16, height: 16, background: record.color }}></span>
                                    <span className="small text-muted">Cor na agenda</span>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="card border-0 shadow-sm mt-3">
                        <div className="card-body">
                            <h6 className="fw-semibold mb-3"><i className="ti ti-phone me-1"></i> Contato</h6>
                            <InfoRow label="Telefone" value={person?.telephone} />
                            <InfoRow label="Celular" value={person?.cellphone} />
                            <InfoRow label="WhatsApp" value={person?.whatsapp ? 'Sim' : 'Não'} />
                        </div>
                    </div>
                </div>

                {/* Detalhes */}
                <div className="col-md-8">
                    <div className="card border-0 shadow-sm">
                        <div className="card-header bg-transparent d-flex justify-content-between align-items-center">
                            <h6 className="mb-0 fw-semibold"><i className="ti ti-user me-1"></i> Dados Pessoais</h6>
                            <Link href={`/panel/doctors/${record.id}/work-schedule`} className="btn btn-sm btn-outline-primary">
                                <i className="ti ti-calendar me-1"></i> Escala de Trabalho
                            </Link>
                        </div>
                        <div className="card-body">
                            <div className="row">
                                <div className="col-md-6"><InfoRow label="CPF" value={person?.national_registry} /></div>
                                <div className="col-md-6"><InfoRow label="Data de Nascimento" value={person?.birth_date_formatted || person?.birth_date} /></div>
                                <div className="col-md-6"><InfoRow label="Sexo" value={person?.gender} /></div>
                                <div className="col-md-6"><InfoRow label="Estado Civil" value={person?.marital_status} /></div>
                                <div className="col-md-6"><InfoRow label="Nome da Mãe" value={person?.mother_name} /></div>
                                <div className="col-md-6"><InfoRow label="Nome do Pai" value={person?.father_name} /></div>
                            </div>
                        </div>
                    </div>

                    <div className="card border-0 shadow-sm mt-3">
                        <div className="card-header bg-transparent">
                            <h6 className="mb-0 fw-semibold"><i className="ti ti-id me-1"></i> Documentos</h6>
                        </div>
                        <div className="card-body">
                            <div className="row">
                                <div className="col-md-4"><InfoRow label="RG" value={person?.state_registry} /></div>
                                <div className="col-md-4"><InfoRow label="Órgão Emissor" value={person?.state_registry_agency} /></div>
                                <div className="col-md-4"><InfoRow label="UF" value={person?.state_registry_initial} /></div>
                            </div>
                        </div>
                    </div>

                    <div className="card border-0 shadow-sm mt-3">
                        <div className="card-header bg-transparent">
                            <h6 className="mb-0 fw-semibold"><i className="ti ti-map-pin me-1"></i> Endereço</h6>
                        </div>
                        <div className="card-body">
                            <div className="row">
                                <div className="col-md-3"><InfoRow label="CEP" value={person?.zipcode} /></div>
                                <div className="col-md-6"><InfoRow label="Logradouro" value={person?.address} /></div>
                                <div className="col-md-3"><InfoRow label="Número" value={person?.number} /></div>
                                <div className="col-md-4"><InfoRow label="Complemento" value={person?.complement} /></div>
                                <div className="col-md-4"><InfoRow label="Bairro" value={person?.district} /></div>
                                <div className="col-md-4"><InfoRow label="Cidade/UF" value={person?.city ? `${person.city}/${person.state}` : null} /></div>
                            </div>
                        </div>
                    </div>

                    {record.observation && (
                        <div className="card border-0 shadow-sm mt-3">
                            <div className="card-header bg-transparent">
                                <h6 className="mb-0 fw-semibold"><i className="ti ti-note me-1"></i> Observações</h6>
                            </div>
                            <div className="card-body">
                                <p className="mb-0">{record.observation}</p>
                            </div>
                        </div>
                    )}
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
