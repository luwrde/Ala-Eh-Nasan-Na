import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../public/assets/preferences.js', import.meta.url), 'utf8');
const settingsSource = await readFile(new URL('../public/assets/settings.js', import.meta.url), 'utf8');
const settingsHtml = await readFile(new URL('../public/user-settings.html', import.meta.url), 'utf8');
const key = 'alaEhPassengerPreferences';

// A small DOM adapter exercises the actual settings handlers without an external test dependency.
function browser(stored = null, storageBlocked = false, settingsPage = false) {
  const ids = new Map(), nodes = [], listeners = {}, windowEvents = {};
  let saved = stored, focused = null;
  class Node {
    constructor(tag = 'div') { this.tag = tag; this.children = []; this.attrs = {}; this.events = {}; this.textContent = ''; this.value = ''; this.hidden = false; this.isConnected = true; nodes.push(this); }
    set id(value) { this._id = value; ids.set(value, this); }
    get id() { return this._id; }
    set textContent(value) { this._text = value; this.children = []; }
    get textContent() { return this._text; }
    set innerHTML(markup) { for (const match of markup.matchAll(/<(\w+)[^>]*\bid="([^"]+)"[^>]*>/g)) { const node = new Node(match[1]); node.id = match[2]; } }
    append(...children) { this.children.push(...children); }
    querySelector(selector) { return selector === 'img' ? this.children.find(child => child.tag === 'img') || null : null; }
    setAttribute(name, value) { this.attrs[name] = value; }
    getAttribute(name) { return this.attrs[name] ?? null; }
    addEventListener(name, fn) { this.events[name] = fn; }
    focus() { focused = this; }
    showModal() { this.open = true; }
    close() { this.open = false; }
    reportValidity() { this.reported = true; return true; }
    setCustomValidity(message) { this.validationMessage = message; }
    getBoundingClientRect() { return { left: 0, right: 420, top: 0, bottom: 500 }; }
  }
  const profile = new Node(), name = new Node(), avatar = new Node(), root = { dataset: {}, lang: 'en' };
  const menu = new Node(); menu.id = 'passenger-menu'; menu.setAttribute('aria-label', 'Open navigation'); menu.setAttribute('aria-expanded', 'false');
  const search = new Node('input'); search.id = 'terminal-search'; search.setAttribute('placeholder', 'Name, landmark, or municipality');
  const text = new Node(); text.textContent = 'Where are you headed?'; text.parentElement = { closest: () => null };
  const pageTitle = new Node(); pageTitle.textContent = settingsPage ? 'Settings' : 'Dashboard';
  if (settingsPage) {
    for (const match of settingsHtml.matchAll(/<(\w+)[^>]*\bid="([^\"]+)"[^>]*>/g)) {
      if (!ids.has(match[2])) { const node = new Node(match[1]); node.id = match[2]; }
    }
  }
  const document = {
    documentElement: root, body: new Node('body'),
    createElement: tag => new Node(tag), getElementById: id => ids.get(id) || null,
    querySelector: selector => selector === '.passenger-profile' ? profile : selector === '.page-title' ? pageTitle : null,
    querySelectorAll(selector) {
      if (selector === '.passenger-profile-name') return [name];
      if (selector === '.passenger-profile-avatar') return [avatar];
      const attribute = selector.slice(1, -1);
      return nodes.filter(node => Object.hasOwn(node.attrs, attribute));
    },
    createTreeWalker() { let visited = false; return { currentNode: text, nextNode() { if (visited) return false; visited = true; return true; } }; },
    addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
    dispatchEvent(event) { (listeners[event.type] || []).forEach(fn => fn(event)); },
  };
  const media = { matches: true, addEventListener(type, fn) { this.change = fn; } };
  const window = { matchMedia: () => media, addEventListener(type, fn) { windowEvents[type] = fn; } };
  const localStorage = { getItem: () => saved, setItem(name, value) { assert.equal(name, key); if (storageBlocked) throw new Error('Denied'); saved = value; } };
  const context = vm.createContext({ document, window, localStorage, NodeFilter: { SHOW_TEXT: 4 }, CustomEvent: class { constructor(type) { this.type = type; } }, setTimeout: () => 1, clearTimeout() {} });
  vm.runInContext(source, context);
  if (settingsPage) vm.runInContext(settingsSource, context);
  document.dispatchEvent({ type: 'DOMContentLoaded' });
  const edit = profile.children[0];
  const click = node => node.events.click({ target: node });
  return { root, name, avatar, text, window, media, ids, edit, document,
    open: () => click(edit), cancel: () => click(ids.get('profile-cancel')),
    save(displayName, theme = 'dark') {
      ids.get('profile-name').value = displayName;
      ids.get('profile-theme').value = theme;
      ids.get('profile-settings-form').events.submit({ preventDefault() {} });
    },
    stored: () => saved, focused: () => focused, storageChanged(value) { saved = value; windowEvents.storage({ key }); },
  };
}

test('pencil opens dialog; saving updates name, initials, theme, and persistent preferences in English', () => {
  const app = browser();
  assert.equal(app.edit.getAttribute('aria-label'), 'Edit profile');
  assert.equal(app.name.textContent, 'Demo passenger');
  assert.equal(app.text.textContent, 'Where are you headed?');
  assert.equal(app.ids.has('profile-dialect'), false);
  app.open();
  assert.equal(app.ids.get('profile-settings').open, true);
  app.save('  Juan   dela Cruz  ');
  assert.equal(app.name.textContent, 'Juan dela Cruz');
  assert.equal(app.avatar.textContent, 'JD');
  assert.equal(app.root.dataset.theme, 'dark');
  assert.equal(app.root.lang, 'en');
  assert.equal(app.text.textContent, 'Where are you headed?');
  assert.equal(app.ids.get('profile-settings').open, false);
  assert.equal(app.focused(), app.edit);
  assert.equal(app.window.PassengerUI.t('Showing {shown} of {total} locations', { shown: 3, total: 6 }), 'Showing 3 of 6 locations');
  assert.deepEqual(JSON.parse(app.stored()), { name: 'Juan dela Cruz', theme: 'dark' });
  const nextPage = browser(app.stored());
  assert.equal(nextPage.name.textContent, 'Juan dela Cruz');
  assert.equal(nextPage.root.dataset.theme, 'dark');
  assert.equal(nextPage.text.textContent, 'Where are you headed?');
});

test('Settings page saves through the shared preferences and stays synchronized with the pencil editor', () => {
  const app = browser(JSON.stringify({ name: 'Ana', theme: 'light' }), false, true);
  assert.equal(app.document.title, 'Settings | Ala-Eh Nasaan Na?');
  assert.equal(app.ids.get('settings-name').value, 'Ana');
  app.ids.get('settings-name').value = '  Rosa   Santos  ';
  app.ids.get('settings-theme').value = 'dark';
  app.ids.get('passenger-settings').events.submit({ preventDefault() {} });
  assert.deepEqual(JSON.parse(app.stored()), { name: 'Rosa Santos', theme: 'dark' });
  assert.equal(app.name.textContent, 'Rosa Santos');
  assert.equal(app.avatar.textContent, 'RS');
  assert.equal(app.root.dataset.theme, 'dark');
  assert.equal(app.ids.get('settings-status').textContent, 'Settings saved.');
  app.open(); app.save('Miguel', 'system');
  assert.equal(app.ids.get('settings-name').value, 'Miguel');
  assert.equal(app.ids.get('settings-theme').value, 'system');
  app.media.matches = false; app.media.change();
  assert.equal(app.root.dataset.theme, 'light');
  app.storageChanged(JSON.stringify({ name: 'Juan', theme: 'dark' }));
  assert.equal(app.ids.get('settings-name').value, 'Juan');
  assert.equal(app.ids.get('settings-theme').value, 'dark');
});

test('Settings page discards edits, rejects empty names, and reports failed saves without applying them', () => {
  const app = browser(JSON.stringify({ name: 'Ana', theme: 'light' }), false, true);
  app.ids.get('settings-name').value = 'Unsaved';
  app.ids.get('settings-theme').value = 'dark';
  app.ids.get('settings-cancel').events.click();
  assert.equal(app.ids.get('settings-name').value, 'Ana');
  assert.equal(app.ids.get('settings-theme').value, 'light');
  app.ids.get('settings-name').value = '   ';
  app.ids.get('passenger-settings').events.submit({ preventDefault() {} });
  assert.ok(app.ids.get('settings-name').validationMessage);
  assert.equal(app.name.textContent, 'Ana');
  const blocked = browser(null, true, true);
  blocked.ids.get('settings-name').value = 'Unsaved';
  blocked.ids.get('settings-theme').value = 'dark';
  blocked.ids.get('passenger-settings').events.submit({ preventDefault() {} });
  assert.equal(blocked.ids.get('settings-error').hidden, false);
  assert.equal(blocked.ids.get('settings-status').textContent, '');
  assert.equal(blocked.name.textContent, 'Demo passenger');
  assert.equal(blocked.root.dataset.theme, 'light');
});
test('cancel and invalid names leave saved settings unchanged', () => {
  const app = browser();
  app.open();
  app.ids.get('profile-name').value = 'Discard me';
  app.cancel();
  assert.equal(app.name.textContent, 'Demo passenger');
  assert.equal(app.stored(), null);
  app.open();
  app.save('   ');
  assert.ok(app.ids.get('profile-name').validationMessage);
  assert.equal(app.ids.get('profile-settings').open, true);
  assert.equal(app.stored(), null);
});

test('profile pictures persist across pages and Settings saves; removal restores initials only after saving', () => {
  const photo = 'data:image/png;base64,aGVsbG8=';
  const saved = JSON.stringify({ name: 'Ana Cruz', theme: 'dark', photo });
  const app = browser(saved, false, true);
  assert.equal(app.avatar.querySelector('img').src, photo);
  app.ids.get('settings-name').value = 'Rosa Santos';
  app.ids.get('passenger-settings').events.submit({ preventDefault() {} });
  assert.equal(JSON.parse(app.stored()).photo, photo, 'saving the Settings form keeps the existing picture');
  const nextPage = browser(app.stored());
  assert.equal(nextPage.avatar.querySelector('img').src, photo);
  nextPage.open();
  nextPage.ids.get('profile-photo-remove').events.click();
  assert.equal(nextPage.ids.get('profile-photo-preview').textContent, 'RS');
  nextPage.cancel();
  assert.equal(JSON.parse(nextPage.stored()).photo, photo, 'Cancel does not remove the saved picture');
  nextPage.open();
  assert.equal(nextPage.ids.get('profile-photo-preview').querySelector('img').src, photo);
  nextPage.ids.get('profile-photo-remove').events.click();
  nextPage.save('Rosa Santos', 'dark');
  assert.equal(nextPage.avatar.querySelector('img'), null);
  assert.equal(nextPage.avatar.textContent, 'RS');
  assert.equal(Object.hasOwn(JSON.parse(nextPage.stored()), 'photo'), false);
});

test('external or unsupported profile picture data falls back to initials', () => {
  for (const photo of ['https://example.com/photo.jpg', 'data:image/svg+xml;base64,PHN2Zz4=', 'data:image/png;base64,invalid!']) {
    const app = browser(JSON.stringify({ name: 'Ana Cruz', theme: 'light', photo }));
    assert.equal(app.avatar.textContent, 'AC');
    assert.equal(app.avatar.querySelector('img'), null);
    assert.equal(Object.hasOwn(app.window.PassengerUI.getPreferences(), 'photo'), false);
  }
});
test('storage failures are visible and do not pretend to save', () => {
  const app = browser(null, true);
  app.open(); app.save('Unsaved');
  assert.equal(app.name.textContent, 'Demo passenger');
  assert.equal(app.ids.get('profile-error').hidden, false);
  assert.match(app.ids.get('profile-error').textContent, /Could not save/);
  assert.equal(app.ids.get('profile-settings').open, true);
});
test('device theme follows the device; saved changes synchronize across tabs', () => {
  const app = browser(JSON.stringify({ name: 'Ana', theme: 'system', dialect: 'bat' }));
  assert.equal(app.root.dataset.theme, 'dark');
  app.media.matches = false; app.media.change();
  assert.equal(app.root.dataset.theme, 'light');
  app.storageChanged(JSON.stringify({ name: 'Rosa', theme: 'light', dialect: 'en' }));
  assert.equal(app.name.textContent, 'Rosa');
  assert.equal(app.text.textContent, 'Where are you headed?');
  assert.equal(app.root.lang, 'en');
});
test('corrupt preferences fall back safely; names stay text instead of markup', () => {
  const app = browser('invalid JSON');
  assert.equal(app.root.dataset.theme, 'light');
  app.open(); app.save('<img src=x onerror=alert(1)>', 'light');
  assert.equal(app.name.textContent, '<img src=x onerror=alert(1)>');
  assert.equal(app.name.children.length, 0);
});

test('legacy dialect preferences cannot change English wording or the preserved dashboard banner', async () => {
  for (const dialect of ['original', 'en', 'fil', 'bat']) {
    const app = browser(JSON.stringify({ name: 'Ana', theme: 'dark', dialect }));
    assert.equal(app.name.textContent, 'Ana');
    assert.equal(app.root.dataset.theme, 'dark');
    assert.equal(app.root.lang, 'en');
    assert.equal(app.window.PassengerUI.t('Passenger'), 'Passenger');
    assert.equal(app.ids.has('profile-dialect'), false);
    app.open(); app.save('Ana', 'dark');
    assert.equal(Object.hasOwn(JSON.parse(app.stored()), 'dialect'), false);
  }
  const dashboard = await readFile(new URL('../public/user-dashboard.html', import.meta.url), 'utf8');
  assert.match(dashboard, /Aba'y Tara Na, <span>Biyahe Na!<\/span>/);
  assert.match(dashboard, /Hanapin ang tamang ruta, alamin ang pamasahe, at bumiyahe nang walang abala\./);
});
