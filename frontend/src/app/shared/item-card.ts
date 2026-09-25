import { Component, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { MatTooltipModule } from '@angular/material/tooltip';
import { GameKey, ScoredSubstat } from '../core/models';
import { Avatar } from './char-avatar';
import { GradeBadge } from './grade-badge';
import { SlotLabelPipe, StatLabelPipe, StatValuePipe } from './stat-pipes';

export interface ItemView {
  slot: string;
  set: { name: string; icon_url: string | null };
  rarity: number;
  level: number;
  max_level?: number;
  main_stat: string;
  main_value: number | null;
  main_ok?: boolean | null;
  substats: ScoredSubstat[];
  percent: number;
  grade: string;
}

/**
 * One artifact / relic: set, main stat, substats (highlighted by how much the build values them) and grade.
 */
@Component({
  selector: 'app-item-card',
  imports: [Avatar, GradeBadge, StatLabelPipe, StatValuePipe, SlotLabelPipe, MatIconModule, MatTooltipModule],
  template: `
    @let it = item();
    <div class="head">
      <app-avatar [src]="it.set.icon_url" [alt]="it.set.name" [rarity]="it.rarity" size="sm" />
      <div class="title">
        <div class="slot">{{ it.slot | slotLabel: game() }}</div>
        <div class="set muted small" [title]="it.set.name">{{ it.set.name }}</div>
      </div>
      <app-grade-badge [grade]="it.grade" size="sm" [matTooltip]="it.percent + '% of an ideal piece for this build'" />
    </div>

    <div class="main" [class.bad]="it.main_ok === false">
      <span>{{ it.main_stat | statLabel: game() }}</span>
      <span class="value">{{ it.main_value | statValue: game() : it.main_stat }}</span>
      @if (it.main_ok === false) {
        <mat-icon class="warn-icon" matTooltip="This build wants a different main stat">error</mat-icon>
      }
    </div>

    <ul class="subs">
      @for (sub of it.substats; track sub.key) {
        <li [class.stat-good]="sub.weight >= 0.75" [class.stat-dim]="sub.weight === 0">
          <span>{{ sub.key | statLabel: game() }}</span>
          <span>
            {{ sub.value | statValue: game() : sub.key }}
            @if (sub.rolls && sub.rolls > 1) {
              <small class="rolls">×{{ sub.rolls }}</small>
            }
          </span>
        </li>
      }
    </ul>

    <div class="foot muted small">
      <span>+{{ it.level }}@if (it.max_level && it.level < it.max_level) { / +{{ it.max_level }} }</span>
      <span>{{ it.percent }}% of ideal</span>
    </div>
  `,
  styles: `
    :host {
      display: flex; flex-direction: column; gap: 8px;
      background: var(--app-surface);
      border: 1px solid var(--app-border);
      border-radius: 14px;
      padding: 12px;
      min-width: 0;
    }
    .head { display: flex; align-items: center; gap: 10px; }
    .title { flex: 1; min-width: 0; }
    .slot { font-weight: 500; }
    .set { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .main { display: flex; align-items: center; gap: 6px; font-weight: 500; padding: 6px 8px; border-radius: 8px; background: var(--app-surface-2); }
    .main .value { margin-left: auto; }
    .main.bad { background: color-mix(in srgb, var(--grade-d) 16%, transparent); }
    .warn-icon { color: var(--grade-d); font-size: 18px; width: 18px; height: 18px; }
    .subs { list-style: none; margin: 0; padding: 0 4px; display: grid; gap: 2px; font-size: 0.9rem; }
    .subs li { display: flex; justify-content: space-between; gap: 8px; }
    .rolls { color: var(--app-muted); margin-left: 2px; }
    .foot { display: flex; justify-content: space-between; }
  `,
})
export class ItemCard {
  readonly item = input.required<ItemView>();
  readonly game = input.required<GameKey>();
}
