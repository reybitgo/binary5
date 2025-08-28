<?php
// -------------------- SPONSOR TREE: ROOT = LOGGED-IN USER -------------------
$stmt = $pdo->query(
    "SELECT id, username, sponsor_name
     FROM users
     ORDER BY id ASC"
);
$sponsorRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sponsorMap = [];
foreach ($sponsorRows as $r) {
    $sponsorMap[$r['id']] = [
        'id'           => (int)$r['id'],
        'name'         => $r['username'],
        'sponsor_name' => $r['sponsor_name'],
        'children'     => [],
    ];
}

// build parent → child links
foreach ($sponsorMap as $id => &$node) {
    $sponsorName = $node['sponsor_name'];
    if ($sponsorName) {
        foreach ($sponsorMap as $pid => &$parent) {
            if ($parent['name'] === $sponsorName) {
                $parent['children'][] = &$node;
                break;
            }
        }
    }
}
unset($node, $parent);

// force the logged-in user to be the root
$sponsorRoot = $sponsorMap[$uid] ?? null;

function toD3Sponsor($node) {
    if (!$node) return null;
    $children = [];
    foreach ($node['children'] as $child) {
        $children[] = toD3Sponsor($child);
    }
    return [
        'id'       => $node['id'],
        'name'     => $node['name'],
        'position' => 'sponsor',
        'treeType' => 'sponsor',
        'children' => $children
    ];
}

$sponsorTreeJson = json_encode(toD3Sponsor($sponsorRoot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function getIndirects(PDO $pdo, int $rootId, int $maxLevel = 5): array {
    $allRows = [];
    $current = [$rootId];
    for ($lvl = 1; $lvl <= $maxLevel; $lvl++) {
        if (!$current) break;
        $placeholders = implode(',', array_fill(0, count($current), '?'));
        $sql = "SELECT id, username FROM users WHERE sponsor_name IN (SELECT username FROM users WHERE id IN ($placeholders))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($current);
        $next = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['lvl'] = $lvl;
            $allRows[] = $row;
            $next[] = (int)$row['id'];
        }
        $current = $next;
    }
    return $allRows;
}
$indirects = getIndirects($pdo, $uid);
?>

<!-- Leadership Section -->
<h2 class="text-2xl font-bold text-gray-800 mb-4">Matched Bonus</h2>

<div class="bg-white shadow rounded-lg p-6 mb-6">
    <h3 class="text-lg font-semibold text-gray-700">Total Matched Bonus Earned</h3>
    <p class="text-2xl text-blue-500">$<?php
        $tot = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_tx WHERE user_id = ? AND type = 'leadership_bonus'");
        $tot->execute([$uid]);
        echo number_format((float)$tot->fetchColumn(), 2);
    ?></p>
</div>

<div class="bg-white shadow rounded-lg p-6 mb-6">
    <h3 class="text-lg font-semibold text-gray-700 mb-4">Sponsorship Tree</h3>
    <div id="sponsorChart">
        <div class="chart-toolbar">
            <div class="chart-btn" id="resetZoomSponsor">Reset</div>
            <div class="chart-btn" id="expandAllSponsor">Expand All</div>
            <div class="chart-btn" id="collapseAllSponsor">Collapse All</div>
        </div>
        <div class="chart-hint">scroll to zoom • drag background to pan • click a node to collapse/expand</div>
    </div>
</div>

<div class="bg-white shadow rounded-lg p-6">
    <h3 class="text-lg font-semibold text-gray-700 mb-4">Indirect Down-lines & Leadership Paid</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="text-gray-600">
                    <th class="p-2">Indirect</th>
                    <th class="p-2">Level</th>
                    <th class="p-2">Leadership Earned</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($indirects as $ind) {
                    $stmt = $pdo->prepare(
                        "SELECT COALESCE(SUM(amount),0)
                         FROM wallet_tx
                         WHERE user_id = ?
                           AND type = 'leadership_bonus'
                           AND created_at >= (
                               SELECT MIN(created_at)
                               FROM wallet_tx
                               WHERE user_id = ?
                                 AND type = 'pair_bonus'
                           )"
                    );
                    $stmt->execute([$uid, $ind['id']]);
                    $earned = (float)$stmt->fetchColumn();
                    echo "<tr class='border-t'>
                            <td class='p-2'>" . htmlspecialchars($ind['username']) . "</td>
                            <td class='p-2'>L-" . $ind['lvl'] . "</td>
                            <td class='p-2'>$" . number_format($earned, 2) . "</td>
                          </tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Sponsor chart initialization
function initSponsorChart() {
    const sponsorData = <?php echo $sponsorTreeJson ?: 'null'; ?>;
    if (!sponsorData || !sponsorData.id) {
        console.warn('Sponsor chart not initialized: invalid data');
        return;
    }
    renderOrgChart(sponsorData, 'sponsorChart', 'resetZoomSponsor', 'expandAllSponsor', 'collapseAllSponsor');
}

// Use the same renderOrgChart function from binary.php (it should be globally available)
if (typeof renderOrgChart === 'undefined') {
    // If not available, we need to include it here as well
    function renderOrgChart(rootData, containerId, resetId, expandId, collapseId) {
        // Same implementation as in binary.php
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
}
</script>