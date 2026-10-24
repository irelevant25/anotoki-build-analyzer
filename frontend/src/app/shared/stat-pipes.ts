import { Pipe, PipeTransform, inject } from '@angular/core';
import { timeAgo } from './format';
import { MetaStore } from './meta-store';
import { GameKey } from '../api/models';

/** stat key => label, e.g. "critRate_" => "CRIT Rate" (or "CR" with short = true). */
@Pipe({ name: 'statLabel' })
export class StatLabelPipe implements PipeTransform {
  private readonly meta = inject(MetaStore);

  transform(stat: string | null | undefined, game: GameKey, short = false): string {
    return stat ? this.meta.statLabel(game, stat, short) : '-';
  }
}

/** Formats a stat value with its unit, e.g. 46.6 => "46.6%". */
@Pipe({ name: 'statValue' })
export class StatValuePipe implements PipeTransform {
  private readonly meta = inject(MetaStore);

  transform(value: number | null | undefined, game: GameKey, stat: string): string {
    return this.meta.formatStat(game, stat, value);
  }
}

/** slot key => label, e.g. "sands" => "Sands of Eon". */
@Pipe({ name: 'slotLabel' })
export class SlotLabelPipe implements PipeTransform {
  private readonly meta = inject(MetaStore);

  transform(slot: string, game: GameKey): string {
    return this.meta.slotLabel(game, slot);
  }
}

/** Relative time, e.g. "3 hours ago". */
@Pipe({ name: 'timeAgo' })
export class TimeAgoPipe implements PipeTransform {
  transform(iso: string | null | undefined): string {
    return timeAgo(iso);
  }
}
