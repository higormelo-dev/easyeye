import { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';

export default function FlashMessages() {
    const { flash } = usePage().props;
    const [visible, setVisible] = useState({});

    useEffect(() => {
        const next = {};
        if (flash?.success) next.success = true;
        if (flash?.error) next.error = true;
        if (flash?.warning) next.warning = true;
        setVisible(next);

        if (next.success || next.warning) {
            const timer = setTimeout(() => setVisible({}), 5000);
            return () => clearTimeout(timer);
        }
    }, [flash]);

    return (
        <>
            {visible.success && flash?.success && (
                <div className="alert alert-success alert-dismissible fade show" role="alert">
                    <i className="ti ti-check me-1"></i> {flash.success}
                    <button type="button" className="btn-close" onClick={() => setVisible((v) => ({ ...v, success: false }))}></button>
                </div>
            )}
            {visible.error && flash?.error && (
                <div className="alert alert-danger alert-dismissible fade show" role="alert">
                    <i className="ti ti-alert-circle me-1"></i> {flash.error}
                    <button type="button" className="btn-close" onClick={() => setVisible((v) => ({ ...v, error: false }))}></button>
                </div>
            )}
            {visible.warning && flash?.warning && (
                <div className="alert alert-warning alert-dismissible fade show" role="alert">
                    <i className="ti ti-alert-triangle me-1"></i> {flash.warning}
                    <button type="button" className="btn-close" onClick={() => setVisible((v) => ({ ...v, warning: false }))}></button>
                </div>
            )}
        </>
    );
}
