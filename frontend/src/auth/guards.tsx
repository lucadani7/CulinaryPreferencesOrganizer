import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext';
import { Loader } from '@/components/ui';

/** Where a visitor was going before being sent to /login (set by RequireAuth). */
export function redirectTarget(state: unknown): string {
  const from = (state as { from?: unknown } | null)?.from;
  // only internal paths: "/products?q=milk" yes, "//evil.example" no
  return typeof from === 'string' && from.startsWith('/') && !from.startsWith('//') ? from : '/';
}

/** Pages for signed-in users only. */
export function RequireAuth() {
  const { user, loading } = useAuth();
  const location = useLocation();
  if (loading) return <Loader full />;
  if (!user) {
    // keep the query string too, so filters survive the detour through /login
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  }
  return <Outlet />;
}

/** Pages for administrators only (nested inside RequireAuth, so the user is already loaded). */
export function RequireAdmin() {
  const { user } = useAuth();
  if (user?.role !== 'admin') return <Navigate to="/" replace />;
  return <Outlet />;
}

/** Login and register: a signed-in user is sent on to where they were going. */
export function GuestOnly() {
  const { user, loading } = useAuth();
  const location = useLocation();
  if (loading) return <Loader full />;
  if (user) return <Navigate to={redirectTarget(location.state)} replace />;
  return <Outlet />;
}