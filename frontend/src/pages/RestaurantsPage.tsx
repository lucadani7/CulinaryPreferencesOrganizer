import { useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import type { Paginated, Product, Restaurant } from '@/api/types';
import Money from '@/components/Money';
import { Empty, ErrorBox, Field, Loader, Modal, PageHeader, Pagination, Tag } from '@/components/ui';
import SentimentPicker from '@/components/SentimentPicker';

type Form = Partial<Record<keyof Restaurant, string | boolean>>;
const EMPTY: Form = { name: '', cuisine: '', address: '', city: '', country: 'RO', price_level: '', website: '', phone: '', has_vegetarian_options: false, has_vegan_options: false };

export default function RestaurantsPage() {
  const { user } = useAuth();
  const [params, setParams] = useSearchParams();
  const [term, setTerm] = useState(params.get('q') ?? '');
  const [editing, setEditing] = useState<Restaurant | 'new' | null>(null);
  const [detail, setDetail] = useState<Restaurant | null>(null);
  const qc = useQueryClient();

  const filters = {
    q: params.get('q') ?? undefined,
    city: params.get('city') ?? undefined,
    vegetarian: params.get('vegetarian') === '1',
    vegan: params.get('vegan') === '1',
    page: Number(params.get('page') ?? 1),
  };
  const data = useQuery({
    queryKey: ['restaurants', filters],
    queryFn: () => api.get<Paginated<Restaurant>>('/restaurants', filters),
    placeholderData: keepPreviousData,
  });
  const set = (k: string, v: string | null) => {
    const n = new URLSearchParams(params);
    if (v) n.set(k, v); else n.delete(k);
    if (k !== 'page') n.delete('page');
    setParams(n);
  };

  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/restaurants/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['restaurants'] }),
  });

  return (
    <>
      <PageHeader title="Restaurants" subtitle="Places where you can find your favourite dishes." actions={<button type="button" className="btn btn--primary" onClick={() => setEditing('new')}>+ Restaurant</button>} />
      <form className="card toolbar" onSubmit={(e) => { e.preventDefault(); set('q', term); }}>
        <input type="search" placeholder="Name or cuisine…" value={term} onChange={(e) => setTerm(e.target.value)} />
        <input placeholder="City" defaultValue={filters.city} onBlur={(e) => set('city', e.target.value)} />
        <label className="check"><input type="checkbox" checked={filters.vegetarian} onChange={(e) => set('vegetarian', e.target.checked ? '1' : null)} /> Vegetarian options</label>
        <label className="check"><input type="checkbox" checked={filters.vegan} onChange={(e) => set('vegan', e.target.checked ? '1' : null)} /> Vegan options</label>
        <button type="submit" className="btn btn--ghost">Search</button>
      </form>

      <ErrorBox error={data.error ?? remove.error} />
      {data.isLoading ? <Loader /> : data.data?.data.length ? (
        <>
          <div className="cards-grid">
            {data.data.data.map((r) => (
              <article key={r.id} className="card">
                <div className="list__row">
                  <h3><button type="button" className="link" onClick={() => setDetail(r)}>{r.name}</button></h3>
                  {r.price_level && <span className="muted">{'💰'.repeat(r.price_level)}</span>}
                </div>
                <p className="muted small">{[r.cuisine, r.city, r.country].filter(Boolean).join(' · ')}</p>
                <div className="tags">
                  {r.has_vegetarian_options && <Tag tone="green">vegetarian</Tag>}
                  {r.has_vegan_options && <Tag tone="green">vegan</Tag>}
                  <Tag>{r.products_count} products</Tag>
                </div>
                <SentimentPicker compact targetType="restaurant" targetId={r.id} value={r.my_sentiment} />
                {(user?.role === 'admin' || r.created_by === user?.id) && (
                  <div className="card__actions">
                    <button type="button" className="btn btn--ghost btn--sm" onClick={() => setEditing(r)}>Edit</button>
                    <button type="button" className="btn btn--ghost btn--sm" onClick={() => confirm('Delete this restaurant?') && remove.mutate(r.id)}>Delete</button>
                  </div>
                )}
              </article>
            ))}
          </div>
          <Pagination page={data.data.current_page} last={data.data.last_page} onChange={(p) => set('page', String(p))} />
        </>
      ) : <Empty title="No restaurants" />}

      {editing && <RestaurantForm restaurant={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      {detail && <RestaurantDetail restaurant={detail} canEdit={user?.role === 'admin' || detail.created_by === user?.id} onClose={() => setDetail(null)} />}
    </>
  );
}

function RestaurantForm({ restaurant, onClose }: { restaurant: Restaurant | null; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState<Form>(() => restaurant
    ? Object.fromEntries(Object.keys(EMPTY).map((k) => [k, (restaurant as unknown as Record<string, unknown>)[k] ?? ''])) as Form
    : EMPTY);
  const save = useMutation({
    mutationFn: () => {
      const body = { ...form, price_level: form.price_level ? Number(form.price_level) : null, website: form.website || null };
      return restaurant ? api.put(`/restaurants/${restaurant.id}`, body) : api.post('/restaurants', body);
    },
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['restaurants'] }); onClose(); },
  });
  const err = save.error instanceof ApiError ? save.error : null;
  const txt = (k: keyof Restaurant) => ({ value: String(form[k] ?? ''), onChange: (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value })) });
  const submit = (e: FormEvent) => { e.preventDefault(); save.mutate(); };

  return (
    <Modal open title={restaurant ? 'Edit restaurant' : 'New restaurant'} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <Field label="Name" error={err?.field('name')}><input {...txt('name')} required /></Field>
        <div className="grid-2">
          <Field label="Cuisine"><input {...txt('cuisine')} /></Field>
          <Field label="Price level (1–4)"><input type="number" min="1" max="4" {...txt('price_level')} /></Field>
        </div>
        <Field label="Address"><input {...txt('address')} /></Field>
        <div className="grid-2">
          <Field label="City"><input {...txt('city')} /></Field>
          <Field label="Country (ISO)" error={err?.field('country')}><input maxLength={2} {...txt('country')} /></Field>
        </div>
        <div className="grid-2">
          <Field label="Website" error={err?.field('website')}><input type="url" {...txt('website')} /></Field>
          <Field label="Phone"><input {...txt('phone')} /></Field>
        </div>
        <label className="check"><input type="checkbox" checked={!!form.has_vegetarian_options} onChange={(e) => setForm((f) => ({ ...f, has_vegetarian_options: e.target.checked }))} /> Vegetarian options</label>
        <label className="check"><input type="checkbox" checked={!!form.has_vegan_options} onChange={(e) => setForm((f) => ({ ...f, has_vegan_options: e.target.checked }))} /> Vegan options</label>
        <ErrorBox error={save.error} />
        <button type="submit" className="btn btn--primary" disabled={save.isPending}>Save</button>
      </form>
    </Modal>
  );
}

