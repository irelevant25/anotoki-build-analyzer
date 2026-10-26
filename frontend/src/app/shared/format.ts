import { Grade, GameKey } from '../api/models';

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

/**
 * A score bar's fill: one of three colours (the owner's rule of 2026-10-26), cut where the grades are - red below 50
 * (a D), gold to 79 (C and B), green from 80 (A and S) - so a bar never disagrees with the grade beside it; the muted
 * colour where there is no score.
 */
export function scoreColor(score: number | null | undefined): string {
  if (score === null || score === undefined) {
    return 'var(--anotoki-muted)';
  }
  if (score >= 80) return 'var(--bar-high)';
  if (score >= 50) return 'var(--bar-mid)';
  return 'var(--bar-low)';
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
