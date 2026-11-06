(() => {
  'use strict';
  const search = document.getElementById('location-search');
  const filter = document.getElementById('location-filter');
  if (!search || !filter) return;
  const rows = [...document.querySelectorAll('[data-location-row]')];
  function applyFilters() {
    const query = search.value.trim().toLocaleLowerCase();
    let visible = 0;
    rows.forEach(row => {
      const matchesState = filter.value === 'all' || (filter.value === 'unplaced' ? row.dataset.unplaced === '1' : row.dataset.state === filter.value);
      row.hidden = !matchesState || !row.textContent.toLocaleLowerCase().includes(query);
      if (!row.hidden) visible++;
    });
    document.getElementById('location-result-count').textContent = `${visible} of ${rows.length} locations`;
    document.getElementById('location-empty').hidden = visible !== 0;
  }
  document.getElementById('location-filters').hidden = false;
  search.addEventListener('input', applyFilters);
  filter.addEventListener('change', applyFilters);
  document.getElementById('location-clear-filters').addEventListener('click', () => {
    search.value = ''; filter.value = 'all'; applyFilters(); search.focus();
  });
  applyFilters();
})();
