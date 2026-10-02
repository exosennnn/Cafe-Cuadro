<?php
require 'kiosk_bootstrap.php';

if (empty($_SESSION['kiosk_order_type'])) {
    header('Location: ' . BASE_URL . 'pos/kiosk-order-type');
    exit;
}

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// -------- Add to cart (mirrors sales_transaction.php's stock-aware add) --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $response = ['success' => false, 'message' => 'Unable to add this item.', 'cart_count' => kiosk_cart_count()];

    if (!kiosk_csrf_valid()) {
        $response['message'] = 'Your session expired. Please refresh the page.';
    } else {
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $stmt = $conn->prepare("SELECT id, name, price, stock, image_url FROM products WHERE id = ? AND is_active = 1");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product && (int) $product['stock'] > 0) {
            $current_qty = $_SESSION['kiosk_cart'][$product_id]['quantity'] ?? 0;
            if ($current_qty + 1 <= (int) $product['stock']) {
                $_SESSION['kiosk_cart'][$product_id] = [
                    'id'        => $product['id'],
                    'name'      => $product['name'],
                    'price'     => (float) $product['price'],
                    'quantity'  => $current_qty + 1,
                    'stock'     => (int) $product['stock'],
                    'image_url' => $product['image_url'] ?? '',
                ];
                $response = ['success' => true, 'message' => $product['name'] . ' added to cart.', 'cart_count' => kiosk_cart_count()];
            } else {
                $response['message'] = 'Sorry, only ' . (int) $product['stock'] . ' left in stock.';
            }
        } else {
            $response['message'] = 'This item is currently out of stock.';
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$category_id = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$search      = trim($_GET['search'] ?? '');

$categories = $conn->query("SELECT id, name, icon FROM menu_categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

$sql    = "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN menu_categories c ON c.id = p.category_id WHERE p.is_active = 1";
$params = [];
if ($category_id) {
    $sql .= " AND p.category_id = ?";
    $params[] = $category_id;
}
if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$sql .= " ORDER BY p.name";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cat_icons = [];
foreach ($categories as $c) {
    $cat_icons[$c['id']] = $c['icon'] ?? '🍽️';
}
$csrf = h(kiosk_csrf_token());

function get_category_bi_icon($name, $default = 'bi-tags') {
    $n = strtolower(trim((string)$name));
    if (strpos($n, 'all') !== false) return 'bi-grid';
    if (strpos($n, 'hot') !== false) return 'bi-cup-hot';
    if (strpos($n, 'iced') !== false || strpos($n, 'cold') !== false) return 'bi-snow';
    if (strpos($n, 'frappe') !== false || strpos($n, 'blend') !== false) return 'bi-cup-straw';
    if (strpos($n, 'non-coffee') !== false || strpos($n, 'tea') !== false) return 'bi-droplet';
    if (strpos($n, 'coffee') !== false) return 'bi-cup-hot';
    if (strpos($n, 'pastr') !== false || strpos($n, 'bread') !== false || strpos($n, 'cake') !== false || strpos($n, 'food') !== false || strpos($n, 'croissant') !== false) return 'bi-cake2';
    if (strpos($n, 'add') !== false || strpos($n, 'extra') !== false) return 'bi-plus-circle';
    return $default;
}

function kiosk_product_card($product, $cat_icons, $csrf) {
    $stock = (int) $product['stock'];
    $is_out = $stock <= 0;
    $is_low = !$is_out && $stock <= 5;

    $out  = '<div class="kiosk-product-card' . ($is_out ? ' is-out' : '') . '" data-id="' . h($product['id']) . '" data-name="' . h($product['name']) . '"';
    $out .= ' data-price="' . h($product['price']) . '" data-stock="' . $stock . '"';
    $out .= ' data-category="' . h($product['category_name'] ?? '') . '" data-image="' . h($product['image_url'] ?? '') . '"';
    $out .= ' data-icon="' . h($cat_icons[$product['category_id']] ?? '🍽️') . '">';

    // Card Media with fixed consistent aspect ratio
    $out .= '<div class="kiosk-card-media">';
    if (!empty($product['image_url'])) {
        $out .= '<img src="' . h($product['image_url']) . '" alt="' . h($product['name']) . '" loading="lazy">';
    } else {
        $out .= '<div class="kiosk-img-placeholder"><i class="bi ' . get_category_bi_icon($product['category_name'] ?? '') . '"></i></div>';
    }
    if ($is_out) {
        $out .= '<span class="kiosk-card-badge badge-out">Out of Stock</span>';
    } elseif ($is_low) {
        $out .= '<span class="kiosk-card-badge badge-low">Only ' . $stock . ' left</span>';
    }
    $out .= '</div>';

    // Card Body with strict flex alignment
    $out .= '<div class="kiosk-card-body">';
    $out .= '<div class="kiosk-card-info">';
    $out .= '<h3 class="kiosk-card-name" title="' . h($product['name']) . '">' . h($product['name']) . '</h3>';
    $out .= '<div class="kiosk-card-price">₱' . money($product['price']) . '</div>';
    $out .= '</div>';

    // Tactile aligned Add button
    $out .= '<div class="kiosk-card-action">';
    if ($is_out) {
        $out .= '<button type="button" class="kiosk-card-btn disabled" disabled tabindex="-1">Unavailable</button>';
    } else {
        $out .= '<button type="button" class="kiosk-card-btn" aria-label="Add ' . h($product['name']) . '"><i class="bi bi-plus-lg"></i><span>Add</span></button>';
    }
    $out .= '</div>';

    $out .= '</div></div>';
    return $out;
}

if ($is_ajax) {
    header('Content-Type: text/html; charset=utf-8');
    foreach ($products as $product) {
        echo kiosk_product_card($product, $cat_icons, $csrf);
    }
    if (!$products) {
        echo '<p class="muted" style="grid-column:1/-1; padding:48px 16px; text-align:center; background:#fff; border-radius:18px; border:1px dashed var(--k-border); font-size:15px; font-weight:600;">No items found in this category.</p>';
    }
    exit;
}

kiosk_header('Menu', true, 'kiosk_order_type.php');
?>
<style>
/* Layout & Page Container - Flush full width like POS */
body {
    background: #f5ebdd !important;
}
.kiosk-main {
    max-width: 100% !important;
    width: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
    flex: 1;
    display: flex;
    flex-direction: column;
}
footer.kiosk-footer {
    display: none !important;
}

.kiosk-pos-layout {
    display: flex;
    width: 100%;
    min-height: calc(100vh - 78px);
    background: #f5ebdd;
}

/* ==========================================================================
   KIOSK SIDEBAR (Matches POS app-sidebar 100%)
   ========================================================================== */
.kiosk-sidebar {
    width: 260px;
    flex-shrink: 0;
    background: #ffffff !important;
    border-right: 1px solid #e6d8c5 !important;
    box-shadow: none !important;
    padding-top: 14px;
    position: sticky;
    top: 0;
    height: calc(100vh - 78px);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    z-index: 10;
}

.kiosk-sidebar-nav {
    display: flex;
    flex-direction: column;
    padding: 0 4px;
    overflow-y: auto;
    flex: 1;
}
.kiosk-sidebar-nav::-webkit-scrollbar { width: 4px; }
.kiosk-sidebar-nav::-webkit-scrollbar-thumb { background: #e6d8c5; border-radius: 999px; }

/* Group Label identical to POS (.sidebar-group-label) */
.kiosk-sidebar .sidebar-group-label {
    font-size: 0.70rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.07em !important;
    color: #7a6558 !important;
    padding: 14px 18px 6px !important;
    opacity: 0.9 !important;
}

/* Nav Item Links identical to POS (.app-sidebar .nav-link) */
.kiosk-sidebar .nav-link {
    color: #554e48 !important;
    padding: 11px 18px !important;
    margin: 3px 12px !important;
    border-radius: 999px !important;
    font-size: 0.90rem !important;
    font-weight: 500 !important;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    text-decoration: none !important;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
    cursor: pointer !important;
    user-select: none !important;
    border: none !important;
    background: transparent !important;
}

.kiosk-sidebar .nav-link i {
    font-size: 1.15rem !important;
    width: 22px !important;
    text-align: center !important;
    color: #7a6558 !important;
    transition: color 0.18s ease;
}

.kiosk-sidebar .nav-link:hover {
    background: #eef1e4 !important;
    color: #5e6b46 !important;
    transform: translateX(3px) !important;
}
.kiosk-sidebar .nav-link:hover i {
    color: #5e6b46 !important;
}

/* Active Nav Item: Warm Terracotta Pill identical to POS */
.kiosk-sidebar .nav-link.active {
    background: #5e6b46 !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    box-shadow: 0 4px 16px rgba(94,107,70, 0.28) !important;
    transform: none !important;
}
.kiosk-sidebar .nav-link.active i {
    color: #ffffff !important;
}

/* Sidebar Footer identical to POS (.sidebar-footer) */
.kiosk-sidebar .sidebar-footer {
    border-top: 1px solid #e6d8c5 !important;
    padding: 14px 18px !important;
    font-size: 0.80rem !important;
    color: #7a6558 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    background: #ffffff !important;
}
.kiosk-sidebar .sidebar-footer i {
    font-size: 1rem;
    color: #5e6b46;
}

/* Main Content Area (Matches POS app-content) */
.kiosk-app-content {
    flex: 1;
    min-width: 0;
    padding: 24px 32px 48px;
}

/* Top Search Bar */
.kiosk-search-bar { margin-bottom: 22px; }
.kiosk-search-wrap { position: relative; display: flex; align-items: center; }
.kiosk-search-icon { position: absolute; left: 20px; color: #5e6b46; font-size: 18px; pointer-events: none; }
.kiosk-search-bar input {
    width: 100%;
    padding: 14px 22px 14px 50px;
    font-size: 15px;
    font-weight: 600;
    border-radius: 16px;
    border: 1.5px solid #e6d8c5;
    min-height: 52px;
    font-family: var(--font-body);
    background: #ffffff;
    box-shadow: 0 2px 10px rgba(42,24,16, 0.03);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.kiosk-search-bar input:focus {
    outline: none;
    border-color: #5e6b46;
    box-shadow: 0 4px 18px rgba(94,107,70, 0.20);
}

/* Product Grid */
.kiosk-product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(215px, 1fr));
    gap: 20px;
    transition: opacity 0.15s ease;
}

/* Product Card */
.kiosk-product-card {
    background: #ffffff;
    border: 1.5px solid #e6d8c5;
    border-radius: 20px;
    overflow: hidden;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(42,24,16, 0.04);
    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.2s ease;
    display: flex;
    flex-direction: column;
    height: 100%;
    user-select: none;
}
.kiosk-product-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 14px 28px rgba(94,107,70, 0.16);
    border-color: #9aa684;
}
.kiosk-product-card.is-out {
    opacity: 0.72;
    cursor: default;
}
.kiosk-product-card.is-out:hover {
    transform: none;
    box-shadow: 0 4px 14px rgba(42,24,16, 0.04);
    border-color: #e6d8c5;
}

/* Consistent Product Image Sizing */
.kiosk-card-media {
    position: relative;
    width: 100%;
    height: 155px;
    background: #fbf6ee;
    overflow: hidden;
}
.kiosk-card-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}
.kiosk-product-card:hover:not(.is-out) .kiosk-card-media img {
    transform: scale(1.05);
}
.kiosk-img-placeholder {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #eef1e4, #fbf6ee);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 50px;
    color: #5e6b46;
}

/* Card Badges */
.kiosk-card-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 2;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.02em;
}
.kiosk-card-badge.badge-out {
    background: rgba(220, 38, 38, 0.95);
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.35);
}
.kiosk-card-badge.badge-low {
    background: rgba(217, 119, 6, 0.95);
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(217, 119, 6, 0.35);
}

