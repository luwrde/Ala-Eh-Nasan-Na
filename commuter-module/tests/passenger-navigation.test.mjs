import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../public/assets/user.js', import.meta.url), 'utf8');
function passengerPage(includeSettings) {
  const requests = [];
  const redirects = [], nodes = new Map();
  const classes = () => { const values = new Set(); return { add: item => values.add(item), remove: item => values.delete(item), contains: item => values.has(item) }; };
  const document = { body: { classList: classes() }, events: {}, activeElement: null,
    getElementById: id => nodes.get(id) || null, addEventListener(event, callback) { this.events[event] = callback; } };
  const node = () => ({ events: {}, attributes: {}, classList: classes(), hidden: true,
    addEventListener(event, callback) { this.events[event] = callback; },
    setAttribute(name, value) { this.attributes[name] = value; },
    focus() { document.activeElement = this; }, contains(target) { return target === this; } });
  for (const id of ['passenger-logout', 'passenger-menu', 'passenger-menu-close', 'passenger-backdrop', ...(includeSettings ? ['settings-logout'] : [])]) {
    nodes.set(id, node());
  }
  const links = [node(), node(), node(), node()];
  const sidebar = node(), content = node();
  sidebar.querySelector = () => links[includeSettings ? 3 : 2];
  sidebar.querySelectorAll = selector => selector === 'a' ? links : [nodes.get('passenger-menu-close'), ...links, nodes.get('passenger-logout')];
  sidebar.contains = target => target === sidebar || links.includes(target) || target === nodes.get('passenger-menu-close');
  nodes.set('passenger-sidebar', sidebar);
  document.querySelector = selector => selector === '.app-content' ? content : null;
  vm.runInNewContext(source, {
    window: { location: { replace: path => redirects.push(path) }, addEventListener() {} },
    document,
    fetch: async (url, options = {}) => { requests.push({ url, options }); return { ok: true, json: async () => ({}) }; },
  });
  return { nodes, redirects, document, content, links, requests };
}

test('sidebar and Settings logout call the server session and return to login', async () => {
  for (const [button, settingsPage] of [['passenger-logout', false], ['settings-logout', true]]) {
    const app = passengerPage(settingsPage);
    app.nodes.get(button).events.click();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(app.requests.some(request => request.url === 'api/auth.php?action=logout'), true);
    assert.deepEqual(app.redirects, ['user-login.html']);
  }
});

test('modules start hidden and open only from the menu; close, backdrop and Escape restore focus', () => {
  const app = passengerPage(false);
  const menu = app.nodes.get('passenger-menu'), drawer = app.nodes.get('passenger-sidebar'), backdrop = app.nodes.get('passenger-backdrop');
  assert.equal(drawer.inert, true);
  assert.equal(menu.attributes['aria-expanded'], 'false');
  menu.events.click();
  assert.equal(drawer.inert, false);
  assert.equal(drawer.classList.contains('mobile-open'), true);
  assert.equal(backdrop.hidden, false);
  assert.equal(app.content.inert, true);
  assert.equal(app.document.activeElement, app.links[2]);
  const event = key => ({ key, preventDefault() { this.defaultPrevented = true; } });
  app.document.events.keydown(event('Escape'));
  assert.equal(drawer.inert, true);
  assert.equal(backdrop.hidden, true);
  assert.equal(app.content.inert, false);
  assert.equal(app.document.activeElement, menu);
  menu.events.click(); backdrop.events.click();
  assert.equal(drawer.inert, true);
  menu.events.click(); app.nodes.get('passenger-menu-close').events.click();
  assert.equal(drawer.inert, true);
  menu.events.click();
  app.document.activeElement = app.nodes.get('passenger-logout');
  app.document.events.keydown(event('Tab'));
  assert.equal(app.document.activeElement, app.nodes.get('passenger-menu-close'));
});

test('every passenger page keeps all four modules inside a closed drawer with its current-page marker', async () => {
  for (const page of ['user-dashboard.html', 'user-terminals.html', 'user-terminal-details.html', 'user-map.html', 'user-settings.html']) {
    const html = await readFile(new URL(`../public/${page}`, import.meta.url), 'utf8');
    const menu = html.match(/<div class="sidebar-menu">([\s\S]*?)<\/div>/)[1];
    for (const target of ['user-dashboard.html', 'user-terminals.html', 'user-map.html', 'user-settings.html']) assert.ok(menu.includes(`href="${target}"`), page);
    assert.equal([...menu.matchAll(/aria-current="page"/g)].length, 1, page);
    assert.match(html, /assets\/drawer-navigation\.css\?v=\d+/);
    assert.doesNotMatch(html, /assets\/mobile-navigation\.css/);
    assert.match(html, /id="passenger-sidebar"[^>]*aria-hidden="true"[^>]*inert/);
    assert.match(html, /id="passenger-menu-close"/);
    assert.match(html, /id="passenger-backdrop"/);
    const stylesheets = [...html.matchAll(/<link\b[^>]*rel="stylesheet"[^>]*>/g)].map(match => match[0]);
    assert.match(stylesheets.at(-1), page === 'user-map.html' ? /assets\/map-experience\.css\?v=\d+/ : /assets\/drawer-navigation\.css\?v=\d+/, `${page}: navigation/map layout loads after the base styles`);
    assert.match(html, /viewport-fit=cover/);
  }
});

test('registered commuter sign-in opens Map', async () => {
  const html = await readFile(new URL('../public/user-login.html', import.meta.url), 'utf8');
  const ids = new Map(), destinations = [];
  for (const match of html.matchAll(/\bid="([^"]+)"/g)) ids.set(match[1], { value: '', hidden: false, events: {}, reset() {}, focus() {}, addEventListener(event, callback) { this.events[event] = callback; } });
  ids.get('email').value = 'registered@example.com'; ids.get('password').value = 'Registered123!';
  vm.runInNewContext(source, {
    document: { getElementById: id => ids.get(id) || null, querySelectorAll: () => [], addEventListener() {}, querySelector: () => ({ textContent: '' }) },
    window: { addEventListener() {}, location: { assign: page => destinations.push(page), replace: page => destinations.push(page) } },
    fetch: async (url) => ({ ok: url.includes('action=login'), json: async () => ({}) }),
  });
  ids.get('passenger-login').events.submit({ preventDefault() {} });
  await new Promise(resolve => setTimeout(resolve, 0));
  assert.deepEqual(destinations, ['user-map.html']);
});
