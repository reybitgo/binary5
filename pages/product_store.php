<?php
// pages/product_store.php - Enhanced affiliate-driven product store
$is_logged_in = isset($_SESSION['user_id']);
$affiliate_id = isset($_SESSION['aff']) ? (int)$_SESSION['aff'] : (isset($_GET['aff']) ? (int)$_GET['aff'] : null);

// Validate affiliate if provided
$affiliate_user = null;
if ($affiliate_id) {
    $stmt = $pdo->prepare("SELECT id, username, status FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([$affiliate_id]);
    $affiliate_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($affiliate_user) {
        $_SESSION['aff'] = $affiliate_id; // Persist affiliate ID
    } else {
        unset($_SESSION['aff']);
        $affiliate_id = null;
    }
}

// Get highlighted product ID if coming from direct product link
$highlight_product_id = isset($_SESSION['product_id']) ? (int)$_SESSION['product_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Get all active products
$products_query = "
    SELECT p.*, 
           COALESCE(sales.total_sales, 0) as total_sales,
           COALESCE(sales.sales_count, 0) as sales_count
    FROM products p
    LEFT JOIN (
        SELECT 
            p2.id as product_id,
            COUNT(*) as sales_count,
            SUM(ABS(wt.amount)) as total_sales
        FROM wallet_tx wt
        JOIN products p2 ON ABS(wt.amount) = p2.price * (1 - p2.discount/100)
        WHERE wt.type = 'product_purchase'
        GROUP BY p2.id
    ) sales ON sales.product_id = p.id
    WHERE p.active = 1
    ORDER BY 
        CASE WHEN p.id = ? THEN 0 ELSE 1 END,
        sales.sales_count DESC, 
        p.created_at DESC
";

$stmt = $pdo->prepare($products_query);
$stmt->execute([$highlight_product_id ?? 0]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate cart totals
$cart_total = 0;
$cart_items = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $pid => $qty) {
        if (isset($_SESSION['cart_products'][$pid])) {
            $product = $_SESSION['cart_products'][$pid];
            $final_price = $product['price'] * (1 - $product['discount'] / 100);
            $cart_total += $final_price * $qty;
            $cart_items += $qty;
        }
    }
}
?>

<div class="bg-white shadow rounded-lg">
    <!-- Header Section -->
    <div class="p-6 border-b">
        <div class="flex justify-between items-start mb-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Product Store</h2>
                <?php if ($affiliate_user): ?>
                    <p class="text-sm text-blue-600 mt-1">
                        <i class="fas fa-user-friends"></i>
                        Referred by <strong><?= htmlspecialchars($affiliate_user['username']) ?></strong>
                        <?php if (!$is_logged_in): ?>
                            - <span class="text-green-600 font-medium">Get bonus commissions on your purchases!</span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <!-- Cart Summary (if items in cart) -->
            <?php if ($cart_items > 0): ?>
                <div class="bg-blue-50 rounded-lg p-3 border border-blue-200">
                    <div class="text-sm text-blue-800 font-medium">
                        Cart: <?= $cart_items ?> item<?= $cart_items > 1 ? 's' : '' ?>
                    </div>
                    <div class="text-lg font-bold text-blue-900">
                        $<?= number_format($cart_total, 2) ?>
                    </div>
                    <button onclick="showCart()" class="text-xs text-blue-600 hover:text-blue-800 mt-1">
                        View Cart
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions for Logged-in Users -->
        <?php if ($is_logged_in): ?>
            <div class="flex gap-3 mb-4">
                <a href="dashboard.php?page=affiliate" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    🔗 Get Your Affiliate Links
                </a>
                <a href="dashboard.php?page=wallet" class="text-green-600 hover:text-green-800 text-sm font-medium">
                    💰 Wallet: $<?= number_format($user['balance'], 2) ?>
                </a>
            </div>
        <?php endif; ?>
        
        <!-- Non-member CTA -->
        <?php if (!$is_logged_in): ?>
            <div class="bg-gradient-to-r from-green-50 to-blue-50 rounded-lg p-4 border border-green-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-semibold text-gray-800">New to Shoppe Club?</h3>
                        <p class="text-sm text-gray-600">Add products to cart and create your account during checkout!</p>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-green-600 font-medium">Already have account?</div>
                        <a href="login.php<?= $affiliate_id ? '?aff=' . $affiliate_id : '' ?>" 
                           class="text-blue-600 hover:text-blue-800 font-medium">Sign In</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Products Grid -->
    <div class="p-6">
        <?php if (empty($products)): ?>
            <div class="text-center py-12">
                <div class="text-gray-400 text-6xl mb-4">📦</div>
                <h3 class="text-lg font-medium text-gray-800 mb-2">No Products Available</h3>
                <p class="text-gray-500">Check back soon for new products!</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($products as $product): ?>
                    <div class="product-card border rounded-lg overflow-hidden hover:shadow-lg transition-shadow
                               <?= $highlight_product_id == $product['id'] ? 'ring-2 ring-blue-500 ring-opacity-50' : '' ?>">
                        
                        <!-- Product Image -->
                        <div class="aspect-w-16 aspect-h-12 bg-gray-100">
                            <?php if ($product['image_url']): ?>
                                <img src="<?= htmlspecialchars($product['image_url']) ?>" 
                                     alt="<?= htmlspecialchars($product['name']) ?>"
                                     class="w-full h-48 object-cover">
                            <?php else: ?>
                                <div class="w-full h-48 bg-gray-200 flex items-center justify-center">
                                    <span class="text-gray-400 text-4xl">📦</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="p-4">
                            <!-- Product Header -->
                            <div class="flex justify-between items-start mb-2">
                                <h3 class="font-semibold text-lg text-gray-800 truncate" title="<?= htmlspecialchars($product['name']) ?>">
                                    <?= htmlspecialchars($product['name']) ?>
                                </h3>
                                <?php if ($highlight_product_id == $product['id']): ?>
                                    <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2 py-1 rounded-full">
                                        Featured
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Description -->
                            <p class="text-sm text-gray-600 mb-3 line-clamp-2">
                                <?= htmlspecialchars($product['short_desc']) ?>
                            </p>

                            <!-- Price Section -->
                            <div class="mb-4">
                                <?php 
                                $original_price = $product['price'];
                                $final_price = $original_price * (1 - $product['discount'] / 100);
                                ?>
                                <div class="flex items-center justify-between">
                                    <div>
                                        <span class="text-xl font-bold text-gray-900">
                                            $<?= number_format($final_price, 2) ?>
                                        </span>
                                        <?php if ($product['discount'] > 0): ?>
                                            <span class="text-sm text-gray-500 line-through ml-2">
                                                $<?= number_format($original_price, 2) ?>
                                            </span>
                                            <span class="bg-red-100 text-red-800 text-xs font-medium px-2 py-1 rounded-full ml-2">
                                                <?= $product['discount'] ?>% OFF
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Affiliate Commission Info -->
                                <?php if ($product['affiliate_rate'] > 0): ?>
                                    <div class="text-xs text-purple-600 mt-1">
                                        💰 Earn $<?= number_format($final_price * ($product['affiliate_rate'] / 100), 2) ?> commission
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Sales Badge -->
                            <?php if ($product['sales_count'] > 0): ?>
                                <div class="text-xs text-green-600 mb-3">
                                    🔥 <?= $product['sales_count'] ?> sold
                                </div>
                            <?php endif; ?>

                            <!-- Add to Cart Form -->
                            <form class="add-to-cart-form" data-product-id="<?= $product['id'] ?>">
                                <div class="flex items-center gap-2 mb-3">
                                    <label class="text-sm font-medium text-gray-700">Qty:</label>
                                    <select name="quantity" class="form-select text-sm border rounded px-2 py-1 w-20">
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <option value="<?= $i ?>"><?= $i ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                
                                <button type="submit" 
                                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded transition-colors">
                                    Add to Cart
                                </button>
                            </form>

                            <!-- Quick Actions -->
                            <div class="flex gap-2 mt-2">
                                <?php if ($is_logged_in): ?>
                                    <button onclick="buyNow(<?= $product['id'] ?>)" 
                                            class="flex-1 bg-green-600 hover:bg-green-700 text-white text-sm font-medium py-1 px-3 rounded transition-colors">
                                        Buy Now
                                    </button>
                                <?php endif; ?>
                                <button onclick="showProductDetails(<?= $product['id'] ?>)" 
                                        class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm font-medium py-1 px-3 rounded transition-colors">
                                    Details
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Checkout Section (if cart has items) -->
    <?php if ($cart_items > 0): ?>
        <div class="border-t bg-gray-50 p-6">
            <div class="flex justify-between items-center">
                <div class="text-lg font-semibold text-gray-800">
                    Ready to checkout? 
                    <span class="text-blue-600"><?= $cart_items ?> item<?= $cart_items > 1 ? 's' : '' ?> in cart</span>
                </div>
                <div class="flex gap-3">
                    <button onclick="showCart()" class="bg-gray-200 hover:bg-gray-300 px-4 py-2 rounded text-gray-800 font-medium">
                        View Cart
                    </button>
                    <button onclick="proceedToCheckout()" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded font-medium">
                        Checkout - $<?= number_format($cart_total, 2) ?>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Cart Modal -->
<div id="cartModal" class="modal">
    <div class="modal-content max-w-2xl">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">Shopping Cart</h3>
            <button onclick="closeModal('cartModal')" class="text-gray-500 hover:text-gray-700">
                <span class="text-2xl">&times;</span>
            </button>
        </div>
        
        <div id="cartItems">
            <!-- Cart items will be loaded here -->
        </div>
        
        <div class="border-t pt-4 mt-4">
            <div class="flex justify-between items-center text-lg font-bold mb-4">
                <span>Total:</span>
                <span id="cartTotal">$<?= number_format($cart_total, 2) ?></span>
            </div>
            
            <div class="flex gap-3">
                <button onclick="closeModal('cartModal')" class="flex-1 bg-gray-200 hover:bg-gray-300 px-4 py-2 rounded text-gray-800 font-medium">
                    Continue Shopping
                </button>
                <button onclick="proceedToCheckout()" class="flex-1 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded font-medium">
                    Proceed to Checkout
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Product Details Modal -->
<div id="productModal" class="modal">
    <div class="modal-content max-w-2xl">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold" id="productModalTitle">Product Details</h3>
            <button onclick="closeModal('productModal')" class="text-gray-500 hover:text-gray-700">
                <span class="text-2xl">&times;</span>
            </button>
        </div>
        <div id="productModalContent">
            <!-- Product details will be loaded here -->
        </div>
    </div>
</div>

<style>
.modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    visibility: hidden;
    transition: all .3s;
    z-index: 1000;
}
.modal.show {
    opacity: 1;
    visibility: visible;
}
.modal-content {
    background: #fff;
    padding: 1.5rem;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
}
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.product-card {
    transition: transform 0.2s ease-in-out;
}
.product-card:hover {
    transform: translateY(-2px);
}
</style>

