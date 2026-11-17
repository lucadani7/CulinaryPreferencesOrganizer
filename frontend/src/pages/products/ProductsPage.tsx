import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useAllergens, useCategories } from '@/api/hooks';
import { MONTHS, PERISHABILITY } from '@/api/labels';
import type { Paginated, Product } from '@/api/types';
import { Empty, ErrorBox, Loader, PageHeader, Pagination } from '@/components/ui';
import ProductCard from '@/components/ProductCard';

export default function ProductsPage() {
  const [params, setParams] = useSearchParams();
  const q = params.get('q') ?? '';
  const [term, setTerm] = useState(q);
  // keep the search box in sync when the URL changes (Back/Forward, Reset filters)
  useEffect(() => setTerm(q), [q]);
  const categories = useCategories();
  const allergens = useAllergens();

  const filters = {
    q: params.get('q') ?? undefined,
    category_id: params.get('category_id') ?? undefined,
    perishability: params.get('perishability') ?? undefined,
    nutriscore: params.get('nutriscore') ?? undefined,
    month: params.get('month') ?? undefined,
    region: params.get('region') ?? undefined,
    source: params.get('source') ?? undefined,
    vegan: params.get('vegan') === '1',
    vegetarian: params.get('vegetarian') === '1',
    safe_for_me: params.get('safe_for_me') === '1',
    exclude_allergens: params.getAll('exclude'),
    sort: params.get('sort') ?? 'name',
    page: Math.max(1, Number(params.get('page')) || 1),
  };

  const { data, isLoading, error, isFetching } = useQuery({
    queryKey: ['products', filters],
    queryFn: () => api.get<Paginated<Product>>('/products', { ...filters, per_page: 24 }),
    placeholderData: keepPreviousData,
  });

  const update = (k: string, v: string | null) => {
    const next = new URLSearchParams(params);
    if (v === null || v === '') next.delete(k);
    else next.set(k, v);
    if (k === 'month' && !v) next.delete('region'); // the region only narrows the season filter
    if (k !== 'page') next.delete('page'); // a new filter starts again from page 1
    setParams(next);
  };
  const toggleAllergen = (id: string) => {
    const next = new URLSearchParams(params);
    const cur = next.getAll('exclude');
    next.delete('exclude');
    (cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]).forEach((x) => next.append('exclude', x));
    next.delete('page');
    setParams(next);
  };

  return (
    <>
      <PageHeader
        title="Products"
        subtitle={data ? `${data.total} products${isFetching ? ' · updating…' : ''}` : undefined}
        actions={
          <>
            <Link to="/products/import" className="btn btn--ghost">
              🔎 Import Open Food Facts
            </Link>
            <Link to="/products/new" className="btn btn--primary">
              + New product
            </Link>
          </>
        }
      />

      <div className="layout-filters">
        <aside className="filters card">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              update('q', term.trim());
            }}
          >
            <div className="search-row">
              <input
                type="search"
                placeholder="Search name, brand, barcode…"
                aria-label="Search products"
                value={term}
                maxLength={120}
                onChange={(e) => setTerm(e.target.value)}
              />
              <button type="submit" className="btn btn--ghost" aria-label="Search">
                🔎
              </button>
            </div>
          </form>

          <label className="check">
            <input type="checkbox" checked={filters.safe_for_me} onChange={(e) => update('safe_for_me', e.target.checked ? '1' : null)} />
            Only safe for me
          </label>
          <label className="check">
            <input type="checkbox" checked={filters.vegetarian} onChange={(e) => update('vegetarian', e.target.checked ? '1' : null)} />
            Vegetarian
          </label>
          <label className="check">
            <input type="checkbox" checked={filters.vegan} onChange={(e) => update('vegan', e.target.checked ? '1' : null)} />
            Vegan
          </label>

          <h4>Category</h4>
          <select value={filters.category_id ?? ''} onChange={(e) => update('category_id', e.target.value)}>
            <option value="">All</option>
            {categories.data?.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} ({c.products_count})
              </option>
            ))}
          </select>

          <h4>Available in month</h4>
          <select value={filters.month ?? ''} onChange={(e) => update('month', e.target.value)}>
            <option value="">Any time</option>
            {MONTHS.map((m, i) => (
              <option key={m} value={i + 1}>
                {m}
              </option>
            ))}
          </select>

          {filters.month && (
            <input
              placeholder="Region, e.g. RO (optional)"
              aria-label="Region"
              defaultValue={filters.region ?? ''}
              maxLength={64}
              onBlur={(e) => update('region', e.target.value.trim())}
              onKeyDown={(e) => e.key === 'Enter' && update('region', e.currentTarget.value.trim())}
            />
          )}

          <h4>Perishability</h4>
          <select value={filters.perishability ?? ''} onChange={(e) => update('perishability', e.target.value)}>
            <option value="">Any</option>
            {PERISHABILITY.map((p) => (
              <option key={p.value} value={p.value}>
                {p.label}
              </option>
            ))}
          </select>

          <h4>Nutri-Score</h4>
          <div className="chips">
            {['ab', 'abc', 'de'].map((g) => (
              <button key={g} type="button" className={`chip ${filters.nutriscore === g ? 'is-active' : ''}`}
                onClick={() => update('nutriscore', filters.nutriscore === g ? null : g)}>
                {g.toUpperCase().split('').join('/')}
              </button>
            ))}
          </div>

          <h4>Without allergens</h4>
          <div className="chips">
            {allergens.data?.map((a) => (
              <button key={a.id} type="button"
                className={`chip ${filters.exclude_allergens.includes(String(a.id)) ? 'is-active chip--red' : ''}`}
                onClick={() => toggleAllergen(String(a.id))}>
                {a.name}
              </button>
            ))}
          </div>

          <h4>Source</h4>
          <select value={filters.source ?? ''} onChange={(e) => update('source', e.target.value)}>
            <option value="">All</option>
            <option value="off">Open Food Facts</option>
            <option value="manual">Added manually</option>
          </select>

          <h4>Sort by</h4>
          <select value={filters.sort} onChange={(e) => update('sort', e.target.value)}>
            <option value="name">Name A–Z</option>
            <option value="-name">Name Z–A</option>
            <option value="price">Price ascending</option>
            <option value="-price">Price descending</option>
            <option value="nutriscore">Nutri-Score</option>
            <option value="-created_at">Newest</option>
          </select>

          <button type="button" className="btn btn--ghost btn--block" onClick={() => setParams(new URLSearchParams())}>
            Reset filters
          </button>
        </aside>

        <section>
          <ErrorBox error={error} />
          {isLoading ? (
            <Loader />
          ) : error && !data ? null : data?.data.length ? (
            <>
              <div className="product-grid">
                {data.data.map((p) => (
                  <ProductCard key={p.id} product={p} />
                ))}
              </div>
              <Pagination
                page={data.current_page}
                last={data.last_page}
                onChange={(p) => {
                  update('page', String(p));
                  window.scrollTo({ top: 0, behavior: 'smooth' });
                }}
              />
            </>
          ) : (
            <Empty title="No products found">
              <p>Try other filters or import products from Open Food Facts.</p>
              <Link to="/products/import" className="btn btn--primary">
                Import from Open Food Facts
              </Link>
            </Empty>
          )}
        </section>
      </div>
    </>
  );
}
