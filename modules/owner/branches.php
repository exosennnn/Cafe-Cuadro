<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_OWNER]);

$branches = $pdo->query("SELECT b.*,
    (SELECT COUNT(*) FROM employees WHERE branch_id=b.branch_id AND employment_status='ACTIVE') AS emp_count
    FROM branches b ORDER BY b.branch_name")->fetchAll();

$pageTitle = 'Branch Overview';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-1">Branch Overview</h4>

<div class="card">
  <div class="card-body table-responsive">
    <table id="branchesTable" class="table table-hover align-middle">
      <thead><tr><th>Branch</th><th>Address</th><th>Contact #</th><th>Status</th><th>Employees</th></tr></thead>
      <tbody>
      <?php foreach ($branches as $b): ?>
        <tr>
          <td><?= e($b['branch_name']) ?></td>
          <td><?= e($b['address'] ?? '-') ?></td>
          <td><?= e($b['contact_number'] ?? '-') ?></td>
          <td><span class="badge <?= $b['status']=='ACTIVE'?'bg-success':'bg-secondary' ?>"><?= e($b['status']) ?></span></td>
          <td><span class="badge bg-info"><?= (int)$b['emp_count'] ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($branches)): ?><tr><td colspan="5" class="text-center text-muted">No branches found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $('#branchesTable').DataTable({
    order: [[0, 'asc']],
    language: { search: '_INPUT_', searchPlaceholder: 'Search branches...' }
  });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>