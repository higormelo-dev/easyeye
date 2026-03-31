import Modal from './Modal';

export default function ConfirmDialog({ 
    show, 
    onClose, 
    onConfirm, 
    title = 'Confirmação', 
    message = 'Tem certeza que deseja realizar esta operação?',
    confirmText = 'Confirmar',
    cancelText = 'Cancelar',
    variant = 'danger'
}) {
    return (
        <Modal
            show={show}
            onClose={onClose}
            title={title}
            size="sm"
            footer={
                <>
                    <button className="btn btn-light" onClick={onClose}>{cancelText}</button>
                    <button className={`btn btn-${variant}`} onClick={() => {
                        onConfirm();
                        onClose();
                    }}>
                        {confirmText}
                    </button>
                </>
            }
        >
            <div className="text-center py-3">
                <i className={`ti ti-alert-triangle text-${variant} mb-3`} style={{ fontSize: '3rem' }}></i>
                <p className="mb-0 fs-15">{message}</p>
            </div>
        </Modal>
    );
}
