import { Component, computed, input } from '@angular/core';
import { elementColor } from './format';

/**
 * Character (or weapon / set) icon on a rarity-colored background with an optional element ring.
 */
@Component({
  selector: 'app-avatar',
  templateUrl: './char-avatar.html',
  styleUrl: './char-avatar.scss',
})
export class Avatar {
  readonly src = input<string | null | undefined>(null);
  readonly alt = input('');
  readonly rarity = input<number | null | undefined>(null);
  readonly element = input<string | null | undefined>(null);
  /** 24, 32, 48, 64 or 88 pixels across. */
  readonly size = input<'xs' | 'sm' | 'md' | 'lg' | 'xl'>('md');

  protected broken = false;

  protected readonly ring = computed(() => (this.element() ? elementColor(this.element()) : 'transparent'));
  protected readonly initials = computed(() =>
    this.alt()
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((word) => word[0]?.toUpperCase())
      .join(''),
  );
}
