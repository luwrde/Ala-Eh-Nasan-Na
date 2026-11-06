import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../public/assets/login-intro.js', import.meta.url), 'utf8');
function browser({ width = 390, reduced = false, active = false, blockedStorage = false } = {}) {
  const classes = () => { const values = new Set(); return {
    add(...names) { names.forEach(name => values.add(name)); }, remove(...names) { names.forEach(name => values.delete(name)); }, contains: name => values.has(name),
  }; };
  const nodes = new Map(), styles = new Map(), timers = new Map();
  let nextTimer = 0, focused = null, clock = 0;
  const node = () => ({ events: {}, attributes: {}, hidden: true, inert: false, textContent: '',
    addEventListener(name, fn) { this.events[name] = fn; },
    setAttribute(name, value) { this.attributes[name] = value; }, removeAttribute(name) { delete this.attributes[name]; },
    focus() { focused = this; }, blur() { if (focused === this) focused = null; }, getBoundingClientRect() { return { width }; },
  });
  for (const id of ['login-intro', 'login-intro-title', 'login-scene', 'jeepney-pull', 'login-title', 'intro-engine-status']) nodes.set(id, node());
  const root = { classList: classes(), style: { setProperty: (k,v) => styles.set(k,v), removeProperty: k => styles.delete(k) } };
  root.classList.add('login-intro-pending');
  const document = { documentElement: root, body: { classList: classes() }, hidden: false, events: {},
    getElementById: id => nodes.get(id), addEventListener(name, fn) { this.events[name] = fn; } };
  const motion = { matches: reduced, addEventListener(type, fn) { this.changed = fn; } };
  const window = { innerWidth: width, events: {}, matchMedia: () => motion, addEventListener(name, fn) { this.events[name] = fn; } };
  vm.runInNewContext(source, { document, window, sessionStorage: { getItem() { if (blockedStorage) throw new Error('blocked'); return active ? 'active' : null; } },
    setTimeout(fn, ms) { const id = ++nextTimer; timers.set(id, {fn,at:clock+ms}); return id; }, clearTimeout: id => timers.delete(id) });
  const event = (name, data = {}) => nodes.get('jeepney-pull').events[name]?.({ detail: 1, preventDefault() {}, ...data });
  function runNext() {
    const first = [...timers].sort((a,b) => a[1].at-b[1].at)[0];
    if (first) { timers.delete(first[0]); clock=first[1].at; first[1].fn(); }
  }
  function advance(ms) { const end=clock+ms; while([...timers.values()].some(timer=>timer.at<=end))runNext(); clock=end; }
  return { nodes, root, document, window, styles, motion, timers, event, runNext, advance, focused: () => focused };
}

test('intro starts before login, blocks hidden form focus, and needs no browser storage', () => {
  const app = browser({ blockedStorage: true });
  assert.equal(app.nodes.get('login-intro').hidden, false);
  assert.equal(app.nodes.get('login-scene').inert, true);
  assert.equal(app.nodes.get('login-scene').attributes['aria-hidden'], 'true');
  assert.equal(app.document.body.classList.contains('intro-active'), true);
  assert.equal(app.root.classList.contains('login-intro-pending'), false);
  assert.equal(app.focused(), app.nodes.get('login-intro-title'));
  assert.equal(app.timers.size, 0, 'nothing starts until the user taps');
});
test('one tap starts the engine before the jeepney slowly pulls in the original login page', () => {
  const app = browser(); app.event('click');
  assert.equal(app.document.body.classList.contains('intro-starting'), true);
  assert.match(app.nodes.get('intro-engine-status').textContent, /Starting the jeepney/);
  assert.equal(app.nodes.get('jeepney-pull').attributes['aria-busy'], 'true');
  assert.equal(app.styles.get('--login-drag'), '0px');
  app.advance(100);
  assert.equal(app.styles.get('--login-drag'), '0px', 'the engine starts while the vehicle remains parked');
  app.runNext();
  assert.equal(app.document.body.classList.contains('intro-starting'), false);
  assert.equal(app.document.body.classList.contains('intro-driving'), true);
  assert.equal(app.styles.get('--login-drag'), '390px');
  assert.equal(app.styles.get('--login-rope'), '1');
  const scene = app.nodes.get('login-scene');
  assert.equal(scene.inert, true, 'login stays inaccessible until the animation completes');
  scene.events.transitionend({target:scene,propertyName:'opacity'});
  assert.equal(scene.inert, true);
  scene.events.transitionend({target:scene,propertyName:'transform'});
  assert.equal(app.nodes.get('login-intro').hidden, true);
  assert.equal(scene.inert, false);
  assert.equal(scene.attributes['aria-hidden'], undefined);
  assert.equal(app.styles.size, 0);
  assert.equal(app.timers.size, 0);
  assert.equal(app.focused(), app.nodes.get('login-title'));
});
test('repeated taps cannot restart the engine or prematurely finish the journey', () => {
  const app = browser(); app.event('click'); app.advance(300); app.event('click');
  assert.equal(app.timers.size, 1);
  app.runNext(); app.event('click');
  assert.equal(app.document.body.classList.contains('intro-driving'), true);
  assert.equal(app.nodes.get('login-scene').inert, true);
  assert.equal(app.timers.size, 1);
  app.runNext();
  assert.equal(app.nodes.get('login-scene').inert, false, 'fallback finishes if no transitionend arrives');
});
test('tap, mouse and native keyboard button activation share the sequence without a drag requirement', () => {
  for (const [width, detail] of [[390,1],[1440,1],[390,0]]) {
    const app = browser({width});
    app.event('pointerdown'); app.event('pointermove', {clientX:10}); app.event('pointerup');
    assert.equal(app.timers.size, 0, 'old drag handlers are removed');
    app.event('click', {detail}); app.runNext();
    assert.equal(app.styles.get('--login-drag'), `${width}px`);
    app.runNext(); assert.equal(app.nodes.get('login-scene').inert, false);
  }
});
test('reduced motion opens login immediately after activation without engine or travel timers', () => {
  const app = browser({reduced:true}); app.event('click', {detail:0});
  assert.equal(app.nodes.get('login-scene').inert, false);
  assert.equal(app.nodes.get('login-intro').hidden, true);
  assert.equal(app.timers.size, 0);
  assert.equal(app.focused(), app.nodes.get('login-title'));
});
test('rotation or leaving the page during startup or travel safely completes the transition', () => {
  for (const driving of [false,true]) {
    for (const interrupt of ['resize','hidden']) {
      const app = browser(); app.event('click'); if(driving)app.runNext();
      if(interrupt==='resize'){app.window.innerWidth=844;app.window.events.resize();}
      else{app.document.hidden=true;app.document.events.visibilitychange();}
      assert.equal(app.nodes.get('login-scene').inert, false);
      assert.equal(app.timers.size, 0);
    }
  }
});
test('changing to reduced motion mid-animation finishes immediately', () => {
  const app=browser();app.event('click');app.runNext();
  app.motion.matches=true;app.motion.changed();
  assert.equal(app.nodes.get('login-scene').inert,false);
  assert.equal(app.timers.size,0);
});
test('existing sessions keep their Map redirect and never start an intro', () => {
  const app=browser({active:true});
  assert.equal(app.nodes.get('login-scene').inert,false);
  assert.equal(app.nodes.get('login-intro').hidden,true);
  assert.equal(app.root.classList.contains('login-intro-pending'),false);
  assert.equal(app.styles.size,0);
  app.event('click');assert.equal(app.timers.size,0);
});
