import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { SENTIMENTS } from '@/api/labels';
import type { Preference, Sentiment, TargetType } from '@/api/types';

interface Props {
  targetType: TargetType;
  targetId: number;
  value: Sentiment | null | undefined;
  groupId?: number | null;
  compact?: boolean;
  onChange?: (s: Sentiment) => void;
}

const DEFAULT_RATING: Record<Sentiment, number> = { love: 5, like: 4, neutral: 3, dislike: 2, avoid: 1 };

/** Quick buttons for expressing a preference for a product / category / restaurant. */
export default function SentimentPicker({ targetType, targetId, value, groupId = null, compact = false, onChange }: Props) {
  const qc = useQueryClient();
  const m = useMutation({
    mutationFn: (sentiment: Sentiment) =>
      api.post<Preference>('/preferences', {
        target_type: targetType,
        target_id: targetId,
        sentiment,
        // a default rating only for a brand-new preference; an existing one keeps
        // the rating the user set by hand (the API leaves fields that are not sent unchanged)
        ...(value ? {} : { rating: DEFAULT_RATING[sentiment] }),
        group_id: groupId,
      }),
    onSuccess: (p) => {
      onChange?.(p.sentiment);
      const refresh = (queryKey: unknown[]) => void qc.invalidateQueries({ queryKey });
      refresh(['preferences']);
      refresh(['recommendations']);
      refresh(['group']); // group consensus
      refresh(['stats']);
      if (targetType === 'product') {
        refresh(['products']);
        refresh(['product', targetId]);
      } else if (targetType === 'restaurant') {
        refresh(['restaurants']);
        refresh(['restaurant', targetId]);
      } else {
        refresh(['products']); // category preferences change recommendations and filters
      }
    },
  });

  return (
    <div className={`sentiments ${compact ? 'sentiments--compact' : ''}`} role="group" aria-label="Preference">
      {SENTIMENTS.map((s) => (
        <button
          key={s.value}
          type="button"
          className={`sentiment sentiment--${s.value} ${value === s.value ? 'is-active' : ''}`}
          title={s.label}
          aria-pressed={value === s.value}
          disabled={m.isPending}
          onClick={(e) => {
            e.preventDefault();
            e.stopPropagation();
            m.mutate(s.value);
          }}
        >
          <span aria-hidden>{s.emoji}</span>
          {!compact && <span>{s.label}</span>}
        </button>
      ))}
      {m.isError && (
        <span className="field__error" role="alert">
          {m.error.message}
        </span>
      )}
    </div>
  );
}