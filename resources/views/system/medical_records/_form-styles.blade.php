<style>
.pmr-screen {
    --pmr-blue: #35a9e1;
    --pmr-blue-dark: #258dc0;
    --pmr-panel: #d8dde3;
    --pmr-line: #c8d0d8;
    --pmr-input: #f8fafc;
    --pmr-text: #334155;
}

.pmr-screen .pmr-toolbar {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
}

.pmr-screen .pmr-toolbar-btn {
    border: 1px solid #b5c0cb;
    background: #eef2f6;
    color: #2f3d4f;
    font-size: .86rem;
    line-height: 1;
    font-weight: 700;
    border-radius: 999px;
    padding: .36rem .76rem;
}

.pmr-screen .pmr-toolbar-btn:hover {
    background: #e3eaf1;
    color: #1f2937;
}

.pmr-screen .pmr-toolbar-btn-new {
    background: var(--pmr-blue);
    border-color: var(--pmr-blue-dark);
    color: #fff;
}

.pmr-screen .pmr-toolbar-btn-new:hover {
    background: var(--pmr-blue-dark);
    color: #fff;
}

.pmr-screen .pmr-content-card {
    border: 1px solid #cfd6de;
    border-radius: 2px;
    background: #f5f7fa;
    box-shadow: none;
}

.pmr-screen .pmr-record-strip {
    background: #f1f4f8;
    border-bottom: 1px solid #d8dee6;
    color: #5d6978;
    font-size: .74rem;
    font-style: italic;
}

.pmr-form {
    color: var(--pmr-text);
}

.pmr-form .pmr-label {
    color: var(--pmr-blue);
    font-size: 1rem;
    font-style: italic;
    font-weight: 700;
    margin-bottom: .2rem;
    line-height: 1.1;
}

.pmr-form .pmr-top-strip {
    background: #f5f7fa;
}

.pmr-form .pmr-risk-wrap {
    padding-top: .05rem;
}

.pmr-form .pmr-risk-item .pmr-label {
    margin-bottom: .15rem;
    text-align: center;
}

.pmr-form .pmr-risk-switches {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .24rem;
    min-height: 1.7rem;
}

.pmr-form .pmr-risk-switches .form-check {
    margin: 0;
    min-height: 1rem;
}

.pmr-form .pmr-risk-switches .form-check-input {
    margin: 0;
    width: 2.35rem;
    height: 1.35rem;
    border-color: #b7c1cb;
    background-color: #eef2f6;
    cursor: pointer;
}

.pmr-form .pmr-risk-switches .pmr-toggle-label {
    display: none;
}

.pmr-form .pmr-toggle-label {
    font-size: .66rem;
    color: #64748b;
}

.pmr-form .pmr-main-columns {
    margin-top: .05rem;
}

.pmr-form .pmr-main-panel {
    background: var(--pmr-panel);
    border: 1px solid #d2d9e1;
    border-radius: 3px;
    padding: .58rem;
    height: 100%;
}

.pmr-form .pmr-section {
    margin-bottom: .38rem !important;
}

.pmr-form .pmr-main-panel .pmr-section:last-child {
    margin-bottom: 0 !important;
}

.pmr-form .form-control,
.pmr-form .form-select,
.pmr-form .input-group-text,
.pmr-form .btn {
    min-height: 30px;
    font-size: .84rem;
}

.pmr-form .form-control,
.pmr-form .form-select {
    border-color: var(--pmr-line);
    background: var(--pmr-input);
    color: #374151;
    padding-top: .18rem;
    padding-bottom: .18rem;
}

.pmr-form textarea.form-control {
    min-height: 38px;
}

.pmr-form .input-group-sm > .input-group-text,
.pmr-form .input-group-sm > .form-control,
.pmr-form .input-group-sm > .form-select,
.pmr-form .input-group-sm > .btn {
    font-size: .84rem;
}

.pmr-eye-badge {
    font-size: .73rem;
    font-weight: 700;
    color: #3b4e63;
    min-width: 30px;
    text-align: center;
    background: #e7edf3;
    border-color: #ccd4dc;
}

.pmr-eye-inline {
    font-size: .73rem;
    font-weight: 700;
    color: #3b4e63;
    min-width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #e7edf3;
    border: 1px solid #ccd4dc;
    border-radius: .2rem;
    padding: 0 5px;
    flex-shrink: 0;
}

.pmr-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 2px;
    font-size: .84rem;
}

.pmr-table th {
    padding: 0 3px 2px;
    text-align: left;
    border: 0;
    background: transparent;
    color: #374151;
    font-weight: 500;
    font-size: .84rem;
}

