<?php
ob_start();                       // prevent stray output
require_once 'config.php';
require_once 'functions.php';

/* ---------- AJAX endpoints ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    http_response_code(200);

    if (!isset($user) || $user['role'] !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
        exit;
    }

    try {
        switch ($_POST['action']) {
            case 'add':
                $stmt = $pdo->prepare("
                    INSERT INTO products
                    (name, price, image_url, short_desc, long_desc, discount, affiliate_rate, active)
                    VALUES (?,?,?,?,?,?,?,1)
                ");
                $stmt->execute([
                    trim($_POST['name']),
                    max(0, (float)$_POST['price']),
                    trim($_POST['image_url']) ?: null,
                    trim($_POST['short_desc']),
                    trim($_POST['long_desc']),
                    max(0, min(100, (float)($_POST['discount'] ?? 0))),
                    max(0, min(100, (float)($_POST['affiliate_rate'] ?? 0)))
                ]);
                while (ob_get_level()) ob_end_clean();
                echo json_encode(['status' => 'success', 'message' => 'Product added']);
                break;

            case 'update':
                if (empty($_POST['id'])) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Product ID missing']);
                    exit;
                }
                $stmt = $pdo->prepare("
                    UPDATE products
                    SET name = ?, price = ?, image_url = ?,
                        short_desc = ?, long_desc = ?, discount = ?, affiliate_rate = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    trim($_POST['name']),
                    max(0, (float)$_POST['price']),
                    trim($_POST['image_url']) ?: null,
                    trim($_POST['short_desc']),
                    trim($_POST['long_desc']),
                    max(0, min(100, (float)($_POST['discount'] ?? 0))),
                    max(0, min(100, (float)($_POST['affiliate_rate'] ?? 0))),
                    (int)$_POST['id']
                ]);
                while (ob_get_level()) ob_end_clean();
                echo json_encode(['status' => 'success', 'message' => 'Product updated']);
                break;

            case 'delete':
                if (empty($_POST['id'])) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Product ID missing']);
                    exit;
                }
                $check = $pdo->prepare("
                    SELECT COUNT(*) FROM wallet_tx wt
                    WHERE wt.type = 'product_purchase'
                      AND EXISTS (
                          SELECT 1 FROM products p
                          WHERE p.id = ?
                            AND ABS(wt.amount) = p.price * (1 - p.discount/100)
                      )
                ");
                $check->execute([(int)$_POST['id']]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception('Cannot delete product with existing transactions');
                }
                $del = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $del->execute([(int)$_POST['id']]);
                while (ob_get_level()) ob_end_clean();
                echo json_encode(['status' => 'success', 'message' => 'Product deleted']);
                break;

            case 'toggle':
                if (empty($_POST['id'])) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Product ID missing']);
                    exit;
                }
                $pdo->prepare("UPDATE products SET active = NOT active WHERE id = ?")
                     ->execute([(int)$_POST['id']]);
                while (ob_get_level()) ob_end_clean();
                echo json_encode(['status' => 'success', 'message' => 'Status toggled']);
                break;

            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        while (ob_get_level()) ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

/* ---------- page protection ---------- */
if (!isset($user) || $user['role'] !== 'admin') {
    redirect('../dashboard.php', 'Admin access only');
}

