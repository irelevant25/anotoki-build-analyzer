import { Component, inject } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatStepperIntl } from '@angular/material/stepper';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { TranslationService } from '../../core/i18n/translation';
import { StepperWords } from './stepper-words';

const ENGLISH = { 'stepper.optional': 'Optional', 'stepper.completed': 'Completed', 'stepper.editable': 'Editable' };
const SLOVAK = { 'stepper.optional': 'Nepovinné', 'stepper.completed': 'Dokončené', 'stepper.editable': 'Dá sa upraviť' };

/** Provides the words the way the import wizard does: for itself and what it holds. */
@Component({ template: '', providers: [{ provide: MatStepperIntl, useClass: StepperWords }] })
class Host {
  readonly intl = inject(MatStepperIntl);
}

describe('the stepper’s own words', () => {
  function host(language: 'en' | 'sk') {
    TestBed.configureTestingModule({ providers: [language === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }) : provideTestTranslations(ENGLISH)] });
    const fixture = TestBed.createComponent(Host);
    return { fixture, intl: fixture.componentInstance.intl, i18n: TestBed.inject(TranslationService) };
  }
  const words = (intl: MatStepperIntl) => [intl.optionalLabel, intl.completedLabel, intl.editableLabel];

  it('are in the language on the page from the start - before anything is drawn', () => {
    expect(words(host('en').intl)).toEqual(['Optional', 'Completed', 'Editable']);
    TestBed.resetTestingModule();
    const { intl } = host('sk');
    expect(intl).toBeInstanceOf(StepperWords);
    expect(words(intl)).toEqual(['Nepovinné', 'Dokončené', 'Dá sa upraviť']);
  });

  it('follow the language, and tell the stepper to draw its headers again', async () => {
    const { fixture, intl, i18n } = host('sk');
    await fixture.whenStable();
    let fired = 0;
    intl.changes.subscribe(() => fired++);

    expect(await i18n.setLanguage('en', { remember: false })).toBe(true);
    await fixture.whenStable();

    expect(words(intl)).toEqual(['Optional', 'Completed', 'Editable']);
    expect(fired).toBeGreaterThan(0);
  });

  it('show the key of a string that is missing, like any other word of the site', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    TestBed.configureTestingModule({ providers: [provideTestTranslations({ 'stepper.optional': 'Optional' })] });
    const intl = TestBed.createComponent(Host).componentInstance.intl;
    expect(words(intl)).toEqual(['Optional', 'stepper.completed', 'stepper.editable']);
    warn.mockRestore();
  });
});
