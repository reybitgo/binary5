<?php
/******************************************************
 * index.php – Vertical Binary MLM Org Chart (PDO + D3)
 * - Strict left/right placement (children array ordered left,right)
 * - Zoom (wheel), Pan (drag background), Collapsible on node click
 * - Initial view centered in the window
 *
 * Expected table: users(id INT PK, username VARCHAR, upline_id INT NULL, position VARCHAR['left'|'right'])
 ******************************************************/

// -------------------- DB CONFIG --------------------
$DB_HOST = '127.0.0.1';
$DB_NAME = 'binary5_db';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

// -------------------- PDO CONNECT ------------------
$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$DB_CHARSET";
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (Exception $e) {
  http_response_code(500);
  echo "DB connection error: " . htmlspecialchars($e->getMessage());
  exit;
}

// -------------------- FETCH DATA -------------------
$sql = "SELECT id, username, upline_id, position FROM users ORDER BY id ASC";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();

// Build id->node map
$map = [];
foreach ($rows as $r) {
  $map[(int)$r['id']] = [
    'id'        => (int)$r['id'],
    'name'      => $r['username'],
    'upline_id' => isset($r['upline_id']) ? (int)$r['upline_id'] : null,
    'position'  => $r['position'],
    // store separate slots to enforce order, we'll convert to children[] later
    'left'      => null,
    'right'     => null
  ];
}

// Attach children strictly to left/right slot
$root = null;
foreach ($map as $id => &$node) {
  if ($node['upline_id'] === null) {
    $root = &$node;
  } else {
    $pid = $node['upline_id'];
    if (isset($map[$pid])) {
      if ($node['position'] === 'left') {
        $map[$pid]['left'] = &$node;
      } elseif ($node['position'] === 'right') {
        $map[$pid]['right'] = &$node;
      }
    }
  }
}
unset($node);

// Fallback root if none detected
if (!$root && !empty($map)) {
  $firstId = array_key_first($map);
  $root = $map[$firstId];
}

// Convert to D3-friendly (children array preserving [left, right] order)
function toD3($node) {
  if (!$node) return null;
  $children = [];
  if ($node['left'])  $children[] = toD3($node['left']);
  if ($node['right']) $children[] = toD3($node['right']);
  return [
    'id'       => $node['id'],
    'name'     => $node['name'],
    'position' => $node['position'] ?: 'root',
    'children' => $children
  ];
}