.pmr-table td {
    border: 0;
    padding: 0 1px;
    vertical-align: middle;
}

.pmr-table td.pmr-od {
    text-align: center;
    font-weight: 700;
    color: #4b5563;
    font-size: .82rem;
    width: 32px;
    background: transparent;
}

.pmr-table td input {
    width: 100%;
    border: 1px solid var(--pmr-line);
    border-radius: .2rem;
    outline: none;
    text-align: left;
    padding: .2rem .4rem;
    font-size: .84rem;
    background: var(--pmr-input);
}

.pmr-table td input:focus {
    background: #fff;
    border-color: #8eb4d0;
}

.pmr-form .pmr-collapse-toggle {
    cursor: pointer;
    padding: 4px 0;
    border-top: 1px solid #d8dde3;
    display: flex;
    align-items: center;
}

.pmr-form .pmr-collapse-toggle .pmr-collapse-icon {
    transition: transform .2s;
    font-size: .72rem;
    color: var(--pmr-blue);
}

.pmr-form .pmr-collapse-toggle[aria-expanded='true'] .pmr-collapse-icon {
    transform: rotate(180deg);
}

.pmr-form .pmr-bottom-bar {
    background: #f5f7fa;
    border-top: 1px solid #d5dbe3;
    border-bottom: 1px solid #d5dbe3;
}

.pmr-form .pmr-bottom-bar .pmr-label {
    font-size: 1.02rem;
    margin-bottom: 0;
}

.pmr-doc-img-btn {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 64px;
    min-height: 46px;
    border: 0;
    background: transparent;
    border-radius: .3rem;
    padding: 2px;
    cursor: pointer;
    text-decoration: none;
    transition: transform .14s ease;
}

.pmr-doc-img-btn:hover {
    transform: translateY(-1px);
}

.pmr-doc-img-btn img {
    width: 40px;
    height: 30px;
    object-fit: contain;
    margin-bottom: 1px;
}

