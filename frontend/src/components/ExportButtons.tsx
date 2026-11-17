import { useState } from 'react';
import { api } from '@/api/client';

type Format = 'csv' | 'pdf';
type Query = Record<string, string | number | boolean | null | undefined>;

/** CSV / PDF buttons that download an authenticated export (GET {path}?format=csv|pdf). */
export default function ExportButtons({ path, query, name = 'export' }: { path: string; query?: Query; name?: string }) {
  const [busy, setBusy] = useState<Format | null>(null);
  const [error, setError] = useState<string | null>(null);

  const run = async (format: Format) => {
    setBusy(format);
    setError(null);
    try {
      // the file name comes from Content-Disposition; `${name}.${format}` is only a fallback
      await api.download(path, { ...query, format }, `${name}.${format}`);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Export failed');
    } finally {
      setBusy(null);
    }
  };

  return (
    <span className="export-buttons">
      {(['csv', 'pdf'] as const).map((format) => (
        <button
          key={format}
          type="button"
          className="btn btn--ghost btn--sm"
          onClick={() => void run(format)}
          disabled={busy !== null}
          aria-busy={busy === format}
        >
          {busy === format ? '…' : `⬇ ${format.toUpperCase()}`}
        </button>
      ))}
      {error && (
        <small className="text-error" role="alert">
          {error}
        </small>
      )}
    </span>
  );
}