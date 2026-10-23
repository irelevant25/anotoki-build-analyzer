import { siteNoteText } from './site-note';

describe("a note of the site's own, in the language on the page", () => {
  const both = { en: 'Swirl the Pyro first.', sk: 'Najprv rozvíri Pyro.' };
  const english = { en: 'Swirl the Pyro first.' };

  it('is the Slovak where an editor wrote it, on a Slovak page - its language the page', () => {
    expect(siteNoteText(both, 'sk')).toEqual({ text: 'Najprv rozvíri Pyro.', lang: null });
  });

  it('is the English where no Slovak was written, marked English on a Slovak page', () => {
    expect(siteNoteText(english, 'sk')).toEqual({ text: 'Swirl the Pyro first.', lang: 'en' });
    expect(siteNoteText({ en: 'Swirl.', sk: '' }, 'sk')).toEqual({ text: 'Swirl.', lang: 'en' });
  });

  it('is the English on an English page, whatever else was written, with no mark', () => {
    expect(siteNoteText(both, 'en')).toEqual({ text: 'Swirl the Pyro first.', lang: null });
  });

  it('is the English, marked so, in a language notes are not written in', () => {
    expect(siteNoteText(both, 'de')).toEqual({ text: 'Swirl the Pyro first.', lang: 'en' });
  });

  it('is nothing for no note', () => {
    expect(siteNoteText(null, 'sk')).toBeNull();
    expect(siteNoteText(undefined, 'en')).toBeNull();
  });
});
