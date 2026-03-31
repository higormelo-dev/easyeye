import { Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import { StatusBadge } from '@/Components/UI/Badge';

export default function PatientShow({ record }) {
    const person = record.person;

    return (
        <AuthenticatedLayout title={`Paciente — ${person?.full_name || record.code}`}>
            <FlashMessages />

            <nav aria-label="breadcrumb" className="mb-3">
                <ol className="breadcrumb mb-0">
                    <li className="breadcrumb-item"><Link href="/panel/dashboard">Dashboard</Link></li>
                    <li className="breadcrumb-item"><Link href="/panel/patients">Pacientes</Link></li>
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
                            <code className="d-block mb-2">{record.code}</code>
                            <StatusBadge active={record.active} />
                        </div>
                    </div>

                    <div className="card border-0 shadow-sm mt-3">
                        <div className="card-body">
                            <h6 className="fw-semibold mb-3"><i className="ti ti-phone me-1"></i> Contato</h6>
                            <InfoRow label="Telefone" value={person?.telephone} />
                            <InfoRow label="Celular" value={person?.cellphone} />
                            <InfoRow label="WhatsApp" value={person?.whatsapp ? 'Sim' : 'Não'} />
                            <InfoRow label="E-mail" value={person?.email} />
                        </div>
                    </div>

                    <div className="card border-0 shadow-sm mt-3">
                        <div className="card-body">
                            <h6 className="fw-semibold mb-3"><i className="ti ti-heart-rate-monitor me-1"></i> Dados Clínicos</h6>
                            <InfoRow label="Convênio" value={record.covenant?.name} />
                            <InfoRow label="Nº Carteirinha" value={record.card_number} />
                            <InfoRow label="Tipo de Pele" value={record.skin_type?.name} />
                            <InfoRow label="Tipo de Íris" value={record.iris_type?.name} />
                        </div>
                    </div>

                    <div className="mt-3">
                        <Link
                            href={`/panel/patients/${record.id}/medicalrecords`}
                            className="btn btn-primary w-100"
                        >
                            <i className="ti ti-file-medical me-1"></i> Ver Prontuários
                        </Link>
                    </div>
                </div>

                {/* Detalhes */}
                <div className="col-md-8">
                    <div className="card border-0 shadow-sm">
                        <div className="card-header bg-transparent">
                            <h6 className="mb-0 fw-semibold"><i className="ti ti-user me-1"></i> Dados Pessoais</h6>
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
