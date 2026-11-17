import { useTheme, type ThemeChoice } from '@/theme';

const NEXT: Record<ThemeChoice, ThemeChoice> = { light: 'dark', dark: 'system', system: 'light' };
const ICON: Record<ThemeChoice, string> = { light: '☀️', dark: '🌙', system: '🖥️' };
const LABEL: Record<ThemeChoice, string> = { light: 'Light theme', dark: 'Dark theme', system: 'System theme' };

/** Cycles light → dark → follow the system. */
export default function ThemeToggle({ className = '' }: { className?: string }) {
  const { choice, setChoice } = useTheme();
  const next = NEXT[choice];
  return (
    <button
      type="button"
      className={`icon-btn theme-toggle ${className}`}
      onClick={() => setChoice(next)}
      title={`${LABEL[choice]} · click for ${LABEL[next].toLowerCase()}`}
      aria-label={`${LABEL[choice]}. Switch to ${LABEL[next].toLowerCase()}`}
    >
      <span aria-hidden>{ICON[choice]}</span>
    </button>
  );
}
