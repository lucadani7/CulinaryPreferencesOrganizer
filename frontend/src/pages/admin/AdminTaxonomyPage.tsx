import { useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useAllergens, useCategories } from '@/api/hooks';
import type { Allergen } from '@/api/types';
import { ErrorBox, Loader, PageHeader } from '@/components/ui';

export default function AdminTaxonomyPage() {
  const qc = useQueryClient();
  const categories = useCategories();
  const allergens = useAllergens();
  const [cat, setCat] = useState({ name: '', off_tag: '', parent_id: '' });
  const [alg, setAlg] = useState({ name: '', off_tag: '' });

  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['categories'] });
    void qc.invalidateQueries({ queryKey: ['allergens'] });
  };
  const addCat = useMutation({
    mutationFn: () => api.post('/admin/categories', { ...cat, parent_id: cat.parent_id ? Number(cat.parent_id) : null, off_tag: cat.off_tag || null }),
    onSuccess: () => { setCat({ name: '', off_tag: '', parent_id: '' }); refresh(); },
  });
  const delCat = useMutation({ mutationFn: (id: number) => api.delete(`/admin/categories/${id}`), onSuccess: refresh });
  const renameCat = useMutation({ mutationFn: ({ id, name }: { id: number; name: string }) => api.put(`/admin/categories/${id}`, { name }), onSuccess: refresh });
  const addAlg = useMutation({ mutationFn: () => api.post('/admin/allergens', alg), onSuccess: () => { setAlg({ name: '', off_tag: '' }); refresh(); } });
  // the API refuses to delete an allergen that users avoid unless ?force=1 is sent
  const delAlg = useMutation({
    mutationFn: ({ id, force }: { id: number; force: boolean }) => api.delete(`/admin/allergens/${id}`, { force }),
    onSuccess: refresh,
  });
  const confirmDeleteAllergen = (a: Allergen) => {
    const users = a.users_count ?? 0;
    const question = users > 0
      ? `${users} user(s) avoid ${a.name}. Deleting it also removes it from their restrictions. Delete anyway?`
      : `Delete allergen ${a.name}?`;
    if (confirm(question)) delAlg.mutate({ id: a.id, force: users > 0 });
  };
  const rename = (current: string, next: string, save: (name: string) => void) => {
    const name = next.trim();
    if (name && name !== current) save(name);
  };
  const renameAlg = useMutation({ mutationFn: ({ id, name }: { id: number; name: string }) => api.put(`/admin/allergens/${id}`, { name }), onSuccess: refresh });

  const submitCat = (e: FormEvent) => { e.preventDefault(); addCat.mutate(); };
  const submitAlg = (e: FormEvent) => { e.preventDefault(); addAlg.mutate(); };

  return (
    <>
      <PageHeader title="Categories and allergens" subtitle="OFF tags (e.g. en:dairies, en:gluten) link the local taxonomy to Open Food Facts on import." />
      <div className="grid-dashboard">
        <section className="card">
          <h2>Categories</h2>
          <ErrorBox error={addCat.error ?? delCat.error ?? renameCat.error} />
          <form className="inline-form" onSubmit={submitCat}>
            <input placeholder="Name" value={cat.name} onChange={(e) => setCat({ ...cat, name: e.target.value })} required />
            <input placeholder="OFF tag" value={cat.off_tag} onChange={(e) => setCat({ ...cat, off_tag: e.target.value })} />
            <select value={cat.parent_id} onChange={(e) => setCat({ ...cat, parent_id: e.target.value })}>
              <option value="">no parent</option>
              {categories.data?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
            <button type="submit" className="btn btn--primary btn--sm">Add</button>
          </form>
          {categories.isLoading ? <Loader /> : (
            <table className="table table--compact">
              <thead><tr><th>Name</th><th>OFF tag</th><th>Parent</th><th>Products</th><th /></tr></thead>
              <tbody>
                {categories.data?.map((c) => (
                  <tr key={c.id}>
                    <td><input defaultValue={c.name} aria-label={`Name of ${c.name}`} onBlur={(e) => rename(c.name, e.target.value, (name) => renameCat.mutate({ id: c.id, name }))} /></td>
                    <td><code>{c.off_tag ?? '—'}</code></td>
                    <td>{c.parent?.name ?? '—'}</td>
                    <td className="num">{c.products_count}</td>
                    <td><button type="button" className="icon-btn" aria-label={`Delete ${c.name}`} onClick={() => confirm(`Delete category ${c.name}? Its products keep existing, without a category.`) && delCat.mutate(c.id)}>🗑</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>

        <section className="card">
          <h2>Allergens</h2>
          <ErrorBox error={addAlg.error ?? delAlg.error ?? renameAlg.error} />
          <form className="inline-form" onSubmit={submitAlg}>
            <input placeholder="Name" value={alg.name} onChange={(e) => setAlg({ ...alg, name: e.target.value })} required />
            <input placeholder="OFF tag (en:...)" value={alg.off_tag} onChange={(e) => setAlg({ ...alg, off_tag: e.target.value })} required />
            <button type="submit" className="btn btn--primary btn--sm">Add</button>
          </form>
          {allergens.isLoading ? <Loader /> : (
            <table className="table table--compact">
              <thead><tr><th>Name</th><th>OFF tag</th><th>Products</th><th>Users</th><th /></tr></thead>
              <tbody>
                {allergens.data?.map((a) => (
                  <tr key={a.id}>
                    <td><input defaultValue={a.name} aria-label={`Name of ${a.name}`} onBlur={(e) => rename(a.name, e.target.value, (name) => renameAlg.mutate({ id: a.id, name }))} /></td>
                    <td><code>{a.off_tag}</code></td>
                    <td className="num">{a.products_count}</td>
                    <td className="num">{a.users_count ?? 0}</td>
                    <td><button type="button" className="icon-btn" aria-label={`Delete ${a.name}`} onClick={() => confirmDeleteAllergen(a)}>🗑</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      </div>
    </>
  );
}
