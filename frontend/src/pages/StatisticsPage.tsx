import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { api } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { useMyGroups } from '@/api/hooks';
import type { Report } from '@/api/types';
import { ErrorBox, Loader, PageHeader } from '@/components/ui';
import ExportButtons from '@/components/ExportButtons';

const PALETTE = ['#16a34a', '#0ea5e9', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#ec4899', '#64748b'];
const SENTIMENT_COLORS: Record<string, string> = { Love: '#15803d', Like: '#4ade80', Neutral: '#94a3b8', Dislike: '#fb923c', Avoid: '#dc2626' };

export default function StatisticsPage() {
  const { user } = useAuth();
  const groups = useMyGroups();
  const [scope, setScope] = useState<'me' | 'group' | 'global'>('me');
  const [groupId, setGroupId] = useState<number | undefined>();
  const [busyAll, setBusyAll] = useState(false);
  const [allError, setAllError] = useState<unknown>(null);

  // the group tab needs a group: pick the first one as soon as the list is loaded
  useEffect(() => {
    if (scope === 'group' && groupId === undefined && groups.data?.length) setGroupId(groups.data[0].id);
  }, [scope, groupId, groups.data]);

  const downloadAll = async () => {
    setBusyAll(true);
    setAllError(null);
    try {
      await api.download('/stats/export/all', query, 'statistics.pdf');
    } catch (e) {
      setAllError(e);
    } finally {
      setBusyAll(false);
    }
  };

  const reports = useQuery({ queryKey: ['stats-list'], queryFn: () => api.get<{ key: string; title: string }[]>('/stats') });
  const query = { scope, group_id: scope === 'group' ? groupId : undefined };
  const ready = scope !== 'group' || !!groupId;

  return (
    <>
      <PageHeader
        title="Statistics"
        subtitle="All reports can be exported to open formats (CSV, PDF)."
        actions={
          <button type="button" className="btn btn--primary" disabled={!ready || busyAll} onClick={() => void downloadAll()}>
            {busyAll ? 'Generating…' : '⬇ Full PDF report'}
          </button>
        }
      />
      <div className="tabs">
        <button type="button" className={`tab ${scope === 'me' ? 'is-active' : ''}`} onClick={() => setScope('me')}>Mine</button>
        <button type="button" className={`tab ${scope === 'group' ? 'is-active' : ''}`} onClick={() => setScope('group')}>Group</button>
        {/* the API answers 403 to non-admins for the global scope, so the tab is only shown to admins */}
        {user?.role === 'admin' && <button type="button" className={`tab ${scope === 'global' ? 'is-active' : ''}`} onClick={() => setScope('global')}>Global</button>}
        {scope === 'group' && (
          <select value={groupId ?? ''} onChange={(e) => setGroupId(Number(e.target.value))}>
            {groups.data?.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
          </select>
        )}
      </div>

      <ErrorBox error={allError} />
      <ErrorBox error={reports.error} />
      {!ready ? <p className="muted">{groups.isLoading ? 'Loading groups…' : 'You are not in any group.'}</p> : reports.isLoading ? <Loader /> : (
        <div className="stats-grid">
          {reports.data?.map((r) => <ReportCard key={`${r.key}-${scope}-${groupId}`} reportKey={r.key} query={query} />)}
        </div>
      )}
    </>
  );
}

function ReportCard({ reportKey, query }: { reportKey: string; query: { scope: string; group_id?: number } }) {
  const [asTable, setAsTable] = useState(false);
  const { data, isLoading, error } = useQuery({
    queryKey: ['stats', reportKey, query],
    queryFn: () => api.get<Report>(`/stats/${reportKey}`, query),
  });

  return (
    <section className={`card report ${data?.chart === 'none' ? 'report--wide' : ''}`}>
      <header className="card__header">
        <div>
          <h2>{data?.title ?? '…'}</h2>
          {data && <p className="muted small">{data.description}</p>}
        </div>
        <div className="report__actions">
          {data && data.chart !== 'none' && (
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => setAsTable((t) => !t)}>{asTable ? '📊 Chart' : '▦ Table'}</button>
          )}
          <ExportButtons path={`/stats/${reportKey}/export`} query={query} name={reportKey} />
        </div>
      </header>
      <ErrorBox error={error} />
      {isLoading ? <Loader /> : data && (data.rows.length === 0 ? <p className="muted">No data available.</p> : asTable || data.chart === 'none' ? <ReportTable report={data} /> : <ReportChart report={data} />)}
    </section>
  );
}

function ReportTable({ report }: { report: Report }) {
  return (
    <div className="table-wrap">
      <table className="table table--compact">
        <thead><tr>{Object.values(report.columns).map((c) => <th key={c}>{c}</th>)}</tr></thead>
        <tbody>
          {report.rows.map((row, i) => (
            <tr key={i}>
              {Object.keys(report.columns).map((k) => {
                const v = row[k];
                return <td key={k} className={typeof v === 'number' ? 'num' : ''}>{v === null ? '—' : typeof v === 'boolean' ? (v ? 'yes' : 'no') : String(v)}</td>;
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ReportChart({ report }: { report: Report }) {
  const keys = Object.keys(report.columns);
  const [labelKey, ...valueKeys] = keys;
  // top-products shows only the score; other reports show all numeric columns
  const numeric = valueKeys.filter((k) => report.rows.some((r) => typeof r[k] === 'number')).slice(0, report.key === 'top-products' ? 1 : 3);
  const rows = report.rows.map((r) => ({ ...r, [labelKey]: String(r[labelKey] ?? '') }));

  if (report.chart === 'pie') {
    const vk = numeric[0];
    return (
      <ResponsiveContainer width="100%" height={260}>
        <PieChart>
          <Pie data={rows} dataKey={vk} nameKey={labelKey} innerRadius={55} outerRadius={95} paddingAngle={2}>
            {rows.map((r, i) => <Cell key={i} fill={SENTIMENT_COLORS[String(r[labelKey])] ?? PALETTE[i % PALETTE.length]} />)}
          </Pie>
          <Tooltip />
          <Legend />
        </PieChart>
      </ResponsiveContainer>
    );
  }

  if (report.chart === 'line') {
    return (
      <ResponsiveContainer width="100%" height={260}>
        <LineChart data={rows} margin={{ left: -10, right: 10 }}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey={labelKey} fontSize={11} />
          <YAxis fontSize={11} allowDecimals={false} />
          <Tooltip />
          {numeric.length > 1 && <Legend />}
          {numeric.map((k, i) => <Line key={k} type="monotone" dataKey={k} name={report.columns[k]} stroke={PALETTE[i]} strokeWidth={2} dot={{ r: 3 }} />)}
        </LineChart>
      </ResponsiveContainer>
    );
  }

  const horizontal = rows.length > 6;
  return (
    <ResponsiveContainer width="100%" height={horizontal ? Math.max(220, rows.length * 28) : 260}>
      <BarChart data={rows} layout={horizontal ? 'vertical' : 'horizontal'} margin={{ left: horizontal ? 40 : -10, right: 10 }}>
        <CartesianGrid strokeDasharray="3 3" horizontal={!horizontal} vertical={horizontal} />
        {horizontal ? (
          <>
            <XAxis type="number" fontSize={11} allowDecimals={false} />
            <YAxis type="category" dataKey={labelKey} fontSize={11} width={110} />
          </>
        ) : (
          <>
            <XAxis dataKey={labelKey} fontSize={11} />
            <YAxis fontSize={11} allowDecimals={false} />
          </>
        )}
        <Tooltip />
        {numeric.length > 1 && <Legend />}
        {numeric.map((k, i) => (
          <Bar key={k} dataKey={k} name={report.columns[k]} fill={PALETTE[i]} radius={3}
            stackId={report.chart === 'stacked' && k !== 'total' ? 'a' : undefined} hide={report.chart === 'stacked' && k === 'total'} />
        ))}
      </BarChart>
    </ResponsiveContainer>
  );
}
