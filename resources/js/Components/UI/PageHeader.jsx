export default function PageHeader({ title, subtitle, children }) {
    return (
        <div className="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-3 border-1 border-bottom">
            <div className="flex-grow-1">
                <h4 className="fw-bold mb-0">{title}</h4>
                {subtitle && <p className="text-muted mb-0 mt-1">{subtitle}</p>}
            </div>
            {children && (
                <div className="d-flex align-items-center gap-2 flex-shrink-0">
                    {children}
                </div>
            )}
        </div>
    );
}
