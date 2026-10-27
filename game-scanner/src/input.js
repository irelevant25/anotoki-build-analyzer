import { Button, Key, keyboard, mouse, Point, Region, screen } from '@nut-tree-fork/nut-js';
import { SETTINGS } from './config.js';
import { frameFromNutImage } from './frame.js';

mouse.config.autoDelayMs = 0;
keyboard.config.autoDelayMs = 0;

export class Stopped extends Error {}

// Cancellation shared by everything the scan waits on: once stop() is called, the next check throws.
export class StopSignal {
  stopped = false;
  stop() {
    this.stopped = true;
  }
  check() {
    if (this.stopped) throw new Stopped('stopped');
  }
}

export async function sleep(ms, signal) {
  signal?.check();
  await new Promise((resolve) => setTimeout(resolve, ms));
  signal?.check();
}

export async function screenSize() {
  return { width: await screen.width(), height: await screen.height() };
}

export async function grab(rect) {
  if (!rect) return frameFromNutImage(await screen.grab(), 0, 0);
  const image = await screen.grabRegion(new Region(rect.x, rect.y, rect.width, rect.height));
  return frameFromNutImage(image, rect.x, rect.y);
}

export async function click(x, y, signal) {
  await mouse.setPosition(new Point(x, y));
  await sleep(SETTINGS.afterMoveDelayMs, signal);
  await mouse.pressButton(Button.LEFT);
  await sleep(SETTINGS.clickHoldMs);
  await mouse.releaseButton(Button.LEFT);
}

// Positive notches scroll down, negative up. One notch at a time, as a real wheel would send them.
export async function scroll(notches, signal) {
  for (let i = 0; i < Math.abs(notches); i++) {
    if (notches > 0) await mouse.scrollDown(SETTINGS.wheelDelta);
    else await mouse.scrollUp(SETTINGS.wheelDelta);
    await sleep(SETTINGS.scrollTickDelayMs, signal);
  }
}

// Presses and releases a key by its name ('E').
export async function pressKey(name, signal) {
  await keyboard.pressKey(Key[name]);
  await sleep(SETTINGS.keyHoldMs);
  await keyboard.releaseKey(Key[name]);
  signal?.check();
}

export async function moveTo(x, y) {
  await mouse.setPosition(new Point(x, y));
}
