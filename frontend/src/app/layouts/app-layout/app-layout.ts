import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { SiteFooterComponent } from '@anotoki/lib/footer';
import { ShellComponent } from '@anotoki/lib/shell';
import { Changelog } from '../../core/changelog';
import { feedbackOffered } from '../../core/feedback';
import { TopBar } from '../top-bar/top-bar';

/**
 * The frame of every page outside the admin panel - open to everybody (somebody new, a visitor, a person
 * signed in): the anotoki family's frame (@anotoki/lib/shell's <anotoki-shell>: a skip link, the bar, the page in
 * <main id="main">, as wide as the bar's row) with the family's top bar and the app's sections, and the family's
 * footer at its foot (@anotoki/lib/footer, anotoki-lib 0.6.3 - this site's own footer, the owner's choice of
 * 2026-10-18): its top border; "Version X · What's new · Feedback" - the version the changelog's newest entry,
 * What's new this site's own dialog (both once the changelog has come: core/changelog.ts), Feedback the family's
 * form in the kit's dialog, only while a message can be sent (core/feedback.ts); then Terms · Privacy · Contact and
 * the fan clause. Its words are the library's (`anotoki.footer.*`, the database's strings first).
 */
@Component({
  selector: 'app-app-layout',
  imports: [RouterOutlet, ShellComponent, SiteFooterComponent, TopBar],
  templateUrl: './app-layout.html',
  styleUrl: './app-layout.scss',
})
export class AppLayout {
  protected readonly changelog = inject(Changelog);
  /** Feedback in the footer: while a message can be sent - signed in, or a visitor - and the site is ready. */
  protected readonly feedback = feedbackOffered();
}
