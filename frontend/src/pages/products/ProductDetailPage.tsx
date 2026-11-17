import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { formatDate, PERISHABILITY, seasonText, SENTIMENTS } from '@/api/labels';
import Money from '@/components/Money';
import type { ProductDetail, ShoppingList } from '@/api/types';
import { ErrorBox, GradeBadge, Loader, Modal, PageHeader, Tag } from '@/components/ui';
import SentimentPicker from '@/components/SentimentPicker';

const NUTRIENTS: Record<string, string> = {
  'energy-kcal_100g': 'Energy (kcal)',
  fat_100g: 'Fat (g)',
  'saturated-fat_100g': 'of which saturates (g)',
  carbohydrates_100g: 'Carbohydrates (g)',
  sugars_100g: 'of which sugars (g)',
  fiber_100g: 'Fibre (g)',
  proteins_100g: 'Protein (g)',
  salt_100g: 'Salt (g)',
};

export default function ProductDetailPage() {
  const { id } = useParams();
  const { user } = useAuth();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [addOpen, setAddOpen] = useState(false);
  const [imageUrl, setImageUrl] = useState('');

  const { data: p, isLoading, error } = useQuery({
    queryKey: ['product', Number(id)],
    queryFn: () => api.get<ProductDetail>(`/products/${id}`),
  });

  const sync = useMutation({
    mutationFn: () => api.post(`/products/${id}/sync`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['product', Number(id)] }),
  });
  const remove = useMutation({
    mutationFn: () => api.delete(`/products/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['products'] });
      navigate('/products');
    },
  });
  const addImage = useMutation({
    mutationFn: (body: FormData | { url: string }) => api.post(`/products/${id}/images`, body),
    onSuccess: () => {
      setImageUrl('');
      void qc.invalidateQueries({ queryKey: ['product', Number(id)] });
    },
  });

  if (isLoading) return <Loader />;
  if (error || !p) return <ErrorBox error={error ?? new Error('Product not found')} />;

  const canEdit = user?.role === 'admin' || p.created_by === user?.id;
  const totalVotes = Object.values(p.preference_stats).reduce((a, b) => a + (b ?? 0), 0);

  return (
    <>
      <PageHeader
        title={p.name}
        subtitle={[p.brand, p.quantity, p.barcode && `barcode ${p.barcode}`].filter(Boolean).join(' · ')}
        actions={
          <>
            <button type="button" className="btn btn--primary" onClick={() => setAddOpen(true)}>
              + Add to shopping list
            </button>
            {canEdit && p.barcode && (
              <button type="button" className="btn btn--ghost" onClick={() => sync.mutate()} disabled={sync.isPending}>
                {sync.isPending ? 'Syncing…' : '↻ Sync with OFF'}
              </button>
            )}
            {canEdit && (
              <>
                <Link to={`/products/${p.id}/edit`} className="btn btn--ghost">
                  Edit
                </Link>
                <button type="button" className="btn btn--danger" onClick={() => confirm('Delete this product?') && remove.mutate()}>
                  Delete
                </button>
              </>
            )}
          </>
        }
      />
      <ErrorBox error={sync.error ?? remove.error} />

      {!p.compatibility.compatible && (
        <div className="alert alert--warn">
          <strong>⚠ Warning, not suitable for you:</strong> {p.compatibility.reasons.join('; ')}
        </div>
      )}

      <div className="detail">
        <div className="detail__media card">
          <div className="detail__image">
            {p.image_url ? <img src={p.image_url} alt={p.name} /> : <span aria-hidden>🍽️</span>}
          </div>
          {p.images && p.images.length > 0 && (
            <div className="thumbs">
              {p.images.map((img) => (
                <a key={img.id} href={img.url} target="_blank" rel="noreferrer" title={img.type}>
                  <img src={img.url} alt={img.type} />
                </a>
              ))}
            </div>
          )}
          {canEdit && (
            <div className="image-upload">
              <input placeholder="Image URL" value={imageUrl} onChange={(e) => setImageUrl(e.target.value)} />
              <button type="button" className="btn btn--ghost btn--sm" disabled={!imageUrl} onClick={() => addImage.mutate({ url: imageUrl })}>
                Add
              </button>
              <label className="btn btn--ghost btn--sm">
                Upload file
                <input type="file" accept="image/*" hidden onChange={(e) => {
                  const f = e.target.files?.[0];
                  if (!f) return;
                  const fd = new FormData();
                  fd.append('image', f);
                  fd.append('type', 'front');
                  addImage.mutate(fd);
                }} />
              </label>
            </div>
          )}
        </div>

        <div className="detail__info">
          <section className="card">
            <h2>My preference</h2>
            <SentimentPicker targetType="product" targetId={p.id} value={p.my_preference?.sentiment} />
            {totalVotes > 0 && (
              <div className="votes">
                {SENTIMENTS.map((s) => (
                  <span key={s.value} title={s.label}>
                    {s.emoji} {p.preference_stats[s.value] ?? 0}
                  </span>
                ))}
                {p.avg_rating && <span className="muted">· average rating {p.avg_rating}/5</span>}
              </div>
            )}
          </section>

          <section className="card">
            <h2>Details</h2>
            <dl className="kv">
              <dt>Category</dt>
              <dd>{p.category?.name ?? '—'}</dd>
              <dt>Price</dt>
              <dd><Money value={p.price} /></dd>
              <dt>Nutri-Score / NOVA / Eco</dt>
              <dd>
                <GradeBadge grade={p.nutriscore} /> {p.nova_group ? <Tag>NOVA {p.nova_group}</Tag> : null} <GradeBadge grade={p.ecoscore} kind="eco" />
              </dd>
              <dt>Diet</dt>
              <dd>
                {p.is_vegan ? <Tag tone="green">vegan</Tag> : p.is_vegetarian ? <Tag tone="green">vegetarian</Tag> : p.is_vegetarian === false ? <Tag tone="amber">contains animal products</Tag> : <span className="muted">unknown</span>}
              </dd>
              <dt>Perishability</dt>
              <dd>
                {PERISHABILITY.find((x) => x.value === p.perishability)?.label}
                {p.shelf_life_days ? ` · keeps for ~${p.shelf_life_days} days` : ''}
              </dd>
              <dt>Best before</dt>
              <dd>{formatDate(p.expires_at)}</dd>
              <dt>Source</dt>
              <dd>
                {p.source === 'off' ? (
                  <>
                    <a href={`https://world.openfoodfacts.org/product/${p.barcode}`} target="_blank" rel="noreferrer">Open Food Facts</a>
                    <span className="muted"> · synced {formatDate(p.off_synced_at)}</span>
                  </>
                ) : (
                  <>added manually{p.creator ? ` by ${p.creator.name}` : ''}</>
                )}
              </dd>
            </dl>
          </section>

          <section className="card">
            <h2>Allergens</h2>
            {p.allergens?.length ? (
              <div className="tags">
                {p.allergens.map((a) => (
                  <Tag key={a.id} tone={user?.allergens.some((u) => u.id === a.id) ? 'red' : a.pivot?.level === 'traces' ? 'amber' : 'neutral'}>
                    {a.name}
                    {a.pivot?.level === 'traces' ? ' (traces)' : ''}
                  </Tag>
                ))}
              </div>
            ) : (
              <p className="muted">No allergens declared.</p>
            )}
            {p.ingredients_text && (
              <>
                <h3>Ingredients</h3>
                <p className="ingredients">{p.ingredients_text}</p>
              </>
            )}
          </section>

          {p.nutriments && Object.keys(p.nutriments).length > 0 && (
            <section className="card">
              <h2>Nutrition facts / 100 g</h2>
              <table className="table table--compact">
                <tbody>
                  {Object.entries(NUTRIENTS).filter(([k]) => p.nutriments?.[k] !== undefined).map(([k, label]) => (
                    <tr key={k}>
                      <td>{label}</td>
                      <td className="num">{Number(p.nutriments![k]).toFixed(1)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          )}

          <section className="card">
            <h2>Seasonal availability</h2>
            {p.availabilities?.length ? (
              <ul className="list">
                {p.availabilities.map((a) => (
                  <li key={a.id} className="list__row">
                    <span>
                      <strong>{a.region}</strong> · {seasonText(a.month_from, a.month_to)}
                    </span>
                    {a.notes && <span className="muted">{a.notes}</span>}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="muted">No seasonality data.</p>
            )}
          </section>

          {p.restaurants && p.restaurants.length > 0 && (
            <section className="card">
              <h2>Where to find it</h2>
              <ul className="list">
                {p.restaurants.map((r) => (
                  <li key={r.id} className="list__row">
                    <span>
                      <strong>{r.name}</strong> {r.city && <span className="muted">· {r.city}</span>}
                      {r.pivot?.dish_name && <div className="small">{r.pivot.dish_name}</div>}
                    </span>
                    <Money value={r.pivot?.price} />
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      </div>

      <AddToListModal open={addOpen} onClose={() => setAddOpen(false)} productId={p.id} />
    </>
  );
}

function AddToListModal({ open, onClose, productId }: { open: boolean; onClose: () => void; productId: number }) {
  const qc = useQueryClient();
  const [listId, setListId] = useState('');
  const [qty, setQty] = useState('1');
  const lists = useQuery({
    queryKey: ['shopping-lists', 'open'],
    queryFn: () => api.get<ShoppingList[]>('/shopping-lists', { status: 'open' }),
    enabled: open,
  });
  const add = useMutation({
    mutationFn: () => api.post(`/shopping-lists/${listId}/items`, { product_id: productId, quantity: Number(qty) }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['shopping-lists'] });
      onClose();
    },
  });

  return (
    <Modal open={open} title="Add to list" onClose={onClose}>
      {lists.isLoading ? (
        <Loader />
      ) : lists.data?.length ? (
        <form className="stack" onSubmit={(e) => { e.preventDefault(); add.mutate(); }}>
          <select value={listId} onChange={(e) => setListId(e.target.value)} required>
            <option value="">Choose a list…</option>
            {lists.data.map((l) => (
              <option key={l.id} value={l.id}>
                {l.name} {l.group ? `(${l.group.name})` : ''}
              </option>
            ))}
          </select>
          <input type="number" min="0.01" step="0.01" value={qty} onChange={(e) => setQty(e.target.value)} />
          <ErrorBox error={add.error} />
          <button type="submit" className="btn btn--primary" disabled={!listId || add.isPending}>
            Add
          </button>
        </form>
      ) : (
        <p>
          You have no open lists. <Link to="/shopping-lists">Create one</Link>.
        </p>
      )}
    </Modal>
  );
}
