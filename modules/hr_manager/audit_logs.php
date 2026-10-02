<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_MANAGER]);

$search = trim($_GET['search'] ?? '');
$where = '1=1'; $params = [];
if ($search !== '') { $where .= " AND (al.action LIKE ? OR al.module LIKE ? OR u.email LIKE ?)"; $params[]="%$search%";$params[]="%$search%";$params[]="%$search%"; }

$total = $pdo->prepare("SELECT COUNT(*) AS c FROM audit_logs al LEFT JOIN users u ON al.user_id=u.user_id WHERE $where");
$total->execute($params);
$total = $total->fetch()['c'];
[$offset, $limit, $page, $totalPages] = paginate($total, 20);

$stmt = $pdo->prepare("SELECT al.*, u.first_name, u.last_name, u.email FROM audit_logs al LEFT JOIN users u ON al.user_id=u.user_id
    WHERE $where ORDER BY al.created_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'Audit Logs';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">Audit Logs</h4>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search action, module, or user email..." value="<?= e($search) ?>"></div>
  <div class="col-md-2"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i></button></div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle small" id="auditLogsTable">
      <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Module</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td><?= fdate($log['created_at'], 'M d, Y g:i:s A') ?></td>
          <td><?= $log['user_id'] ? e($log['first_name'].' '.$log['last_name']) : 'System / Guest' ?></td>
          <td><span class="badge bg-secondary"><?= e($log['action']) ?></span></td>
          <td><?= e($log['module']) ?></td>
          <td><?= e($log['details']) ?></td>
          <td><?= e($log['ip_address']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($logs)): ?><tr><td colspan="6" class="text-center text-muted">No audit logs found.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?= renderPagination($page, $totalPages) ?>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#auditLogsTable').DataTable({ order: [], paging: false, info: false });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