/* Card Body */
.kiosk-card-body {
    padding: 14px 16px 16px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    flex: 1;
    min-height: 135px;
}
.kiosk-card-info { margin-bottom: 12px; }
.kiosk-card-name {
    font-weight: 800;
    font-size: 15px;
    line-height: 1.35;
    margin: 0 0 6px;
    color: #2a1810;
    min-height: 40px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.kiosk-card-price {
    font-weight: 800;
    color: #5e6b46;
    font-size: 17px;
    letter-spacing: -0.01em;
    margin: 0;
}

/* Aligned Add Button */
.kiosk-card-action { margin-top: auto; }
.kiosk-card-btn {
    width: 100%;
    min-height: 42px;
    padding: 8px 16px;
    border-radius: 999px;
    border: 1.5px solid #cfd8bd;
    background: #eef1e4;
    color: #5e6b46;
    font-weight: 800;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    cursor: pointer;
    transition: all 0.16s ease;
}
.kiosk-product-card:hover:not(.is-out) .kiosk-card-btn {
    background: #5e6b46;
    color: #ffffff;
    border-color: #5e6b46;
    box-shadow: 0 4px 14px rgba(94,107,70, 0.30);
}
.kiosk-card-btn.disabled {
    background: #f1f2f4;
    color: #9ca3af;
    border-color: #e5e7eb;
    cursor: not-allowed;
    box-shadow: none !important;
}

/* Modal Popup */
.kiosk-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 999;
    padding: 16px;
}
.kiosk-modal-backdrop.open { display: flex; }
.kiosk-modal {
    background: #ffffff;
    border-radius: 24px;
    max-width: 440px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.40);
    border: 1px solid #e6d8c5;
}
.kiosk-modal img { width: 100%; height: 210px; object-fit: cover; display: block; }
.kiosk-modal .kiosk-img-placeholder { height: 210px; font-size: 68px; }
.kiosk-modal-body { padding: 22px 24px 26px; }
.kiosk-modal-body h2 {
    margin: 0 0 6px;
    font-family: var(--font-brand);
    color: #2a1810;
    font-size: 22px;
    font-weight: 800;
}
.kiosk-modal-body .cat { color: #7a6558; font-size: 13px; font-weight: 600; margin: 0 0 10px; }
.kiosk-modal-body .price { font-size: 24px; font-weight: 800; color: #5e6b46; margin: 0 0 18px; }
.kiosk-qty-row { display: flex; align-items: center; justify-content: center; gap: 18px; margin-bottom: 20px; }
.kiosk-qty-row button {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    border: 2px solid #e6d8c5;
    background: #faf6f2;
    font-size: 22px;
    font-weight: 800;
    color: #2a1810;
    cursor: pointer;
    transition: all 0.15s ease;
}
.kiosk-qty-row button:hover { background: #e3e8d6; border-color: #5e6b46; color: #5e6b46; }
.kiosk-qty-row span { font-size: 24px; font-weight: 800; min-width: 36px; text-align: center; color: #2a1810; }

/* Responsive adjustments */
@media (max-width: 992px) {
    .kiosk-pos-layout { flex-direction: column; }
    .kiosk-sidebar {
        width: 100% !important;
        height: auto !important;
        position: static !important;
        border-right: none !important;
        border-bottom: 1px solid #e6d8c5 !important;
        padding: 8px 12px !important;
    }
    .kiosk-sidebar-nav {
        flex-direction: row !important;
        overflow-x: auto;
        flex-wrap: nowrap !important;
        padding-bottom: 4px !important;
        -webkit-overflow-scrolling: touch;
    }
    .kiosk-sidebar .sidebar-group-label,
    .kiosk-sidebar .sidebar-footer { display: none !important; }
    .kiosk-sidebar .nav-link {
        flex: 0 0 auto;
        white-space: nowrap;
        margin: 2px 4px !important;
        padding: 8px 16px !important;
    }
    .kiosk-app-content { padding: 16px 16px 32px !important; }
}
@media (max-width: 576px) {
    .kiosk-product-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .kiosk-card-media { height: 130px; }
    .kiosk-card-body { padding: 10px 12px 12px; min-height: 120px; }
    .kiosk-card-name { font-size: 13px; min-height: 34px; }
    .kiosk-card-price { font-size: 15px; }
}
</style>

<div class="kiosk-pos-layout">
    <!-- POS-identical Sidebar -->
    <aside class="app-sidebar kiosk-sidebar" id="kioskSidebar">
        <div class="kiosk-sidebar-nav" id="kiosk-cat-tabs">
            <div class="sidebar-group-label">Menu Categories</div>
            <a href="<?= BASE_URL ?>pos/kiosk-menu" data-category-id="" class="nav-link <?= !$category_id ? 'active' : '' ?>">
                <i class="bi bi-grid"></i>
                <span>All Items</span>
            </a>
            <?php foreach ($categories as $cat): 
                $bIcon = get_category_bi_icon($cat['name']);
            ?>
                <a href="<?= BASE_URL ?>pos/kiosk-menu?category=<?= h($cat['id']) ?>" data-category-id="<?= h($cat['id']) ?>" class="nav-link <?= $category_id == $cat['id'] ? 'active' : '' ?>">
                    <i class="bi <?= $bIcon ?>"></i>
                    <span><?= h($cat['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="sidebar-footer">
            <i class="bi bi-display"></i>
            <span>Kiosk mode</span>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="kiosk-app-content">
        <div class="kiosk-search-bar">
            <div class="kiosk-search-wrap">
                <i class="bi bi-search kiosk-search-icon"></i>
                <input type="text" id="kiosk-search" placeholder="Search menu items..." value="<?= h($search) ?>" autocomplete="off">
            </div>
        </div>

        <div class="kiosk-product-grid" id="kiosk-product-grid">
            <?php foreach ($products as $product): ?>
                <?= kiosk_product_card($product, $cat_icons, $csrf) ?>
            <?php endforeach; ?>
            <?php if (!$products): ?>
                <p class="muted" style="grid-column:1/-1; padding:48px 16px; text-align:center; background:#fff; border-radius:18px; border:1px dashed #e6d8c5; font-size:15px; font-weight:600;">No items found in this category.</p>
            <?php endif; ?>
        </div>
    </main>
</div>

<div class="kiosk-modal-backdrop" id="kiosk-modal-backdrop">
    <div class="kiosk-modal">
        <div id="kiosk-modal-media"></div>
        <div class="kiosk-modal-body">
            <p class="cat" id="kiosk-modal-cat"></p>
            <h2 id="kiosk-modal-name"></h2>
            <p class="price" id="kiosk-modal-price"></p>
            <div class="kiosk-qty-row">
                <button type="button" id="kiosk-qty-minus">&minus;</button>
                <span id="kiosk-qty-value">1</span>
                <button type="button" id="kiosk-qty-plus">+</button>
            </div>
            <p id="kiosk-modal-feedback" style="min-height:18px; text-align:center; font-size:13px; color:var(--k-mint); font-weight:700;"></p>
            <div style="display:flex; gap:10px;">
                <button type="button" class="kiosk-btn secondary block" id="kiosk-modal-close">Cancel</button>
                <button type="button" class="kiosk-btn block" id="kiosk-modal-add">Add to Cart</button>
            </div>
        </div>
    </div>
</div>

<script>
var CSRF = <?= json_encode($csrf) ?>;
var searchInput = document.getElementById('kiosk-search');
var grid = document.getElementById('kiosk-product-grid');
var modalBackdrop = document.getElementById('kiosk-modal-backdrop');
var modalMedia = document.getElementById('kiosk-modal-media');
var modalName = document.getElementById('kiosk-modal-name');
var modalCat = document.getElementById('kiosk-modal-cat');
var modalPrice = document.getElementById('kiosk-modal-price');
var modalFeedback = document.getElementById('kiosk-modal-feedback');
var qtyValue = document.getElementById('kiosk-qty-value');
var currentProduct = null;

function openModalForCard(card) {
    currentProduct = {
        id: card.dataset.id, name: card.dataset.name, price: parseFloat(card.dataset.price),
        stock: parseInt(card.dataset.stock, 10), image: card.dataset.image, category: card.dataset.category, icon: card.dataset.icon
    };
    modalName.textContent = currentProduct.name;
    modalCat.textContent = currentProduct.category || '';
    modalPrice.textContent = '₱' + currentProduct.price.toFixed(2);
    modalMedia.innerHTML = currentProduct.image
        ? '<img src="' + currentProduct.image + '" alt="">'
        : '<div class="kiosk-img-placeholder">' + currentProduct.icon + '</div>';
    qtyValue.textContent = '1';
    modalFeedback.textContent = '';
    document.getElementById('kiosk-modal-add').disabled = currentProduct.stock <= 0;
    document.getElementById('kiosk-modal-add').textContent = currentProduct.stock <= 0 ? 'Out of Stock' : 'Add to Cart';
    modalBackdrop.classList.add('open');
}

grid.addEventListener('click', function (e) {
    var card = e.target.closest('.kiosk-product-card');
    if (card) { openModalForCard(card); }
});

document.getElementById('kiosk-modal-close').addEventListener('click', function () {
    modalBackdrop.classList.remove('open');
});
modalBackdrop.addEventListener('click', function (e) {
    if (e.target === modalBackdrop) { modalBackdrop.classList.remove('open'); }
});

document.getElementById('kiosk-qty-minus').addEventListener('click', function () {
    var v = Math.max(1, parseInt(qtyValue.textContent, 10) - 1);
    qtyValue.textContent = v;
});
document.getElementById('kiosk-qty-plus').addEventListener('click', function () {
    var v = parseInt(qtyValue.textContent, 10) + 1;
    if (currentProduct && v > currentProduct.stock) { v = currentProduct.stock; }
    qtyValue.textContent = v;
});

document.getElementById('kiosk-modal-add').addEventListener('click', function () {
    if (!currentProduct) return;
    var qty = parseInt(qtyValue.textContent, 10);
    var addBtn = this;
    addBtn.disabled = true;
    var calls = [];
    for (var i = 0; i < qty; i++) { calls.push(i); }

    function addOne(i) {
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'add');
        fd.append('product_id', currentProduct.id);
        return fetch(window.location.pathname, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); });
    }

    (function chain(i, lastData) {
        if (i >= qty) {
            addBtn.disabled = false;
            if (lastData && lastData.success) {
                modalFeedback.style.color = 'var(--k-mint)';
                modalFeedback.textContent = 'Added to cart!';
                updateCartBadge(lastData.cart_count);
                window.setTimeout(function () { modalBackdrop.classList.remove('open'); }, 650);
            } else if (lastData) {
                modalFeedback.style.color = 'var(--k-red)';
                modalFeedback.textContent = lastData.message || 'Unable to add item.';
            }
            return;
        }
        addOne(i).then(function (data) { chain(i + 1, data); });
    })(0, null);
});

function updateCartBadge(count) {
    var btn = document.querySelector('.kiosk-cart-btn');
    if (!btn) return;
    var badge = btn.querySelector('.kiosk-cart-badge');
    if (count > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'kiosk-cart-badge';
            btn.appendChild(badge);
        }
        badge.textContent = count;
    } else if (badge) {
        badge.remove();
    }
}

var searchTimer;
function filterProducts() {
    var activeTab = document.querySelector('.kiosk-sidebar .nav-link.active');
    var catId = activeTab ? (activeTab.getAttribute('data-category-id') || '') : '';
    var term = searchInput ? searchInput.value.trim() : '';

    var params = new URLSearchParams();
    if (catId) params.set('category', catId);
    if (term) params.set('search', term);

    var targetUrl = '<?= BASE_URL ?>pos/kiosk-menu' + (params.toString() ? '?' + params.toString() : '');
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', targetUrl);
    }

    grid.style.opacity = '0.5';
    fetch(targetUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            grid.innerHTML = html;
            grid.style.opacity = '1';
        })
        .catch(function () {
            grid.style.opacity = '1';
        });
}

searchInput.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(filterProducts, 220);
});

document.querySelectorAll('.kiosk-sidebar .nav-link').forEach(function (tab) {
    tab.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelectorAll('.kiosk-sidebar .nav-link').forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        filterProducts();
    });
});
</script>
<?php kiosk_footer(); ?>
