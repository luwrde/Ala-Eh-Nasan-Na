'use strict';

(() => {
  const root = document.documentElement, body = document.body;
  const intro = document.getElementById('login-intro');
  const scene = document.getElementById('login-scene');
  const jeepney = document.getElementById('jeepney-pull');
  const status = document.getElementById('intro-engine-status');
  if (!intro || !scene || !jeepney) { root.classList.remove('login-intro-pending'); return; }
  let mode = 'ready', startupTimer = null, travelTimer = null;
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  function position(distance) {
    root.style.setProperty('--login-drag', `${distance}px`);
    root.style.setProperty('--login-progress', String(distance / window.innerWidth));
  }
  function finish() {
    if (mode === 'complete') return;
    clearTimeout(startupTimer); clearTimeout(travelTimer); mode = 'complete';
    body.classList.remove('intro-active', 'intro-starting', 'intro-driving');
    intro.hidden = true;
    jeepney.removeAttribute('aria-disabled'); jeepney.removeAttribute('aria-busy');
    scene.inert = false; scene.removeAttribute('aria-hidden');
    for (const name of ['--login-drag', '--login-progress', '--login-rope']) root.style.removeProperty(name);
    document.getElementById('login-title')?.focus({ preventScroll: true });
  }
  function drive() {
    if (mode !== 'starting') return;
    if (motion.matches) { finish(); return; }
    mode = 'driving';
    body.classList.remove('intro-starting'); body.classList.add('intro-driving');
    if (status) status.textContent = 'Let\u2019s go! Opening sign in\u2026';
    // Both the vehicle and page use the same travel and timing, joined by the tow strap.
    scene.getBoundingClientRect();
    position(window.innerWidth);
    travelTimer = setTimeout(finish, 2600); // Fallback if transitionend is interrupted.
  }
  function start(event) {
    if (mode !== 'ready') return; // Repeated taps cannot restart or skip the sequence.
    mode = 'starting';
    if (event.detail > 0) jeepney.blur();
    jeepney.setAttribute('aria-disabled', 'true'); jeepney.setAttribute('aria-busy', 'true');
    if (motion.matches) { finish(); return; }
    body.classList.add('intro-starting');
    root.style.setProperty('--login-rope', '1');
    if (status) status.textContent = 'Starting the jeepney\u2026';
    startupTimer = setTimeout(drive, 700);
  }

  position(0);
  scene.inert = true; scene.setAttribute('aria-hidden', 'true');
  intro.hidden = false; body.classList.add('intro-active');
  root.classList.remove('login-intro-pending');
  document.getElementById('login-intro-title')?.focus({ preventScroll: true });
  // A native button click covers a phone tap, a mouse click, Enter and Space.
  jeepney.addEventListener('click', start);
  scene.addEventListener('transitionend', event => {
    if (mode === 'driving' && event.target === scene && event.propertyName === 'transform') finish();
  });
  window.addEventListener('resize', () => {
    if (mode === 'starting' || mode === 'driving') finish();
    else if (mode === 'ready') position(0);
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden && (mode === 'starting' || mode === 'driving')) finish();
  });
  motion.addEventListener?.('change', () => {
    if (motion.matches && (mode === 'starting' || mode === 'driving')) finish();
  });
})();
