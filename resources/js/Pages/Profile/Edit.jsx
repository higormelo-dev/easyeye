import { useRef, useState } from 'react';
import { useForm, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Edit({ mustVerifyEmail, userPhotoUrl, profileUrl, passwordUrl }) {
    const { auth, flash } = usePage().props;

    /* ─── Profile form ─── */
    const profileForm = useForm({
        _method: 'patch',
        name: auth.user?.name ?? '',
        email: auth.user?.email ?? '',
        photo: null,
    });

    const [photoPreview, setPhotoPreview] = useState(userPhotoUrl);
    const fileInputRef = useRef(null);

    function handlePhotoChange(e) {
        const file = e.target.files[0];
        if (!file) return;
        profileForm.setData('photo', file);
        setPhotoPreview(URL.createObjectURL(file));
    }

    function submitProfile(e) {
        e.preventDefault();
        profileForm.post(profileUrl, {
            forceFormData: true,
            onSuccess: () => profileForm.setData('photo', null),
        });
    }

    /* ─── Password form ─── */
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const [showCurrent, setShowCurrent] = useState(false);
    const [showNew, setShowNew]         = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);

    function submitPassword(e) {
        e.preventDefault();
        passwordForm.put(passwordUrl, {
            onSuccess: () => passwordForm.reset(),
        });
    }

    return (
        <AuthenticatedLayout title="Editar Perfil">
            <nav aria-label="breadcrumb" className="mb-3">
                <ol className="breadcrumb mb-0">
                    <li className="breadcrumb-item">
                        <Link href="/panel/dashboard">Dashboard</Link>
                    </li>
                    <li className="breadcrumb-item active">Editar Perfil</li>
                </ol>
            </nav>

            {/* Flash messages */}
            {flash?.success && (
                <div className="alert alert-success alert-dismissible fade show mb-3" role="alert">
                    <i className="ti ti-circle-check me-2"></i>{flash.success}
                    <button
                        type="button"
                        className="btn-close"
                        onClick={e => e.currentTarget.closest('.alert').remove()}
                    />
                </div>
            )}

            <div className="card">
                <div className="card-body">

                    {/* ── Seção 1: Foto + Informações pessoais ── */}
                    <form onSubmit={submitProfile} encType="multipart/form-data">
                        <div className="row border-bottom mb-4 pb-3">

                            {/* Foto */}
                            <div className="col-12 mb-3">
                                <div className="row align-items-center">
                                    <div className="col-lg-2">
                                        <label className="form-label mb-0">Foto</label>
                                        <p className="text-muted small mb-0 mt-1">
                                            JPG, PNG ou WebP. Máx 2 MB.
                                        </p>
                                    </div>
                                    <div className="col-lg-10">
                                        <div className="profile-container" style={{ position: 'relative', display: 'inline-block' }}>
                                            <img
                                                src={photoPreview}
                                                alt={auth.user?.name}
                                                className="rounded-circle"
                                                style={{ width: 80, height: 80, objectFit: 'cover' }}
                                            />
                                            <div
                                                className="overlay-btn"
                                                style={{
                                                    position: 'absolute', inset: 0,
                                                    background: 'rgba(0,0,0,.4)', borderRadius: '50%',
                                                    display: 'flex', alignItems: 'center',
                                                    justifyContent: 'center', cursor: 'pointer', opacity: 0,
                                                    transition: 'opacity .2s',
                                                }}
                                                onMouseEnter={e => (e.currentTarget.style.opacity = 1)}
                                                onMouseLeave={e => (e.currentTarget.style.opacity = 0)}
                                                onClick={() => fileInputRef.current?.click()}
                                            >
                                                <i className="ti ti-photo fs-16 text-white"></i>
                                            </div>
                                        </div>
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            onChange={handlePhotoChange}
                                            style={{ display: 'none' }}
                                        />
                                        {profileForm.errors.photo && (
                                            <div className="text-danger small mt-1">{profileForm.errors.photo}</div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Nome */}
                            <div className="col-lg-6">
                                <div className="row align-items-center mb-3">
                                    <div className="col-lg-4">
                                        <label htmlFor="profile_name" className="form-label mb-0">
                                            Nome <span className="text-danger ms-1">*</span>
                                        </label>
                                    </div>
                                    <div className="col-lg-8">
                                        <input
                                            id="profile_name"
                                            type="text"
                                            className={`form-control ${profileForm.errors.name ? 'is-invalid' : ''}`}
                                            value={profileForm.data.name}
                                            onChange={e => profileForm.setData('name', e.target.value)}
                                            autoComplete="name"
                                            autoFocus
                                        />
                                        {profileForm.errors.name && (
                                            <div className="invalid-feedback">{profileForm.errors.name}</div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* E-mail */}
                            <div className="col-lg-6">
                                <div className="row align-items-center mb-3">
                                    <div className="col-lg-4">
                                        <label htmlFor="profile_email" className="form-label mb-0">
                                            E-mail <span className="text-danger ms-1">*</span>
                                        </label>
                                    </div>
                                    <div className="col-lg-8">
                                        <input
                                            id="profile_email"
                                            type="email"
                                            className={`form-control ${profileForm.errors.email ? 'is-invalid' : ''}`}
                                            value={profileForm.data.email}
                                            onChange={e => profileForm.setData('email', e.target.value)}
                                            autoComplete="username"
                                        />
                                        {profileForm.errors.email && (
                                            <div className="invalid-feedback">{profileForm.errors.email}</div>
                                        )}

                                        {mustVerifyEmail && (
                                            <div className="mt-2">
                                                <span className="badge bg-warning text-dark me-1">
                                                    <i className="ti ti-alert-circle me-1"></i>E-mail não verificado
                                                </span>
                                                <Link
                                                    href="/auth/email/verification-notification"
                                                    method="post"
                                                    as="button"
                                                    className="btn btn-link btn-sm p-0 align-baseline"
                                                >
                                                    Reenviar verificação
                                                </Link>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                        </div>

                        {/* ── Ações do perfil ── */}
                        <div className="d-flex align-items-center justify-content-end gap-2 mb-4">
                            <Link href="/panel/dashboard" className="btn btn-light">
                                Cancelar
                            </Link>
                            <button
                                type="submit"
                                className="btn btn-primary"
                                disabled={profileForm.processing}
                            >
                                {profileForm.processing
                                    ? <span className="spinner-border spinner-border-sm me-1"></span>
                                    : <i className="ti ti-device-floppy me-1"></i>}
                                Salvar
                            </button>
                        </div>
                    </form>

                    {/* ── Seção 2: Alterar Senha ── */}
                    <form onSubmit={submitPassword}>
                        <div className="row border-bottom mb-4 pb-3">
                            <div className="mb-3 col-12">
                                <h5 className="fw-bold mb-0">Alterar Senha</h5>
                            </div>

                            {/* Senha atual */}
                            <div className="col-lg-4">
                                <div className="row align-items-center mb-3">
                                    <div className="col-lg-5">
                                        <label className="form-label mb-0">Senha atual</label>
                                    </div>
                                    <div className="col-lg-7">
                                        <div className="input-group">
                                            <input
                                                type={showCurrent ? 'text' : 'password'}
                                                className={`form-control ${passwordForm.errors.current_password ? 'is-invalid' : ''}`}
                                                value={passwordForm.data.current_password}
                                                onChange={e => passwordForm.setData('current_password', e.target.value)}
                                                autoComplete="current-password"
                                            />
                                            <button
                                                type="button"
                                                className="btn btn-outline-secondary"
                                                tabIndex={-1}
                                                onClick={() => setShowCurrent(v => !v)}
                                            >
                                                <i className={showCurrent ? 'ti ti-eye-off' : 'ti ti-eye'}></i>
                                            </button>
                                            {passwordForm.errors.current_password && (
                                                <div className="invalid-feedback">{passwordForm.errors.current_password}</div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Nova senha */}
                            <div className="col-lg-4">
                                <div className="row align-items-center mb-3">
                                    <div className="col-lg-5">
                                        <label className="form-label mb-0">Nova senha</label>
                                    </div>
                                    <div className="col-lg-7">
                                        <div className="input-group">
                                            <input
                                                type={showNew ? 'text' : 'password'}
                                                className={`form-control ${passwordForm.errors.password ? 'is-invalid' : ''}`}
                                                value={passwordForm.data.password}
                                                onChange={e => passwordForm.setData('password', e.target.value)}
                                                autoComplete="new-password"
                                            />
                                            <button
                                                type="button"
                                                className="btn btn-outline-secondary"
                                                tabIndex={-1}
                                                onClick={() => setShowNew(v => !v)}
                                            >
                                                <i className={showNew ? 'ti ti-eye-off' : 'ti ti-eye'}></i>
                                            </button>
                                            {passwordForm.errors.password && (
                                                <div className="invalid-feedback">{passwordForm.errors.password}</div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Confirmar senha */}
                            <div className="col-lg-4">
                                <div className="row align-items-center mb-3">
                                    <div className="col-lg-5">
                                        <label className="form-label mb-0">Confirmar senha</label>
                                    </div>
                                    <div className="col-lg-7">
                                        <div className="input-group">
                                            <input
                                                type={showConfirm ? 'text' : 'password'}
                                                className="form-control"
                                                value={passwordForm.data.password_confirmation}
                                                onChange={e => passwordForm.setData('password_confirmation', e.target.value)}
                                                autoComplete="new-password"
                                            />
                                            <button
                                                type="button"
                                                className="btn btn-outline-secondary"
                                                tabIndex={-1}
                                                onClick={() => setShowConfirm(v => !v)}
                                            >
                                                <i className={showConfirm ? 'ti ti-eye-off' : 'ti ti-eye'}></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="col-12">
                                <div className="d-flex justify-content-end">
                                    <button
                                        type="submit"
                                        className="btn btn-outline-primary btn-sm"
                                        disabled={passwordForm.processing}
                                    >
                                        {passwordForm.processing
                                            ? <span className="spinner-border spinner-border-sm me-1"></span>
                                            : <i className="ti ti-lock me-1"></i>}
                                        Atualizar Senha
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
