import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/UI/ConfirmDialog';

export default function WorkSchedule({
    doctor, days: initialDays, blocks: initialBlocks,
    interval: initialInterval, entity_interval,
    sync_url, store_block_url, destroy_block_base,
}) {
    const [days, setDays] = useState(
        initialDays.map(d => ({ ...d, ranges: d.ranges.map(r => ({ ...r })) }))
    );
    const [interval, setInterval] = useState(initialInterval ?? '');
    const [saving, setSaving] = useState(false);
    const [saveSuccess, setSaveSuccess] = useState(false);
    const [saveError, setSaveError] = useState('');

    const [blocks, setBlocks] = useState(initialBlocks);
    const [showBlockForm, setShowBlockForm] = useState(false);
    const [storing, setStoring] = useState(false);
    const [blockErrors, setBlockErrors] = useState({});
    const [blockForm, setBlockForm] = useState({ type: 'absence', starts_at: '', ends_at: '', reason: '' });

    const [confirmState, setConfirmState] = useState({ show: false, action: null, message: '', title: '' });

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const activeDaysCount = () => days.filter(d => d.active).length;

    function toggleDay(idx) {
        setDays(prev => prev.map((d, i) => i === idx ? { ...d, active: !d.active } : d));
    }
    function addRange(idx) {
        setDays(prev => prev.map((d, i) => i === idx
            ? { ...d, ranges: [...d.ranges, { starts_at: '08:00', ends_at: '12:00' }] }
            : d));
    }
    function removeRange(dayIdx, rangeIdx) {
        setDays(prev => prev.map((d, i) => i === dayIdx
            ? { ...d, ranges: d.ranges.filter((_, ri) => ri !== rangeIdx) }
            : d));
    }
    function updateRange(dayIdx, rangeIdx, field, value) {
        setDays(prev => prev.map((d, i) => i === dayIdx
            ? { ...d, ranges: d.ranges.map((r, ri) => ri === rangeIdx ? { ...r, [field]: value } : r) }
            : d));
    }

    async function save() {
        setSaving(true);
        setSaveSuccess(false);
        setSaveError('');
        try {
            const res = await fetch(sync_url, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ schedule_interval: interval || null, days }),
            });
            const data = await res.json();
            if (!res.ok) { setSaveError(data.message ?? 'Erro ao salvar.'); return; }
            setSaveSuccess(true);
            setTimeout(() => setSaveSuccess(false), 3000);
        } catch {
            setSaveError('Erro de conexão.');
        } finally {
            setSaving(false);
        }
    }

    async function storeBlock() {
        setStoring(true);
        setBlockErrors({});
        try {
            const res = await fetch(store_block_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(blockForm),
            });
            const data = await res.json();
            if (res.status === 422) { setBlockErrors(data.errors ?? {}); return; }
            if (!res.ok) return;
            setBlocks(prev => [...prev, data.data].sort((a, b) => a.starts_at.localeCompare(b.starts_at)));
            setBlockForm({ type: 'absence', starts_at: '', ends_at: '', reason: '' });
            setShowBlockForm(false);
        } finally {
            setStoring(false);
        }
    }

    function confirmDestroyBlock(blockId) {
        setConfirmState({
            show: true,
            title: 'Remover bloqueio?',
            message: 'Esta ação não pode ser desfeita.',
            action: () => doDestroyBlock(blockId),
        });
    }

    async function doDestroyBlock(blockId) {
        const res = await fetch(`${destroy_block_base}/${blockId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });
        if (res.ok) setBlocks(prev => prev.filter(b => b.id !== blockId));
    }

    return (
        <AuthenticatedLayout title="Escala de Trabalho">
            <nav aria-label="breadcrumb" className="mb-3">
                <ol className="breadcrumb mb-0">
                    <li className="breadcrumb-item"><Link href="/panel/dashboard">Dashboard</Link></li>
                    <li className="breadcrumb-item"><Link href="/panel/doctors">Médicos</Link></li>
                    <li className="breadcrumb-item active">Escala de Trabalho</li>
                </ol>
            </nav>

            {/* Doctor header */}
            <div className="card card-body py-3 mb-3">
                <div className="d-flex align-items-center gap-3">
                    <img
                        src={doctor.photo_url}
                        alt={doctor.name}
                        className="rounded-circle flex-shrink-0"
                        width="56" height="56"
                        style={{ objectFit: 'cover', border: `3px solid ${doctor.color}` }}
                    />
                    <div>
                        <h5 className="mb-0 fw-bold" style={{ color: doctor.color }}>{doctor.name}</h5>
                        <small className="text-muted">CRM {doctor.record} &mdash; {doctor.code}</small>
                    </div>
                    <div className="ms-auto">
                        <Link href="/panel/doctors" className="btn btn-sm btn-outline-secondary">
                            <i className="fas fa-arrow-left me-1"></i> Voltar
                        </Link>
                    </div>
                </div>
            </div>

            <div className="row g-3">
                {/* Left — Work schedule */}
                <div className="col-lg-8">
                    <div className="card">
                        <div className="card-header d-flex align-items-center justify-content-between">
                            <span><i className="fas fa-clock me-2 text-info"></i>Horários de Atendimento</span>
                            <span className="badge bg-secondary">{activeDaysCount()} dia(s) ativo(s)</span>
                        </div>
                        <div className="card-body">
                            {/* Interval input */}
                            <div className="mb-4 p-3 bg-light rounded">
                                <label className="form-label fw-semibold mb-1">
                                    <i className="fas fa-stopwatch me-1 text-secondary"></i>
                                    Intervalo entre consultas
                                </label>
                                <div className="d-flex align-items-center gap-2" style={{ maxWidth: 280 }}>
                                    <input
                                        type="number"
                                        className="form-control form-control-sm"
                                        value={interval}
                                        onChange={e => setInterval(e.target.value)}
                                        min="5" max="120" step="5"
                                        placeholder="Ex: 15"
                                    />
                                    <span className="text-muted small flex-shrink-0">minutos</span>
                                </div>
                                <small className="text-muted d-block mt-1">
                                    Padrão da clínica: {entity_interval} min. Deixe em branco para usar o padrão.
                                </small>
                            </div>

                            {/* Day grid */}
                            {days.map((day, dayIdx) => (
                                <div key={day.day} className="border rounded mb-2 overflow-hidden">
                                    <div className={`d-flex align-items-center px-3 py-2 ${day.active ? 'bg-info bg-opacity-10' : 'bg-light'}`}>
                                        <div className="form-check form-switch mb-0 flex-grow-1">
                                            <input
                                                className="form-check-input"
                                                type="checkbox"
                                                id={`day-${day.day}`}
                                                checked={day.active}
                                                onChange={() => toggleDay(dayIdx)}
                                            />
                                            <label
                                                className={`form-check-label fw-semibold ${day.active ? 'text-dark' : 'text-muted'}`}
                                                htmlFor={`day-${day.day}`}
                                            >
                                                {day.name}
                                            </label>
                                        </div>
                                        {day.active && (
                                            <button type="button" className="btn btn-sm btn-outline-info" onClick={() => addRange(dayIdx)}>
                                                <i className="fas fa-plus me-1"></i> Faixa
                                            </button>
                                        )}
                                    </div>

                                    {day.active ? (
                                        <div className="px-3 pb-3 pt-2">
                                            {day.ranges.map((range, rangeIdx) => (
                                                <div key={rangeIdx} className="d-flex align-items-center gap-2 mb-2">
                                                    <span className="text-muted small" style={{ width: 30 }}>De</span>
                                                    <input
                                                        type="time"
                                                        className="form-control form-control-sm"
                                                        style={{ maxWidth: 120 }}
                                                        value={range.starts_at}
                                                        onChange={e => updateRange(dayIdx, rangeIdx, 'starts_at', e.target.value)}
                                                    />
                                                    <span className="text-muted small">até</span>
                                                    <input
                                                        type="time"
                                                        className="form-control form-control-sm"
                                                        style={{ maxWidth: 120 }}
                                                        value={range.ends_at}
                                                        onChange={e => updateRange(dayIdx, rangeIdx, 'ends_at', e.target.value)}
                                                    />
                                                    {day.ranges.length > 1 && (
                                                        <button type="button" className="btn btn-sm btn-outline-danger"
                                                            onClick={() => removeRange(dayIdx, rangeIdx)}>
                                                            <i className="fas fa-times"></i>
                                                        </button>
                                                    )}
                                                </div>
                                            ))}
                                            {day.ranges.length === 0 && (
                                                <div className="text-muted small">Nenhuma faixa. Clique em &quot;+ Faixa&quot;.</div>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="px-3 pb-2 pt-1">
                                            <small className="text-muted">Médico não atende neste dia.</small>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                        <div className="card-footer d-flex align-items-center gap-2">
                            <button type="button" className="btn btn-info" onClick={save} disabled={saving}>
                                {saving
                                    ? <span className="spinner-border spinner-border-sm me-1"></span>
                                    : <i className="far fa-save me-1"></i>}
                                Salvar Escala
                            </button>
                            {saveSuccess && (
                                <span className="text-success small">
                                    <i className="fas fa-check-circle me-1"></i> Salvo com sucesso.
                                </span>
                            )}
                            {saveError && <span className="text-danger small">{saveError}</span>}
                        </div>
                    </div>
                </div>

                {/* Right — Blocks */}
                <div className="col-lg-4">
                    <div className="card">
                        <div className="card-header d-flex align-items-center justify-content-between">
                            <span><i className="fas fa-ban me-2 text-danger"></i>Bloqueios / Ausências</span>
                            <button type="button" className="btn btn-sm btn-outline-danger"
                                onClick={() => setShowBlockForm(!showBlockForm)}>
                                <i className={showBlockForm ? 'fas fa-times' : 'fas fa-plus'}></i>
                            </button>
                        </div>

                        {showBlockForm && (
                            <div className="card-body border-bottom pb-3">
                                <div className="row g-2">
                                    <div className="col-12">
                                        <label className="form-label small mb-1">Tipo</label>
                                        <select className="form-select form-select-sm" value={blockForm.type}
                                            onChange={e => setBlockForm(f => ({ ...f, type: e.target.value }))}>
                                            <option value="absence">Ausência</option>
                                            <option value="holiday">Feriado</option>
                                            <option value="meeting">Reunião / Compromisso</option>
                                            <option value="other">Outro</option>
                                        </select>
                                    </div>
                                    <div className="col-12">
                                        <label className="form-label small mb-1">Início <span className="text-danger">*</span></label>
                                        <input type="datetime-local"
                                            className={`form-control form-control-sm ${blockErrors.starts_at ? 'is-invalid' : ''}`}
                                            value={blockForm.starts_at}
                                            onChange={e => setBlockForm(f => ({ ...f, starts_at: e.target.value }))} />
                                        {blockErrors.starts_at && <div className="invalid-feedback">{blockErrors.starts_at[0]}</div>}
                                    </div>
                                    <div className="col-12">
                                        <label className="form-label small mb-1">Fim <span className="text-danger">*</span></label>
                                        <input type="datetime-local"
                                            className={`form-control form-control-sm ${blockErrors.ends_at ? 'is-invalid' : ''}`}
                                            value={blockForm.ends_at}
                                            onChange={e => setBlockForm(f => ({ ...f, ends_at: e.target.value }))} />
                                        {blockErrors.ends_at && <div className="invalid-feedback">{blockErrors.ends_at[0]}</div>}
                                    </div>
                                    <div className="col-12">
                                        <label className="form-label small mb-1">Motivo</label>
                                        <input type="text" className="form-control form-control-sm"
                                            value={blockForm.reason} placeholder="Opcional..."
                                            onChange={e => setBlockForm(f => ({ ...f, reason: e.target.value }))} />
                                    </div>
                                    <div className="col-12">
                                        <button type="button" className="btn btn-sm btn-danger w-100"
                                            onClick={storeBlock} disabled={storing}>
                                            {storing && <span className="spinner-border spinner-border-sm me-1"></span>}
                                            Adicionar Bloqueio
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="card-body p-0">
                            {blocks.length === 0 ? (
                                <div className="text-center py-4 text-muted">
                                    <i className="fas fa-check-circle fa-2x mb-2 text-success"></i>
                                    <p className="small mb-0">Nenhum bloqueio futuro.</p>
                                </div>
                            ) : blocks.map(block => (
                                <div key={block.id} className="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
                                    <div className="flex-grow-1">
                                        <div className="fw-semibold small">{block.type_label}</div>
                                        <div className="text-muted" style={{ fontSize: '.8rem' }}>
                                            <i className="fas fa-calendar-alt me-1"></i>
                                            {block.starts_at} &rarr; {block.ends_at}
                                        </div>
                                        {block.reason && (
                                            <div className="text-secondary" style={{ fontSize: '.8rem' }}>{block.reason}</div>
                                        )}
                                    </div>
                                    <button type="button" className="btn btn-sm btn-outline-danger flex-shrink-0"
                                        onClick={() => confirmDestroyBlock(block.id)}>
                                        <i className="fas fa-trash fa-xs"></i>
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                show={confirmState.show}
                title={confirmState.title}
                message={confirmState.message}
                onConfirm={confirmState.action}
                onClose={() => setConfirmState(s => ({ ...s, show: false }))}
            />
        </AuthenticatedLayout>
    );
}