function RestaurantDetail({ restaurant, canEdit, onClose }: { restaurant: Restaurant; canEdit: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const [term, setTerm] = useState('');
  const [dish, setDish] = useState('');
  const [price, setPrice] = useState('');
  const r = useQuery({ queryKey: ['restaurant', restaurant.id], queryFn: () => api.get<Restaurant>(`/restaurants/${restaurant.id}`) });
  const search = useQuery({
    queryKey: ['products', 'suggest', term],
    queryFn: () => api.get<Paginated<Product>>('/products', { q: term, per_page: 5 }),
    enabled: term.length >= 2,
  });
  const attach = useMutation({
    mutationFn: (pid: number) => api.post(`/restaurants/${restaurant.id}/products`, { product_id: pid, dish_name: dish || null, price: price ? Number(price) : null }),
    onSuccess: () => { setTerm(''); setDish(''); setPrice(''); void qc.invalidateQueries({ queryKey: ['restaurant', restaurant.id] }); void qc.invalidateQueries({ queryKey: ['restaurants'] }); },
  });
  const detach = useMutation({
    mutationFn: (pid: number) => api.delete(`/restaurants/${restaurant.id}/products/${pid}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['restaurant', restaurant.id] }),
  });

  return (
    <Modal open title={restaurant.name} onClose={onClose}>
      <p className="muted">{[restaurant.address, restaurant.city].filter(Boolean).join(', ')}</p>
      {restaurant.website && <p><a href={restaurant.website} target="_blank" rel="noreferrer">{restaurant.website}</a></p>}
      {restaurant.latitude && restaurant.longitude && (
        <p><a href={`https://www.openstreetmap.org/?mlat=${restaurant.latitude}&mlon=${restaurant.longitude}#map=17/${restaurant.latitude}/${restaurant.longitude}`} target="_blank" rel="noreferrer">📍 View on map (OpenStreetMap)</a></p>
      )}
      <h3>Dishes / products</h3>
      {r.isLoading ? <Loader /> : r.data?.products?.length ? (
        <ul className="list">
          {r.data.products.map((p) => (
            <li key={p.id} className="list__row">
              <span>{p.pivot.dish_name ?? p.name} <span className="muted small">({p.name})</span></span>
              <span><Money value={p.pivot.price} /> {canEdit && <button type="button" className="icon-btn" title="Remove" onClick={() => detach.mutate(p.id)}>×</button>}</span>
            </li>
          ))}
        </ul>
      ) : <p className="muted">No linked products.</p>}
      {canEdit && <div className="stack">
        <div className="grid-2">
          <input placeholder="Dish name" value={dish} onChange={(e) => setDish(e.target.value)} />
          <input placeholder="Price" type="number" value={price} onChange={(e) => setPrice(e.target.value)} />
        </div>
        <input placeholder="Search the catalog for a product…" value={term} onChange={(e) => setTerm(e.target.value)} />
        {search.data?.data.map((p) => (
          <button type="button" key={p.id} className="btn btn--ghost btn--sm" onClick={() => attach.mutate(p.id)}>+ {p.name}</button>
        ))}
        <ErrorBox error={attach.error} />
      </div>}
    </Modal>
  );
}
