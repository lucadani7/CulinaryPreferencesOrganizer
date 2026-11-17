import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useMyGroups } from '@/api/hooks';
import type { Group } from '@/api/types';
import { Empty, ErrorBox, Field, Loader, Modal, PageHeader } from '@/components/ui';

export default function GroupsPage() {
  const groups = useMyGroups();
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');

  const create = useMutation({
    mutationFn: () => api.post<Group>('/groups', { name, description }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['groups'] });
      setOpen(false);
      setName('');
      setDescription('');
    },
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    create.mutate();
  };

  return (
    <>
      <PageHeader
        title="Groups"
        subtitle="Family, flatmates, friends — shared preferences and shopping lists."
        actions={<button type="button" className="btn btn--primary" onClick={() => setOpen(true)}>+ New group</button>}
      />
      {groups.isLoading ? (
        <Loader />
      ) : groups.data?.length ? (
        <div className="cards-grid">
          {groups.data.map((g) => (
            <Link key={g.id} to={`/groups/${g.id}`} className="card card--link">
              <h3>👪 {g.name}</h3>
              {g.description && <p className="muted">{g.description}</p>}
              <p className="small">
                {g.members_count} members · {g.shopping_lists_count} lists · owner: {g.owner?.name}
              </p>
            </Link>
          ))}
        </div>
      ) : (
        <Empty title="You are not in any group">
          <button type="button" className="btn btn--primary" onClick={() => setOpen(true)}>Create a group</button>
        </Empty>
      )}

      <Modal open={open} title="New group" onClose={() => setOpen(false)}>
        <form className="stack" onSubmit={submit}>
          <Field label="Name"><input value={name} onChange={(e) => setName(e.target.value)} required autoFocus /></Field>
          <Field label="Description"><textarea rows={3} value={description} onChange={(e) => setDescription(e.target.value)} /></Field>
          <ErrorBox error={create.error} />
          <button type="submit" className="btn btn--primary" disabled={create.isPending}>Create</button>
        </form>
      </Modal>
    </>
  );
}
