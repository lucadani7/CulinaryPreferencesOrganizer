import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/api/client';
import { useAllergens, useCategories, useExchangeRates } from '@/api/hooks';
import { formatMoney, MONTHS, PERISHABILITY } from '@/api/labels';
import type { Availability, Product, ProductDetail } from '@/api/types';
import { ErrorBox, Field, Loader, PageHeader } from '@/components/ui';

type Form = {
  name: string; brand: string; barcode: string; category_id: string; image_url: string; price: string;
  quantity: string; ingredients_text: string; nutriscore: string; nova_group: string;
  is_vegetarian: '' | 'true' | 'false'; is_vegan: '' | 'true' | 'false'; perishability: string;
  shelf_life_days: string; expires_at: string;
  allergens: { id: number; level: 'contains' | 'traces' }[];
  availabilities: Availability[];
};

const EMPTY: Form = {
  name: '', brand: '', barcode: '', category_id: '', image_url: '', price: '', quantity: '',
  ingredients_text: '', nutriscore: '', nova_group: '', is_vegetarian: '', is_vegan: '', perishability: 'medium',
  shelf_life_days: '', expires_at: '', allergens: [], availabilities: [{ region: 'RO', month_from: 1, month_to: 12 }],
};

const tri = (v: boolean | null) => (v === null ? '' : v ? 'true' : 'false') as Form['is_vegan'];
const nul = (v: string) => (v === '' ? null : v);

