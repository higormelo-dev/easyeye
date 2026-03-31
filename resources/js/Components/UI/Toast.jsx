import { useEffect, useState } from 'react';

export default function Toast({ flash = {} }) {
    const [toasts, setToasts] = useState([]);

    useEffect(() => {
        if (flash?.success) {
            addToast(flash.success, 'success', 'ti-circle-check');
        }
        if (flash?.error) {
            addToast(flash.error, 'danger', 'ti-alert-triangle');
        }
        if (flash?.info) {
            addToast(flash.info, 'info', 'ti-info-circle');
        }
    }, [flash]);

    const addToast = (message, type = 'success', icon = 'ti-circle-check') => {
        const id = Math.random().toString(36).substr(2, 9);
        setToasts((prev) => [...prev, { id, message, type, icon }]);

        // Auto remove after 5 seconds
        setTimeout(() => {
            removeToast(id);
        }, 5000);
    };

    const removeToast = (id) => {
        setToasts((prev) => prev.filter((t) => t.id !== id));
    };

    if (toasts.length === 0) return null;

    return (
        <div className="toast-container position-fixed bottom-0 end-0 p-3" style={{ zIndex: 9999 }}>
            {toasts.map((t) => (
                <div key={t.id} className={`toast show align-items-center text-bg-${t.type} border-0 mb-2`} role="alert" aria-live="assertive" aria-atomic="true">
                    <div className="d-flex">
                        <div className="toast-body fw-medium">
                            <i className={`ti ${t.icon} me-2 fs-14`}></i>
                            {t.message}
                        </div>
                        <button type="button" className="btn-close btn-close-white me-2 m-auto" onClick={() => removeToast(t.id)} aria-label="Close"></button>
                    </div>
                </div>
            ))}
        </div>
    );
}
