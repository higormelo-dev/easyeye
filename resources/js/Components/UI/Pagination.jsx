export default function Pagination({ meta, onPageChange }) {
    if (!meta || meta.last_page <= 1) return null;

    const { current_page, last_page, total, per_page } = meta;
    const from = (current_page - 1) * per_page + 1;
    const to = Math.min(current_page * per_page, total);

    const pages = [];
    const maxVisible = 5;
    let start = Math.max(1, current_page - Math.floor(maxVisible / 2));
    let end = Math.min(last_page, start + maxVisible - 1);
    if (end - start + 1 < maxVisible) {
        start = Math.max(1, end - maxVisible + 1);
    }

    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    return (
        <div className="d-flex align-items-center justify-content-between flex-wrap mt-3 px-1">
            <span className="text-muted small">
                {from}–{to} de {total} registro{total !== 1 ? 's' : ''}
            </span>
            <nav>
                <ul className="pagination pagination-sm mb-0">
                    <li className={`page-item${current_page === 1 ? ' disabled' : ''}`}>
                        <button className="page-link" onClick={() => onPageChange(current_page - 1)}>
                            <i className="ti ti-chevron-left"></i>
                        </button>
                    </li>
                    {start > 1 && (
                        <>
                            <li className="page-item">
                                <button className="page-link" onClick={() => onPageChange(1)}>1</button>
                            </li>
                            {start > 2 && <li className="page-item disabled"><span className="page-link">...</span></li>}
                        </>
                    )}
                    {pages.map((p) => (
                        <li key={p} className={`page-item${p === current_page ? ' active' : ''}`}>
                            <button className="page-link" onClick={() => onPageChange(p)}>{p}</button>
                        </li>
                    ))}
                    {end < last_page && (
                        <>
                            {end < last_page - 1 && <li className="page-item disabled"><span className="page-link">...</span></li>}
                            <li className="page-item">
                                <button className="page-link" onClick={() => onPageChange(last_page)}>{last_page}</button>
                            </li>
                        </>
                    )}
                    <li className={`page-item${current_page === last_page ? ' disabled' : ''}`}>
                        <button className="page-link" onClick={() => onPageChange(current_page + 1)}>
                            <i className="ti ti-chevron-right"></i>
                        </button>
                    </li>
                </ul>
            </nav>
        </div>
    );
}
