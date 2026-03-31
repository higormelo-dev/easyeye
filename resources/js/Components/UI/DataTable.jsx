import { useState, useMemo } from 'react';
import Pagination from './Pagination';

export default function DataTable({
    columns,
    data,
    pagination = null,
    onPageChange = null,
    searchable = true,
    emptyMessage = 'Nenhum registro encontrado.',
    actions = null,
    selectable = false,
    selectedIds = [],
    onSelectionChange = null,
    rowClassName = null,
}) {
    const [localSearch, setLocalSearch] = useState('');
    const [sortField, setSortField] = useState(null);
    const [sortDir, setSortDir] = useState('asc');

    const filtered = useMemo(() => {
        if (!localSearch || pagination) return data;
        const q = localSearch.toLowerCase();
        return data.filter((row) =>
            columns.some((col) => {
                const val = getNestedValue(row, col.field);
                return val && String(val).toLowerCase().includes(q);
            })
        );
    }, [data, localSearch, columns, pagination]);

    const sorted = useMemo(() => {
        if (!sortField || pagination) return filtered;
        return [...filtered].sort((a, b) => {
            const aVal = getNestedValue(a, sortField) ?? '';
            const bVal = getNestedValue(b, sortField) ?? '';
            const cmp = String(aVal).localeCompare(String(bVal), 'pt-BR', { numeric: true });
            return sortDir === 'asc' ? cmp : -cmp;
        });
    }, [filtered, sortField, sortDir, pagination]);

    const handleSort = (field) => {
        if (sortField === field) {
            setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        } else {
            setSortField(field);
            setSortDir('asc');
        }
    };

    const allSelected = sorted.length > 0 && sorted.every((r) => selectedIds.includes(r.id));

    return (
        <>
            {searchable && !pagination && (
                <div className="d-flex align-items-center justify-content-between flex-wrap mb-3">
                    <div className="search-set">
                        <div className="input-group input-group-sm" style={{ maxWidth: 280 }}>
                            <span className="input-group-text bg-white">
                                <i className="ti ti-search fs-12"></i>
                            </span>
                            <input
                                type="text"
                                className="form-control border-start-0"
                                placeholder="Pesquisar..."
                                value={localSearch}
                                onChange={(e) => setLocalSearch(e.target.value)}
                            />
                            {localSearch && (
                                <button className="btn btn-outline-secondary border-start-0" onClick={() => setLocalSearch('')}>
                                    <i className="ti ti-x fs-12"></i>
                                </button>
                            )}
                        </div>
                    </div>
                    <span className="text-muted small">
                        {sorted.length} registro{sorted.length !== 1 ? 's' : ''}
                    </span>
                </div>
            )}

            <div className="table-responsive">
                <table className="table table-nowrap table-hover mb-0">
                    <thead>
                        <tr>
                            {selectable && (
                                <th style={{ width: 40 }}>
                                    <input
                                        type="checkbox"
                                        className="form-check-input"
                                        checked={allSelected}
                                        onChange={() => {
                                            if (allSelected) {
                                                onSelectionChange?.([]);
                                            } else {
                                                onSelectionChange?.(sorted.map((r) => r.id));
                                            }
                                        }}
                                    />
                                </th>
                            )}
                            {columns.map((col) => (
                                <th
                                    key={col.field}
                                    className={col.sortable !== false && !pagination ? 'cursor-pointer user-select-none' : ''}
                                    onClick={() => col.sortable !== false && !pagination && handleSort(col.field)}
                                    style={col.width ? { width: col.width } : undefined}
                                >
                                    {col.label}
                                    {sortField === col.field && (
                                        <i className={`ti ti-arrow-${sortDir === 'asc' ? 'up' : 'down'} ms-1 fs-12`}></i>
                                    )}
                                </th>
                            ))}
                            {actions && <th className="text-end">Ações</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {sorted.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={columns.length + (actions ? 1 : 0) + (selectable ? 1 : 0)}
                                    className="text-center text-muted py-4"
                                >
                                    {emptyMessage}
                                </td>
                            </tr>
                        ) : (
                            sorted.map((row) => (
                                <tr key={row.id} className={rowClassName ? rowClassName(row) : ''}>
                                    {selectable && (
                                        <td>
                                            <input
                                                type="checkbox"
                                                className="form-check-input"
                                                checked={selectedIds.includes(row.id)}
                                                onChange={() => {
                                                    const next = selectedIds.includes(row.id)
                                                        ? selectedIds.filter((id) => id !== row.id)
                                                        : [...selectedIds, row.id];
                                                    onSelectionChange?.(next);
                                                }}
                                            />
                                        </td>
                                    )}
                                    {columns.map((col) => (
                                        <td key={col.field}>
                                            {col.render
                                                ? col.render(getNestedValue(row, col.field), row)
                                                : getNestedValue(row, col.field) ?? '—'}
                                        </td>
                                    ))}
                                    {actions && <td className="text-end">{actions(row)}</td>}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {pagination && (
                <Pagination meta={pagination} onPageChange={onPageChange} />
            )}
        </>
    );
}

function getNestedValue(obj, path) {
    if (!path) return undefined;
    return path.split('.').reduce((acc, key) => acc?.[key], obj);
}
