import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { DIETS, MONTHS, STATUS } from '@/api/labels';
import Money from '@/components/Money';
import type { Recommendations, Report, ShoppingList } from '@/api/types';
import { Empty, ErrorBox, Loader, PageHeader, Stat, Tag } from '@/components/ui';
import ProductCard from '@/components/ProductCard';

export default function DashboardPage() {
  const { user } = useAuth();
  const overview = useQuery({ queryKey: ['stats', 'overview'], queryFn: () => api.get<Report>('/stats/overview') });
  const recs = useQuery({ queryKey: ['recommendations'], queryFn: () => api.get<Recommendations>('/preferences/recommendations') });
  const lists = useQuery({
    queryKey: ['shopping-lists', 'open'],
    queryFn: () => api.get<ShoppingList[]>('/shopping-lists', { status: 'open' }),
  });

  // the overview report is a list of { indicator, value } rows; index it by indicator
  const ov = Object.fromEntries((overview.data?.rows ?? []).map((r) => [String(r.indicator), r.value]));
  const stat = (indicator: string) => (overview.isLoading ? '…' : String(ov[indicator] ?? '—'));
  const firstName = user?.name.split(' ')[0] ?? '';

  return (
    <>
      <PageHeader
        title={`Hi, ${firstName}!`}
        subtitle={
          <>
            Diet: <strong>{DIETS.find((d) => d.value === user?.diet)?.label}</strong>
            {user?.allergens.length ? <> · Avoiding: {user.allergens.map((a) => a.name).join(', ')}</> : null}
          </>
        }
        actions={
          <>
            <Link to="/products/import" className="btn btn--ghost">
              🔎 Search Open Food Facts
            </Link>
            <Link to="/shopping-lists" className="btn btn--primary">
              + New list
            </Link>
          </>
        }
      />

      <ErrorBox error={overview.error} />
      <section className="stats-row">
        <Stat label="Products in catalog" value={stat('Products in catalog')} />
        <Stat label="My preferences" value={stat('Recorded preferences')} />
        <Stat label="Shopping lists" value={stat('Shopping lists')} />
        <Stat label="Completed lists" value={stat('Completed lists')} />
      </section>

      <div className="grid-dashboard">
        <section className="card">
          <header className="card__header">
            <h2>Recommendations for {MONTHS[(recs.data?.month ?? new Date().getMonth() + 1) - 1]}</h2>
            <Link to="/preferences">All preferences →</Link>
          </header>
          {recs.isLoading ? (
            <Loader />
          ) : recs.error ? (
            <ErrorBox error={recs.error} />
          ) : recs.data?.products.length ? (
            <>
              {recs.data.based_on_categories.length > 0 && (
                <p className="muted">
                  Based on your favourite categories: {recs.data.based_on_categories.map((c) => <Tag key={c}>{c}</Tag>)}
                </p>
              )}
              {/* rating a product here removes it from the list: recommendations only contain unrated products */}
              <div className="product-grid product-grid--small">
                {recs.data.products.slice(0, 6).map((p) => (
                  <ProductCard key={p.id} product={p} />
                ))}
              </div>
            </>
          ) : (
            <Empty title="No recommendations yet">
              <p>Rate a few products or categories.</p>
              <Link to="/products" className="btn btn--primary">
                Browse products
              </Link>
            </Empty>
          )}
        </section>

        <section className="card">
          <header className="card__header">
            <h2>Open lists</h2>
            <Link to="/shopping-lists">All →</Link>
          </header>
          {lists.isLoading ? (
            <Loader />
          ) : lists.error ? (
            <ErrorBox error={lists.error} />
          ) : lists.data?.length ? (
            <ul className="list">
              {lists.data.slice(0, 6).map((l) => (
                <li key={l.id}>
                  <Link to={`/shopping-lists/${l.id}`} className="list__row">
                    <div>
                      <strong>{l.name}</strong>
                      <div className="muted small">
                        {l.group ? `👪 ${l.group.name} · ` : ''}
                        {l.checked_count}/{l.items_count} items · {STATUS[l.status]}
                      </div>
                    </div>
                    <Money value={l.estimated_total} />
                  </Link>
                  <div
                    className="progress"
                    role="progressbar"
                    aria-label={`${l.name}: items checked`}
                    aria-valuemin={0}
                    aria-valuemax={l.items_count ?? 0}
                    aria-valuenow={l.checked_count ?? 0}
                  >
                    <span style={{ width: `${l.items_count ? ((l.checked_count ?? 0) / l.items_count) * 100 : 0}%` }} />
                  </div>
                </li>
              ))}
            </ul>
          ) : (
            <Empty title="No open lists">
              <Link to="/shopping-lists" className="btn btn--primary">
                Create a list
              </Link>
            </Empty>
          )}
        </section>
      </div>
    </>
  );
}
