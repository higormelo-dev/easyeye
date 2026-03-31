import { useEffect, useRef } from 'react';

export default function Modal({ show, onClose, title, size = '', footer, children }) {
    const backdropRef = useRef(null);

    useEffect(() => {
        if (show) {
            document.body.classList.add('modal-open');
            document.body.style.overflow = 'hidden';
        }
        return () => {
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
        };
    }, [show]);

    if (!show) return null;

    const sizeClass = size ? `modal-${size}` : '';

    return (
        <div
            className="modal fade show d-block"
            style={{ background: 'rgba(0,0,0,0.5)' }}
            ref={backdropRef}
            onClick={(e) => {
                if (e.target === backdropRef.current) onClose();
            }}
        >
            <div className={`modal-dialog modal-dialog-centered ${sizeClass}`}>
                <div className="modal-content">
                    <div className="modal-header">
                        <h5 className="modal-title">{title}</h5>
                        <button type="button" className="btn-close" onClick={onClose}></button>
                    </div>
                    <div className="modal-body">{children}</div>
                    {footer && <div className="modal-footer">{footer}</div>}
                </div>
            </div>
        </div>
    );
}
