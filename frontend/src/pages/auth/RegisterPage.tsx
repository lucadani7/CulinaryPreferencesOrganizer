import { useState, type ChangeEvent, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth, type RegisterData } from '@/auth/AuthContext';
import { ApiError } from '@/api/client';
import { DIETS } from '@/api/labels';
import { Field } from '@/components/ui';
import ThemeToggle from '@/components/ThemeToggle';

export default function RegisterPage() {
  const { register } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState<RegisterData>({
    name: '', email: '', password: '', password_confirmation: '', diet: 'omnivore', country: 'RO',
  });
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);

  const set = (k: keyof RegisterData) => (e: ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value }));

  const mismatch = form.password_confirmation !== '' && form.password !== form.password_confirmation;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (mismatch) return; // checked here too: the API would answer with the same error
    setBusy(true);
    setError(null);
    try {
      // an empty country is sent as "not set" instead of an invalid code
      await register({ ...form, country: form.country?.trim() ? form.country.trim().toUpperCase() : undefined });
      // new account: the profile page is where allergens and restrictions are set
      navigate('/profile', { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, 'The server is not responding.'));
    } finally {
      setBusy(false);
    }
  };

  const generalError = error && Object.keys(error.errors).length === 0 ? error.message : null;

  return (
    <div className="auth">
            <ThemeToggle className="auth__corner" />
      <form className="auth__card" onSubmit={submit}>
        <div className="auth__brand">
          <span aria-hidden>🥕</span> Culinary Preferences Organizer
        </div>
        <h1>Create account</h1>
        <Field label="Name" error={error?.field('name')}>
          <input value={form.name} onChange={set('name')} required autoFocus maxLength={120} autoComplete="name" />
        </Field>
        <Field label="Email" error={error?.field('email')}>
          <input type="email" value={form.email} onChange={set('email')} required maxLength={190} autoComplete="email" />
        </Field>
        <div className="grid-2">
          <Field label="Password" error={error?.field('password')} hint="At least 8 characters">
            <input
              type="password"
              value={form.password}
              onChange={set('password')}
              required
              minLength={8}
              autoComplete="new-password"
            />
          </Field>
          <Field label="Confirm password" error={mismatch ? 'The passwords do not match.' : undefined}>
            <input
              type="password"
              value={form.password_confirmation}
              onChange={set('password_confirmation')}
              required
              autoComplete="new-password"
            />
          </Field>
        </div>
        <div className="grid-2">
          <Field label="Diet" error={error?.field('diet')}>
            <select value={form.diet} onChange={set('diet')}>
              {DIETS.map((d) => (
                <option key={d.value} value={d.value}>
                  {d.label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Country" error={error?.field('country')} hint="2-letter code, e.g. RO">
            <input
              value={form.country}
              onChange={set('country')}
              maxLength={2}
              pattern="[A-Za-z]{2}"
              title="Two letters, e.g. RO"
              autoComplete="country"
            />
          </Field>
        </div>
        {generalError && <p className="text-error">{generalError}</p>}
        <button type="submit" className="btn btn--primary btn--block" disabled={busy || mismatch}>
          {busy ? 'Creating…' : 'Create account'}
        </button>
        <p className="auth__alt">
          Already have an account? <Link to="/login">Sign in</Link>
        </p>
      </form>
    </div>
  );
}