export default function ProductFormPage() {
  const { id } = useParams();
  const editing = !!id;
  const navigate = useNavigate();
  const qc = useQueryClient();
  const categories = useCategories();
  const allergens = useAllergens();
  const fx = useExchangeRates();
  const [form, setForm] = useState<Form>(EMPTY);

  const existing = useQuery({
    queryKey: ['product', Number(id)],
    queryFn: () => api.get<ProductDetail>(`/products/${id}`),
    enabled: editing,
  });

  useEffect(() => {
    const p = existing.data;
    if (!p) return;
    setForm({
      name: p.name, brand: p.brand ?? '', barcode: p.barcode ?? '', category_id: p.category_id ? String(p.category_id) : '',
      image_url: p.image_url ?? '', price: p.price ?? '', quantity: p.quantity ?? '',
      ingredients_text: p.ingredients_text ?? '', nutriscore: p.nutriscore ?? '', nova_group: p.nova_group ? String(p.nova_group) : '',
      is_vegetarian: tri(p.is_vegetarian), is_vegan: tri(p.is_vegan), perishability: p.perishability,
      shelf_life_days: p.shelf_life_days ? String(p.shelf_life_days) : '', expires_at: p.expires_at?.slice(0, 10) ?? '',
      allergens: (p.allergens ?? []).map((a) => ({ id: a.id, level: a.pivot?.level ?? 'contains' })),
      availabilities: (p.availabilities ?? []).map(({ region, month_from, month_to, notes }) => ({ region, month_from, month_to, notes })),
    });
  }, [existing.data]);

  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...form,
        brand: nul(form.brand), barcode: nul(form.barcode), image_url: nul(form.image_url),
        category_id: form.category_id ? Number(form.category_id) : null,
        price: form.price === '' ? null : Number(form.price),
        quantity: nul(form.quantity), ingredients_text: nul(form.ingredients_text),
        nutriscore: nul(form.nutriscore), nova_group: form.nova_group ? Number(form.nova_group) : null,
        is_vegetarian: form.is_vegetarian === '' ? null : form.is_vegetarian === 'true',
        is_vegan: form.is_vegan === '' ? null : form.is_vegan === 'true',
        shelf_life_days: form.shelf_life_days ? Number(form.shelf_life_days) : null,
        expires_at: nul(form.expires_at),
      };
      return editing ? api.put<Product>(`/products/${id}`, body) : api.post<Product>('/products', body);
    },
    onSuccess: (p) => {
      void qc.invalidateQueries({ queryKey: ['products'] });
      void qc.invalidateQueries({ queryKey: ['product', p.id] });
      navigate(`/products/${p.id}`);
    },
  });

  if (editing && existing.isLoading) return <Loader />;
  const err = save.error instanceof ApiError ? save.error : null;
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }));
  const input = (k: keyof Form) => ({
    value: form[k] as string,
    onChange: (e: { target: { value: string } }) => set(k, e.target.value as never),
  });

  const toggleAllergen = (aid: number) =>
    set('allergens', form.allergens.some((a) => a.id === aid)
      ? form.allergens.filter((a) => a.id !== aid)
      : [...form.allergens, { id: aid, level: 'contains' }]);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    save.mutate();
  };

  return (
    <>
      <PageHeader title={editing ? `Edit: ${existing.data?.name ?? ''}` : 'New product'} />
      <form className="form card" onSubmit={submit}>
        <ErrorBox error={save.error} />
        <fieldset>
          <legend>General</legend>
          <div className="grid-3">
            <Field label="Name *" error={err?.field('name')}><input {...input('name')} required /></Field>
            <Field label="Brand"><input {...input('brand')} /></Field>
            <Field label="Barcode" error={err?.field('barcode')}><input {...input('barcode')} inputMode="numeric" /></Field>
          </div>
          <div className="grid-3">
            <Field label="Category">
              <select {...input('category_id')}>
                <option value="">—</option>
                {categories.data?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <Field label="Quantity" hint="e.g. 500 g"><input {...input('quantity')} /></Field>
            <Field label="Image URL" error={err?.field('image_url')}><input type="url" {...input('image_url')} /></Field>
          </div>
        </fieldset>

        <fieldset>
          <legend>Price and shelf life</legend>
          <div className="grid-3">
            {/* prices are stored in euro; the hint shows the value in the user's local currency */}
            <Field
              label="Price (EUR)"
              error={err?.field('price')}
              hint={
                fx.data?.local_currency && fx.data.local_rate && form.price !== ''
                  ? `≈ ${formatMoney(Number(form.price) * fx.data.local_rate, fx.data.local_currency)} at the ECB rate`
                  : undefined
              }
            >
              <input type="number" step="0.01" min="0" {...input('price')} />
            </Field>
            <Field label="Perishability">
              <select {...input('perishability')}>
                {PERISHABILITY.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
              </select>
            </Field>
          </div>
          <div className="grid-3">
            <Field label="Keeps for (days)"><input type="number" min="0" {...input('shelf_life_days')} /></Field>
            <Field label="Best before"><input type="date" {...input('expires_at')} /></Field>
            <span />
          </div>
        </fieldset>

        <fieldset>
          <legend>Nutrition and restrictions</legend>
          <div className="grid-3">
            <Field label="Nutri-Score">
              <select {...input('nutriscore')}>
                <option value="">—</option>
                {['a', 'b', 'c', 'd', 'e'].map((g) => <option key={g} value={g}>{g.toUpperCase()}</option>)}
              </select>
            </Field>
            <Field label="Vegetarian">
              <select {...input('is_vegetarian')}>
                <option value="">Unknown</option><option value="true">Yes</option><option value="false">No</option>
              </select>
            </Field>
            <Field label="Vegan">
              <select {...input('is_vegan')}>
                <option value="">Unknown</option><option value="true">Yes</option><option value="false">No</option>
              </select>
            </Field>
          </div>
          <Field label="Ingredients"><textarea rows={3} {...input('ingredients_text')} /></Field>
          <div className="field">
            <span className="field__label">Allergens (click: contains → traces → remove)</span>
            <div className="chips">
              {allergens.data?.map((a) => {
                const cur = form.allergens.find((x) => x.id === a.id);
                return (
                  <button key={a.id} type="button"
                    className={`chip ${cur ? (cur.level === 'traces' ? 'is-active chip--amber' : 'is-active chip--red') : ''}`}
                    onClick={() => cur?.level === 'contains'
                      ? set('allergens', form.allergens.map((x) => (x.id === a.id ? { ...x, level: 'traces' } : x)))
                      : toggleAllergen(a.id)}>
                    {a.name}{cur?.level === 'traces' ? ' (traces)' : ''}
                  </button>
                );
              })}
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend>Availability by season / region</legend>
          {form.availabilities.map((a, i) => (
            <div key={i} className="grid-4 align-end">
              <Field label="Region / country"><input value={a.region} onChange={(e) => set('availabilities', form.availabilities.map((x, j) => (j === i ? { ...x, region: e.target.value } : x)))} required /></Field>
              <Field label="From month">
                <select value={a.month_from} onChange={(e) => set('availabilities', form.availabilities.map((x, j) => (j === i ? { ...x, month_from: Number(e.target.value) } : x)))}>
                  {MONTHS.map((m, k) => <option key={m} value={k + 1}>{m}</option>)}
                </select>
              </Field>
              <Field label="To month">
                <select value={a.month_to} onChange={(e) => set('availabilities', form.availabilities.map((x, j) => (j === i ? { ...x, month_to: Number(e.target.value) } : x)))}>
                  {MONTHS.map((m, k) => <option key={m} value={k + 1}>{m}</option>)}
                </select>
              </Field>
              <button type="button" className="btn btn--ghost" onClick={() => set('availabilities', form.availabilities.filter((_, j) => j !== i))}>Remove</button>
            </div>
          ))}
          <button type="button" className="btn btn--ghost btn--sm" onClick={() => set('availabilities', [...form.availabilities, { region: 'RO', month_from: 1, month_to: 12 }])}>
            + Add range
          </button>
        </fieldset>

        <div className="form__actions">
          <button type="button" className="btn btn--ghost" onClick={() => navigate(-1)}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save'}</button>
        </div>
      </form>
    </>
  );
}
