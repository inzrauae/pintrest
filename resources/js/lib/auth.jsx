import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import api, { csrf, setUnauthorizedHandler } from './api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(undefined); // undefined = loading, null = guest

    useEffect(() => {
        setUnauthorizedHandler(() => setUser(null));
        api.get('/auth/me')
            .then((r) => setUser(r.data.data))
            .catch(() => setUser(null));
    }, []);

    const login = useCallback(async (credentials) => {
        await csrf();
        const r = await api.post('/auth/login', credentials);
        setUser(r.data.data);
    }, []);

    const register = useCallback(async (payload) => {
        await csrf();
        const r = await api.post('/auth/register', payload);
        setUser(r.data.data);
    }, []);

    const logout = useCallback(async () => {
        await api.post('/auth/logout').catch(() => {});
        setUser(null);
    }, []);

    return <AuthContext.Provider value={{ user, setUser, login, register, logout }}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
