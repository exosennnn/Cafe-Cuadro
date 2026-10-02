<?php
/**
 * FINANCE MODULE TAB BAR
 * Shared by every page under modules/finance/ so the module reads as one
 * self-contained area (Dashboard / Transactions / Payables / Payroll /
 * Categories / Financial Report). Set $financeTab before including.
 */
$financeTab = $financeTab ?? '';
$__tabs = [
    'dashboard'    => ['finance/dashboard',  'bi-speedometer2',      'Dashboard'],
    'transactions' => ['finance',            'bi-cash-coin',         'Transactions'],
    'payables'     => ['finance/payables',   'bi-receipt-cutoff',    'Supplier Payables'],
    'payroll'      => ['finance/payroll',    'bi-cash-stack',        'Payroll Payments'],
    'categories'   => ['finance/categories', 'bi-tags',              'Categories'],
    'report'       => ['finance/report',     'bi-file-earmark-bar-graph', 'Financial Report'],
];
?>
<div class="d-flex flex-wrap gap-2 mb-3 no-print">
    <?php foreach ($__tabs as $key => [$cleanUrl, $icon, $label]): ?>
        <a href="<?= url($cleanUrl) ?>"
           class="btn btn-sm <?= $financeTab === $key ? 'btn-brand' : 'btn-outline-secondary' ?>">
            <i class="bi <?= $icon ?> me-1"></i><?= $label ?>
        </a>
    <?php endforeach; ?>
</div>
