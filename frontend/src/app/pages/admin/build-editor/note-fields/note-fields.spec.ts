import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideKit } from '../../../../core/kit';
import { NoteDraft } from '../editor-draft';
import { NoteFields } from './note-fields';

describe("a note's fields: its English and its Slovak side by side, the Slovak read as Slovak", () => {
  let fixture: ComponentFixture<NoteFields>;
  let host: HTMLElement;
  let changes: NoteDraft[];

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();

  async function setup(value: NoteDraft, errors: { en: string | null; sk: string | null } = { en: null, sk: null }): Promise<void> {
    TestBed.configureTestingModule({ providers: [provideTestTranslations({}), provideKit()] });
    fixture = TestBed.createComponent(NoteFields);
    fixture.componentRef.setInput('label', 'Note on this build');
    fixture.componentRef.setInput('value', value);
    fixture.componentRef.setInput('maxlength', 20000);
    fixture.componentRef.setInput('path', 'notes.build');
    fixture.componentRef.setInput('errors', errors);
    changes = [];
    fixture.componentInstance.valueChange.subscribe((note) => changes.push(note));
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  it('draws the English and the Slovak box - the Slovak one in a block of Slovak, named in Slovak - each with its place', async () => {
    await setup({ en: 'Swirl first.', sk: 'Najprv rozvír.' });

    const boxes = [...host.querySelectorAll<HTMLTextAreaElement>('textarea')];
    expect(boxes.map((box) => box.value)).toEqual(['Swirl first.', 'Najprv rozvír.']);
    expect(boxes.map((box) => text(host.querySelector(`label[for="${box.id}"]`)))).toEqual(['English', 'Slovenčina']);
    expect(boxes.map((box) => box.closest('[lang="sk"]') !== null)).toEqual([false, true]);
    expect(boxes.map((box) => box.maxLength)).toEqual([20000, 20000]);
    expect([...host.querySelectorAll('[data-path]')].map((place) => place.getAttribute('data-path'))).toEqual(['notes.build.en', 'notes.build.sk']);
    // One group, named by the note's label, described by its hint.
    const group = host.querySelector('[role="group"]')!;
    expect(text(host.querySelector(`#${group.getAttribute('aria-labelledby')}`))).toBe('Note on this build');
    expect(group.getAttribute('aria-describedby')).toBeNull();
  });

  it('gives each box back with the other as it was', async () => {
    await setup({ en: 'Swirl first.', sk: '' });
    const slovak = host.querySelectorAll<HTMLTextAreaElement>('textarea')[1];
    slovak.value = 'Najprv rozvír.';
    slovak.dispatchEvent(new Event('input'));
    expect(changes).toEqual([{ en: 'Swirl first.', sk: 'Najprv rozvír.' }]);
  });

  it("says what is wrong: the English box's in its field, the Slovak box's under the two - outside the Slovak block -, the box marked", async () => {
    await setup({ en: '', sk: 'Len po slovensky.' }, { en: 'Write the English first.', sk: 'At most 20000 characters.' });

    const [english, slovak] = [...host.querySelectorAll<HTMLTextAreaElement>('textarea')];
    expect(english.getAttribute('aria-invalid')).toBe('true');
    expect(text(host.querySelector('.note-en'))).toContain('Write the English first.');
    expect(slovak.getAttribute('aria-invalid')).toBe('true');
    const said = host.querySelector('.sk-error')!;
    expect(text(said)).toBe('At most 20000 characters.');
    expect(said.getAttribute('role')).toBe('alert');
    expect(said.closest('[lang="sk"]')).toBeNull();
  });
});
