import { NgTemplateOutlet } from '@angular/common';
import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * The anotoki mark and the wordmark beside it - the family's brand (the IAM's UI kit's, made of this app's
 * own pieces): the mark, "anotoki" and the app's name; on a phone's bar the app's short name alone.
 *
 * The mark (public/anotoki.png, the IAM's) has its own white outline, so it reads on a light and on a dark
 * bar alike. Its `alt` is empty: the wordmark beside it says the name, and saying it twice is noise.
 */
@Component({
  selector: 'app-brand',
  imports: [NgTemplateOutlet, RouterLink],
  templateUrl: './brand.html',
  styleUrl: './brand.scss',
})
export class Brand {
  readonly size = input(32);
  /** "anotoki" before the app's name; without it, the name alone beside the mark (the bar on a phone). */
  readonly showName = input(true);
  /** The app's name after "anotoki" - worded by whoever draws the brand. */
  readonly area = input<string | null>(null);
  /** Where the brand leads; none: the brand alone (the "not set up" page). */
  readonly link = input<string | null>(null);
  readonly linkLabel = input<string | null>(null);
}
