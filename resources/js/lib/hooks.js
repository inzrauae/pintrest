import { useCallback, useEffect, useRef, useState } from 'react';
import api, { errorMessage } from './api';

/** GET an endpoint and expose { data, loading, error, reload, setData }. */
export function useApi(path, params = null, { enabled = true } = {}) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(enabled);
    const [error, setError] = useState(null);
    const key = path + JSON.stringify(params || {});

    const reload = useCallback(
        (silent = false) => {
            if (!enabled || !path) return Promise.resolve();
            if (!silent) setLoading(true);
            return api
                .get(path, { params })
                .then((r) => {
                    setData(r.data);
                    setError(null);
                })
                .catch((e) => setError(errorMessage(e)))
                .finally(() => setLoading(false));
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [key, enabled],
    );

    useEffect(() => {
        reload();
    }, [reload]);

    return { data, setData, loading, error, reload };
}

/** Calls fn every `ms` while `active` is true. */
export function useInterval(fn, ms, active = true) {
    const saved = useRef(fn);
    saved.current = fn;
    useEffect(() => {
        if (!active) return undefined;
        const id = setInterval(() => saved.current(), ms);
        return () => clearInterval(id);
    }, [ms, active]);
}

export function formatDate(value, withTime = true) {
    if (!value) return '—';
    const d = new Date(value);
    return withTime
        ? d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
        : d.toLocaleDateString(undefined, { dateStyle: 'medium' });
}

export function timeAgo(value) {
    if (!value) return '';
    const s = Math.round((Date.now() - new Date(value).getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    return `${Math.floor(s / 86400)}d ago`;
}
