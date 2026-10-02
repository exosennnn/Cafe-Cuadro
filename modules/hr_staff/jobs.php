<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_STAFF, ROLE_HR_MANAGER]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $title = trim($_POST['title']);
        $deptId = $_POST['department_id'] ?: null;
        $branchId = $_POST['branch_id'] ?: null;
        $desc = trim($_POST['description']);
        $reqs = trim($_POST['requirements']);
        $type = $_POST['employment_type'];
        $slots = (int)$_POST['slots'];
        $closing = $_POST['closing_date'] ?: null;

        // Operational role this job provisions on hire. Allow-listed so a
        // tampered POST value can never grant a role beyond these -
        // empty/anything else falls back to plain Employee at hire time.
        $targetRoleId = $_POST['target_role_id'] !== '' ? (int)$_POST['target_role_id'] : null;
        $allowedHireRoles = [ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF];
        if ($targetRoleId !== null && !in_array($targetRoleId, $allowedHireRoles, true)) {
            $targetRoleId = null;
        }

        $errors = [];
        if ($branchId === null) {
            $errors[] = 'Please select the branch this job vacancy is for.';
        }
        if ($closing !== null) {
            $today = date('Y-m-d');
            if ($closing < $today) {
                $errors[] = 'Closing date must be today or a future date.';
            }
        }

        if (empty($errors)) {
            if ($action === 'create') {
                $stmt = $pdo->prepare("INSERT INTO job_vacancies (title, target_role_id, department_id, branch_id, description, requirements, employment_type, slots, posted_by, closing_date) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$title, $targetRoleId, $deptId, $branchId, $desc, $reqs, $type, $slots, $userId, $closing]);
                setFlash('success', 'Job vacancy posted successfully.');
                logAudit($pdo, $userId, 'CREATE_JOB', 'Recruitment', $title);
            } else {
                $jobId = (int)$_POST['job_id'];
                $stmt = $pdo->prepare("UPDATE job_vacancies SET title=?, target_role_id=?, department_id=?, branch_id=?, description=?, requirements=?, employment_type=?, slots=?, closing_date=? WHERE job_id=?");
                $stmt->execute([$title, $targetRoleId, $deptId, $branchId, $desc, $reqs, $type, $slots, $closing, $jobId]);
                setFlash('success', 'Job vacancy updated successfully.');
                logAudit($pdo, $userId, 'UPDATE_JOB', 'Recruitment', 'job_id=' . $jobId);
            }
        } else {
            foreach ($errors as $error) {
                setFlash('error', $error);
            }
        }
    } elseif ($action === 'toggle_status') {
        $jobId = (int)$_POST['job_id'];
        $pdo->prepare("UPDATE job_vacancies SET status = IF(status='OPEN','CLOSED','OPEN') WHERE job_id=?")->execute([$jobId]);
        setFlash('success', 'Job status updated.');
    } elseif ($action === 'delete') {
        $jobId = (int)$_POST['job_id'];
        $pdo->prepare("DELETE FROM job_vacancies WHERE job_id=?")->execute([$jobId]);
        setFlash('success', 'Job vacancy deleted.');
        logAudit($pdo, $userId, 'DELETE_JOB', 'Recruitment', 'job_id=' . $jobId);
    }
    redirect('modules/hr_staff/jobs.php');
}

$search = trim($_GET['search'] ?? '');
$where = '1=1'; $params = [];
if ($search !== '') { $where .= " AND jv.title LIKE ?"; $params[] = "%$search%"; }

$total = $pdo->prepare("SELECT COUNT(*) AS c FROM job_vacancies jv WHERE $where");
$total->execute($params);
$total = $total->fetch()['c'];
[$offset, $limit, $page, $totalPages] = paginate($total, 8);

