import { safePath } from './sign-in-page';

describe('safePath (where a sign-in comes back to)', () => {
  it('keeps paths on this site', () => {
    expect(safePath('/accounts/3?build=7')).toBe('/accounts/3?build=7');
    expect(safePath('/')).toBe('/');
  });

  it('refuses anything that could lead elsewhere', () => {
    for (const value of [undefined, '', 'https://elsewhere.example/', '//elsewhere.example', '/\\elsewhere.example', '/\t/elsewhere.example', '/\n/elsewhere.example', 'accounts']) {
      expect(safePath(value)).toBe('/');
    }
  });
});
