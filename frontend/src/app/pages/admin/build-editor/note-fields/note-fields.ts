import { Component, input, output } from '@angular/core';
import { uniqueId } from '@anotoki/lib/ui/dom';
import { TextareaComponent } from '@anotoki/lib/ui/forms';
import { NoteDraft } from '../editor-draft';

/**
 * A note of the site's own in the builds editor - on a build, on a team (builds.site_notes) -: its English and its
 * Slovak side by side across the card (one over the other on a phone), each the kit's textarea, named together by the
 * note's label - a group of the site's own: the kit's labelled group draws one control's width, too narrow for two
 * boxes of prose. English is the note; Slovak is optional, shown to members who read Slovak.
 *
 * The Slovak box stands in a block of `lang="sk"`, so the browser checks its spelling and a screen reader reads it as
 * Slovak - and so it is named in Slovak, "Slovenčina", as language menus name it: the kit's textarea has no `lang` of its
 * own (worth asking the library for). Its error is said under the two, outside that block, with the box marked wrong.
 *
 * `path` is where the note is in a save ("notes.build"): the boxes carry their places ("notes.build.en"), which the
 * editor's page finds a problem's field by.
 */
@Component({
  selector: 'app-note-fields',
  imports: [TextareaComponent],
  templateUrl: './note-fields.html',
  styleUrl: './note-fields.scss',
})
export class NoteFields {
  /** What the note is on: "Note on this build". */
  readonly label = input.required<string>();
  /** A quieter line under the label. */
  readonly hint = input<string | null>(null);
  readonly value = input.required<NoteDraft>();
  readonly maxlength = input.required<number>();
  /** Where the note is in a save: "notes.build", "notes.teams[0]". */
  readonly path = input.required<string>();
  /** What is wrong with each box, or null. */
  readonly errors = input<{ en: string | null; sk: string | null }>({ en: null, sk: null });
  readonly rows = input(5);

  readonly valueChange = output<NoteDraft>();

  protected readonly labelId = uniqueId('note-label');
  protected readonly hintId = uniqueId('note-hint');

  protected set(language: 'en' | 'sk', text: string): void {
    this.valueChange.emit({ ...this.value(), [language]: text });
  }
}
