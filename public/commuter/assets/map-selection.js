import { mapCoordinates, googleMapsUrl, element, translate } from './terminals-common.js?v=8';

/** Focus the actual record, even if a marker layer is still catching up with filtering. */
export function focusTerminal(state, record, padding = null, openPopup = true) {
  const coordinates = record && mapCoordinates(record);
  if (!state?.map || !coordinates) return false;
  state.map.stop();
  state.map.invalidateSize({ pan: false, animate: false });
  if (padding) state.map.fitBounds([coordinates, coordinates], { ...padding, maxZoom: 17, animate: false });
  else state.map.setView(coordinates, 17, { animate: false });
  let marker = state.markers.get(record.id);
  if (!marker && state.leaflet && state.markerLayer) {
    const popup = element('div', 'terminal-map-popup');
    popup.append(element('strong', '', record.name), element('p', '', record.address));
    const googleUrl = googleMapsUrl(record);
    if (googleUrl) {
      const google = element('a', 'terminal-google-link', translate('Open in Google Maps'));
      google.href = googleUrl; google.target = '_blank'; google.rel = 'noopener noreferrer';
      popup.append(google);
    }
    marker = state.leaflet.circleMarker(coordinates, {
      radius: 9, color: '#ffffff', weight: 2, fillColor: '#dc2626', fillOpacity: 1,
    }).bindPopup(popup).addTo(state.markerLayer);
    state.markers.set(record.id, marker);
  }
  if (openPopup) marker?.openPopup();
  return true;
}
