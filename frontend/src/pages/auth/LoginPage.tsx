import { useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';
import { redirectTarget } from '@/auth/guards';
import { ApiError } from '@/api/client';
import { Field } from '@/components/ui';
import ThemeToggle from '@/components/ThemeToggle';

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await login(email, password);
      navigate(redirectTarget(location.state), { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, 'The server is not responding.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="auth">
            <ThemeToggle className="auth__corner" />
      <form className="auth__card" onSubmit={submit}>
        <div className="auth__brand">
          <span aria-hidden>🥕</span> Culinary Preferences Organizer
        </div>
        <h1>Sign in</h1>
        <p className="muted">Manage your culinary preferences and shopping lists.</p>

        <Field label="Email" error={error?.field('email')}>
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus autoComplete="email" />
        </Field>
        <Field label="Password" error={error?.field('password')}>
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoComplete="current-password" />
        </Field>
        {error && !error.field('email') && !error.field('password') && <p className="text-error">{error.message}</p>}

        <button type="submit" className="btn btn--primary btn--block" disabled={busy}>
          {busy ? 'Checking…' : 'Sign in'}
        </button>
        <p className="auth__alt">
          No account yet? <Link to="/register">Sign up</Link>
        </p>
        {/* demo accounts from the seeder: shown only in development (pnpm dev), never in a production build */}
        {import.meta.env.DEV && (
          <p className="auth__demo muted">
            Demo: <code>ana@cpo.test</code> / <code>password</code> · admin: <code>admin@cpo.test</code>
          </p>
        )}
      </form>
    </div>
  );
}