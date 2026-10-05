import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { Footer } from '../footer/footer';
import { TopBar } from '../top-bar/top-bar';

/**
 * The frame of every page outside the admin panel - open to everybody (somebody new, a visitor, a person
 * signed in): the family's top bar with the app's sections, the page, and the footer (version, What's new).
 */
@Component({
  selector: 'app-app-layout',
  imports: [RouterOutlet, TopBar, Footer],
  templateUrl: './app-layout.html',
  styleUrl: './app-layout.scss',
})
export class AppLayout {}
