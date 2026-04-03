{{-- Estilos compartilhados para o formulário de prontuário (layout smart_oftal) --}}
<style>
/* ── Labels (teal italic, como smart_oftal) ────────────────────────── */
.pmr-label {
    color: #26a69a;
    font-size: .78rem;
    font-style: italic;
    font-weight: 500;
    display: block;
    margin-bottom: 2px;
}
.pmr-toggle-label {
    font-size: .72rem;
    color: #6c757d;
}

/* ── Eye badges (input-group) ──────────────────────────────────────── */
.pmr-eye-badge {
    font-size: .75rem;
    font-weight: 700;
    color: #1976d2;
    min-width: 28px;
    text-align: center;
    background: #e3eef9;
    border-color: #d0e4f7;
}
.pmr-eye-inline {
    font-size: .75rem;
    font-weight: 700;
    color: #1976d2;
    min-width: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #e3eef9;
    border: 1px solid #d0e4f7;
    border-radius: .25rem;
    padding: 0 6px;
    height: 31px;
    flex-shrink: 0;
}

/* ── Refraction tables ─────────────────────────────────────────────── */
.pmr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .85rem;
}
.pmr-table th {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    padding: 3px 6px;
    text-align: center;
    font-weight: 500;
    font-size: .75rem;
}
.pmr-table td {
    border: 1px solid #dee2e6;
    padding: 0;
}
.pmr-table td.pmr-od {
    text-align: center;
    font-weight: 700;
    color: #1976d2;
    font-size: .75rem;
    padding: 3px;
    width: 36px;
    background: #f5f9ff;
}
.pmr-table td input {
    width: 100%;
    border: none;
    outline: none;
    text-align: center;
    padding: 4px 3px;
    font-size: .82rem;
    background: transparent;
}
.pmr-table td input:focus {
    background: #f0f9f8;
}

/* ── Sections spacing ──────────────────────────────────────────────── */
.pmr-section {
    /* Seções internas com separação sutil */
}

/* ── Collapse toggle (campos extras) ──────────────────────────────── */
.pmr-collapse-toggle {
    cursor: pointer;
    padding: 5px 0;
    border-top: 1px solid #e9ecef;
    user-select: none;
    display: flex;
    align-items: center;
}
.pmr-collapse-toggle:hover .pmr-label {
    color: #1976d2;
}
.pmr-collapse-toggle .pmr-collapse-icon {
    transition: transform .2s;
    font-size: .7rem;
    color: #26a69a;
}
.pmr-collapse-toggle[aria-expanded="true"] .pmr-collapse-icon {
    transform: rotate(180deg);
}

/* ── Bottom bar (Prescrições / Laudos / Salvar) ────────────────────── */
.pmr-bottom-bar {
    background: linear-gradient(135deg, #4dd0e1 0%, #26a69a 100%);
    border-top: 1px solid #1de9b6;
}
.pmr-bottom-bar .pmr-label {
    color: #fff;
    font-style: italic;
    font-size: .8rem;
}

/* Doc type buttons */
.pmr-doc-btn {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 50px;
    background: rgba(255,255,255,.9);
    border: 1px solid #b2dfdb;
    border-radius: .375rem;
    color: #00796b;
    cursor: pointer;
    transition: all .15s;
    padding: 2px;
}
.pmr-doc-btn:hover {
    background: #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,.12);
    transform: translateY(-1px);
    color: #004d40;
}
.pmr-doc-btn i {
    font-size: 1.1rem;
    margin-bottom: 1px;
}
.pmr-doc-btn-label {
    font-size: .58rem;
    line-height: 1;
    text-align: center;
    max-width: 52px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Save button (green circle) */
.pmr-save-btn {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: #43a047;
    color: #fff;
    border: 2px solid #2e7d32;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    transition: all .15s;
}
.pmr-save-btn:hover {
    background: #2e7d32;
    color: #fff;
    transform: scale(1.05);
}

/* ── File thumbnails ───────────────────────────────────────────────── */
.pmr-file-thumb {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    border: 1px solid #dee2e6;
    border-radius: .375rem;
    overflow: hidden;
    background: #f8f9fa;
    text-decoration: none;
    color: #6c757d;
}
.pmr-file-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.pmr-file-thumb i {
    font-size: 1.5rem;
}
.pmr-file-thumb:hover {
    border-color: #26a69a;
    box-shadow: 0 2px 6px rgba(0,0,0,.1);
}

/* ── Docs table compact ────────────────────────────────────────────── */
.pmr-docs-table {
    font-size: .82rem;
}
.pmr-docs-table th {
    font-size: .75rem;
    font-weight: 600;
}

/* ── Responsive adjustments ────────────────────────────────────────── */
@media (max-width: 991.98px) {
    .pmr-doc-btn {
        width: 44px;
        height: 42px;
    }
    .pmr-doc-btn i { font-size: .9rem; }
    .pmr-doc-btn-label { font-size: .5rem; }
    .pmr-save-btn { width: 42px; height: 42px; font-size: 1.2rem; }
}

/* ── Image-based doc buttons (layout igual screenshot) ──────────────── */
.pmr-doc-img-btn {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 60px;
    height: 54px;
    background: rgba(255,255,255,.92);
    border: 1px solid #b2dfdb;
    border-radius: .375rem;
    cursor: pointer;
    transition: all .15s;
    padding: 2px 3px;
    text-decoration: none;
}
.pmr-doc-img-btn:hover {
    background: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,.14);
    transform: translateY(-1px);
}
.pmr-doc-img-btn img {
    width: 32px;
    height: 32px;
    object-fit: contain;
    margin-bottom: 2px;
}
.pmr-doc-img-btn-label {
    font-size: .58rem;
    line-height: 1.1;
    text-align: center;
    max-width: 56px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #00796b;
    font-weight: 500;
}
.pmr-doc-img-btn.pmr-doc-annexo {
    background: rgba(244,67,54,.1);
    border-color: #ef9a9a;
}
.pmr-doc-img-btn.pmr-doc-annexo:hover {
    background: rgba(244,67,54,.18);
}
.pmr-doc-img-btn.pmr-doc-annexo .pmr-doc-img-btn-label {
    color: #c62828;
}

/* Preview (create mode): botões desabilitados com aparência de ficha completa */
.pmr-doc-preview {
    opacity: .42;
    cursor: not-allowed !important;
    pointer-events: none;
}

/* Pink button (auto-fill tonometria time + print prescription) */
.btn-pink {
    background-color: #e91e8c;
    border-color: #c2185b;
    color: #fff;
}
.btn-pink:hover, .btn-pink:focus {
    background-color: #c2185b;
    border-color: #ad1457;
    color: #fff;
}

@media (max-width: 991.98px) {
    .pmr-doc-img-btn { width: 48px; height: 46px; }
    .pmr-doc-img-btn img { width: 26px; height: 26px; }
    .pmr-doc-img-btn-label { font-size: .52rem; }
}
</style>