<script>
// Fixed JavaScript for pages/product_store.php
// Replace the entire <script> section with this

// Add to cart functionality
document.querySelectorAll('.add-to-cart-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const productId = this.dataset.productId;
        const quantity = this.querySelector('[name="quantity"]').value;
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        
        // Show loading state
        submitBtn.textContent = 'Adding...';
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-75');
        
        // Prepare form data
        const formData = new FormData();
        formData.append('action', 'add_to_cart');
        formData.append('product_id', productId);
        formData.append('quantity', quantity);
        formData.append('page', 'product_store'); // Add page parameter
        formData.append('ajax', '1');
        
        fetch('dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Success state
                submitBtn.textContent = 'Added!';
                submitBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700', 'opacity-75');
                submitBtn.classList.add('bg-green-600');
                
                // Show success message if available
                if (data.message) {
                    showNotification(data.message, 'success');
                }
                
                setTimeout(() => {
                    location.reload(); // Refresh to update cart display
                }, 1000);
            } else {
                // Error state
                const errorMsg = data.message || 'Failed to add product to cart';
                showNotification(errorMsg, 'error');
                
                // Reset button
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-75');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Network error. Please check your connection and try again.', 'error');
            
            // Reset button
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-75');
        });
    });
});

// Notification function
function showNotification(message, type = 'info') {
    // Remove existing notification
    const existingNotification = document.querySelector('.notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification fixed top-4 right-4 z-50 max-w-sm p-4 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full`;
    
    // Set colors based on type
    if (type === 'success') {
        notification.classList.add('bg-green-100', 'border-l-4', 'border-green-500', 'text-green-700');
    } else if (type === 'error') {
        notification.classList.add('bg-red-100', 'border-l-4', 'border-red-500', 'text-red-700');
    } else {
        notification.classList.add('bg-blue-100', 'border-l-4', 'border-blue-500', 'text-blue-700');
    }
    
    notification.innerHTML = `
        <div class="flex items-center justify-between">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    ${type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ'}
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium">${message}</p>
                </div>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-lg leading-none">&times;</button>
        </div>
    `;
    
    // Add to DOM
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.classList.add('translate-x-full');
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 300);
        }
    }, 5000);
}

