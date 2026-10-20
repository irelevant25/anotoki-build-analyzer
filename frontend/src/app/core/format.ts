import { Grade, GameKey } from './models';

export const GAME_LABELS: Record<GameKey, string> = {
  genshin: 'Genshin Impact',
};

/** The games the site offers, in the order a page lists them (the server's App\Enums\Game). */
export const GAMES: readonly GameKey[] = ['genshin'];

export function gradeColor(grade: Grade | string | null | undefined): string {
  switch (grade) {
    case 'S':
      return 'var(--grade-s)';
    case 'A':
      return 'var(--grade-a)';
    case 'B':
      return 'var(--grade-b)';
    case 'C':
      return 'var(--grade-c)';
    case 'D':
      return 'var(--grade-d)';
    default:
      return 'var(--anotoki-muted)';
  }
}

export function scoreColor(score: number | null | undefined): string {
  if (score === null || score === undefined) {
    return 'var(--anotoki-muted)';
  }
  if (score >= 90) return 'var(--grade-s)';
  if (score >= 80) return 'var(--grade-a)';
  if (score >= 65) return 'var(--grade-b)';
  if (score >= 50) return 'var(--grade-c)';
  return 'var(--grade-d)';
}

export function elementColor(element: string | null | undefined): string {
  return element ? `var(--el-${element})` : 'var(--anotoki-muted)';
}

export function titleCase(text: string | null | undefined): string {
  if (!text) {
    return '';
  }
  return text.replace(/(^|[\s-])(\w)/g, (_, sep: string, char: string) => sep + char.toUpperCase());
}

/**
 * "3 hours ago" style relative time.
 */
export function timeAgo(iso: string | null | undefined): string {
  if (!iso) {
    return 'never';
  }
  const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
  const units: [number, string][] = [
    [60, 'second'],
    [60, 'minute'],
    [24, 'hour'],
    [30, 'day'],
    [12, 'month'],
    [Number.POSITIVE_INFINITY, 'year'],
  ];
  let value = Math.abs(seconds);
  for (const [size, unit] of units) {
    if (value < size) {
      const rounded = Math.max(1, Math.floor(value));
      const label = `${rounded} ${unit}${rounded === 1 ? '' : 's'}`;
      return seconds >= 0 ? `${label} ago` : `in ${label}`;
    }
    value /= size;
  }
  return iso;
}
