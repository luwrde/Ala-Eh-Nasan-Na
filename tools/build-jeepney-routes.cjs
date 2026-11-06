// Build a small, attributed route snapshot from OSM /relation/{id}/full.json files.
// Usage: node tools/build-jeepney-routes.cjs <directory containing jeepney-ID.json>
const fs = require('node:fs');
const path = require('node:path');
const { positionStops } = require('../public/assets/jeepney-routing.js');
const sourceDirectory = process.argv[2];
if (!sourceDirectory) throw new Error('Provide the directory containing the downloaded OSM relations.');
const routeIds = [5391157, 10755762, 10755763];
const routes = routeIds.map((id) => {
  const data = JSON.parse(fs.readFileSync(path.join(sourceDirectory, `jeepney-${id}.json`), 'utf8'));
  const elements = new Map(data.elements.map((element) => [element.type + element.id, element]));
  const relation = elements.get(`relation${id}`);
  const ways = relation.members.filter((member) => member.type === 'way' && !member.role.startsWith('platform'))
    .map((member) => elements.get(`way${member.ref}`));
  const nodeIds = [];
  const roadNames = [];
  ways.forEach((way, index) => {
    const nodes = way.nodes.slice();
    if (index === 0) {
      const next = ways[1].nodes;
      if (next.includes(nodes[0]) && !next.includes(nodes.at(-1))) nodes.reverse();
    } else if (nodes.at(-1) === nodeIds.at(-1)) nodes.reverse();
    if (nodeIds.length && nodeIds.at(-1) !== nodes[0]) throw new Error(`Disconnected route ${id} at way ${way.id}`);
    nodeIds.push(...(index === 0 ? nodes : nodes.slice(1)));
    roadNames.push(...Array(nodes.length - 1).fill(way.tags?.name || 'Local road'));
  });
  const route = {
    id, ref: relation.tags.ref,
    name: id === 5391157 ? 'Bauan – Batangas City proper (BATRASCO loop)' : relation.tags.name.replace('Jeepney BW-02: ', ''),
    roundtrip: relation.tags.roundtrip === 'yes',
    source: `https://www.openstreetmap.org/relation/${id}`,
    version: relation.version,
    updatedAt: relation.timestamp,
    coordinates: nodeIds.map((nodeId) => { const node = elements.get(`node${nodeId}`); return [node.lon, node.lat]; }),
    roadNames,
    stops: relation.members.filter((member) => member.type === 'node' && /^(platform|stop)/.test(member.role)).map((member) => {
      const node = elements.get(`node${member.ref}`);
      return {
        id: `osm-${node.id}`, name: node.tags?.name || 'Mapped stop',
        coordinates: [node.lon, node.lat],
        type: /Grand Terminal/i.test(node.tags?.name || '') ? 'terminal' : 'boarding_point',
        landmark: node.tags?.description || '',
        access: member.role.endsWith('entry_only') ? 'Boarding only' : member.role.endsWith('exit_only') ? 'Alighting only' : 'Boarding and alighting',
        source: `https://www.openstreetmap.org/node/${node.id}`,
      };
    }),
  };
  route.stops = positionStops(route);
  return route;
});
const output = {
  attribution: '© OpenStreetMap contributors',
  license: 'ODbL-1.0',
  licenseUrl: 'https://www.openstreetmap.org/copyright',
  retrievedAt: new Date().toISOString().slice(0, 10),
  description: 'Community-mapped BW-02 routes; not a franchise approval or live service guarantee.',
  routes,
};
fs.writeFileSync(path.join(__dirname, '../public/assets/jeepney-routes.json'), JSON.stringify(output));
console.log(`Built ${routes.length} connected directional routes.`);
