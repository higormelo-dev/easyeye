export default function FormInput({
    label,
    name,
    type = 'text',
    value,
    onChange,
    error,
    required = false,
    placeholder = '',
    disabled = false,
    autoFocus = false,
    className = '',
    mask = null,
    ...rest
}) {
    return (
        <div className={`mb-3 ${className}`}>
            {label && (
                <label htmlFor={name} className="form-label">
                    {label} {required && <span className="text-danger">*</span>}
                </label>
            )}
            <input
                type={type}
                id={name}
                name={name}
                className={`form-control${error ? ' is-invalid' : ''}`}
                value={value ?? ''}
                onChange={(e) => onChange(name, e.target.value)}
                placeholder={placeholder}
                disabled={disabled}
                autoFocus={autoFocus}
                required={required}
                {...rest}
            />
            {error && <div className="invalid-feedback">{error}</div>}
        </div>
    );
}

export function FormSelect({
    label,
    name,
    value,
    onChange,
    error,
    options = {},
    required = false,
    disabled = false,
    placeholder = 'Selecione...',
    className = '',
}) {
    return (
        <div className={`mb-3 ${className}`}>
            {label && (
                <label htmlFor={name} className="form-label">
                    {label} {required && <span className="text-danger">*</span>}
                </label>
            )}
            <select
                id={name}
                name={name}
                className={`form-select${error ? ' is-invalid' : ''}`}
                value={value ?? ''}
                onChange={(e) => onChange(name, e.target.value)}
                disabled={disabled}
                required={required}
            >
                <option value="">{placeholder}</option>
                {Array.isArray(options)
                    ? options.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                    ))
                    : Object.entries(options).map(([k, v]) => (
                        <option key={k} value={k}>{v}</option>
                    ))
                }
            </select>
            {error && <div className="invalid-feedback">{error}</div>}
        </div>
    );
}

export function FormSwitch({ label, name, checked, onChange, disabled = false, className = '' }) {
    return (
        <div className={`form-check form-switch ${className}`}>
            <input
                className="form-check-input"
                type="checkbox"
                id={name}
                checked={checked ?? false}
                onChange={(e) => onChange(name, e.target.checked)}
                disabled={disabled}
            />
            <label className="form-check-label" htmlFor={name}>
                {label}
            </label>
        </div>
    );
}

export function FormTextarea({
    label,
    name,
    value,
    onChange,
    error,
    required = false,
    rows = 3,
    disabled = false,
    className = '',
    ...rest
}) {
    return (
        <div className={`mb-3 ${className}`}>
            {label && (
                <label htmlFor={name} className="form-label">
                    {label} {required && <span className="text-danger">*</span>}
                </label>
            )}
            <textarea
                id={name}
                name={name}
                className={`form-control${error ? ' is-invalid' : ''}`}
                value={value ?? ''}
                onChange={(e) => onChange(name, e.target.value)}
                rows={rows}
                disabled={disabled}
                required={required}
                {...rest}
            />
            {error && <div className="invalid-feedback">{error}</div>}
        </div>
    );
}
