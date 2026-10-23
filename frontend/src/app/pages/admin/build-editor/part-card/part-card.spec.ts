import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { provideKit } from '../../../../core/kit';
import { PartCard } from './part-card';

describe("a part's card: what state the part is in, and the way back to the builds file's", () => {
  let fixture: ComponentFixture<PartCard>;
  let host: HTMLElement;
  let ask: ReturnType<typeof vi.fn>;
  let used: number;

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();

  async function setup(state: { edited?: boolean; unsaved?: boolean; canUseFile?: boolean }, answer = true): Promise<void> {
    ask = vi.fn(() => Promise.resolve(answer));
    TestBed.configureTestingModule({ providers: [provideTestTranslations({}), provideKit(), { provide: ConfirmService, useValue: { ask } }] });
    fixture = TestBed.createComponent(PartCard);
    fixture.componentRef.setInput('heading', 'Weapons');
    fixture.componentRef.setInput('part', 'weapons');
    fixture.componentRef.setInput('edited', state.edited ?? false);
    fixture.componentRef.setInput('unsaved', state.unsaved ?? false);
    fixture.componentRef.setInput('canUseFile', state.canUseFile ?? false);
    used = 0;
    fixture.componentInstance.useFile.subscribe(() => (used += 1));
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  it('says nothing of a part as the file has it, saved', async () => {
    await setup({});
    expect(text(host.querySelector('.anotoki-card__title'))).toBe('Weapons');
    expect(host.querySelectorAll('anotoki-badge, button.use-file')).toHaveLength(0);
  });

  it('marks a part edited and not saved, and puts it back as the file has it once asked', async () => {
    await setup({ edited: true, unsaved: true, canUseFile: true });
    expect([text(host.querySelector('.edited')), text(host.querySelector('.unsaved'))]).toEqual(['Edited', 'Unsaved']);

    host.querySelector<HTMLButtonElement>('button.use-file')!.click();
    await fixture.whenStable();
    expect(ask).toHaveBeenCalledWith({
      title: "Use the file's weapons?",
      message: "What the builds file says replaces this build's weapons. Until you save, Discard puts back what was there.",
      confirmLabel: "Use the file's",
    });
    expect(used).toBe(1);
  });

  it('leaves the part as it is when the question is answered no', async () => {
    await setup({ edited: true, canUseFile: true }, false);
    host.querySelector<HTMLButtonElement>('button.use-file')!.click();
    await fixture.whenStable();
    expect(used).toBe(0);
  });
});
