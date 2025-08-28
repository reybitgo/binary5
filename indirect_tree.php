<?php
/******************************************************
 * indirect_tree.php – D3.js Org Chart (draggable, zoomable, collapsible)
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
$sql = "SELECT id, username, sponsor_id FROM users ORDER BY id ASC";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();

// Build an id->node map
$map = [];
foreach ($rows as $r) {
  $map[$r['id']] = [
    'id'      => (int)$r['id'],
    'name'    => $r['username'],
    'parent'  => $r['sponsor_id'] ? (int)$r['sponsor_id'] : null,
    'children'=> []
  ];
}

// Attach children
$root = null;
foreach ($map as $id => &$node) {
  if ($node['parent'] === null) {
    $root = &$node;
  } else {
    if (isset($map[$node['parent']])) {
      $map[$node['parent']]['children'][] = &$node;
    }
  }
}
unset($node);

// If no explicit root, pick first record
if (!$root && !empty($map)) {
  $firstId = array_key_first($map);
  $root = $map[$firstId];
}

$treeJson = json_encode($root, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>D3 Collapsible Org Chart</title>
  <script src="https://cdn.jsdelivr.net/npm/d3@7"></script>
  <style>
    html, body { margin:0; height:100%; background:#0b1020; font-family:Arial, sans-serif; }
    #chart { width:100%; height:100%; }
    .node rect {
      fill:#121a35;
      stroke:#5b79ff;
      stroke-width:1.25px;
      rx:12px; ry:12px;
    }
    .node text { fill:#e9ecf1; font-size:13px; font-weight:600; }
    .link { fill:none; stroke:#3a4a8a; stroke-opacity:0.8; stroke-width:1.5px; }
  </style>
</head>
<body>
<div id="chart"></div>
<script>
const data = <?php echo $treeJson ?: '{}'; ?>;

if (!data || !data.id) {
  document.getElementById('chart').innerHTML = '<p style="padding:20px;color:#fff">No data found.</p>';
} else {
  renderChart(data);
}

function renderChart(rootData) {
  const width = window.innerWidth;
  const height = window.innerHeight;

  const svg = d3.select('#chart').append('svg')
    .attr('width', width)
    .attr('height', height)
    .call(d3.zoom().scaleExtent([0.3, 2.5]).on("zoom", (event) => {
      g.attr("transform", event.transform);
    }));

  const g = svg.append('g');

  const nodeWidth = 140, nodeHeight = 40;
  const treeLayout = d3.tree().nodeSize([nodeWidth*2, nodeHeight*2]);

  const root = d3.hierarchy(rootData);
  root.x0 = width / 2;
  root.y0 = 0;

  // Collapse children initially
  root.children?.forEach(collapse);

  update(root);

  function collapse(d) {
    if (d.children) {
      d._children = d.children;
      d._children.forEach(collapse);
      d.children = null;
    }
  }

  function update(source) {
    const treeData = treeLayout(root);
    const nodes = treeData.descendants();
    const links = treeData.links();

    nodes.forEach(d => d.y = d.depth * 100);

    // ----- Nodes -----
    const node = g.selectAll('g.node')
      .data(nodes, d => d.id || (d.id = ++i));

    const nodeEnter = node.enter().append('g')
      .attr('class','node')
      .attr('transform', d => `translate(${source.x0},${source.y0})`)
      .on('click', (event, d) => {
        if (d.children) {
          d._children = d.children;
          d.children = null;
        } else {
          d.children = d._children;
          d._children = null;
        }
        update(d);
      });

    nodeEnter.append('rect')
      .attr('width', nodeWidth)
      .attr('height', nodeHeight)
      .attr('x', -nodeWidth/2)
      .attr('y', -nodeHeight/2);

    nodeEnter.append('text')
      .attr('dy', 5)
      .attr('text-anchor','middle')
      .text(d => d.data.name);

    const nodeUpdate = nodeEnter.merge(node);
    nodeUpdate.transition().duration(400)
      .attr('transform', d => `translate(${d.x},${d.y})`);

    const nodeExit = node.exit().transition().duration(400)
      .attr('transform', d => `translate(${source.x},${source.y})`)
      .remove();

    // ----- Links -----
    const link = g.selectAll('path.link')
      .data(links, d => d.target.id);

    const linkEnter = link.enter().insert('path','g')
      .attr('class','link')
      .attr('d', d => {
        const o = {x: source.x0, y: source.y0};
        return diagonal(o,o);
      });

    const linkUpdate = linkEnter.merge(link);
    linkUpdate.transition().duration(400)
      .attr('d', d => diagonal(d.source, d.target));

    link.exit().transition().duration(400)
      .attr('d', d => {
        const o = {x: source.x, y: source.y};
        return diagonal(o,o);
      })
      .remove();

    nodes.forEach(d => { d.x0 = d.x; d.y0 = d.y; });
  }

  function diagonal(s, d) {
    return `M ${s.x},${s.y}
            C ${(s.x+d.x)/2},${s.y}
              ${(s.x+d.x)/2},${d.y}
              ${d.x},${d.y}`;
  }
}
</script>
</body>
</html>
