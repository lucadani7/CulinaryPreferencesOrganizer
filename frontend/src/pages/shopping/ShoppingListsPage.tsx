import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useMyGroups } from '@/api/hooks';
import { formatDate, STATUS } from '@/api/labels';
import Money from '@/components/Money';
import type { ListStatus, ShoppingList } from '@/api/types';
import { Empty, ErrorBox, Field, Loader, Modal, PageHeader, Tag } from '@/components/ui';

export default function ShoppingListsPage() {
  const [status, setStatus] = useState<ListStatus | ''>('');
  const [mode, setMode] = useState<'manual' | 'generate' | null>(null);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const groups = useMyGroups();

  const lists = useQuery({
    queryKey: ['shopping-lists', status],
    queryFn: () => api.get<ShoppingList[]>('/shopping-lists', { status: status || undefined }),
  });

  const [form, setForm] = useState({ name: '', group_id: '', planned_for: '', budget: '', only_seasonal: true, max_items: '20' });
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const create = useMutation({
    mutationFn: () => {
      const base = {
        name: form.name || undefined,
        group_id: form.group_id ? Number(form.group_id) : null,
        planned_for: form.planned_for || null,
        budget: form.budget ? Number(form.budget) : null,
      };
      return mode === 'generate'
        ? api.post<ShoppingList>('/shopping-lists/generate', { ...base, only_seasonal: form.only_seasonal, max_items: Number(form.max_items) })
        : api.post<ShoppingList>('/shopping-lists', base);
    },
    onSuccess: (l) => {
      void qc.invalidateQueries({ queryKey: ['shopping-lists'] });
      navigate(`/shopping-lists/${l.id}`);
    },
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    create.mutate();
  };

  return (
    <>
      <PageHeader
        title="Shopping lists"
        actions={
          <>
            <button type="button" className="btn btn--ghost" onClick={() => setMode('generate')}>✨ Generate from preferences</button>
            <button type="button" className="btn btn--primary" onClick={() => setMode('manual')}>+ New list</button>
          </>
        }
      />
      <div className="tabs">
        {(['', 'open', 'completed', 'archived'] as const).map((s) => (
          <button type="button" key={s} className={`tab ${status === s ? 'is-active' : ''}`} onClick={() => setStatus(s)}>
            {s ? STATUS[s] : 'All'}
          </button>
        ))}
      </div>

      <ErrorBox error={lists.error} />
      {lists.isLoading ? (
        <Loader />
      ) : lists.data?.length ? (
        <div className="cards-grid">
          {lists.data.map((l) => {
            const pct = l.items_count ? Math.round(((l.checked_count ?? 0) / l.items_count) * 100) : 0;
            const over = l.budget && (l.estimated_total ?? 0) > Number(l.budget);
            return (
              <Link key={l.id} to={`/shopping-lists/${l.id}`} className="card card--link">
                <div className="list__row">
                  <h3>{l.name}</h3>
                  <Tag tone={l.status === 'completed' ? 'green' : l.status === 'archived' ? 'neutral' : 'blue'}>{STATUS[l.status]}</Tag>
                </div>
                <p className="muted small">
                  {l.group ? `👪 ${l.group.name}` : '👤 personal'} · by {l.user?.name}
                  {l.planned_for && ` · for ${formatDate(l.planned_for)}`}
                </p>
                <div className="progress"><span style={{ width: `${pct}%` }} /></div>
                <div className="list__row small">
                  <span>{l.checked_count}/{l.items_count} items</span>
                  <span className={over ? 'text-error' : ''}>
                    <Money value={l.estimated_total} />
                    {l.budget ? <> / <Money value={l.budget} /></> : null}
                  </span>
                </div>
              </Link>
            );
          })}
        </div>
      ) : (
        <Empty title="No lists">
          <p>Create a list manually or have it generated from your preferences.</p>
        </Empty>
      )}

      <Modal open={mode !== null} title={mode === 'generate' ? 'Generate list from preferences' : 'New list'} onClose={() => setMode(null)}>
        <form className="stack" onSubmit={submit}>
          {mode === 'generate' && (
            <p className="muted small">
              Includes liked products (yours or the group members'), excludes allergens and products incompatible with any member's diet.
            </p>
          )}
          <Field label={mode === 'generate' ? 'Name (optional)' : 'Name'}>
            <input value={form.name} onChange={set('name')} required={mode === 'manual'} autoFocus />
          </Field>
          <Field label="For group">
            <select value={form.group_id} onChange={set('group_id')}>
              <option value="">— personal —</option>
              {groups.data?.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
            </select>
          </Field>
          <div className="grid-2">
            <Field label="Planned date"><input type="date" value={form.planned_for} onChange={set('planned_for')} /></Field>
            <Field label="Budget (EUR)"><input type="number" min="0" step="0.01" value={form.budget} onChange={set('budget')} /></Field>
          </div>
          {mode === 'generate' && (
            <div className="grid-2">
              <Field label="Maximum number of products"><input type="number" min="1" max="100" value={form.max_items} onChange={set('max_items')} /></Field>
              <label className="check">
                <input type="checkbox" checked={form.only_seasonal} onChange={(e) => setForm((f) => ({ ...f, only_seasonal: e.target.checked }))} />
                Seasonal products only
              </label>
            </div>
          )}
          <ErrorBox error={create.error} />
          <button type="submit" className="btn btn--primary" disabled={create.isPending}>{create.isPending ? 'Creating…' : mode === 'generate' ? 'Generate' : 'Create'}</button>
        </form>
      </Modal>
    </>
  );
}