function showCart() {
    loadCart();
    document.getElementById('cartModal')?.classList.add('show');
}

function loadCart() {
    const cartItems = <?= json_encode($_SESSION['cart'] ?? []) ?>;
    const cartProducts = <?= json_encode($_SESSION['cart_products'] ?? []) ?>;
    const cartContainer = document.getElementById('cartItems');
    
    if (!cartContainer) return; // Guard clause
    
    if (Object.keys(cartItems).length === 0) {
        cartContainer.innerHTML = '<p class="text-gray-500 text-center py-8">Your cart is empty</p>';
        return;
    }
    
    let html = '';
    let total = 0;
    
    for (const [productId, quantity] of Object.entries(cartItems)) {
        const product = cartProducts[productId];
        if (product) {
            const finalPrice = product.price * (1 - product.discount / 100);
            const itemTotal = finalPrice * quantity;
            total += itemTotal;
            
            html += `
                <div class="flex items-center justify-between border-b pb-3 mb-3">
                    <div class="flex items-center space-x-3">
                        <img src="${product.image_url || '/images/placeholder.jpg'}" alt="${escapeHtml(product.name)}" class="w-16 h-16 object-cover rounded">
                        <div>
                            <h4 class="font-medium">${escapeHtml(product.name)}</h4>
                            <p class="text-sm text-gray-500">$${finalPrice.toFixed(2)} each</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <input type="number" value="${quantity}" min="1" max="10" 
                               class="w-16 px-2 py-1 border rounded text-center"
                               onchange="updateCartItem(${productId}, this.value)">
                        <button onclick="removeCartItem(${productId})" class="text-red-500 hover:text-red-700">
                            <span class="text-lg">&times;</span>
                        </button>
                    </div>
                </div>
            `;
        }
    }
    
    cartContainer.innerHTML = html;
    const cartTotalElement = document.getElementById('cartTotal');
    if (cartTotalElement) {
        cartTotalElement.textContent = `$${total.toFixed(2)}`;
    }
}