$stmt = $pdo->prepare("SELECT jv.*, d.department_name, b.branch_name,
    (SELECT COUNT(*) FROM job_applications WHERE job_id=jv.job_id) AS app_count
    FROM job_vacancies jv LEFT JOIN departments d ON jv.department_id=d.department_id
    LEFT JOIN branches b ON jv.branch_id=b.branch_id
    WHERE $where ORDER BY jv.posted_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$jobs = $stmt->fetchAll();

$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$branches = $pdo->query("SELECT * FROM branches WHERE status='ACTIVE' ORDER BY branch_name")->fetchAll();

$pageTitle = 'Manage Job Vacancies';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div class="emp-page-header mb-0">
    <div class="emp-page-icon"><i class="bi bi-briefcase-fill"></i></div>
    <div>
      <h4>Manage Job Vacancies</h4>
    </div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#jobModal" onclick="resetForm()"><i class="bi bi-plus-circle"></i> Post New Job</button>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search by title..." value="<?= e($search) ?>"></div>
  <div class="col-md-2"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i> Search</button></div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="jobsTable">
      <thead><tr><th>Title</th><th>Department</th><th>Branch</th><th>Type</th><th>Slots</th><th>Applicants</th><th>Status</th><th>Closing</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $j): ?>
        <tr>
          <td><?= e($j['title']) ?></td>
          <td><?= e($j['department_name'] ?? '-') ?></td>
          <td><?= e($j['branch_name'] ?? '-') ?></td>
          <td><?= e($j['employment_type']) ?></td>
          <td><?= (int)$j['slots'] ?></td>
          <td><span class="badge bg-info"><?= (int)$j['app_count'] ?></span></td>
          <td><span class="badge <?= $j['status']=='OPEN'?'bg-success':'bg-secondary' ?>"><?= $j['status'] ?></span></td>
          <td><?= fdate($j['closing_date']) ?></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-primary" onclick='editJob(<?= json_encode($j) ?>)'><i class="bi bi-pencil"></i></button>
            <form method="POST" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="action" value="toggle_status">
              <input type="hidden" name="job_id" value="<?= $j['job_id'] ?>">
              <button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat"></i></button>
            </form>
            <form method="POST" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="job_id" value="<?= $j['job_id'] ?>">
              <button class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
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
  $('#jobsTable').DataTable({ order: [], paging: false, info: false, columnDefs: [{ orderable: false, targets: -1 }] });
});
</script>

<!-- Modal -->
<div class="modal fade" id="jobModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" id="formAction" value="create">
        <input type="hidden" name="job_id" id="jobId">
        <div class="modal-header"><h5 class="modal-title" id="modalTitle">Post New Job</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Job Title</label><input type="text" name="title" id="title" class="form-control" required></div>
          <div class="mb-3">
            <label class="form-label">Account Provisioned on Hire</label>
            <select name="target_role_id" id="target_role_id" class="form-select">
              <option value="">Employee (HR self-service only)</option>
              <option value="<?= ROLE_CASHIER ?>">Cashier (+ POS access)</option>
              <option value="<?= ROLE_INVENTORY_STAFF ?>">Inventory Staff (+ Inventory/Procurement access)</option>
              <option value="<?= ROLE_FINANCE_STAFF ?>">Finance Staff (+ Finance access)</option>
            </select>
            <div class="form-text">Controls which role the hired applicant's account gets automatically. Everyone still gets HR self-service (leave, attendance) either way.</div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Department</label>
              <select name="department_id" id="department_id" class="form-select">
                <option value="">-- Select Department --</option>
                <?php foreach ($departments as $d): if ($d['department_name'] === 'General') continue; ?><option value="<?= $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Branch</label>
              <select name="branch_id" id="branch_id" class="form-select" required>
                <option value="">-- Select Branch --</option>
                <?php foreach ($branches as $b): ?><option value="<?= $b['branch_id'] ?>"><?= e($b['branch_name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Employment Type</label>
              <select name="employment_type" id="employment_type" class="form-select">
                <?php foreach (['FULL_TIME','PART_TIME','CONTRACTUAL','PROBATIONARY'] as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3"><label class="form-label">Description</label><textarea name="description" id="description" class="form-control" rows="3" required></textarea></div>
          <div class="mb-3"><label class="form-label">Requirements</label><textarea name="requirements" id="requirements" class="form-control" rows="2"></textarea></div>
          <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Slots</label><input type="number" name="slots" id="slots" class="form-control" value="1" min="1"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Closing Date</label><input type="date" name="closing_date" id="closing_date" class="form-control" min="<?= date('Y-m-d') ?>"></div>
          </div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
      </form>
    </div>
  </div>
</div>

<script>
function resetForm() {
  document.getElementById('modalTitle').innerText = 'Post New Job';
  document.getElementById('formAction').value = 'create';
  document.querySelector('#jobModal form').reset();
}
function editJob(j) {
  document.getElementById('modalTitle').innerText = 'Edit Job Vacancy';
  document.getElementById('formAction').value = 'update';
  document.getElementById('jobId').value = j.job_id;
  document.getElementById('title').value = j.title;
  document.getElementById('target_role_id').value = j.target_role_id || '';
  document.getElementById('department_id').value = j.department_id || '';
  document.getElementById('branch_id').value = j.branch_id || '';
  document.getElementById('employment_type').value = j.employment_type;
  document.getElementById('description').value = j.description;
  document.getElementById('requirements').value = j.requirements || '';
  document.getElementById('slots').value = j.slots;
  document.getElementById('closing_date').value = j.closing_date || '';
  new bootstrap.Modal(document.getElementById('jobModal')).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>