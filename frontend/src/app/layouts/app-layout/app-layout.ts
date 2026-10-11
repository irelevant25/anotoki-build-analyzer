import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { ShellComponent } from '@anotoki/lib/shell';
import { Footer } from '../footer/footer';
import { TopBar } from '../top-bar/top-bar';

/**
 * The frame of every page outside the admin panel - open to everybody (somebody new, a visitor, a person
 * signed in): the anotoki family's frame (@anotoki/lib/shell's <anotoki-shell>: a skip link, the bar, the page in
 * <main id="main">, as wide as the bar's row) with the family's top bar and the app's sections, and the footer
 * (version, What's new, Feedback) at its foot.
 */
@Component({
  selector: 'app-app-layout',
  imports: [RouterOutlet, ShellComponent, TopBar, Footer],
  templateUrl: './app-layout.html',
  styleUrl: './app-layout.scss',
})
export class AppLayout {}