// Helper function to escape HTML
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

function updateCartItem(productId, quantity) {
    const formData = new FormData();
    formData.append('action', 'update_cart');
    formData.append('product_id', productId);
    formData.append('quantity', quantity);
    formData.append('page', 'product_store');
    formData.append('ajax', '1');
    
    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadCart();
        } else {
            showNotification('Failed to update cart', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Failed to update cart', 'error');
    });
}

function removeCartItem(productId) {
    updateCartItem(productId, 0);
}

function proceedToCheckout() {
    <?php if (isset($_SESSION['user_id'])): ?>
        // For logged-in users, go to member checkout
        window.location.href = 'dashboard.php?page=checkout';
    <?php else: ?>
        // For guests, go to guest checkout
        window.location.href = 'checkout.php<?= isset($_SESSION["aff"]) ? "?aff=" . $_SESSION["aff"] : "" ?>';
    <?php endif; ?>
}

function buyNow(productId) {
    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('quantity', '1');
    formData.append('page', 'product_store');
    formData.append('ajax', '1');
    
    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            proceedToCheckout();
        } else {
            showNotification(data.message || 'Failed to add product', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Failed to add product', 'error');
    });
}

function showProductDetails(productId) {
    const products = <?= json_encode($products) ?>;
    const product = products.find(p => p.id == productId);
    
    if (product) {
        document.getElementById('productModalTitle').textContent = product.name;
        
        const finalPrice = product.price * (1 - product.discount / 100);
        
        document.getElementById('productModalContent').innerHTML = `
            <div class="mb-4">
                <img src="${product.image_url || '/images/placeholder.jpg'}" alt="${escapeHtml(product.name)}" class="w-full max-w-md mx-auto rounded-lg">
            </div>
            <div class="space-y-3">
                <div>
                    <h4 class="font-semibold text-lg mb-2">Price</h4>
                    <div class="text-2xl font-bold text-green-600">$${finalPrice.toFixed(2)}</div>
                    ${product.discount > 0 ? `<div class="text-sm text-gray-500 line-through">Was: $${product.price}</div>` : ''}
                </div>
                
                <div>
                    <h4 class="font-semibold mb-2">Description</h4>
                    <p class="text-gray-700">${escapeHtml(product.short_desc)}</p>
                </div>
                
                ${product.long_desc ? `
                <div>
                    <h4 class="font-semibold mb-2">Details</h4>
                    <p class="text-gray-700">${escapeHtml(product.long_desc)}</p>
                </div>
                ` : ''}
                
                ${product.affiliate_rate > 0 ? `
                <div class="bg-purple-50 p-3 rounded">
                    <h4 class="font-semibold text-purple-800 mb-1">Affiliate Program</h4>
                    <p class="text-sm text-purple-700">Earn ${product.affiliate_rate}% commission ($${(finalPrice * product.affiliate_rate / 100).toFixed(2)}) for each sale you refer!</p>
                </div>
                ` : ''}
            </div>
            
            <div class="mt-6 flex gap-3">
                <button onclick="addToCartFromModal(${product.id})" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded font-medium">
                    Add to Cart
                </button>
                <button onclick="closeModal('productModal')" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-800 py-2 px-4 rounded font-medium">
                    Close
                </button>
            </div>
        `;
        
        document.getElementById('productModal')?.classList.add('show');
    }
}

function addToCartFromModal(productId) {
    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('quantity', '1');
    formData.append('page', 'product_store');
    formData.append('ajax', '1');
    
    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeModal('productModal');
            showNotification(data.message || 'Product added to cart!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(data.message || 'Failed to add product', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Failed to add product', 'error');
    });
}

function closeModal(modalId) {
    document.getElementById(modalId)?.classList.remove('show');
}

// Close modals when clicking outside
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('show');
        }
    });
});
</script>