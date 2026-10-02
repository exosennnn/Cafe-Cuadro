<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_MANAGER, ROLE_OWNER]);

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
if (!is_string($statusFilter) || !in_array($statusFilter, ['', 'ACTIVE', 'VOID'], true)) {
    $statusFilter = '';
}

$where = '1=1';
$params = [];
if ($statusFilter !== '') {
    $where .= ' AND ec.status=?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where .= " AND (ec.contract_no LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ? OR jv.title LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$total = $pdo->prepare("SELECT COUNT(*) FROM employment_contracts ec
    LEFT JOIN job_applications ja ON ja.application_id=ec.application_id
    LEFT JOIN applicants a ON a.applicant_id=ja.applicant_id
    LEFT JOIN job_vacancies jv ON jv.job_id=ja.job_id
    WHERE $where");
$total->execute($params);
[$offset, $limit, $page, $totalPages] = paginate((int)$total->fetchColumn(), 15);

$stmt = $pdo->prepare("SELECT ec.contract_id, ec.contract_no, ec.application_id, ec.status, ec.generated_at,
        ec.employee_signed_at, ec.employer_signed_at,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
                 NULLIF(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ''), 'Applicant') AS employee_name,
        jv.title AS job_title
    FROM employment_contracts ec
    LEFT JOIN employees e ON e.employee_id=ec.employee_id
    LEFT JOIN job_applications ja ON ja.application_id=ec.application_id
    LEFT JOIN applicants a ON a.applicant_id=ja.applicant_id
    LEFT JOIN users u ON u.user_id=COALESCE(e.user_id, a.user_id)
    LEFT JOIN job_vacancies jv ON jv.job_id=ja.job_id
    WHERE $where
    ORDER BY ec.generated_at DESC, ec.contract_id DESC
    LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$contracts = $stmt->fetchAll();

$pageTitle = 'Contracts';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="mb-1">Contracts</h4>
  </div>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search contract, employee, or position..." value="<?= e($search) ?>"></div>
  <div class="col-md-3">
    <select name="status" class="form-select">
      <option value="">All Contract Statuses</option>
      <?php foreach (['ACTIVE', 'VOID'] as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i> Search</button></div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Contract No.</th><th>Employee</th><th>Position</th><th>Status</th><th>Employee Signature</th><th>Employer Signature</th><th>Issued</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($contracts as $contract): ?>
        <tr>
          <td><strong><?= e($contract['contract_no']) ?></strong></td>
          <td><?= e($contract['employee_name']) ?></td>
          <td><?= e($contract['job_title'] ?? '-') ?></td>
          <td><span class="badge <?= $contract['status'] === 'ACTIVE' ? 'bg-success' : 'bg-secondary' ?>"><?= e($contract['status']) ?></span></td>
          <td><?= $contract['employee_signed_at'] ? e(fdate($contract['employee_signed_at'], 'M d, Y g:i A')) : '<span class="text-muted">Pending</span>' ?></td>
          <td><?= $contract['employer_signed_at'] ? e(fdate($contract['employer_signed_at'], 'M d, Y g:i A')) : '<span class="text-muted">Pending</span>' ?></td>
          <td><?= e(fdate($contract['generated_at'], 'M d, Y g:i A')) ?></td>
          <td><?php if ($contract['application_id']): ?><a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>hr-staff/contract?application_id=<?= (int)$contract['application_id'] ?>"><i class="bi bi-file-earmark-text"></i> Open</a><?php else: ?><span class="small text-muted">No application link</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($contracts)): ?><tr><td colspan="8" class="text-center text-muted">No contracts found.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?= renderPagination($page, $totalPages) ?>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