$treeJson = json_encode(toD3($root), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Vertical Binary MLM Org Chart</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- D3 v7 -->
  <script src="https://cdn.jsdelivr.net/npm/d3@7"></script>
  <style>
    :root {
      --bg: #0b1020;
      --panel: #121a35;
      --stroke: #5b79ff;
      --stroke-faint: #3a4a8a;
      --text: #e9ecf1;
      --muted: #cbd5ff;
    }
    html, body { height:100%; margin:0; background:var(--bg); color:var(--text); font-family:system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
    #chart { position:fixed; inset:0; }
    .link { fill:none; stroke:var(--stroke-faint); stroke-opacity:.85; stroke-width:1.5px; }
    .node rect {
      fill: var(--panel);
      stroke: var(--stroke);
      stroke-width: 1.25px;
      rx: 12px; ry: 12px;
      filter: drop-shadow(0 2px 4px rgba(0,0,0,.3));
    }
    .node text { fill: var(--text); font-size: 13px; font-weight: 600; dominant-baseline: middle; text-anchor: middle; }
    .badge { fill:#2b3a72; stroke:var(--stroke); stroke-width:1px; }
    .badge-text { fill: var(--muted); font-size: 10px; font-weight: 700; }
    .node:hover rect { stroke: #9db1ff; }
    .hint {
      position: fixed; left: 12px; bottom: 10px;
      background: rgba(18,26,53,.85);
      padding: 8px 10px; border: 1px solid var(--stroke-faint);
      border-radius: 10px; font-size: 12px; color: var(--muted);
      user-select: none;
    }
    .toolbar {
      position: fixed; right: 12px; top: 12px; display: flex; gap: 8px;
    }
    .btn {
      background: var(--panel); color: var(--muted);
      border: 1px solid var(--stroke-faint); border-radius: 10px;
      padding: 6px 10px; cursor: pointer; font-weight: 600; user-select: none;
    }
    .btn:hover { border-color: var(--stroke); }
  </style>
</head>
<body>
  <div id="chart"></div>

  <div class="toolbar">
    <div class="btn" id="resetZoom">Reset</div>
    <div class="btn" id="expandAll">Expand All</div>
    <div class="btn" id="collapseAll">Collapse All</div>
  </div>
  <div class="hint">scroll to zoom • drag background to pan • click a node to collapse/expand</div>

<script>
const data = <?php echo $treeJson ?: 'null'; ?>;

if (!data || !data.id) {
  document.getElementById('chart').innerHTML =
    '<div style="padding:16px">No data found. Ensure at least one row has <code>upline_id = NULL</code> as the root.</div>';
} else {
  renderOrgChart(data);
}

function renderOrgChart(rootData) {
  const container = document.getElementById('chart');
  let width = container.clientWidth;
  let height = container.clientHeight;

  // SVG + top-level group for zoom/pan
  const svg = d3.select('#chart').append('svg')
    .attr('width', width)
    .attr('height', height)
    .attr('viewBox', [0, 0, width, height].join(' '))
    .style('display', 'block');

  const g = svg.append('g');

  // Config
  const duration = 300;
  const nodeWidth = 160;
  const nodeHeight = 42;
  const levelGapY = 95;    // vertical distance between levels
  const siblingGapX = 26;  // horizontal separation base (the tree layout will space further as needed)

  // Create hierarchy (children already in [left,right] order from PHP)
  const root = d3.hierarchy(rootData, d => d.children);

  // Collapsible: start collapsed below first level
  root.x0 = width / 2;
  root.y0 = 40;
  if (root.children) root.children.forEach(collapse);

  // D3 vertical tree
  const tree = d3.tree()
    .nodeSize([nodeWidth + siblingGapX, levelGapY])
    .separation((a, b) => {
      // Slightly more separation if nodes are from different parents
      return a.parent === b.parent ? 1 : 1.2;
    });

  // Zoom/pan
  const zoom = d3.zoom()
    .scaleExtent([0.3, 2.5])
    .on('zoom', (event) => g.attr('transform', event.transform));
  svg.call(zoom);

  // Initial render + center
  update(root);
  centerOnRoot();

  // Toolbar actions
  document.getElementById('resetZoom').onclick = () => { centerOnRoot(); };
  document.getElementById('expandAll').onclick = () => { expandAll(root); update(root); };
  document.getElementById('collapseAll').onclick = () => { collapseAll(root); update(root); };

  // Keep responsive on resize
  window.addEventListener('resize', () => {
    width = container.clientWidth;
    height = container.clientHeight;
    svg.attr('width', width).attr('height', height).attr('viewBox', [0,0,width,height].join(' '));
    centerOnRoot();
  });

  function centerOnRoot() {
    // After layout, root.x/root.y exist. Center root horizontally; keep small top margin.
    const currentTransform = d3.zoomTransform(svg.node());
    const scale = 0.9; // comfy default
    const tx = width / 2 - (root.x0 ?? root.x) * scale;
    const ty = 60 - (root.y0 ?? root.y) * scale;
    svg.transition().duration(300).call(zoom.transform, d3.zoomIdentity.translate(tx, ty).scale(scale));
  }

  function update(source) {
    // Compute layout
    tree(root);

    // Convert to vertical coordinates:
    // d.x = horizontal, d.y = vertical (depth already in y)
    root.each(d => {
      d.y = d.depth * levelGapY + 80; // extra top padding
    });

    // ----- LINKS -----
    const link = g.selectAll('path.link')
      .data(root.links(), d => d.target.data.id);

    // Enter
    link.enter().append('path')
      .attr('class', 'link')
      .attr('d', d => elbow({source: source, target: source})) // from source to itself (collapsed animation)
      .merge(link)
      .transition().duration(duration)
      .attr('d', d => elbow(d));

    // Exit
    link.exit()
      .transition().duration(duration)
      .attr('d', d => elbow({source: source, target: source}))
      .remove();

    // ----- NODES -----
    const node = g.selectAll('g.node')
      .data(root.descendants(), d => d.data.id);

    // Enter group
    const nodeEnter = node.enter().append('g')
      .attr('class', 'node')
      .attr('transform', d => `translate(${source.x0 ?? source.x},${source.y0 ?? source.y})`)
      .on('click', (event, d) => {
        toggle(d);
        update(d);
      });

    // Node body
    nodeEnter.append('rect')
      .attr('width', nodeWidth)
      .attr('height', nodeHeight)
      .attr('x', -nodeWidth/2)
      .attr('y', -nodeHeight/2);

    // Title
    nodeEnter.append('text')
      .attr('dy', 3)
      .text(d => d.data.name);

    // Position badge
    const badgeW = 42, badgeH = 16;
    nodeEnter.append('rect')
      .attr('class', 'badge')
      .attr('width', badgeW).attr('height', badgeH)
      .attr('x', nodeWidth/2 - badgeW - 8 - nodeWidth/2)
      .attr('y', nodeHeight/2 - badgeH - 6 - nodeHeight/2)
      .attr('rx', 8).attr('ry', 8);

    nodeEnter.append('text')
      .attr('class', 'badge-text')
      .attr('x', nodeWidth/2 - badgeW/2 - 8 - nodeWidth/2)
      .attr('y', nodeHeight/2 - badgeH/2 - 6 - nodeHeight/2 + 1)
      .attr('text-anchor', 'middle')
      .text(d => (d.data.position || 'root').toUpperCase());

    // Update + transition to new positions
    const nodeUpdate = nodeEnter.merge(node);
    nodeUpdate.transition().duration(duration)
      .attr('transform', d => `translate(${d.x},${d.y})`);

    // Exit
    node.exit()
      .transition().duration(duration)
      .attr('transform', d => `translate(${source.x},${source.y})`)
      .remove();

    // Stash old positions for smooth transitions
    root.each(d => { d.x0 = d.x; d.y0 = d.y; });
  }

  // Smooth vertical elbow
  function elbow(d) {
    const sx = d.source.x, sy = d.source.y;
    const tx = d.target.x, ty = d.target.y;
    const my = (sy + ty) / 2;
    return `M${sx},${sy} C${sx},${my} ${tx},${my} ${tx},${ty}`;
  }

  // Collapse/expand helpers
  function toggle(d) {
    if (d.children) {
      d._children = d.children;
      d.children = null;
    } else {
      d.children = d._children;
      d._children = null;
    }
  }
  function collapse(d) {
    if (d.children) {
      d._children = d.children;
      d._children.forEach(collapse);
      d.children = null;
    }
  }
  function expand(d) {
    if (d._children) {
      d.children = d._children;
      d._children = null;
    }
    if (d.children) d.children.forEach(expand);
  }
  function collapseAll(d) {
    d.children && d.children.forEach(collapseAll);
    if (d !== root) collapse(d);
  }
  function expandAll(d) {
    expand(d);
  }
}
</script>
</body>
</html>
