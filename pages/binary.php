<?php
// -------------------- BINARY TREE: ROOT = LOGGED-IN USER -------------------
$stmt = $pdo->query(
    "SELECT id, username, upline_id, position
     FROM users
     ORDER BY id ASC"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$map = [];
foreach ($rows as $r) {
    $map[$r['id']] = [
        'id'        => (int)$r['id'],
        'name'      => $r['username'],
        'upline_id' => $r['upline_id'] ? (int)$r['upline_id'] : null,
        'position'  => $r['position'],
        'left'      => null,
        'right'     => null,
    ];
}

// wire children
foreach ($map as $id => &$node) {
    $pid = $node['upline_id'];
    if (isset($map[$pid])) {
        if ($node['position'] === 'left')  $map[$pid]['left']  = &$node;
        if ($node['position'] === 'right') $map[$pid]['right'] = &$node;
    }
}
unset($node);

// force the logged-in user to be the tree root
$binaryRoot = $map[$uid] ?? null;

// Convert to D3-friendly format
function toD3Binary($node) {
    if (!$node) return null;
    $children = [];
    if ($node['left'])  $children[] = toD3Binary($node['left']);
    if ($node['right']) $children[] = toD3Binary($node['right']);
    return [
        'id'       => $node['id'],
        'name'     => $node['name'],
        'position' => $node['position'] ?: 'root',
        'treeType' => 'binary',
        'children' => $children
    ];
}

$binaryTreeJson = json_encode(toD3Binary($binaryRoot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>

<!-- Binary Tree Section -->
<h2 class="text-2xl font-bold text-gray-800 mb-4">Binary Tree Structure</h2>
<p class="text-gray-600 mb-6">View your binary organization and pair statistics</p>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-700">Left Count</h3>
        <p class="text-2xl text-blue-500"><?=$user['left_count']?></p>
    </div>
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-700">Right Count</h3>
        <p class="text-2xl text-blue-500"><?=$user['right_count']?></p>
    </div>
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-700">Pairs Today</h3>
        <p class="text-2xl text-blue-500"><?=$user['pairs_today']?>/10</p>
    </div>
</div>

<div class="bg-white shadow rounded-lg p-6">
    <h3 class="text-lg font-semibold text-gray-700 mb-4">Binary Organization Chart</h3>
    <div id="orgChart">
        <div class="chart-toolbar">
            <div class="chart-btn" id="resetZoom">Reset</div>
            <div class="chart-btn" id="expandAll">Expand All</div>
            <div class="chart-btn" id="collapseAll">Collapse All</div>
        </div>
        <div class="chart-hint">scroll to zoom • drag background to pan • click a node to collapse/expand</div>
    </div>
</div>

<script>
// Binary chart initialization
function initBinaryChart() {
    const binaryData = <?php echo $binaryTreeJson ?: 'null'; ?>;
    if (!binaryData || !binaryData.id) {
        console.warn('Binary chart not initialized: invalid data');
        return;
    }
    renderOrgChart(binaryData, 'orgChart', 'resetZoom', 'expandAll', 'collapseAll');
}

// D3.js Chart Rendering Function
function renderOrgChart(rootData, containerId, resetId, expandId, collapseId) {
    const container = document.getElementById(containerId);
    if (!container) {
        console.error(`Container #${containerId} not found`);
        return;
    }
    let width = container.clientWidth;
    let height = container.clientHeight;

    d3.select(`#${containerId} svg`).remove();

    const svg = d3.select(`#${containerId}`).append('svg')
        .attr('width', width)
        .attr('height', height)
        .attr('viewBox', [0, 0, width, height].join(' '))
        .style('display', 'block');

    const g = svg.append('g');
    const linkGroup = g.append('g').attr('class', 'links');
    const nodeGroup = g.append('g').attr('class', 'nodes');

    const duration = 300;
    const nodeWidth = 160;
    const nodeHeight = 42;
    const levelGapY = 95;
    const siblingGapX = 26;

    const root = d3.hierarchy(rootData, d => d.children);
    root.x0 = width / 2;
    root.y0 = 40;
    if (root.children) root.children.forEach(collapse);

    const tree = d3.tree()
        .nodeSize([nodeWidth + siblingGapX, levelGapY])
        .separation((a, b) => a.parent === b.parent ? 1 : 1.2);

    const zoom = d3.zoom()
        .scaleExtent([0.3, 2.5])
        .on('zoom', (event) => g.attr('transform', event.transform));
    svg.call(zoom);

    update(root);
    centerOnRoot();

    document.getElementById(resetId).onclick = () => { centerOnRoot(); };
    document.getElementById(expandId).onclick = () => { expandAll(root); update(root); };
    document.getElementById(collapseId).onclick = () => { collapseAll(root); update(root); };

    window.addEventListener('resize', () => {
        width = container.clientWidth;
        height = container.clientHeight;
        svg.attr('width', width).attr('height', height).attr('viewBox', [0,0,width,height].join(' '));
        centerOnRoot();
    });

    function centerOnRoot() {
        const currentTransform = d3.zoomTransform(svg.node());
        const scale = 0.9;
        const tx = width / 2 - (root.x0 ?? root.x) * scale;
        const ty = 60 - (root.y0 ?? root.y) * scale;
        svg.transition().duration(300).call(zoom.transform, d3.zoomIdentity.translate(tx, ty).scale(scale));
    }

    function update(source) {
        tree(root);
        root.each(d => {
            d.y = d.depth * levelGapY + 80;
        });

        const link = linkGroup.selectAll('path.link')
            .data(root.links(), d => d.target.data.id);

        link.enter().append('path')
            .attr('class', 'link')
            .attr('d', d => elbow({source: source, target: source}))
            .merge(link)
            .transition().duration(duration)
            .attr('d', d => elbow(d));

        link.exit()
            .transition().duration(duration)
            .attr('d', d => elbow({source: source, target: source}))
            .remove();

        const node = nodeGroup.selectAll('g.node')
            .data(root.descendants(), d => d.data.id);

        const nodeEnter = node.enter().append('g')
            .attr('class', d => {
                const hasChildren = (d.children && d.children.length > 0) || (d._children && d._children.length > 0);
                return hasChildren ? 'node has-children' : 'node no-children';
            })
            .attr('transform', d => `translate(${source.x0 ?? source.x},${source.y0 ?? source.y})`)
            .on('click', (event, d) => {
                toggle(d);
                update(d);
            });

        nodeEnter.append('rect')
            .attr('width', nodeWidth)
            .attr('height', nodeHeight)
            .attr('x', -nodeWidth/2)
            .attr('y', -nodeHeight/2);

        nodeEnter.append('text')
            .attr('dy', 3)
            .text(d => d.data.name);

        const isBinaryTree = rootData.treeType === 'binary';
        if (isBinaryTree) {
            const badgeW = 20, badgeH = 16;
            nodeEnter.append('rect')
                .attr('class', 'badge')
                .attr('width', badgeW).attr('height', badgeH)
                .attr('x', -nodeWidth/2 + 8)
                .attr('y', -nodeHeight/2 + 6)
                .attr('rx', 6).attr('ry', 6);

            nodeEnter.append('text')
                .attr('class', 'badge-text')
                .attr('x', -nodeWidth/2 + 8 + badgeW/2)
                .attr('y', -nodeHeight/2 + 6 + badgeH/2 + 1)
                .attr('text-anchor', 'middle')
                .text(d => d.depth === 0 ? '' : (d.data.position === 'left' ? 'L' : 'R'));
        }

        const nodeUpdate = nodeEnter.merge(node);
        nodeUpdate.attr('class', d => {
            const hasChildren = (d.children && d.children.length > 0) || (d._children && d._children.length > 0);
            return hasChildren ? 'node has-children' : 'node no-children';
        });
        nodeUpdate.transition().duration(duration)
            .attr('transform', d => `translate(${d.x},${d.y})`);

        node.exit()
            .transition().duration(duration)
            .attr('transform', d => `translate(${source.x},${source.y})`)
            .remove();

        root.each(d => { d.x0 = d.x; d.y0 = d.y; });
    }

    function elbow(d) {
        const sx = d.source.x, sy = d.source.y;
        const tx = d.target.x, ty = d.target.y;
        const my = (sy + ty) / 2;
        return `M${sx},${sy} C${sx},${my} ${tx},${my} ${tx},${ty}`;
    }

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