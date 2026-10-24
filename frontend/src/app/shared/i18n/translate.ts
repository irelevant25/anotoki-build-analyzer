import { Pipe, PipeTransform, inject } from '@angular/core';
import { TranslationParams, TranslationService, WordKey } from './translation';

/**
 * `{{ 'title.builds' | translate }}`, and with placeholders filled in:
 * `{{ 'whatsNew.version' | translate: { version: v } }}`.
 *
 * This site's own pipe over the anotoki library's service (the library's own `translate` pipe takes any
 * string): the key is typed - the site's keys (en.ts) and the library's words (`anotoki.*`) - so a key that
 * does not exist is a template compile error. A game term is never a key: it goes in as a placeholder -
 * `{ weapons: terms.weapons }` (sites/main/common/game-terms.ts).
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

  transform(key: WordKey, params?: TranslationParams): string {
    return this._i18n.t(key, params);
  }
}
