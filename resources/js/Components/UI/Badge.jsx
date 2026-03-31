const variants = {
    success: 'bg-success-light text-success',
    danger: 'bg-danger-light text-danger',
    warning: 'bg-warning-light text-warning',
    info: 'bg-info-light text-info',
    primary: 'bg-primary-light text-primary',
    secondary: 'bg-secondary-light text-secondary',
};

export default function Badge({ variant = 'primary', children, className = '' }) {
    return (
        <span className={`badge ${variants[variant] || variants.primary} ${className}`}>
            {children}
        </span>
    );
}

export function StatusBadge({ active, deletedAt = null }) {
    if (deletedAt) {
        return <Badge variant="danger">Inativo</Badge>;
    }
    return active !== false
        ? <Badge variant="success">Ativo</Badge>
        : <Badge variant="warning">Inativo</Badge>;
}
