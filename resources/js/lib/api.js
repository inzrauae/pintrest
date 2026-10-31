import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
});

let onUnauthorized = () => {};
export function setUnauthorizedHandler(fn) {
    onUnauthorized = fn;
}

api.interceptors.response.use(
    (r) => r,
    (error) => {
        if (error.response?.status === 401) onUnauthorized();
        return Promise.reject(error);
    },
);

/** Fetches the CSRF cookie before state-changing auth requests. */
export function csrf() {
    return axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

/** Turns any API error into a short user-facing message. */
export function errorMessage(error, fallback = 'Something went wrong. Please try again.') {
    const data = error?.response?.data;
    if (!data) return error?.message === 'Network Error' ? 'Network error — check your connection.' : fallback;
    if (data.errors) {
        const first = Object.values(data.errors).flat();
        if (first.length) return first.join(' ');
    }
    if (error.response.status === 429) return 'Too many requests. Please slow down and try again shortly.';
    return data.message || fallback;
}

/** Per-field validation errors: { field: 'message' }. */
export function fieldErrors(error) {
    const errors = error?.response?.data?.errors || {};
    return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, Array.isArray(v) ? v.join(' ') : v]));
}

export function download(path, params = {}) {
    const query = new URLSearchParams(params).toString();
    window.location.href = `/api/${path}${query ? `?${query}` : ''}`;
}

export default api;
