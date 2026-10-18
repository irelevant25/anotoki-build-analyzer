import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { Api } from './api';
import { SyncRunSummary, SyncStepResult } from './models';
import { SyncDriver } from './sync-driver';

function run(status: SyncRunSummary['status'], done: number): SyncRunSummary {
  return {
    id: 1,
    kind: 'builds',
    game: 'hsr',
    status,
    started_at: '2026-09-27T10:00:00Z',
    finished_at: null,
    stats: null,
    progress: { done, total: 2 },
    next_step_at: null,
    last_step_at: null,
    started_by: null,
    source: { key: 'hsr-1', name: 'Source 1', game: 'hsr' },
  };
}

function step(active: boolean, did: SyncRunSummary | null, waitMs: number | null, lines: string[] = []): SyncStepResult {
  return { active, wait_ms: waitMs, runs: active && did ? [did] : [], busy: false, did, lines };
}

describe('SyncDriver', () => {
  let steps: SyncStepResult[];
  let api: { syncStep: ReturnType<typeof vi.fn>; syncStatus: ReturnType<typeof vi.fn> };
  let driver: SyncDriver;

  beforeEach(() => {
    vi.useFakeTimers();
    steps = [];
    api = { syncStep: vi.fn(() => of(steps.shift())), syncStatus: vi.fn(() => of(step(false, null, null))) };
    TestBed.configureTestingModule({ providers: [{ provide: Api, useValue: api }] });
    driver = TestBed.inject(SyncDriver);
  });

  afterEach(() => vi.useRealTimers());

  it('steps until nothing is active, waiting as long as the server asks (crawl delays)', async () => {
    steps = [step(true, run('running', 1), 10_000, ['  Kafka: 1 build(s)']), step(false, run('success', 2), null, ['Done: success.'])];

    driver.start();
    await vi.advanceTimersByTimeAsync(0);
    expect(api.syncStep).toHaveBeenCalledTimes(1);
    expect(driver.driving()).toBe(true);

    await vi.advanceTimersByTimeAsync(9_000);
    expect(api.syncStep).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(1_000);
    expect(api.syncStep).toHaveBeenCalledTimes(2);
    expect(driver.driving()).toBe(false);
    expect(driver.finished()?.status).toBe('success');
    expect(driver.lines()).toEqual(['  Kafka: 1 build(s)', 'Done: success.']);
  });

  it('stops stepping when paused, and continues when started again', async () => {
    steps = [step(true, run('running', 1), 10_000), step(false, run('success', 2), null)];

    driver.start();
    await vi.advanceTimersByTimeAsync(0);
    driver.pause();
    await vi.advanceTimersByTimeAsync(60_000);
    expect(api.syncStep).toHaveBeenCalledTimes(1);
    expect(driver.driving()).toBe(false);

    driver.start();
    await vi.advanceTimersByTimeAsync(0);
    expect(api.syncStep).toHaveBeenCalledTimes(2);
    expect(driver.active()).toBe(false);
  });
});
