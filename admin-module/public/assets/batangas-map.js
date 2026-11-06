(() => {
  // A rectangular navigation boundary around Batangas province and its coastal routes.
  const area = [[13.45, 120.5], [14.25, 121.55]];
  const center = [13.7565, 121.0583];

  const contains = position => Array.isArray(position)
    && position.length >= 2
    && position.every(value => value !== null && value !== '' && Number.isFinite(Number(value)))
    && Number(position[0]) >= area[0][0] && Number(position[0]) <= area[1][0]
    && Number(position[1]) >= area[0][1] && Number(position[1]) <= area[1][1];

  const create = element => {
    const bounds = L.latLngBounds(area);
    const map = L.map(element, {
      maxBounds: bounds,
      maxBoundsViscosity: 1,
      minZoom: 10,
      maxZoom: 19,
      // Prevent a pinch gesture from temporarily zooming out to the world.
      bounceAtZoomLimits: false
    }).setView(center, 12);

    const constrainViewport = () => {
      // Keep the whole viewport inside the region, including on wide screens.
      map.setMinZoom(Math.max(10, map.getBoundsZoom(bounds, true)));
      map.panInsideBounds(bounds, {animate: false});
    };
    map.on('resize', constrainViewport);
    constrainViewport();

    if (window.ResizeObserver) {
      const observer = new ResizeObserver(() => map.invalidateSize({pan: false}));
      observer.observe(map.getContainer());
      map.on('unload', () => observer.disconnect());
    }

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      bounds,
      noWrap: true,
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    return map;
  };

  const fitPoints = (map, points, options = {}) => {
    const localPoints = points.filter(contains);
    if (localPoints.length) {
      map.fitBounds(L.latLngBounds(localPoints), {maxZoom: 14, ...options});
    }
  };

  window.BatangasMap = Object.freeze({create, contains, fitPoints});
})();
