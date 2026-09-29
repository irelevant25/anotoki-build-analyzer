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
  templateUrl: './item-card.html',
  styleUrl: './item-card.scss',
})
export class ItemCard {
  readonly item = input.required<ItemView>();
  readonly game = input.required<GameKey>();
}
