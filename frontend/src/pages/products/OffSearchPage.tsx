import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import type { OffProduct, OffSearchResult, Product } from '@/api/types';
import { Empty, ErrorBox, GradeBadge, Loader, PageHeader, Pagination, Tag } from '@/components/ui';

const tagLabel = (t: string) => t.replace(/^[a-z]{2}:/, '').replace(/-/g, ' ');

export default function OffSearchPage() {
  const [term, setTerm] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [onlyRo, setOnlyRo] = useState(false);
  const [barcode, setBarcode] = useState('');
  const navigate = useNavigate();
  const qc = useQueryClient();

  const search = useQuery({
    queryKey: ['off-search', query, page, onlyRo],
    queryFn: () => api.get<OffSearchResult>('/off/search', { q: query, page, page_size: 24, country: onlyRo ? 'romania' : undefined }),
    enabled: query.length >= 2,
    staleTime: 5 * 60_000,
  });

  const importer = useMutation({
    mutationFn: (code: string) => api.post<Product>('/off/import', { barcode: code }),
    onSuccess: (p) => {
      void qc.invalidateQueries({ queryKey: ['products'] });
      void qc.invalidateQueries({ queryKey: ['off-search'] });
      navigate(`/products/${p.id}`);
    },
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setPage(1);
    setQuery(term.trim());
  };

  return (
    <>
      <PageHeader
        title="Open Food Facts"
        subtitle={<>Search the open database <a href="https://world.openfoodfacts.org" target="_blank" rel="noreferrer">Open Food Facts</a> and import products into your own catalog.</>}
      />

      <div className="grid-2 off-forms">
        <form className="card search-bar" onSubmit={submit}>
          <input type="search" placeholder="e.g. yogurt, dark chocolate, mustard…" value={term} onChange={(e) => setTerm(e.target.value)} />
          <label className="check"><input type="checkbox" checked={onlyRo} onChange={(e) => { setOnlyRo(e.target.checked); setPage(1); }} /> Romania only</label>
          <button type="submit" className="btn btn--primary" disabled={term.trim().length < 2}>Search</button>
        </form>
        <form className="card search-bar" onSubmit={(e) => { e.preventDefault(); importer.mutate(barcode.trim()); }}>
          <input placeholder="Barcode (EAN), e.g. 3017620422003" value={barcode} onChange={(e) => setBarcode(e.target.value.replace(/\D/g, ''))} inputMode="numeric" />
          <button type="submit" className="btn btn--primary" disabled={barcode.length < 6 || importer.isPending}>
            {importer.isPending ? 'Importing…' : 'Import by barcode'}
          </button>
        </form>
      </div>

      <ErrorBox error={search.error ?? importer.error} />

      {search.isFetching && !search.data ? (
        <Loader label="Querying Open Food Facts…" />
      ) : search.data ? (
        search.data.products.length ? (
          <>
            <p className="muted">{search.data.count.toLocaleString('en-GB')} results</p>
            <div className="off-grid">
              {search.data.products.map((p) => (
                <OffCard key={p.barcode} p={p} busy={importer.isPending && importer.variables === p.barcode} onImport={() => importer.mutate(p.barcode)} />
              ))}
            </div>
            <Pagination page={page} last={Math.ceil(search.data.count / search.data.page_size)} onChange={setPage} />
          </>
        ) : (
          <Empty title="No results in Open Food Facts" />
        )
      ) : (
        <Empty title="Search for a product">
          <p>The data (ingredients, allergens, Nutri-Score, images, countries) is fetched automatically on import.</p>
        </Empty>
      )}
    </>
  );
}

function OffCard({ p, busy, onImport }: { p: OffProduct; busy: boolean; onImport: () => void }) {
  return (
    <article className="off-card card">
      <div className="off-card__image">{p.image_url ? <img src={p.image_url} alt="" loading="lazy" /> : <span aria-hidden>🍽️</span>}</div>
      <div className="off-card__body">
        <h3>{p.name || <em className="muted">unnamed</em>}</h3>
        <p className="muted small">{[p.brand, p.quantity].filter(Boolean).join(' · ')} · {p.barcode}</p>
        <div className="tags">
          <GradeBadge grade={p.nutriscore} />
          {p.nova_group && <Tag>NOVA {p.nova_group}</Tag>}
          {p.is_vegan && <Tag tone="green">vegan</Tag>}
          {!p.is_vegan && p.is_vegetarian && <Tag tone="green">vegetarian</Tag>}
          {p.allergens_tags.slice(0, 4).map((a) => <Tag key={a} tone="red">{tagLabel(a)}</Tag>)}
        </div>
      </div>
      <div className="off-card__actions">
        {p.local_id ? (
          <Link to={`/products/${p.local_id}`} className="btn btn--ghost btn--sm">✓ In catalog</Link>
        ) : (
          <button type="button" className="btn btn--primary btn--sm" onClick={onImport} disabled={busy}>{busy ? '…' : 'Import'}</button>
        )}
      </div>
    </article>
  );
}
