import { useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { formatDate, STATUS } from '@/api/labels';
import Money from '@/components/Money';
import type { ListStatus, Paginated, Product, ShoppingList, ShoppingListItem } from '@/api/types';
import { ErrorBox, Loader, PageHeader, Tag } from '@/components/ui';
import ExportButtons from '@/components/ExportButtons';

export default function ShoppingListDetailPage() {
  const { id } = useParams();
  const lid = Number(id);
  const qc = useQueryClient();
  const navigate = useNavigate();
  const key = ['shopping-list', lid];

  const list = useQuery({ queryKey: key, queryFn: () => api.get<ShoppingList>(`/shopping-lists/${lid}`) });
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: key });
    void qc.invalidateQueries({ queryKey: ['shopping-lists'] });
  };

  const toggle = useMutation({
    mutationFn: (item: ShoppingListItem) => api.patch(`/shopping-lists/${lid}/items/${item.id}/toggle`),
    // actualizare optimista: bifarea apare instant
    onMutate: async (item) => {
      await qc.cancelQueries({ queryKey: key });
      const prev = qc.getQueryData<ShoppingList>(key);
      qc.setQueryData<ShoppingList>(key, (l) => l && { ...l, items: l.items?.map((i) => (i.id === item.id ? { ...i, is_checked: !i.is_checked } : i)) });
      return { prev };
    },
    onError: (_e, _i, ctx) => ctx?.prev && qc.setQueryData(key, ctx.prev),
    onSettled: refresh,
  });
  const updateItem = useMutation({
    mutationFn: ({ item, data }: { item: ShoppingListItem; data: Partial<Record<'quantity' | 'unit' | 'unit_price' | 'notes', string | number | null>> }) =>
      api.patch(`/shopping-lists/${lid}/items/${item.id}`, data),
    onSuccess: refresh,
  });
  const removeItem = useMutation({ mutationFn: (itemId: number) => api.delete(`/shopping-lists/${lid}/items/${itemId}`), onSuccess: refresh });
  const updateList = useMutation({ mutationFn: (data: Partial<ShoppingList>) => api.patch(`/shopping-lists/${lid}`, data), onSuccess: refresh });
  const duplicate = useMutation({ mutationFn: () => api.post<ShoppingList>(`/shopping-lists/${lid}/duplicate`), onSuccess: (l) => navigate(`/shopping-lists/${l.id}`) });
  const removeList = useMutation({
    mutationFn: () => api.delete(`/shopping-lists/${lid}`),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['shopping-lists'] }); navigate('/shopping-lists'); },
  });

  const grouped = useMemo(() => {
    const map = new Map<string, ShoppingListItem[]>();
    for (const i of list.data?.items ?? []) {
      const k = i.product?.category?.name ?? 'Other';
      map.set(k, [...(map.get(k) ?? []), i]);
    }
    return [...map.entries()].sort(([a], [b]) => a.localeCompare(b, 'ro'));
  }, [list.data]);

  if (list.isLoading) return <Loader />;
  if (!list.data) return <ErrorBox error={list.error} />;
  const l = list.data;
  const items = l.items ?? [];
  const checked = items.filter((i) => i.is_checked).length;
  const archived = l.status === 'archived';
  const warningsFor = (itemId: number) => l.warnings?.filter((w) => w.item_id === itemId) ?? [];

  return (
    <>
      <PageHeader
        title={l.name}
        subtitle={
          <>
            <Tag tone={l.status === 'completed' ? 'green' : 'blue'}>{STATUS[l.status]}</Tag>{' '}
            {l.group ? `👪 ${l.group.name}` : '👤 personal'}
            {l.planned_for && ` · for ${formatDate(l.planned_for)}`} · {checked}/{items.length} checked
          </>
        }
        actions={
          <>
            <ExportButtons path={`/shopping-lists/${lid}/export`} name={`shopping_list_${lid}`} />
            <select value={l.status} onChange={(e) => updateList.mutate({ status: e.target.value as ListStatus })}>
              {Object.entries(STATUS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
            <button type="button" className="btn btn--ghost" onClick={() => duplicate.mutate()}>Duplicate</button>
            <button type="button" className="btn btn--danger" onClick={() => confirm('Delete this list?') && removeList.mutate()}>Delete</button>
          </>
        }
      />
      <ErrorBox error={toggle.error ?? updateItem.error ?? removeItem.error ?? updateList.error} />

      <div className="budget-bar card">
        <div>
          <span className="muted small">Estimated total</span>
          <strong className="big"><Money value={l.estimated_total} /></strong>
        </div>
        {l.budget && (
          <div>
            <span className="muted small">Budget</span>
            <strong className={`big ${(l.estimated_total ?? 0) > Number(l.budget) ? 'text-error' : ''}`}><Money value={l.budget} /></strong>
          </div>
        )}
        <div className="budget-bar__progress">
          <div className="progress progress--lg"><span style={{ width: `${items.length ? (checked / items.length) * 100 : 0}%` }} /></div>
        </div>
      </div>

      {!archived && <AddItemForm listId={lid} onAdded={refresh} />}

      {items.length === 0 ? (
        <p className="muted">The list is empty.</p>
      ) : (
        grouped.map(([cat, rows]) => (
          <section key={cat} className="card shopping-section">
            <h3>{cat}</h3>
            <ul className="shopping-items">
              {rows.map((i) => {
                const warns = warningsFor(i.id);
                return (
                  <li key={i.id} className={`shopping-item ${i.is_checked ? 'is-checked' : ''}`}>
                    <input type="checkbox" checked={i.is_checked} disabled={archived} onChange={() => toggle.mutate(i)} aria-label={`Check ${i.custom_name ?? i.product?.name}`} />
                    <div className="shopping-item__name">
                      {i.product ? <Link to={`/products/${i.product.id}`}>{i.product.name}</Link> : <span>{i.custom_name}</span>}
                      {i.product?.brand && <span className="muted small"> · {i.product.brand}</span>}
                      {warns.length > 0 && (
                        <div className="small text-error">⚠ {warns.map((w) => `${w.user}: ${w.reasons.join(', ')}`).join(' | ')}</div>
                      )}
                      {i.notes && <div className="small muted">{i.notes}</div>}
                    </div>
                    <input className="qty" type="number" min="0.01" step="0.01" defaultValue={Number(i.quantity)} disabled={archived}
                      onBlur={(e) => Number(e.target.value) !== Number(i.quantity) && updateItem.mutate({ item: i, data: { quantity: Number(e.target.value) } })} />
                    <input className="unit" defaultValue={i.unit} disabled={archived}
                      onBlur={(e) => e.target.value !== i.unit && updateItem.mutate({ item: i, data: { unit: e.target.value } })} />
                    <span className="price"><Money value={Number(i.quantity) * Number(i.unit_price ?? i.product?.price ?? 0)} /></span>
                    {!archived && <button type="button" className="icon-btn" title="Remove" onClick={() => removeItem.mutate(i.id)}>×</button>}
                  </li>
                );
              })}
            </ul>
          </section>
        ))
      )}
    </>
  );
}

function AddItemForm({ listId, onAdded }: { listId: number; onAdded: () => void }) {
  const [term, setTerm] = useState('');
  const [qty, setQty] = useState('1');
  const [unit, setUnit] = useState('pcs');

  const suggestions = useQuery({
    queryKey: ['products', 'suggest', term],
    queryFn: () => api.get<Paginated<Product>>('/products', { q: term, per_page: 6 }),
    enabled: term.trim().length >= 2,
  });

  const add = useMutation({
    mutationFn: (body: { product_id?: number; custom_name?: string }) =>
      api.post(`/shopping-lists/${listId}/items`, { ...body, quantity: Number(qty), unit }),
    onSuccess: () => { setTerm(''); setQty('1'); onAdded(); },
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const exact = suggestions.data?.data.find((p) => p.name.toLowerCase() === term.trim().toLowerCase());
    add.mutate(exact ? { product_id: exact.id } : { custom_name: term.trim() });
  };

  return (
    <form className="card add-item" onSubmit={submit}>
      <div className="add-item__search">
        <input placeholder="Add a product (from the catalog or free text)…" value={term} onChange={(e) => setTerm(e.target.value)} />
        {suggestions.data && suggestions.data.data.length > 0 && term.length >= 2 && (
          <ul className="suggestions">
            {suggestions.data.data.map((p) => (
              <li key={p.id}>
                <button type="button" onClick={() => add.mutate({ product_id: p.id })}>
                  {p.name} <span className="muted small">{p.brand} · <Money value={p.price} /></span>
                  {p.has_my_allergens && <span className="text-error small"> ⚠</span>}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
      <input className="qty" type="number" min="0.01" step="0.01" value={qty} onChange={(e) => setQty(e.target.value)} />
      <input className="unit" value={unit} onChange={(e) => setUnit(e.target.value)} />
      <button type="submit" className="btn btn--primary" disabled={!term.trim() || add.isPending}>Add</button>
      <ErrorBox error={add.error} />
    </form>
  );
}
