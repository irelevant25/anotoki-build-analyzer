import { Pipe, PipeTransform, inject } from '@angular/core';
import { TranslationKey } from './en';
import { TranslationParams, TranslationService } from './translation';

/**
 * `{{ 'title.builds' | translate }}`, and with placeholders filled in:
 * `{{ 'signIn.failed' | translate: { code: c } }}`.
 *
 * The key is typed, so a key that does not exist is a template compile error. A game term is never a key:
 * it goes in as a placeholder - `{ weapons: terms.weapons }` (core/game-terms.ts).
 *
 * Impure on purpose. A pure pipe answers from its arguments alone, and the argument here is a constant
 * key - switching the language would change every string on the page while the pipe kept handing back
 * its first answer. The signal read inside t() is what marks the page for another look; being impure is
 * what lets the pipe answer again when it comes. The cost is one map lookup per binding per change
 * detection.
 */
@Pipe({ name: 'translate', pure: false })
export class TranslatePipe implements PipeTransform {
  private readonly _i18n = inject(TranslationService);

  transform(key: TranslationKey, params?: TranslationParams): string {
    return this._i18n.t(key, params);
  }
}
