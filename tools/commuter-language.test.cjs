const test = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const language = readFileSync('public/commuter/assets/language.js', 'utf8');
const preferences = readFileSync('public/commuter/assets/preferences.js', 'utf8');
function browser(saved = null) {
  let value = saved;
  const document = { documentElement: { dataset: {} }, addEventListener() {} };
  const window = { matchMedia: () => ({ matches: true, addEventListener() {} }) };
  const context = vm.createContext({ window, document,
    localStorage: { getItem: () => value, setItem: (_, next) => { value = next; } },
  });
  vm.runInContext(language, context);
  vm.runInContext(preferences, context);
  return { ui: window.PassengerUI, language: window.PassengerLanguage, document, saved: () => value };
}
test('new and existing commuter preferences default to English without losing the chosen theme', () => {
  const fresh = browser();
  assert.equal(fresh.ui.getPreferences().language, 'en');
  assert.equal(fresh.ui.t('Change password'), 'Change password');
  const existing = browser(JSON.stringify({ name: 'Ana', theme: 'dark' }));
  assert.equal(existing.ui.getPreferences().name, 'Ana');
  assert.equal(existing.ui.getPreferences().language, 'en');
  assert.equal(existing.document.documentElement.dataset.theme, 'dark');
});
test('language persists between pages and profile saves preserve it', () => {
  const first = browser();
  first.ui.savePreferences({ language: 'fil', name: 'Ana', theme: 'system' });
  const next = browser(first.saved());
  assert.equal(next.document.documentElement.lang, 'fil');
  assert.equal(next.ui.t('Change password'), 'Palitan ang password');
  assert.equal(next.ui.t('View route for {name}', { name: 'Ana' }), 'Tingnan ang ruta ni Ana');
  next.ui.savePreferences({ name: 'Rosa', theme: 'light' });
  assert.equal(next.ui.getPreferences().language, 'fil');
  next.ui.savePreferences({ language: 'en' });
  assert.equal(next.document.documentElement.lang, 'en');
  assert.equal(next.ui.t('Change password'), 'Change password');
});
test('unsupported language settings fall back and unknown names are unchanged', () => {
  const app = browser(JSON.stringify({ language: 'invalid', theme: 'light' }));
  assert.equal(app.ui.getPreferences().language, 'en');
  assert.equal(app.language.translate('Grand Terminal', 'fil'), 'Grand Terminal');
  assert.equal(app.language.translate('Mga Setting', 'en'), 'Settings');
  assert.equal(browser('{bad json').ui.getPreferences().language, 'en');
});