.pmr-doc-img-btn-label {
    font-size: .52rem;
    line-height: 1.02;
    text-align: center;
    color: #64748b;
    font-weight: 600;
    max-width: 62px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pmr-doc-img-btn.pmr-doc-annexo .pmr-doc-img-btn-label {
    color: #b45309;
}

.pmr-doc-preview {
    opacity: .42;
    pointer-events: none;
    cursor: not-allowed !important;
}

.pmr-save-btn {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    border: 1px solid #2f8444;
    background: #3aa655;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.55rem;
    transition: all .15s;
}

.pmr-save-btn:hover {
    background: #2f8444;
    color: #fff;
    transform: scale(1.03);
}

.btn-pink {
    background-color: #de6a79;
    border-color: #cc5868;
    color: #fff;
}

.btn-pink:hover,
.btn-pink:focus {
    background-color: #ca5566;
    border-color: #bb4c5d;
    color: #fff;
}

.pmr-file-thumb {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border: 1px solid #d3d8de;
    border-radius: .3rem;
    overflow: hidden;
    background: #fff;
    text-decoration: none;
    color: #6b7280;
}

.pmr-file-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.pmr-file-thumb i {
    font-size: 1.3rem;
}

.pmr-docs-table {
    font-size: .8rem;
}

.pmr-docs-table th {
    font-size: .72rem;
    font-weight: 600;
}

.pmr-patient-card {
    border: 1px solid #cfd5dd;
    border-radius: 2px;
    overflow: hidden;
    background: #f2f4f7;
}

.pmr-patient-avatar-wrap {
    width: 100%;
    aspect-ratio: 1 / 1;
    background: #d0d4d9;
    border-bottom: 1px solid #c8ced6;
}

.pmr-patient-avatar {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.pmr-patient-body {
    padding: .7rem .85rem;
}

.pmr-patient-label {
    color: #334155;
    font-size: .82rem;
    font-weight: 500;
    margin-bottom: .1rem;
}

.pmr-patient-value {
    color: #475569;
    font-size: .78rem;
    word-break: break-word;
}

.pmr-patient-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .45rem;
}

.pmr-patient-divider {
    height: 1px;
    background: #d4dae2;
    margin: .5rem 0;
}

:root[data-bs-theme=dark] .pmr-screen {
    --pmr-blue: #66c7ff;
    --pmr-blue-dark: #4ea9da;
    --pmr-panel: #1f2733;
    --pmr-line: #3a4555;
    --pmr-input: #121922;
    --pmr-text: #d6dde7;
}

:root[data-bs-theme=dark] .pmr-screen .pmr-toolbar-btn {
    border-color: #425062;
    background: #1a2230;
    color: #d6dde7;
}

:root[data-bs-theme=dark] .pmr-screen .pmr-toolbar-btn:hover {
    background: #222c3d;
    color: #ecf2fb;
}

:root[data-bs-theme=dark] .pmr-screen .pmr-toolbar-btn-new {
    background: #1b89c2;
    border-color: #1574a4;
    color: #fff;
}

:root[data-bs-theme=dark] .pmr-screen .pmr-toolbar-btn-new:hover {
    background: #1574a4;
}

:root[data-bs-theme=dark] .pmr-screen .pmr-content-card {
    background: #111827;
    border-color: #2a3547;
}

:root[data-bs-theme=dark] .pmr-screen .pmr-record-strip {
    background: #0f172a;
    border-bottom-color: #2a3547;
    color: #9fb0c9;
}

:root[data-bs-theme=dark] .pmr-form .pmr-top-strip {
    background: #111827;
}

:root[data-bs-theme=dark] .pmr-form .pmr-main-panel {
    background: #1b2432;
    border-color: #2f3a4d;
}

:root[data-bs-theme=dark] .pmr-form .form-control,
:root[data-bs-theme=dark] .pmr-form .form-select {
    background: #121a26;
    border-color: #384559;
    color: #dbe4ef;
}

:root[data-bs-theme=dark] .pmr-form .form-control::placeholder {
    color: #8695a8;
}

:root[data-bs-theme=dark] .pmr-form .input-group-text {
    background: #18212f;
    border-color: #384559;
    color: #b9c7d8;
}

:root[data-bs-theme=dark] .pmr-form .pmr-risk-switches .form-check-input {
    border-color: #506178;
    background-color: #1c2533;
}

:root[data-bs-theme=dark] .pmr-form .pmr-risk-switches .form-check-input:checked {
    background-color: #1890cf;
    border-color: #157db3;
}

:root[data-bs-theme=dark] .pmr-table th {
    color: #c8d4e6;
}

:root[data-bs-theme=dark] .pmr-table td.pmr-od {
    color: #b3c1d4;
}

:root[data-bs-theme=dark] .pmr-table td input {
    background: #121a26;
    border-color: #384559;
    color: #dbe4ef;
}

:root[data-bs-theme=dark] .pmr-table td input:focus {
    background: #162031;
    border-color: #5b8db5;
}

:root[data-bs-theme=dark] .pmr-form .pmr-collapse-toggle {
    border-top-color: #2f3a4d;
}

:root[data-bs-theme=dark] .pmr-form .pmr-bottom-bar {
    background: #0f172a;
    border-top-color: #2a3547;
    border-bottom-color: #2a3547;
}

:root[data-bs-theme=dark] .pmr-doc-img-btn-label {
    color: #b7c4d5;
}

:root[data-bs-theme=dark] .pmr-doc-img-btn.pmr-doc-annexo .pmr-doc-img-btn-label {
    color: #f2bb70;
}

:root[data-bs-theme=dark] .pmr-file-thumb {
    background: #101827;
    border-color: #354258;
    color: #9dafc7;
}

:root[data-bs-theme=dark] .pmr-docs-table thead th {
    background: #101827;
    color: #9fb0c9;
    border-color: #2a3547;
}

:root[data-bs-theme=dark] .pmr-docs-table tbody td {
    border-color: #2a3547;
    color: #d6dde7;
}

:root[data-bs-theme=dark] .pmr-patient-card {
    background: #111827;
    border-color: #2a3547;
}

:root[data-bs-theme=dark] .pmr-patient-avatar-wrap {
    background: #1b2432;
    border-bottom-color: #2a3547;
}

:root[data-bs-theme=dark] .pmr-patient-label {
    color: #c8d4e6;
}

:root[data-bs-theme=dark] .pmr-patient-value {
    color: #aebcd0;
}

:root[data-bs-theme=dark] .pmr-patient-divider {
    background: #2a3547;
}

@media (max-width: 1199.98px) {
    .pmr-form .pmr-label {
        font-size: .9rem;
    }

    .pmr-form .pmr-bottom-bar .pmr-label {
        font-size: .92rem;
    }
}

@media (max-width: 991.98px) {
    .pmr-screen .pmr-toolbar {
        flex-wrap: wrap;
    }

    .pmr-screen .pmr-toolbar-btn {
        font-size: .8rem;
        padding: .32rem .6rem;
    }

    .pmr-form .pmr-main-panel {
        padding: .5rem;
    }

    .pmr-form .pmr-label {
        font-size: .88rem;
    }

    .pmr-doc-img-btn {
        width: 52px;
    }

    .pmr-doc-img-btn img {
        width: 34px;
        height: 25px;
    }

    .pmr-doc-img-btn-label {
        font-size: .48rem;
    }
}
</style>
