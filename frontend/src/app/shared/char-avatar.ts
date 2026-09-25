import { Component, computed, input } from '@angular/core';
import { elementColor } from '../core/format';

/**
 * Character (or weapon / set) icon on a rarity-colored background with an optional element ring.
 */
@Component({
  selector: 'app-avatar',
  template: `
    <div class="avatar" [class]="'rarity-' + (rarity() ?? 0) + ' ' + size()" [style.--ring]="ring()">
      @if (src()) {
        <img [src]="src()" [alt]="alt()" loading="lazy" referrerpolicy="no-referrer" (error)="broken = true" [hidden]="broken" />
      }
      @if (!src() || broken) {
        <span class="fallback">{{ initials() }}</span>
      }
    </div>
  `,
  styles: `
    :host { display: inline-block; flex: none; }
    .avatar {
      --dim: 48px;
      width: var(--dim); height: var(--dim);
      border-radius: 14px;
      overflow: hidden;
      display: grid; place-items: center;
      background-color: var(--app-surface-2);
      box-shadow: inset 0 0 0 2px var(--ring, transparent);
    }
    .avatar.sm { --dim: 32px; border-radius: 10px; }
    .avatar.lg { --dim: 88px; border-radius: 20px; }
    .avatar.xs { --dim: 24px; border-radius: 6px; }
    img { width: 100%; height: 100%; object-fit: cover; }
    .fallback { font-weight: 600; color: var(--app-muted); font-size: 0.8em; }
  `,
})
export class Avatar {
  readonly src = input<string | null | undefined>(null);
  readonly alt = input('');
  readonly rarity = input<number | null | undefined>(null);
  readonly element = input<string | null | undefined>(null);
  readonly size = input<'xs' | 'sm' | 'md' | 'lg'>('md');

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
