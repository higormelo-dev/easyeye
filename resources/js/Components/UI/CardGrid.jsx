import { useState, useEffect, useCallback } from 'react';
import Pagination from './Pagination';

export default function CardGrid({
    fetchUrl,
    renderCard,
    emptyMessage = 'Nenhum registro encontrado.',
    searchPlaceholder = 'Pesquisar...',
    headerActions = null,
}) {
    const [data, setData] = useState([]);
    const [meta, setMeta] = useState(null);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ page, search });
            const res = await fetch(`${fetchUrl}?${params}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setData(json.data || []);
            setMeta(json.meta || null);
        } catch (err) {
            console.error('CardGrid fetch error:', err);
        } finally {
            setLoading(false);
        }
    }, [fetchUrl, page, search]);

    useEffect(() => {
        const timer = setTimeout(fetchData, search ? 300 : 0);
        return () => clearTimeout(timer);
    }, [fetchData]);

    useEffect(() => {
        setPage(1);
    }, [search]);

    return (
        <>
            <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div className="input-group input-group-sm" style={{ maxWidth: 300 }}>
                    <span className="input-group-text bg-white">
                        <i className="ti ti-search fs-12"></i>
                    </span>
                    <input
                        type="text"
                        className="form-control border-start-0"
                        placeholder={searchPlaceholder}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    {search && (
                        <button className="btn btn-outline-secondary" onClick={() => setSearch('')}>
                            <i className="ti ti-x fs-12"></i>
                        </button>
                    )}
                </div>
                {headerActions}
            </div>

            {loading ? (
                <div className="text-center py-5">
                    <div className="spinner-border text-primary" role="status">
                        <span className="visually-hidden">Carregando...</span>
                    </div>
                </div>
            ) : data.length === 0 ? (
                <div className="text-center text-muted py-5">
                    <i className="ti ti-database-off fs-1 d-block mb-2"></i>
                    {emptyMessage}
                </div>
            ) : (
                <div className="row g-3">
                    {data.map((item) => renderCard(item))}
                </div>
            )}

            {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
    );
}
