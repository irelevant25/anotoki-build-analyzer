import { Pipe, PipeTransform, inject } from '@angular/core';
import { PluralKey } from './en';
import { TranslationParams, TranslationService } from './translation';

/**
 * `{{ 'buildDetail.optionAny' | translatePlural: n }}`: the form of a plural family (`.one`, `.few`,
 * `.other`) the number asks for in the language on the page, with the number as `{count}`. A third
 * argument carries more placeholders. This site's own pipe over the anotoki library's service, the family's
 * name typed (en.ts): one that does not exist is a template compile error.
 *
 * A family belongs to a place in a sentence, not to a noun: each phrase is complete in its own case. A
 * sentence with two counts is a family on the number its Slovak noun follows, with the other number as a
 * plain placeholder - or a frame key filled with two such phrases.
 *
 * Not for a count beside a game term, which is written "term: {count}" with the plain pipe.
 *
 * Impure for the reason the translate pipe is: the language changes under a constant key.
 */
@Pipe({ name: 'translatePlural', pure: false })
export class TranslatePluralPipe implements PipeTransform {
  private readonly _i18n = inject(TranslationService);

  transform(key: PluralKey, count: number, params?: TranslationParams): string {
    return this._i18n.plural(key, count, params);
  }
}
