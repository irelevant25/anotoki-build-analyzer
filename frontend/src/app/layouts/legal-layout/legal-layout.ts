import { ChangeDetectionStrategy, Component } from '@angular/core';
import { ANOTOKI_LEGAL_CONFIG } from '@anotoki/lib/legal';
import { legalPagesConfig } from '../../core/legal-facts';
import { AppLayout } from '../app-layout/app-layout';

/**
 * The legal pages' frame - Terms, Privacy and Contact, the anotoki family's pages (@anotoki/lib/legal/pages, each
 * a lazy route of its own in app.routes.ts): everybody's pages' frame (layouts/app-layout: the family's shell, the
 * top bar with the app's sections, the footer with the links to these pages), which draws the page in its router
 * outlet - with this site's facts (core/legal-facts.ts), which only the pages read: they load with the first of
 * them that is opened, out of the first load. The About and Support pages (@anotoki/lib/legal/about, /legal/support,
 * anotoki-lib 0.8.0) are drawn in it too, as the library asks: they read none of the facts.
 *
 * Anybody may read them, signed in or not, a visitor or not - also while the IAM requires signing in here: what a
 * site keeps is said before anybody signs in to it. So no guard stands in front of them (app.routes.ts), and
 * nothing here asks for a sign-in or for anybody's game accounts. While the site is not ready, the family's status
 * page stands instead of them, as instead of every page (the site gate in app.html).
 */
@Component({
  selector: 'app-legal-layout',
  imports: [AppLayout],
  providers: [{ provide: ANOTOKI_LEGAL_CONFIG, useFactory: legalPagesConfig }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './legal-layout.html',
  styleUrl: './legal-layout.scss',
})
export class LegalLayout {}
