import { useState, type FormEvent } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { DIETS, formatDate } from '@/api/labels';
import type { AdminUser, Paginated, Role } from '@/api/types';
import { ErrorBox, Field, Loader, Modal, PageHeader, Pagination, Tag } from '@/components/ui';
import ExportButtons from '@/components/ExportButtons';

export default function AdminUsersPage() {
  const { user: me } = useAuth();
  const qc = useQueryClient();
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);

  const users = useQuery({
    queryKey: ['admin-users', search, role, page],
    queryFn: () => api.get<Paginated<AdminUser>>('/admin/users', { q: search, role, page }),
    placeholderData: keepPreviousData,
  });
  const update = useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<AdminUser> }) => api.patch(`/admin/users/${id}`, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-users'] }),
  });
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/admin/users/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-users'] }),
  });

  return (
    <>
      <PageHeader
        title="User administration"
        subtitle={users.data ? `${users.data.total} accounts` : undefined}
        actions={<><ExportButtons path="/admin/users/export" name="users" /><button type="button" className="btn btn--primary" onClick={() => setCreating(true)}>+ User</button></>}
      />
      <form className="card toolbar" onSubmit={(e) => { e.preventDefault(); setSearch(q); setPage(1); }}>
        <input type="search" placeholder="Name or email…" value={q} onChange={(e) => setQ(e.target.value)} />
        <select value={role} onChange={(e) => { setRole(e.target.value); setPage(1); }}>
          <option value="">All roles</option><option value="user">User</option><option value="admin">Administrator</option>
        </select>
        <button type="submit" className="btn btn--ghost">Search</button>
      </form>
      <ErrorBox error={users.error ?? update.error ?? remove.error} />
      {users.isLoading ? <Loader /> : (
        <div className="card table-wrap">
          <table className="table">
            <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Diet</th><th>Activity</th><th>Last login</th><th /></tr></thead>
            <tbody>
              {users.data?.data.map((u) => (
                <tr key={u.id} className={u.is_active ? '' : 'is-muted'}>
                  <td><strong>{u.name}</strong><div className="muted small">{u.email}</div></td>
                  <td>
                    <select value={u.role} disabled={u.id === me?.id} onChange={(e) => update.mutate({ id: u.id, data: { role: e.target.value as Role } })}>
                      <option value="user">user</option><option value="admin">admin</option>
                    </select>
                  </td>
                  <td>
                    <button type="button" className="btn btn--ghost btn--sm" disabled={u.id === me?.id} onClick={() => update.mutate({ id: u.id, data: { is_active: !u.is_active } })}>
                      {u.is_active ? <Tag tone="green">active</Tag> : <Tag tone="red">deactivated</Tag>}
                    </button>
                  </td>
                  <td>{DIETS.find((d) => d.value === u.diet)?.label}</td>
                  <td className="small">{u.preferences_count} prefs · {u.shopping_lists_count} lists · {u.groups_count} groups</td>
                  <td className="small muted">{formatDate(u.last_login_at)}</td>
                  <td>
                    {u.id !== me?.id && <button type="button" className="icon-btn" title="Delete" onClick={() => confirm(`Delete account ${u.email}?`) && remove.mutate(u.id)}>🗑</button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {users.data && <Pagination page={users.data.current_page} last={users.data.last_page} onChange={setPage} />}
        </div>
      )}
      {creating && <CreateUserModal onClose={() => setCreating(false)} />}
    </>
  );
}

function CreateUserModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const [f, setF] = useState({ name: '', email: '', password: '', role: 'user', diet: 'omnivore', country: 'RO' });
  const create = useMutation({
    mutationFn: () => api.post('/admin/users', f),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['admin-users'] }); onClose(); },
  });
  const submit = (e: FormEvent) => { e.preventDefault(); create.mutate(); };
  return (
    <Modal open title="New user" onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <Field label="Name"><input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></Field>
        <Field label="Email"><input type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} required /></Field>
        <Field label="Password"><input type="password" minLength={8} value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} required /></Field>
        <div className="grid-2">
          <Field label="Role"><select value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })}><option value="user">user</option><option value="admin">admin</option></select></Field>
          <Field label="Diet"><select value={f.diet} onChange={(e) => setF({ ...f, diet: e.target.value })}>{DIETS.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}</select></Field>
        </div>
        <ErrorBox error={create.error} />
        <button type="submit" className="btn btn--primary" disabled={create.isPending}>Create</button>
      </form>
    </Modal>
  );
}
