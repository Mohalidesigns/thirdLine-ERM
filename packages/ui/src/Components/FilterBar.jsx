import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';

export default function FilterBar({ filters = [], currentFilters = {}, route: routeName, searchPlaceholder = 'Search...' }) {
    const [values, setValues] = useState(currentFilters);

    useEffect(() => {
        setValues(currentFilters);
    }, [JSON.stringify(currentFilters)]);

    const handleChange = (key, value) => {
        const updated = { ...values, [key]: value || '' };
        setValues(updated);

        // Clean empty values
        const cleaned = {};
        Object.entries(updated).forEach(([k, v]) => {
            if (v !== '' && v !== null && v !== undefined) {
                cleaned[k] = v;
            }
        });

        router.get(routeName, cleaned, { preserveState: true, preserveScroll: true });
    };

    const handleReset = () => {
        const empty = {};
        setValues(empty);
        router.get(routeName, {}, { preserveState: true, preserveScroll: true });
    };

    const hasActiveFilters = Object.values(values).some(v => v !== '' && v !== null && v !== undefined);

    return (
        <div className="filter-bar">
            <div className="filter-bar-inner">
                {/* Search input */}
                {filters.some(f => f.type === 'search') && (
                    <div className="filter-group flex-1 min-w-[200px]">
                        <label className="filter-label">Search</label>
                        <div className="relative">
                            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                            <input
                                type="text"
                                placeholder={searchPlaceholder}
                                value={values.search || ''}
                                onChange={(e) => handleChange('search', e.target.value)}
                                className="filter-input pl-9"
                            />
                        </div>
                    </div>
                )}

                {/* Select filters */}
                {filters.filter(f => f.type === 'select').map((filter) => (
                    <div key={filter.name} className="filter-group min-w-[140px]">
                        <label className="filter-label">{filter.label}</label>
                        <select
                            value={values[filter.name] || ''}
                            onChange={(e) => handleChange(filter.name, e.target.value)}
                            className="filter-select"
                        >
                            <option value="">{filter.placeholder || `All ${filter.label}`}</option>
                            {filter.options.map((opt) => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                        </select>
                    </div>
                ))}

                {/* Checkbox filters */}
                {filters.filter(f => f.type === 'checkbox').map((filter) => (
                    <div key={filter.name} className="filter-group justify-end">
                        <label className="filter-label">&nbsp;</label>
                        <label className="flex items-center gap-2 text-sm text-gray-600 cursor-pointer h-[42px]">
                            <input
                                type="checkbox"
                                checked={values[filter.name] === '1' || values[filter.name] === true}
                                onChange={(e) => handleChange(filter.name, e.target.checked ? '1' : '')}
                                className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                            />
                            {filter.label}
                        </label>
                    </div>
                ))}

                {/* Reset button */}
                {hasActiveFilters && (
                    <div className="filter-group justify-end">
                        <label className="filter-label">&nbsp;</label>
                        <button onClick={handleReset} className="filter-reset">
                            Reset
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
