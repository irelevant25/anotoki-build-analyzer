import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { Api } from '../../../core/api';
import { EditorCatalog, GameKey } from '../../../core/models';

/**
 * What the builds editor picks from and holds a build to, per game (GET /api/admin/builds/{game}/catalog): the game's
 * facts and the catalog's characters, weapons and sets. Asked for once while the app lives - the Builds page's
 * character picker and the editor's page share it -, and again after the catalog was refreshed (forget()). A failed
 * answer is not kept: the next ask asks again.
 */
@Injectable({ providedIn: 'root' })
export class EditorCatalogs {
  private readonly api = inject(Api);
  private readonly answers = new Map<GameKey, Promise<EditorCatalog>>();

  get(game: GameKey): Promise<EditorCatalog> {
    let answer = this.answers.get(game);
    if (!answer) {
      answer = firstValueFrom(this.api.editorCatalog(game));
      this.answers.set(game, answer);
      answer.catch(() => {
        if (this.answers.get(game) === answer) {
          this.answers.delete(game);
        }
      });
    }
    return answer;
  }

  /** The game's catalog changed (refreshed on the Builds page): the next ask asks the server. */
  forget(game: GameKey): void {
    this.answers.delete(game);
  }
}
