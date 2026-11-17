import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { DIETS, sentimentLabel } from '@/api/labels';
import type { Consensus, ConsensusItem, Group, ShoppingList } from '@/api/types';
import { Empty, ErrorBox, Loader, PageHeader, Tag } from '@/components/ui';
import ExportButtons from '@/components/ExportButtons';

export default function GroupDetailPage() {
  const { id } = useParams();
  const gid = Number(id);
  const { user } = useAuth();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');

  const group = useQuery({ queryKey: ['group', gid], queryFn: () => api.get<Group>(`/groups/${gid}`) });
  const consensus = useQuery({ queryKey: ['group', gid, 'consensus'], queryFn: () => api.get<Consensus>(`/groups/${gid}/consensus`) });

  const invalidate = () => {
    void qc.invalidateQueries({ queryKey: ['group', gid] });
    void qc.invalidateQueries({ queryKey: ['groups'] });
  };
  const addMember = useMutation({
    mutationFn: () => api.post(`/groups/${gid}/members`, { email }),
    onSuccess: () => { setEmail(''); invalidate(); },
  });
  const removeMember = useMutation({
    mutationFn: (uid: number) => api.delete(`/groups/${gid}/members/${uid}`),
    onSuccess: (_d, uid) => (uid === user?.id ? navigate('/groups') : invalidate()),
  });
  const removeGroup = useMutation({
    mutationFn: () => api.delete(`/groups/${gid}`),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['groups'] }); navigate('/groups'); },
  });
  const generate = useMutation({
    mutationFn: () => api.post<ShoppingList>('/shopping-lists/generate', { group_id: gid, only_seasonal: false, name: `${group.data?.name} list` }),
    onSuccess: (l) => navigate(`/shopping-lists/${l.id}`),
  });

  if (group.isLoading) return <Loader />;
  if (!group.data) return <ErrorBox error={group.error} />;
  const g = group.data;
  const isOwner = g.owner_id === user?.id || user?.role === 'admin';

  const submit = (e: FormEvent) => {
    e.preventDefault();
    addMember.mutate();
  };

  return (
    <>
      <PageHeader
        title={`👪 ${g.name}`}
        subtitle={g.description}
        actions={
          <>
            <button type="button" className="btn btn--primary" onClick={() => generate.mutate()} disabled={generate.isPending}>
              ✨ Generate shared list
            </button>
            <ExportButtons path="/stats/preferences-by-sentiment/export" query={{ scope: 'group', group_id: gid }} name="group_preferences" />
            {isOwner ? (
              <button type="button" className="btn btn--danger" onClick={() => confirm('Delete this group?') && removeGroup.mutate()}>Delete group</button>
            ) : (
              <button type="button" className="btn btn--ghost" onClick={() => user && removeMember.mutate(user.id)}>Leave group</button>
            )}
          </>
        }
      />
      <ErrorBox error={addMember.error ?? removeMember.error ?? generate.error} />

      <div className="grid-dashboard">
        <section className="card">
          <h2>Members ({g.members?.length})</h2>
          <ul className="list">
            {g.members?.map((m) => (
              <li key={m.id} className="list__row">
                <div>
                  <strong>{m.name}</strong> {m.pivot.role === 'owner' && <Tag tone="blue">owner</Tag>}
                  <div className="muted small">
                    {DIETS.find((d) => d.value === m.diet)?.label}
                    {m.allergens?.length ? ` · avoids: ${m.allergens.map((a) => a.name).join(', ')}` : ''}
                  </div>
                </div>
                {isOwner && m.pivot.role !== 'owner' && (
                  <button type="button" className="icon-btn" title="Remove" onClick={() => removeMember.mutate(m.id)}>×</button>
                )}
              </li>
            ))}
          </ul>
          {isOwner && (
            <form className="inline-form" onSubmit={submit}>
              <input type="email" placeholder="new member email" value={email} onChange={(e) => setEmail(e.target.value)} required />
              <button type="submit" className="btn btn--primary btn--sm" disabled={addMember.isPending}>Add</button>
            </form>
          )}
        </section>

        <section className="card">
          <h2>Common restrictions</h2>
          {consensus.data ? (
            <>
              <p>Common diet: <strong>{DIETS.find((d) => d.value === consensus.data?.common_restrictions.diet)?.label}</strong></p>
              <div className="tags">
                {consensus.data.common_restrictions.allergens.length
                  ? consensus.data.common_restrictions.allergens.map((a) => <Tag key={a.id} tone="red">{a.name}</Tag>)
                  : <span className="muted">No allergens to avoid.</span>}
              </div>
            </>
          ) : <Loader />}
        </section>
      </div>

      {consensus.data && (
        <div className="grid-3 consensus">
          <ConsensusColumn title="✅ Shared favourites" items={consensus.data.favorites} empty="No shared positive preferences yet." />
          <ConsensusColumn title="⚖️ Divided opinions" items={consensus.data.conflicts} empty="No disagreements. 🎉" />
          <ConsensusColumn title="🚫 To avoid" items={consensus.data.avoid} empty="Nothing to avoid." />
        </div>
      )}
    </>
  );
}

function ConsensusColumn({ title, items, empty }: { title: string; items: ConsensusItem[]; empty: string }) {
  return (
    <section className="card">
      <h2>{title}</h2>
      {items.length === 0 ? (
        <Empty title={empty} />
      ) : (
        <ul className="list">
          {items.map((i) => (
            <li key={`${i.type}:${i.id}`} className="consensus__item">
              <div className="list__row">
                {i.type === 'product' ? <Link to={`/products/${i.id}`}><strong>{i.name}</strong></Link> : <strong>{i.name}</strong>}
                <span className={`score ${i.score > 0 ? 'score--pos' : i.score < 0 ? 'score--neg' : ''}`}>{i.score > 0 ? '+' : ''}{i.score}</span>
              </div>
              <div className="small muted">
                {i.type === 'category' ? 'category' : i.type === 'restaurant' ? 'restaurant' : 'product'} · {i.votes} votes ·{' '}
                {i.opinions.map((o) => `${o.user.split(' ')[0]} ${sentimentLabel(o.sentiment)?.emoji}`).join('  ')}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
