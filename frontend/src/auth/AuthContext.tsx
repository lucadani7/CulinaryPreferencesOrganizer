import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ApiError, api, setUnauthorizedHandler, tokenStore } from '@/api/client';
import type { User } from '@/api/types';

interface AuthState {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (data: RegisterData) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
  setUser: (u: User) => void;
}

export interface RegisterData {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  diet?: string;
  country?: string;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const qc = useQueryClient();

  const clear = useCallback(() => {
    tokenStore.set(null);
    setUser(null);
    qc.clear();
  }, [qc]);

  const refresh = useCallback(async () => {
    if (!tokenStore.get()) {
      setLoading(false);
      return;
    }
    try {
      setUser(await api.get<User>('/auth/me'));
    } catch (e) {
      // only an invalid token (401) or a deactivated account (403) ends the session;
      // a network error keeps the token so a reload works once the server is back
      if (e instanceof ApiError && (e.status === 401 || e.status === 403)) clear();
    } finally {
      setLoading(false);
    }
  }, [clear]);

  useEffect(() => {
    setUnauthorizedHandler(clear);
    void refresh();
  }, [clear, refresh]);

  /** Starts a new session: no data cached for a previous account may survive. */
  const start = useCallback(
    (res: { user: User; token: string }) => {
      qc.clear();
      tokenStore.set(res.token);
      setUser(res.user);
    },
    [qc],
  );

  const login = useCallback(
    async (email: string, password: string) => {
      start(await api.post<{ user: User; token: string }>('/auth/login', { email, password }));
    },
    [start],
  );

  const register = useCallback(
    async (data: RegisterData) => {
      start(await api.post<{ user: User; token: string }>('/auth/register', data));
    },
    [start],
  );

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout'); // revokes the token on the server
    } catch {
      /* already invalid or server unreachable: log out locally anyway */
    }
    clear();
  }, [clear]);

  const value = useMemo(
    () => ({ user, loading, login, register, logout, refresh, setUser }),
    [user, loading, login, register, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}