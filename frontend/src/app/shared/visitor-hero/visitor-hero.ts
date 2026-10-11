import { Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth';
import { GAME_LABELS } from '../../core/format';
import { TranslatePipe } from '../../core/i18n/translate';
import { GameKey } from '../../core/models';
import { signInOrSayWhy } from '../../core/sign-in';

/**
 * What somebody sees who is neither signed in nor holds a visitor key ("nobody"): what the site does, and
 * the three ways on - import an account (which makes them a visitor), browse the builds, or sign in. On the
 * dashboard, and on an account or character page opened by a link that is not theirs to open
 * (401 sign_in_required). Nothing here asks the API for anybody's own data.
 *
 * Somebody signed in at the IAM in this browser seldom sees it: the IAM's sign-in hint signs them in here before
 * the first page is drawn (the auth module's one silent hop, app.config.ts).
 */
@Component({
  selector: 'app-visitor-hero',
  imports: [RouterLink, MatButtonModule, MatIconModule, TranslatePipe],
  templateUrl: './visitor-hero.html',
  styleUrl: './visitor-hero.scss',
})
export class VisitorHero {
  protected readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly games: GameKey[] = ['genshin', 'hsr'];
  protected readonly gameLabels = GAME_LABELS;

  /** To the IAM and back to this page; the failure's screen says why when the sign-in cannot start. */
  protected signIn(): void {
    void signInOrSayWhy(this.auth, this.router);
  }
}