/* ---------- fetch products ---------- */
$products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Products</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* Toast + modal styles (copied from settings.php) */
    .toast-container{position:fixed;top:20px;right:20px;z-index:1000;pointer-events:none}
    .toast{background:#fff;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,.1);min-width:300px;max-width:400px;padding:16px;border-left:4px solid;transform:translateX(100%);opacity:0;transition:all .3s cubic-bezier(.68,-.55,.27,1.55);pointer-events:auto}
    .toast.show{transform:translateX(0);opacity:1}
    .toast.success{border-left-color:#10b981}
    .toast.error{border-left-color:#ef4444}
    .toast .toast-close{background:none;border:none;font-size:18px;color:#6b7280;cursor:pointer}
    .modal{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:.3s;z-index:1000;padding:1rem}
    .modal.show{opacity:1;visibility:visible}
    .modal-content{background:#fff;border-radius:12px;padding:24px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto}
  </style>
</head>
<body class="bg-gray-100">
  <!-- Toast container -->
  <div id="toast-container" class="toast-container"></div>

  <!-- Add/Edit Modal -->
  <div id="prodModal" class="modal">
    <div class="modal-content">
      <h3 id="modalTitle" class="text-xl mb-4 font-semibold">Add Product</h3>
      <form id="prodForm" onsubmit="return saveProduct(event)">
        <input type="hidden" name="action" id="action" value="add">
        <input type="hidden" name="id" id="prodId">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium mb-1">Product Name *</label>
            <input required id="name" name="name" placeholder="Enter product name" class="w-full border rounded p-2">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Price *</label>
            <input required id="price" name="price" type="number" step="0.01" min="0" placeholder="0.00" class="w-full border rounded p-2">
          </div>
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium mb-1">Image URL</label>
          <input id="image_url" name="image_url" placeholder="https://example.com/image.jpg" class="w-full border rounded p-2">
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium mb-1">Short Description *</label>
          <textarea required id="short_desc" name="short_desc" placeholder="Brief description for product cards" class="w-full border rounded p-2 h-16"></textarea>
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium mb-1">Long Description</label>
          <textarea id="long_desc" name="long_desc" placeholder="Detailed description" class="w-full border rounded p-2 h-24"></textarea>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
          <div>
            <label class="block text-sm font-medium mb-1">Discount % (0-100)</label>
            <input id="discount" name="discount" type="number" min="0" max="100" placeholder="0" class="w-full border rounded p-2">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Affiliate Rate % (0-100)</label>
            <input id="affiliate_rate" name="affiliate_rate" type="number" min="0" max="100" placeholder="0" class="w-full border rounded p-2">
          </div>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            <span class="button-text">Save Product</span>
            <span class="button-loading hidden">
              <svg class="animate-spin h-4 w-4 inline mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Saving...
            </span>
          </button>
          <button type="button" onclick="closeModal()" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Page Content -->
  <div class="container mx-auto px-4 py-8">
    <div class="bg-white shadow rounded-lg p-6">
      <div class="flex justify-between items-center mb-6">
        <div>
          <h2 class="text-2xl font-bold">Manage Products</h2>
          <p class="text-gray-600">Add, edit, and manage affiliate products</p>
        </div>
        <button onclick="openAddModal()" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Add New Product</button>
      </div>

      <?php if ($products): ?>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="text-gray-600 border-b">
                <th class="p-3">ID</th><th class="p-3">Product</th><th class="p-3">Price</th>
                <th class="p-3">Discount</th><th class="p-3">Aff %</th><th class="p-3">Status</th><th class="p-3">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($products as $p): ?>
                <tr class="border-b hover:bg-gray-50">
                  <td class="p-3"><?= htmlspecialchars($p['id']) ?></td>
                  <td class="p-3">
                    <div class="flex items-center gap-3">
                      <img src="<?= htmlspecialchars($p['image_url'] ?? '') ?>" class="w-12 h-12 object-cover rounded" alt="<?= htmlspecialchars($p['name']) ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                      <div class="w-12 h-12 bg-gray-200 rounded items-center justify-center hidden"><span class="text-gray-500 text-xs">No img</span></div>
                      <div>
                        <div class="font-medium"><?= htmlspecialchars($p['name']) ?></div>
                        <div class="text-sm text-gray-600"><?= htmlspecialchars(mb_substr($p['short_desc'] ?? '',0,50)) ?><?= mb_strlen($p['short_desc'] ?? '') > 50 ? '...' : '' ?></div>
                      </div>
                    </div>
                  </td>
                  <td class="p-3">
                    <div>$<?= number_format($p['price'],2) ?></div>
                    <?php if($p['discount']>0): ?>
                      <div class="text-sm text-green-600">Sale: $<?= number_format($p['price']*(1-$p['discount']/100),2) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="p-3"><?= $p['discount']>0 ? '<span class="bg-red-100 text-red-800 px-2 py-1 rounded text-sm">'.$p['discount'].'%</span>' : '<span class="text-gray-400">0%</span>' ?></td>
                  <td class="p-3"><?= $p['affiliate_rate']>0 ? '<span class="bg-purple-100 text-purple-800 px-2 py-1 rounded text-sm">'.$p['affiliate_rate'].'%</span>' : '<span class="text-gray-400">0%</span>' ?></td>
                  <td class="p-3">
                    <label class="inline-flex items-center cursor-pointer">
                      <input type="checkbox" <?= $p['active']?'checked':'' ?> onchange="toggleStatus(<?= $p['id'] ?>)">
                      <div class="relative ml-2">
                        <div class="block <?= $p['active']?'bg-green-400':'bg-gray-600' ?> w-14 h-8 rounded-full"></div>
                        <div class="dot absolute left-1 top-1 bg-white w-6 h-6 rounded-full transition <?= $p['active']?'translate-x-6':'' ?>"></div>
                      </div>
                    </label>
                  </td>
                  <td class="p-3 space-x-2">
                    <button class="text-blue-600 hover:text-blue-800" onclick="openEditModal(<?= htmlspecialchars(json_encode($p,JSON_NUMERIC_CHECK|JSON_HEX_QUOT)) ?>)">Edit</button>
                    <button class="text-red-600 hover:text-red-800" onclick="deleteProduct(<?= $p['id'] ?>)">Delete</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="text-center py-8"><p class="text-gray-500">No products found. Create your first product!</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- JS -->
  <script>
    class ToastNotification {
      constructor() { this.container = document.getElementById('toast-container'); }
      show(msg, type = 'info', dur = 4000) {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<div class="flex justify-between items-start"><span>${msg}</span><button class="toast-close" onclick="this.parentElement.parentElement.remove()">&times;</button></div>`;
        this.container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => toast.remove(), dur);
      }
      success(msg) { this.show(msg, 'success'); }
      error(msg)  { this.show(msg, 'error'); }
    }
    const toastSystem = new ToastNotification();

    function setButtonState(btn, loading) {
      const text = btn.querySelector('.button-text');
      const spin = btn.querySelector('.button-loading');
      loading ? (text.classList.add('hidden'), spin.classList.remove('hidden'), btn.disabled = true)
              : (text.classList.remove('hidden'), spin.classList.add('hidden'), btn.disabled = false);
    }

    /* modal helpers */
    function closeModal() {
      const m = document.getElementById('prodModal');
      m.classList.remove('show');
      setTimeout(() => m.style.display = 'none', 300);
      document.body.style.overflow = 'auto';
    }
    function openAddModal() {
      const m = document.getElementById('prodModal');
      m.style.display = 'flex';
      m.classList.add('show');
      document.getElementById('modalTitle').textContent = 'Add Product';
      document.getElementById('action').value = 'add';
      document.getElementById('prodForm').reset();
    }
    function openEditModal(p) {
      openAddModal();
      document.getElementById('modalTitle').textContent = 'Edit Product';
      document.getElementById('action').value = 'update';
      document.getElementById('prodId').value = p.id ?? '';
      ['name','price','image_url','short_desc','long_desc','discount','affiliate_rate']
        .forEach(k => { const el = document.getElementById(k); if (el) el.value = p[k] ?? ''; });
    }

    /* CRUD */
    function saveProduct(e) {
      e.preventDefault();
      const btn = e.target.querySelector('button[type="submit"]');
      setButtonState(btn, true);
      fetch(location.href, { method: 'POST', body: new FormData(e.target) })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(res => {
          toastSystem[res.status](res.message);
          if (res.status === 'success') setTimeout(() => location.reload(), 1200);
        })
        .catch(err => toastSystem.error(err.message))
        .finally(() => setButtonState(btn, false));
    }
    function deleteProduct(id) {
      if (!confirm('Delete this product? Cannot be undone.')) return;
      fetch(location.href, { method: 'POST', body: new URLSearchParams({ action: 'delete', id }) })
        .then(r => r.json())
        .then(res => {
          toastSystem[res.status](res.message);
          if (res.status === 'success') setTimeout(() => location.reload(), 1200);
        })
        .catch(err => toastSystem.error(err.message));
    }
    function toggleStatus(id) {
      fetch(location.href, { method: 'POST', body: new URLSearchParams({ action: 'toggle', id }) })
        .then(r => r.json())
        .then(res => { toastSystem[res.status](res.message); setTimeout(() => location.reload(), 600); })
        .catch(() => location.reload());
    }

    /* close modal on ESC / backdrop */
    document.addEventListener('keydown', e => e.key === 'Escape' && closeModal());
    document.addEventListener('click', e => e.target.id === 'prodModal' && closeModal());
  </script>
</body>
</html>