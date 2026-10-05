import { Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth';
import { GAME_LABELS } from '../../core/format';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslationService } from '../../core/i18n/translation';
import { GameKey } from '../../core/models';
import { signInOrSayWhy } from '../../core/sign-in';

/**
 * What somebody sees who is neither signed in nor holds a visitor key ("nobody"): what the site does, and
 * the three ways on - import an account (which makes them a visitor), browse the builds, or sign in. On the
 * dashboard, and on an account or character page opened by a link that is not theirs to open
 * (401 sign_in_required). Nothing here asks the API for anybody's own data.
 */
@Component({
  selector: 'app-visitor-hero',
  imports: [RouterLink, MatButtonModule, MatIconModule, TranslatePipe],
  templateUrl: './visitor-hero.html',
  styleUrl: './visitor-hero.scss',
})
export class VisitorHero {
  protected readonly auth = inject(AuthService);
  private readonly i18n = inject(TranslationService);
  private readonly snackBar = inject(MatSnackBar);

  protected readonly games: GameKey[] = ['genshin', 'hsr'];
  protected readonly gameLabels = GAME_LABELS;

  /** To the IAM and back to this page; a snack bar says why when the sign-in cannot start. */
  protected signIn(): void {
    void signInOrSayWhy(this.auth, this.i18n, this.snackBar);
  }
}
