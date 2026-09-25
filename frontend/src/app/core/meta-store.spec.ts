import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { MetaStore } from './meta-store';
import { Meta } from './models';

const META: Meta = {
  version: '0.1.0',
  games: [
    {
      key: 'genshin',
      label: 'Genshin Impact',
      slots: [{ key: 'sands', label: 'Sands of Eon', variable: true }],
      weapon_label: 'Weapon',
      item_label: 'Artifact',
      rank_label: 'Constellation',
      refinement_prefix: 'R',
      rank_prefix: 'C',
      elements: ['pyro'],
      weapon_types: ['polearm'],
      stats: {
        critRate_: { label: 'CRIT Rate', short: 'CR', percent: true },
        hp: { label: 'HP', short: 'HP', percent: false },
      },
      talents: { burst: 'Elemental Burst' },
    },
  ],
};

describe('MetaStore', () => {
  let store: MetaStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    store = TestBed.inject(MetaStore);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('labels and formats stats with the loaded metadata', async () => {
    const loaded = store.load();
    http.expectOne('api/meta').flush(META);
    await loaded;

    expect(store.loaded()).toBe(true);
    expect(store.statLabel('genshin', 'critRate_')).toBe('CRIT Rate');
    expect(store.statLabel('genshin', 'critRate_', true)).toBe('CR');
    expect(store.formatStat('genshin', 'critRate_', 31.1)).toBe('31.1%');
    expect(store.formatStat('genshin', 'hp', 4780)).toBe('4,780');
    expect(store.slotLabel('genshin', 'sands')).toBe('Sands of Eon');
    expect(store.talentLabel('genshin', 'burst')).toBe('Elemental Burst');
  });

  it('falls back to raw keys when the backend is unreachable', async () => {
    const loaded = store.load();
    http.expectOne('api/meta').error(new ProgressEvent('error'));
    await loaded;

    expect(store.loaded()).toBe(false);
    expect(store.statLabel('genshin', 'critRate_')).toBe('critRate_');
    expect(store.formatStat('hsr', 'spd', 134.5)).toBe('134.5');
  });
});
