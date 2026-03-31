import { useState, useEffect, useCallback } from 'react';
import { Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FlashMessages from '@/Components/UI/FlashMessages';
import PageHeader from '@/Components/UI/PageHeader';
import Modal from '@/Components/UI/Modal';
import Badge from '@/Components/UI/Badge';

export default function MedicalRecordsIndex({ patient }) {
    const [records, setRecords] = useState([]);
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(false);
    const [total, setTotal] = useState(0);
    const [loading, setLoading] = useState(true);
    const [detailRecord, setDetailRecord] = useState(null);
    const [showDetail, setShowDetail] = useState(false);

    const person = patient.person;

    const fetchRecords = useCallback(async (p = 1, append = false) => {
        setLoading(true);
        try {
            const res = await fetch(`/panel/patients/${patient.id}/medicalrecords/ajaxlist?per_page=10&page=${p}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setTotal(json.total || 0);
            setHasMore(json.has_more || false);

            if (append) {
                setRecords((prev) => [...prev, ...(json.data || [])]);
            } else {
                setRecords(json.data || []);
            }
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, [patient.id]);

    useEffect(() => {
        fetchRecords();
    }, [fetchRecords]);

    const loadMore = () => {
        const nextPage = page + 1;
        setPage(nextPage);
        fetchRecords(nextPage, true);
    };

    const viewDetail = async (recordId) => {
        try {
            const res = await fetch(`/panel/patients/${patient.id}/medicalrecords/${recordId}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setDetailRecord(json);
            setShowDetail(true);
        } catch { /* ignore */ }
    };

    return (
        <AuthenticatedLayout title={`Prontuários — ${person?.full_name || patient.code}`}>
            <FlashMessages />

            <nav aria-label="breadcrumb" className="mb-3">
                <ol className="breadcrumb mb-0">
                    <li className="breadcrumb-item"><Link href="/panel/dashboard">Dashboard</Link></li>
                    <li className="breadcrumb-item"><Link href="/panel/patients">Pacientes</Link></li>
                    <li className="breadcrumb-item"><Link href={`/panel/patients/${patient.id}`}>{person?.full_name || patient.code}</Link></li>
                    <li className="breadcrumb-item active">Prontuários</li>
                </ol>
            </nav>

            {/* Patient Info Card */}
            <div className="card border-0 shadow-sm mb-3">
                <div className="card-body d-flex align-items-center gap-3 py-2">
                    <img src="/system/images/team.png" className="rounded-circle" width="48" height="48" alt="" />
                    <div>
                        <h6 className="mb-0 fw-semibold">{person?.full_name}</h6>
                        <span className="text-muted small">
                            <code>{patient.code}</code>
                            {patient.covenant?.name && <span className="ms-2">· {patient.covenant.name}</span>}
                        </span>
                    </div>
                    <div className="ms-auto">
                        <Link href={`/panel/patients/${patient.id}/medicalrecords/create`} className="btn btn-primary btn-sm">
                            <i className="ti ti-plus me-1"></i> Novo Prontuário
                        </Link>
                    </div>
                </div>
            </div>

            <PageHeader title="Prontuários" subtitle={`${total} registro${total !== 1 ? 's' : ''}`} />

            {/* Timeline */}
            {loading && page === 1 ? (
                <div className="text-center py-5">
                    <div className="spinner-border text-primary"><span className="visually-hidden">Carregando...</span></div>
                </div>
            ) : records.length > 0 ? (
                <>
                    <ul className="timeline">
                        {records.map((record, i) => {
                            const inverted = i % 2 !== 0;
                            const hasFlags = record.diabetic || record.hypertensive || record.glaucomatous;

                            return (
                                <li key={record.id} className={inverted ? 'timeline-inverted' : ''}>
                                    <div className="timeline-badge" style={{ backgroundColor: record.doctor_color }}>
                                        <img src={record.doctor_photo} alt={record.doctor_name} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                                    </div>
                                    <div className="timeline-panel">
                                        <div className="timeline-heading">
                                            <h5 className="timeline-title mb-1" style={{ color: record.doctor_color }}>
                                                {record.doctor_name}
                                            </h5>
                                            <p className="text-muted small mb-2">
                                                <i className="ti ti-clock me-1"></i>
                                                {record.created_at_formatted} &mdash; <span className="text-secondary fw-semibold">{record.code}</span>
                                            </p>
                                            
                                            {hasFlags && (
                                                <div className="mb-2">
                                                    {record.diabetic && <span className="badge bg-warning text-dark me-1"><i className="ti ti-droplet me-1"></i> Diabético</span>}
                                                    {record.hypertensive && <span className="badge bg-danger text-white me-1"><i className="ti ti-heart-rate-monitor me-1"></i> Hipertenso</span>}
                                                    {record.glaucomatous && <span className="badge bg-info text-white me-1"><i className="ti ti-eye me-1"></i> Glaucoma</span>}
                                                </div>
                                            )}
                                        </div>
                                        <div className="timeline-body">
                                            {record.main_complaint && (
                                                <p className="text-muted small mb-2">{record.main_complaint}</p>
                                            )}
                                            
                                            {(record.tonometer_right || record.tonometer_left) && (
                                                <p className="small mb-2 text-secondary">
                                                    <i className="ti ti-ruler-measure me-1"></i>
                                                    <strong>OD:</strong> {record.tonometer_right || '—'} &nbsp;
                                                    <strong>OE:</strong> {record.tonometer_left || '—'}
                                                </p>
                                            )}

                                            <div className={`mt-2 ${inverted ? 'text-start' : 'text-end'}`}>
                                                <button type="button" className="btn btn-outline-secondary btn-sm me-1" onClick={() => viewDetail(record.id)} title="Ver Detalhes">
                                                    <i className="ti ti-eye"></i>
                                                </button>
                                                <Link href={`/panel/patients/${patient.id}/medicalrecords/${record.id}/edit`} className="btn btn-outline-secondary btn-sm" title="Editar">
                                                    <i className="ti ti-pencil"></i>
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                    {hasMore && (
                        <div className="text-center mt-3">
                            <button className="btn btn-outline-primary btn-sm" onClick={loadMore} disabled={loading}>
                                {loading ? 'Carregando...' : 'Carregar mais'}
                            </button>
                        </div>
                    )}
                </>
            ) : (
                <div className="text-center text-muted py-5">
                    <i className="ti ti-file-medical fs-1 d-block mb-2"></i>
                    Nenhum prontuário registrado para este paciente.
                </div>
            )}

            {/* Detail Modal */}
            <Modal show={showDetail} onClose={() => setShowDetail(false)} title={`Prontuário ${detailRecord?.code || ''}`} size="lg">
                {detailRecord && (
                    <div>
                        <div className="row">
                            <div className="col-md-6"><InfoRow label="Data" value={detailRecord.created_at_formatted} /></div>
                            <div className="col-md-6"><InfoRow label="Médico" value={detailRecord.doctor_name} /></div>
                        </div>
                        <hr />
                        <InfoRow label={detailRecord.labels?.complaint || 'Queixa Principal'} value={detailRecord.main_complaint} />

                        <h6 className="fw-semibold mt-3 mb-2">{detailRecord.labels?.history || 'Histórico'}</h6>
                        <div className="row">
                            <div className="col-md-4">
                                <HistoryItem label={detailRecord.labels?.diabetic} value={detailRecord.diabetic} family={detailRecord.diabetic_family} labels={detailRecord.labels} />
                            </div>
                            <div className="col-md-4">
                                <HistoryItem label={detailRecord.labels?.hypertensive} value={detailRecord.hypertensive} family={detailRecord.hypertensive_family} labels={detailRecord.labels} />
                            </div>
                            <div className="col-md-4">
                                <HistoryItem label={detailRecord.labels?.glaucomatous} value={detailRecord.glaucomatous} family={detailRecord.glaucomatous_family} labels={detailRecord.labels} />
                            </div>
                        </div>

                        {(detailRecord.tonometer_right || detailRecord.tonometer_left) && (
                            <>
                                <h6 className="fw-semibold mt-3 mb-2">{detailRecord.labels?.tonometry || 'Tonometria'}</h6>
                                <div className="row">
                                    <div className="col-md-4"><InfoRow label="OD" value={detailRecord.tonometer_right} /></div>
                                    <div className="col-md-4"><InfoRow label="OE" value={detailRecord.tonometer_left} /></div>
                                    <div className="col-md-4"><InfoRow label="Horário" value={detailRecord.tonometer_time} /></div>
                                </div>
                            </>
                        )}

                        {detailRecord.observation_general && (
                            <InfoRow label={detailRecord.labels?.general_obs || 'Observações Gerais'} value={detailRecord.observation_general} />
                        )}
                        {detailRecord.observation_of_lenses && (
                            <InfoRow label={detailRecord.labels?.lenses_obs || 'Observações de Lentes'} value={detailRecord.observation_of_lenses} />
                        )}
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

function HistoryItem({ label, value, family, labels }) {
    const yes = labels?.yes || 'Sim';
    const no = labels?.no || 'Não';
    const familyLabel = labels?.family || 'Familiar';

    return (
        <div className="mb-2">
            <span className="fw-medium d-block">{label}</span>
            <Badge variant={value ? 'warning' : 'success'}>{value ? yes : no}</Badge>
            <span className="text-muted small ms-2">{familyLabel}: {family ? yes : no}</span>
        </div>
    );
}
