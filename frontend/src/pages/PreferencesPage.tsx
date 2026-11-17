import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useCategories } from '@/api/hooks';
import { formatDate, SENTIMENTS, sentimentLabel } from '@/api/labels';
import type { Preference, Sentiment, TargetType } from '@/api/types';
import { Empty, ErrorBox, Loader, PageHeader } from '@/components/ui';
import SentimentPicker from '@/components/SentimentPicker';

const TABS: { value: TargetType; label: string }[] = [
  { value: 'product', label: 'Products' },
  { value: 'category', label: 'Categories' },
  { value: 'restaurant', label: 'Restaurants' },
];

const linkFor = (p: Preference) =>
  p.preferable_type === 'product' ? `/products/${p.preferable_id}` : p.preferable_type === 'restaurant' ? `/restaurants?q=${encodeURIComponent(p.preferable?.name ?? '')}` : `/products?category_id=${p.preferable_id}`;

export default function PreferencesPage() {
  const [tab, setTab] = useState<TargetType>('product');
  const [sentiment, setSentiment] = useState<Sentiment | ''>('');
  const qc = useQueryClient();
  const categories = useCategories();

  const prefs = useQuery({
    queryKey: ['preferences', tab, sentiment],
    queryFn: () => api.get<Preference[]>('/preferences', { type: tab, sentiment: sentiment || undefined }),
  });
  const catPrefs = useQuery({
    queryKey: ['preferences', 'category', ''],
    queryFn: () => api.get<Preference[]>('/preferences', { type: 'category' }),
  });

  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/preferences/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['preferences'] }),
  });
  const updateNotes = useMutation({
    mutationFn: ({ id, notes, rating }: { id: number; notes?: string; rating?: number }) => api.patch(`/preferences/${id}`, { notes, rating }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['preferences'] }),
  });

  const catSentiment = (id: number) => catPrefs.data?.find((p) => p.preferable_id === id)?.sentiment ?? null;

  return (
    <>
      <PageHeader title="My preferences" subtitle="What you like and what you avoid — used for recommendations and for generating shopping lists." />

      <div className="tabs" role="tablist">
        {TABS.map((t) => (
          <button type="button" key={t.value} role="tab" aria-selected={tab === t.value} className={`tab ${tab === t.value ? 'is-active' : ''}`} onClick={() => setTab(t.value)}>
            {t.label}
          </button>
        ))}
        <span className="tabs__spacer" />
        <select value={sentiment} onChange={(e) => setSentiment(e.target.value as Sentiment | '')}>
          <option value="">All sentiments</option>
          {SENTIMENTS.map((s) => <option key={s.value} value={s.value}>{s.emoji} {s.label}</option>)}
        </select>
      </div>

      {tab === 'category' && (
        <section className="card">
          <h2>Set category preferences</h2>
          <div className="category-prefs">
            {categories.data?.map((c) => (
              <div key={c.id} className="category-prefs__row">
                <span>{c.name}</span>
                <SentimentPicker compact targetType="category" targetId={c.id} value={catSentiment(c.id)} />
              </div>
            ))}
          </div>
        </section>
      )}

      <ErrorBox error={prefs.error ?? remove.error} />
      {prefs.isLoading ? (
        <Loader />
      ) : prefs.data?.length ? (
        <div className="card">
          <table className="table">
            <thead>
              <tr><th>Target</th><th>Preference</th><th>Rating</th><th>Notes</th><th>Added</th><th /></tr>
            </thead>
            <tbody>
              {prefs.data.map((p) => (
                <tr key={p.id}>
                  <td>
                    <Link to={linkFor(p)}><strong>{p.preferable?.name ?? '(deleted)'}</strong></Link>
                    {p.preferable?.brand && <div className="muted small">{p.preferable.brand}</div>}
                  </td>
                  <td>
                    <SentimentPicker compact targetType={p.preferable_type} targetId={p.preferable_id} value={p.sentiment} />
                    <div className="small muted">{sentimentLabel(p.sentiment)?.label}</div>
                  </td>
                  <td>
                    <select value={p.rating ?? ''} onChange={(e) => updateNotes.mutate({ id: p.id, rating: Number(e.target.value), notes: p.notes ?? undefined })}>
                      <option value="">—</option>
                      {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{'★'.repeat(n)}</option>)}
                    </select>
                  </td>
                  <td>
                    <input defaultValue={p.notes ?? ''} placeholder="e.g. sugar-free only"
                      onBlur={(e) => e.target.value !== (p.notes ?? '') && updateNotes.mutate({ id: p.id, notes: e.target.value, rating: p.rating ?? undefined })} />
                  </td>
                  <td className="muted small">{formatDate(p.created_at)}</td>
                  <td>
                    <button type="button" className="icon-btn" title="Delete" onClick={() => remove.mutate(p.id)}>🗑</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty title="No preferences here">
          <p>Use the 😍 🙂 😐 🙁 🚫 buttons on products, categories or restaurants.</p>
        </Empty>
      )}
    </>
  );
}
