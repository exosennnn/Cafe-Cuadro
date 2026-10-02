<?php
require 'database.php';
require 'app.php';
require_admin();

$notifications = get_low_stock_notifications();

$pageTitle = 'Low Stock Notifications';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/shared/pos-legacy.css">
<div class="mb-4">
    <h1 class="h4 fw-bold mb-1">Low Stock Notifications</h1>
</div>
<?php
?>

<section class="panel">
    <h2>Low Stock Alerts</h2>
    <?php if (!$notifications): ?>
        <div class="alert success">No active low-stock notifications. All products are above threshold.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Current Stock</th>
                    <th>Threshold</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notifications as $notif): ?>
                    <tr>
                        <td><?= h($notif['product_name'] ?: 'Unknown product') ?></td>
                        <td><?= h($notif['current_stock']) ?></td>
                        <td><?= h($notif['threshold']) ?></td>
                        <td><?= h($notif['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>