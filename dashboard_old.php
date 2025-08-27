<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) redirect('login.php');

$uid = $_SESSION['user_id'];

// user info
$user = $pdo->prepare("SELECT u.*, w.balance
                       FROM users u
                       JOIN wallets w ON w.user_id = u.id
                       WHERE u.id = ?");
$user->execute([$uid]);
$user = $user->fetch();

// packages
$packages = $pdo->query("SELECT * FROM packages")->fetchAll();

// -------------------- BINARY TREE: ROOT = LOGGED-IN USER -------------------
$uid = $_SESSION['user_id'];          // already defined above

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
        // find parent by username
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

// Convert to D3-friendly format for binary tree
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

// Convert to D3-friendly format for sponsor tree
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

$binaryTreeJson = json_encode(toD3Binary($binaryRoot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sponsorTreeJson = json_encode(toD3Sponsor($sponsorRoot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function flash()
{
    if (isset($_SESSION['flash'])) {
        $msg = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return "<div class='alert alert-info'>$msg</div>";
    }
    return '';
}

// Handle actions
if ($_POST['action'] ?? '') {
    switch ($_POST['action']) {
        case 'request_topup':
            $amt = max(0, (float) $_POST['usdt_amount']);
            if ($amt <= 0) redirect('dashboard.php', 'Invalid amount');
            $hash = trim($_POST['tx_hash']) ?: null;
            $b2p = $amt * USDT_B2P_RATE;
            $pdo->prepare(
              "INSERT INTO ewallet_requests (user_id, type, usdt_amount, b2p_amount, tx_hash, status)
              VALUES (?,'topup', ?, ?, ?, 'pending')"
            )->execute([$uid, $amt, $b2p, $hash]);
            redirect('dashboard.php', 'Top-up request submitted');
            break;

        case 'request_withdraw':
            $amt = max(0, (float) $_POST['usdt_amount']);
            if ($amt <= 0) redirect('dashboard.php','Invalid amount');
            if ($amt > $user['balance']) redirect('dashboard.php','Insufficient balance');

            $addr = trim($_POST['wallet_address']);
            $b2p  = $amt * USDT_B2P_RATE;

            // *** only insert the request, do NOT debit the wallet yet ***
            $pdo->prepare(
              "INSERT INTO ewallet_requests (user_id,type,usdt_amount,b2p_amount,wallet_address,status)
              VALUES (?,'withdraw',?,?,?,'pending')"
            )->execute([$uid,$amt,$b2p,$addr]);

            redirect('dashboard.php','Withdrawal request submitted');
            break;

        case 'transfer':
            $toUser = $_POST['to_username'];
            $amt = (float) $_POST['amount'];
            if ($amt > $user['balance']) redirect('dashboard.php', 'Insufficient balance');
            $to = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $to->execute([$toUser]);
            $to = $to->fetch();
            if (!$to) redirect('dashboard.php', 'Recipient not found');
            $tid = $to['id'];
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")->execute([$amt,$uid]);
            $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")->execute([$amt,$tid]);
            $pdo->prepare("INSERT INTO wallet_tx (user_id,type,amount) VALUES (?,'transfer_out',?)")->execute([$uid,-$amt]);
            $pdo->prepare("INSERT INTO wallet_tx (user_id,type,amount) VALUES (?,'transfer_in',?)")->execute([$tid,$amt]);
            $pdo->commit();
            redirect('dashboard.php', 'Transfer completed');
            break;

        case 'buy_package':
            $pid = (int) $_POST['package_id'];
            $pkg = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
            $pkg->execute([$pid]);
            $pkg = $pkg->fetch();
            if (!$pkg) redirect('dashboard.php', 'Package not found');
            if ($user['balance'] < $pkg['price']) redirect('dashboard.php', 'Insufficient balance');
            // debit + create purchase record
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")
                ->execute([$pkg['price'],$uid]);
            $pdo->prepare("INSERT INTO wallet_tx (user_id,type,amount) VALUES (?,'package',?)")
                ->execute([$uid,-$pkg['price']]);

            // trigger binary calculation (very basic version)
            include 'binary_calc.php';
            calc_binary($uid, $pkg['pv'], $pdo);

            // trigger referral calculation
            include 'referral_calc.php';
            calc_referral($uid, $pkg['price'], $pdo);

            $pdo->commit();
            redirect('dashboard.php', 'Package purchased & commissions calculated');
            break;
    }
}
?>
<!doctype html>
<html>
<head>
  <title>Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- D3 v7 for org chart -->
  <script src="https://cdn.jsdelivr.net/npm/d3@7"></script>
  <style>
    /* Org Chart Styles */
    :root {
      --bg: #ffffff;
      --panel: #f8f9fa;
      --stroke: #007bff;
      --stroke-faint: #6c757d;
      --text: #212529;
      --muted: #6c757d;
    }
    
    #orgChart, #sponsorChart { 
      position: relative; 
      height: 500px; 
      width: 100%; 
      background: var(--bg); 
      border-radius: 8px; 
      overflow: hidden;
      border: 1px solid #dee2e6;
    }
    
    .link { 
      fill: none; 
      stroke: var(--stroke-faint); 
      stroke-opacity: .85; 
      stroke-width: 1.5px; 
    }
    
    .node rect {
      fill: var(--panel);
      stroke-width: 1.25px;
      rx: 12px; 
      ry: 12px;
      filter: drop-shadow(0 2px 4px rgba(0,0,0,.3));
    }
    
    .node.has-children rect {
      stroke: #007bff;
    }
    
    .node.no-children rect {
      stroke: #6c757d;
    }
    
    .node text { 
      fill: var(--text); 
      font-size: 13px; 
      font-weight: 600; 
      dominant-baseline: middle; 
      text-anchor: middle; 
    }
    
    .badge { 
      fill: #e9ecef; 
      stroke: var(--stroke); 
      stroke-width: 1px; 
    }
    
    .badge-text { 
      fill: var(--muted); 
      font-size: 10px; 
      font-weight: 700; 
    }
    
    .node:hover rect { 
      stroke: #9db1ff; 
      cursor: pointer;
    }
    
    .chart-toolbar {
      position: absolute; 
      right: 12px; 
      top: 12px; 
      display: flex; 
      gap: 8px;
      z-index: 10;
    }
    
    .chart-btn {
      background: #ffffff; 
      color: #495057;
      border: 1px solid #ced4da; 
      border-radius: 6px;
      padding: 6px 10px; 
      cursor: pointer; 
      font-weight: 600; 
      user-select: none;
      font-size: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .chart-btn:hover { 
      border-color: var(--stroke);
      background: #f8f9fa;
    }
    
    .chart-hint {
      position: absolute; 
      left: 12px; 
      bottom: 10px;
      background: rgba(248,249,250,0.95);
      padding: 8px 10px; 
      border: 1px solid #ced4da;
      border-radius: 6px; 
      font-size: 12px; 
      color: #495057;
      user-select: none;
      z-index: 10;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
  </style>
</head>
<body class="bg-light">
<div class="container py-4">
  <h2>Dashboard - <?=htmlspecialchars($user['username'])?></h2>
  <?=flash()?>
  <ul class="nav nav-tabs" id="myTab">
    <li class="nav-item"><a href="#home" class="nav-link active" data-bs-toggle="tab">Binary</a></li>
    <li class="nav-item"><a href="#referral" class="nav-link" data-bs-toggle="tab">Referral</a></li>
    <li class="nav-item"><a href="#leadership" class="nav-link" data-bs-toggle="tab">Matched</a></li>
    <li class="nav-item"><a href="#mentor" class="nav-link" data-bs-toggle="tab">Mentor</a></li>
    <li class="nav-item"><a href="#wallet" class="nav-link" data-bs-toggle="tab">Wallet</a></li>
    <li class="nav-item"><a href="#store" class="nav-link" data-bs-toggle="tab">Store</a></li>
  </ul>

  <div class="tab-content mt-3">
    <!-- TAB 1: Tree with Binary Org Chart -->
    <div class="tab-pane fade show active" id="home">
      <div class="card mb-4">
        <div class="card-body">
          <div class="row">
            <div class="col-md-4">
              <h5 class="text-primary">Left Count: <?=$user['left_count']?></h5>
            </div>
            <div class="col-md-4">
              <h5 class="text-success">Right Count: <?=$user['right_count']?></h5>
            </div>
            <div class="col-md-4">
              <h5 class="text-info">Pairs Today: <?=$user['pairs_today']?></h5>
            </div>
          </div>
        </div>
      </div>
      
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Binary Tree (Position-based)</h5>
        </div>
        <div class="card-body p-0">
          <div id="orgChart">
            <div class="chart-toolbar">
              <div class="chart-btn" id="resetZoom">Reset</div>
              <div class="chart-btn" id="expandAll">Expand All</div>
              <div class="chart-btn" id="collapseAll">Collapse All</div>
            </div>
            <div class="chart-hint">scroll to zoom • drag background to pan • click a node to collapse/expand</div>
          </div>
        </div>
      </div>
    </div>

    <!-- TAB 2: Referral -->
    <div class="tab-pane fade" id="referral">
      <div class="row">
        <div class="col-md-6">
          <div class="card mb-4">
            <div class="card-header">Total Referral Bonus Earned</div>
            <div class="card-body">
              <h4>$<?php
                $tot = $pdo->prepare("SELECT COALESCE(SUM(amount),0)
                                      FROM wallet_tx
                                      WHERE user_id = ? AND type = 'referral_bonus'");
                $tot->execute([$uid]);
                echo number_format((float)$tot->fetchColumn(), 2);
              ?></h4>
            </div>
          </div>
        </div>
      </div>

      <h5>Direct Referrals & Earnings</h5>
      <table class="table table-sm">
        <thead><tr><th>Direct</th><th>Referral Earned</th></tr></thead>
        <tbody>
        <?php
        $directs = $pdo->prepare("
            SELECT d.username,
                  COALESCE(SUM(rt.amount),0) AS earned
            FROM users d                          -- directs
            JOIN wallet_tx pkg_tx
                ON pkg_tx.user_id = d.id
                AND pkg_tx.type = 'package'
                AND pkg_tx.amount < 0             -- negative amount = package purchase
            JOIN wallet_tx rt
                ON rt.user_id   = (SELECT id FROM users WHERE username = d.sponsor_name)
                AND rt.type      = 'referral_bonus'
                AND rt.created_at BETWEEN pkg_tx.created_at AND DATE_ADD(pkg_tx.created_at, INTERVAL 1 SECOND)
          WHERE d.sponsor_name = (SELECT username FROM users WHERE id = ?)
          GROUP BY d.id
          ORDER BY d.username
          ");
        $directs->execute([$uid]);
        foreach ($directs as $d) {
          echo "<tr><td>" . htmlspecialchars($d['username']) . "</td>
                    <td>$" . number_format((float)$d['earned'], 2) . "</td></tr>";
        }
        ?>
        </tbody>
      </table>
    </div>

    <!-- TAB 3: Wallet -->
    <div class="tab-pane fade" id="wallet">
      <!-- FILE: dashboard.php (inside #wallet tab) -->
      <div class="card mb-4">
        <div class="card-header">Balance: $<?=number_format($user['balance'],2)?></div>
        <div class="card-body">
          <!-- TOP-UP REQUEST -->
          <form method="post" class="row g-2 mb-3">
            <input type="hidden" name="action" value="request_topup">
            <div class="col-md-5"><input type="number" step="0.01" class="form-control" name="usdt_amount" placeholder="USDT amount" required></div>
            <div class="col-md-5"><input type="text" class="form-control" name="tx_hash" placeholder="Blockchain TX Hash (optional)"></div>
            <div class="col-md-2"><button class="btn btn-success">Request Top-up</button></div>
          </form>

          <!-- WITHDRAW REQUEST -->
          <form method="post" class="row g-2">
            <input type="hidden" name="action" value="request_withdraw">
            <div class="col-md-5"><input type="number" step="0.01" class="form-control" name="usdt_amount" placeholder="USDT amount" required></div>
            <div class="col-md-5"><input type="text" class="form-control" name="wallet_address" placeholder="USDT TRC-20 Address" required></div>
            <div class="col-md-2"><button class="btn btn-warning">Request Withdraw</button></div>
          </form>

          <hr>
          <form method="post" class="row g-2 mt-3">
            <div class="col-md-4"><input type="text" class="form-control" name="to_username" placeholder="Username" required></div>
            <div class="col-md-4"><input type="number" step="0.01" class="form-control" name="amount" placeholder="Amount" required></div>
            <div class="col-md-4"><button class="btn btn-info" name="action" value="transfer">Transfer</button></div>
          </form>

          <!-- pending requests preview -->
          <?php
            $pend = $pdo->prepare("SELECT * FROM ewallet_requests WHERE user_id = ? AND status='pending' ORDER BY id DESC");
            $pend->execute([$uid]);
            if ($pend->rowCount()):
          ?>
            <hr>
            <h6>Pending Requests</h6>
            <table class="table table-sm">
              <thead><tr><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
              <tbody>
                <?php foreach($pend as $r): ?>
                  <tr>
                    <td><?=ucfirst($r['type'])?></td>
                    <td><?=$r['usdt_amount']?> USDT</td>
                    <td><span class="badge bg-warning">Pending</span></td>
                    <td><?=$r['created_at']?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
      <!-- <div class="card mb-4">
        <div class="card-header">Balance: $<?=number_format($user['balance'],2)?></div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <div class="col-md-4"><input type="number" step="0.01" class="form-control" name="amount" placeholder="Amount" required></div>
            <div class="col-md-4"><button class="btn btn-success" name="action" value="topup">Top-up (demo)</button></div>
            <div class="col-md-4"><button class="btn btn-warning" name="action" value="withdraw">Withdraw</button></div>
          </form>
          <hr>
          <form method="post" class="row g-2 mt-3">
            <div class="col-md-4"><input type="text" class="form-control" name="to_username" placeholder="Username" required></div>
            <div class="col-md-4"><input type="number" step="0.01" class="form-control" name="amount" placeholder="Amount" required></div>
            <div class="col-md-4"><button class="btn btn-info" name="action" value="transfer">Transfer</button></div>
          </form>
        </div>
      </div> -->

      <h5>Transactions</h5>
      <table class="table table-sm">
        <thead><tr><th>Type</th><th>Amount</th><th>Date</th></tr></thead>
        <tbody>
        <?php
        $tx = $pdo->prepare("SELECT * FROM wallet_tx WHERE user_id = ? ORDER BY id DESC LIMIT 20");
        $tx->execute([$uid]);
        foreach ($tx as $t) {
          echo "<tr><td>{$t['type']}</td><td>{$t['amount']}</td><td>{$t['created_at']}</td></tr>";
        }
        ?>
        </tbody>
      </table>
    </div>

    <!-- TAB 4: Store -->
    <div class="tab-pane fade" id="store">
      <div class="row">
        <?php foreach ($packages as $p): ?>
        <div class="col-md-4">
          <div class="card mb-3">
            <div class="card-header"><?=htmlspecialchars($p['name'])?></div>
            <div class="card-body">
              <h5>$<?=number_format($p['price'],2)?></h5>
              <p><?=$p['pv']?> PV</p>
              <form method="post">
                <input type="hidden" name="package_id" value="<?=$p['id']?>">
                <button class="btn btn-primary" name="action" value="buy_package">Buy Now</button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- TAB 5: Leadership -->
    <div class="tab-pane fade" id="leadership">
      <?php
      /* ---------- helper: get every indirect 1-5 levels deep ---------- */
      function getIndirects(PDO $pdo, int $rootId, int $maxLevel = 5): array
      {
          $allRows = [];
          $current = [$rootId];          // start with the logged-in user id
          for ($lvl = 1; $lvl <= $maxLevel; $lvl++) {
              if (!$current) break;

              $placeholders = implode(',', array_fill(0, count($current), '?'));
              $sql = "
                  SELECT id, username
                  FROM users
                  WHERE sponsor_name IN (
                        SELECT username FROM users WHERE id IN ($placeholders)
                      )";
              $stmt = $pdo->prepare($sql);
              $stmt->execute($current);
              $next = [];
              while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                  $row['lvl'] = $lvl;
                  $allRows[]  = $row;
                  $next[]     = (int)$row['id'];
              }
              $current = $next;
          }
          return $allRows;
      }

      $indirects = getIndirects($pdo, $uid);   // array of [id, username, lvl]
      ?>
      <div class="row">
        <div class="col-md-6">
          <div class="card mb-4">
            <div class="card-header">Total Matched Bonus Earned</div>
            <div class="card-body">
              <h4>$<?php
                $tot = $pdo->prepare("SELECT COALESCE(SUM(amount),0)
                                      FROM wallet_tx
                                      WHERE user_id = ? AND type = 'leadership_bonus'");
                $tot->execute([$uid]);
                echo number_format((float)$tot->fetchColumn(), 2);
              ?></h4>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Sponsorship Tree</h5>
        </div>
        <div class="card-body p-0">
          <div id="sponsorChart">
            <div class="chart-toolbar">
              <div class="chart-btn" id="resetZoomSponsor">Reset</div>
              <div class="chart-btn" id="expandAllSponsor">Expand All</div>
              <div class="chart-btn" id="collapseAllSponsor">Collapse All</div>
            </div>
            <div class="chart-hint">scroll to zoom • drag background to pan • click a node to collapse/expand</div>
          </div>
        </div>
      </div>

      <h5 class="mt-4">Indirect Down-lines & Leadership Paid</h5>
      <table class="table table-sm">
        <thead>
          <tr><th>Indirect</th><th>Level</th><th>Leadership Earned</th></tr>
        </thead>
        <tbody>
        <?php
        /* ---------- per-indirect earnings ---------- */
        foreach ($indirects as $ind) {
            $stmt = $pdo->prepare(
              "SELECT COALESCE(SUM(amount),0)
              FROM wallet_tx
              WHERE user_id = ?          -- the sponsor (logged-in user)
                AND type = 'leadership_bonus'
                AND created_at >= (
                      SELECT MIN(created_at)
                      FROM wallet_tx
                      WHERE user_id = ?  -- the indirect
                        AND type = 'pair_bonus'
                    )"
            );
            $stmt->execute([$uid, $ind['id']]);
            $earned = (float)$stmt->fetchColumn();
            echo '<tr>
                    <td>'.htmlspecialchars($ind['username']).'</td>
                    <td>L-'.$ind['lvl'].'</td>
                    <td>$'.number_format($earned, 2).'</td>
                  </tr>';
        }
        ?>
        </tbody>
      </table>
    </div>

    <!-- TAB 6: Mentor (leadership-reverse) -->
    <div class="tab-pane fade" id="mentor">
      <?php
      /* ---------- helper: get every ancestor 1-5 levels up ---------- */
      function getAncestors(PDO $pdo, int $rootId, int $maxLevel = 5): array
      {
          $allRows = [];
          $currentId = $rootId;
          for ($lvl = 1; $lvl <= $maxLevel; $lvl++) {
              $stmt = $pdo->prepare(
                'SELECT upline_id, username FROM users WHERE id = ?'
              );
              $stmt->execute([$currentId]);
              $row = $stmt->fetch(PDO::FETCH_ASSOC);
              if (!$row || !$row['upline_id']) break;

              $stmt = $pdo->prepare(
                'SELECT id, username FROM users WHERE id = ?'
              );
              $stmt->execute([$row['upline_id']]);
              $ancestor = $stmt->fetch(PDO::FETCH_ASSOC);
              if (!$ancestor) break;

              $ancestor['lvl'] = $lvl;
              $allRows[] = $ancestor;
              $currentId = $ancestor['id'];
          }
          return $allRows;
      }

      $ancestors = getAncestors($pdo, $uid);
      ?>
      <div class="row">
        <div class="col-md-6">
          <div class="card mb-4">
            <div class="card-header">Total Mentor Bonus Received</div>
            <div class="card-body">
              <h4>$<?php
                $tot = $pdo->prepare(
                  "SELECT COALESCE(SUM(amount),0)
                  FROM wallet_tx
                  WHERE user_id = ? AND type = 'leadership_reverse_bonus'"
                );
                $tot->execute([$uid]);
                echo number_format((float)$tot->fetchColumn(), 2);
              ?></h4>
            </div>
          </div>
        </div>
      </div>

      <h5 class="mt-4">Ancestors & Mentor Bonus Received</h5>
      <table class="table table-sm">
        <thead>
          <tr><th>Ancestor</th><th>Level</th><th>Mentor Bonus Received</th></tr>
        </thead>
        <tbody>
        <?php
        foreach ($ancestors as $anc) {
            $stmt = $pdo->prepare(
              "SELECT COALESCE(SUM(amount),0)
              FROM wallet_tx
              WHERE user_id = ?           -- the logged-in user
                AND type = 'leadership_reverse_bonus'
                AND created_at >= (
                      SELECT MIN(created_at)
                      FROM wallet_tx
                      WHERE user_id = ?   -- the ancestor
                        AND type = 'pair_bonus'
                    )"
            );
            $stmt->execute([$uid, $anc['id']]);
            $received = (float)$stmt->fetchColumn();
            echo '<tr>
                    <td>'.htmlspecialchars($anc['username']).'</td>
                    <td>L-'.$anc['lvl'].'</td>
                    <td>$'.number_format($received, 2).'</td>
                  </tr>';
        }
        ?>
        </tbody>
      </table>
    </div>
  </div>

  <a href="logout.php" class="btn btn-danger mt-4">Logout</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Org Chart JavaScript
const binaryData = <?php echo $binaryTreeJson ?: 'null'; ?>;
const sponsorData = <?php echo $sponsorTreeJson ?: 'null'; ?>;

// Initialize charts when tabs are shown
document.addEventListener('DOMContentLoaded', function() {
  // Initialize binary chart on page load if Tree tab is active
  if (document.querySelector('#home').classList.contains('show')) {
    initBinaryChart();
  }
  
  // Initialize charts when tabs are clicked
  document.querySelector('a[href="#home"]').addEventListener('shown.bs.tab', function () {
    initBinaryChart();
  });
  
  document.querySelector('a[href="#leadership"]').addEventListener('shown.bs.tab', function () {
    initSponsorChart();
  });
});

let binaryChartInitialized = false;
let sponsorChartInitialized = false;

function initBinaryChart() {
  if (binaryChartInitialized || !binaryData || !binaryData.id) {
    return;
  }
  binaryChartInitialized = true;
  renderOrgChart(binaryData, 'orgChart', 'resetZoom', 'expandAll', 'collapseAll');
}

function initSponsorChart() {
  if (sponsorChartInitialized || !sponsorData || !sponsorData.id) {
    return;
  }
  sponsorChartInitialized = true;
  renderOrgChart(sponsorData, 'sponsorChart', 'resetZoomSponsor', 'expandAllSponsor', 'collapseAllSponsor');
}

function renderOrgChart(rootData, containerId, resetId, expandId, collapseId) {
  const container = document.getElementById(containerId);
  let width = container.clientWidth;
  let height = container.clientHeight;

  // Clear any existing chart
  d3.select(`#${containerId} svg`).remove();

  // SVG + top-level group for zoom/pan
  const svg = d3.select(`#${containerId}`).append('svg')
    .attr('width', width)
    .attr('height', height)
    .attr('viewBox', [0, 0, width, height].join(' '))
    .style('display', 'block');

  const g = svg.append('g');
  
  // Create separate groups for links and nodes to control z-order
  const linkGroup = g.append('g').attr('class', 'links');
  const nodeGroup = g.append('g').attr('class', 'nodes');

  // Config
  const duration = 300;
  const nodeWidth = 160;
  const nodeHeight = 42;
  const levelGapY = 95;    // vertical distance between levels
  const siblingGapX = 26;  // horizontal separation base

  // Create hierarchy
  const root = d3.hierarchy(rootData, d => d.children);

  // Collapsible: start collapsed below first level
  root.x0 = width / 2;
  root.y0 = 40;
  if (root.children) root.children.forEach(collapse);

  // D3 vertical tree
  const tree = d3.tree()
    .nodeSize([nodeWidth + siblingGapX, levelGapY])
    .separation((a, b) => {
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
  document.getElementById(resetId).onclick = () => { centerOnRoot(); };
  document.getElementById(expandId).onclick = () => { expandAll(root); update(root); };
  document.getElementById(collapseId).onclick = () => { collapseAll(root); update(root); };

  function centerOnRoot() {
    const currentTransform = d3.zoomTransform(svg.node());
    const scale = 0.9;
    const tx = width / 2 - (root.x0 ?? root.x) * scale;
    const ty = 60 - (root.y0 ?? root.y) * scale;
    svg.transition().duration(300).call(zoom.transform, d3.zoomIdentity.translate(tx, ty).scale(scale));
  }

  function update(source) {
    // Compute layout
    tree(root);

    // Convert to vertical coordinates
    root.each(d => {
      d.y = d.depth * levelGapY + 80;
    });

    // ----- LINKS -----
    const link = linkGroup.selectAll('path.link')
      .data(root.links(), d => d.target.data.id);

    // Enter
    link.enter().append('path')
      .attr('class', 'link')
      .attr('d', d => elbow({source: source, target: source}))
      .merge(link)
      .transition().duration(duration)
      .attr('d', d => elbow(d));

    // Exit
    link.exit()
      .transition().duration(duration)
      .attr('d', d => elbow({source: source, target: source}))
      .remove();

    // ----- NODES -----
    const node = nodeGroup.selectAll('g.node')
      .data(root.descendants(), d => d.data.id);

    // Enter group
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

    // Position badge - only show for binary tree (not sponsorship tree)
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
        .text(d => {
          // root has depth 0; never show badge
          if (d.depth === 0) return '';
          const pos = d.data.position;
          return pos === 'left' ? 'L' : 'R';
        });
    }

    // Update + transition to new positions
    const nodeUpdate = nodeEnter.merge(node);
    
    // Update node classes based on current children state
    nodeUpdate.attr('class', d => {
      const hasChildren = (d.children && d.children.length > 0) || (d._children && d._children.length > 0);
      return hasChildren ? 'node has-children' : 'node no-children';
    });
    
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