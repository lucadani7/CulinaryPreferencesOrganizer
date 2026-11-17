import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { useAllergens } from '@/api/hooks';
import { DIETS } from '@/api/labels';
import type { User } from '@/api/types';
import { ErrorBox, Field, PageHeader } from '@/components/ui';

export default function ProfilePage() {
  const { user, setUser } = useAuth();
  const qc = useQueryClient();
  const allergens = useAllergens();
  const [form, setForm] = useState({ name: '', email: '', diet: 'omnivore', country: '' });
  const [restrictions, setRestrictions] = useState<Record<number, 'avoid' | 'strict'>>({});
  const [pwd, setPwd] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (!user) return;
    setForm({ name: user.name, email: user.email, diet: user.diet, country: user.country ?? '' });
    setRestrictions(Object.fromEntries(user.allergens.map((a) => [a.id, a.severity])));
  }, [user]);

  const save = useMutation({
    mutationFn: () => api.patch<User>('/auth/me', {
      ...form,
      country: form.country || null,
      allergens: Object.entries(restrictions).map(([id, severity]) => ({ id: Number(id), severity })),
    }),
    onSuccess: (u) => {
      setUser(u);
      // the country decides the local currency shown next to euro prices
      void qc.invalidateQueries({ queryKey: ['exchange-rates'] });
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
    },
  });
  const changePwd = useMutation({
    mutationFn: () => api.put('/auth/me/password', pwd),
    onSuccess: () => setPwd({ current_password: '', password: '', password_confirmation: '' }),
  });

  const cycle = (id: number) => setRestrictions((r) => {
    const next = { ...r };
    if (!next[id]) next[id] = 'avoid';
    else if (next[id] === 'avoid') next[id] = 'strict';
    else delete next[id];
    return next;
  });

  const err = save.error instanceof ApiError ? save.error : null;
  const pErr = changePwd.error instanceof ApiError ? changePwd.error : null;
  const submit = (e: FormEvent) => { e.preventDefault(); save.mutate(); };

  return (
    <>
      <PageHeader title="My profile" subtitle="Your diet and allergies are used for filtering, warnings and list generation." />
      <div className="grid-dashboard">
        <form className="card stack" onSubmit={submit}>
          <h2>Personal details and restrictions</h2>
          <Field label="Name" error={err?.field('name')}><input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
          <Field label="Email" error={err?.field('email')}><input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required /></Field>
          <div className="grid-2">
            <Field label="Diet">
              <select value={form.diet} onChange={(e) => setForm({ ...form, diet: e.target.value })}>
                {DIETS.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
              </select>
            </Field>
            <Field label="Country (ISO)" hint="Used for seasonality and to show prices in your local currency" error={err?.field('country')}>
              <input maxLength={2} value={form.country} onChange={(e) => setForm({ ...form, country: e.target.value.toUpperCase() })} />
            </Field>
          </div>
          <div className="field">
            <span className="field__label">Allergies / intolerances (click: avoid → strict → remove)</span>
            <div className="chips">
              {allergens.data?.map((a) => (
                <button key={a.id} type="button" onClick={() => cycle(a.id)}
                  className={`chip ${restrictions[a.id] === 'strict' ? 'is-active chip--red' : restrictions[a.id] ? 'is-active chip--amber' : ''}`}>
                  {a.name}{restrictions[a.id] === 'strict' ? ' (strict)' : ''}
                </button>
              ))}
            </div>
          </div>
          <ErrorBox error={save.error} />
          <button type="submit" className="btn btn--primary" disabled={save.isPending}>{saved ? '✓ Saved' : 'Save'}</button>
        </form>

        <form className="card stack" onSubmit={(e) => { e.preventDefault(); changePwd.mutate(); }}>
          <h2>Change password</h2>
          <Field label="Current password" error={pErr?.field('current_password')}>
            <input type="password" value={pwd.current_password} onChange={(e) => setPwd({ ...pwd, current_password: e.target.value })} required />
          </Field>
          <Field label="New password" error={pErr?.field('password')}>
            <input type="password" value={pwd.password} onChange={(e) => setPwd({ ...pwd, password: e.target.value })} required minLength={8} />
          </Field>
          <Field label="Confirm new password">
            <input type="password" value={pwd.password_confirmation} onChange={(e) => setPwd({ ...pwd, password_confirmation: e.target.value })} required />
          </Field>
          {changePwd.isSuccess && <p className="text-success">Password changed.</p>}
          <button type="submit" className="btn btn--ghost" disabled={changePwd.isPending}>Change password</button>
        </form>
      </div>
    </>
  );
}
