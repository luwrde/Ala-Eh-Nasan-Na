(() => {
  'use strict';
  const navigation = document.querySelector('.dashboard-navigation');
  if (!navigation) return;
  const seats = navigation.querySelector('.seats-dropdown');
  if (seats) navigation.prepend(seats);
  const host = document.createElement('div');
  host.className = 'dashboard-panels';
  host.hidden = true;
  navigation.after(host);
  const items = [...navigation.querySelectorAll('.dashboard-dropdown')].map((details, index) => {
    const summary = details.querySelector('summary');
    const panel = details.querySelector('.dashboard-dropdown-content');
    const title = summary.querySelector('span').firstChild.textContent.trim();
    summary.id = `dashboard-toggle-${index}`;
    panel.id = `dashboard-panel-${index}`;
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-labelledby', summary.id);
    summary.setAttribute('aria-controls', panel.id);
    const heading = document.createElement('h2');
    heading.className = 'dashboard-panel-title';
    heading.textContent = title;
    panel.prepend(heading);
    if (details.classList.contains('seats-dropdown')) panel.classList.add('seats-dropdown');
    host.append(panel);
    return { details, summary, panel, initiallyOpen: details.open };
  });
  function show(active) {
    items.forEach((item) => {
      const open = item === active;
      item.details.open = open;
      item.summary.setAttribute('aria-expanded', String(open));
      item.panel.hidden = !open;
    });
    host.hidden = !active;
  }
  items.forEach((item) => {
    item.summary.addEventListener('click', (event) => {
      event.preventDefault();
      show(item.details.open ? null : item);
    });
  });
  // Keep validation messages and successful saves visible after a form submit.
  show(items.find((item) => item.initiallyOpen) || items.find((item) => item.details.id && location.hash === `#${item.details.id}`));
})();
