<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_APPLICANT]);

$userId = $_SESSION['user_id'];
$applicant = $pdo->prepare("SELECT * FROM applicants WHERE user_id = ?");
$applicant->execute([$userId]);
$applicant = $applicant->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    csrfVerify();
    $jobId = (int)$_POST['job_id'];

    $user = $pdo->prepare("SELECT profile_photo FROM users WHERE user_id=?");
    $user->execute([$userId]);
    $userPhoto = $user->fetch()['profile_photo'] ?? null;

    if (empty($applicant['resume_path'])) {
        setFlash('error', 'Please upload your resume in your profile before applying.');
    } elseif (empty($userPhoto)) {
        setFlash('error', 'Please upload a profile photo in your profile before applying.');
    } else {
        try {
            $pdo->beginTransaction();
            if (!decrementJobSlot($pdo, $jobId)) {
                throw new Exception('This position just reached its applicant limit.');
            }
            $stmt = $pdo->prepare("INSERT INTO job_applications (reference_code, job_id, applicant_id, status) VALUES (?,?,?, 'SUBMITTED')");
            $stmt->execute([generateReferenceCode($pdo), $jobId, $applicant['applicant_id']]);
            $pdo->commit();
            setFlash('success', 'Application submitted successfully!');
            logAudit($pdo, $userId, 'APPLY_JOB', 'Applicant', 'job_id=' . $jobId);
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('error', 'You have already applied for this position, or it is no longer accepting applicants.');
        }
    }
    redirect('modules/applicant/jobs.php');
}

// Search & filter
$search = trim($_GET['search'] ?? '');
$deptFilter = $_GET['department'] ?? '';

$where = "WHERE jv.status = 'OPEN'";
$params = [];
if ($search !== '') {
    $where .= " AND (jv.title LIKE ? OR jv.description LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($deptFilter !== '') {
    $where .= " AND jv.department_id = ?";
    $params[] = $deptFilter;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM job_vacancies jv $where");
$countStmt->execute($params);
$total = $countStmt->fetch()['cnt'];
[$offset, $limit, $page, $totalPages] = paginate($total, 6);

$stmt = $pdo->prepare("SELECT jv.*, d.department_name,
    (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_id = jv.job_id AND ja.applicant_id = ?) AS already_applied
    FROM job_vacancies jv LEFT JOIN departments d ON jv.department_id = d.department_id
    $where ORDER BY jv.posted_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute(array_merge([$applicant['applicant_id']], $params));
$jobs = $stmt->fetchAll();

$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();

$pageTitle = 'Job Openings';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">Job Openings</h4>

<form method="GET" class="row g-2 mb-4">
  <div class="col-md-6">
    <input type="text" name="search" class="form-control" placeholder="Search by title or keyword..." value="<?= e($search) ?>">
  </div>
  <div class="col-md-4">
    <select name="department" class="form-select">
      <option value="">All Departments</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= $d['department_id'] ?>" <?= $deptFilter == $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <button class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
  </div>
</form>

<div class="row g-3">
<?php if (empty($jobs)): ?>
  <div class="col-12"><div class="alert alert-info">No open job positions match your search right now.</div></div>
<?php endif; ?>
<?php foreach ($jobs as $job): ?>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-bold"><?= e($job['title']) ?></h6>
        <p class="small text-muted mb-1"><i class="bi bi-diagram-3"></i> <?= e($job['department_name'] ?? 'General') ?> &middot; <?= e($job['employment_type']) ?></p>
        <p class="small"><?= nl2br(e(substr($job['description'], 0, 180))) ?><?= strlen($job['description']) > 180 ? '...' : '' ?></p>
        <p class="small text-muted mb-2"><i class="bi bi-people"></i> <?= (int)$job['slots'] ?> slot(s)
          <?php if ($job['closing_date']): ?> &middot; Closes <?= fdate($job['closing_date']) ?><?php endif; ?></p>
        <?php if ($job['already_applied'] > 0): ?>
          <button class="btn btn-sm btn-secondary" disabled><i class="bi bi-check-circle"></i> Already Applied</button>
        <?php else: ?>
          <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="job_id" value="<?= $job['job_id'] ?>">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> Apply Now</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="mt-4"><?= renderPagination($page, $totalPages) ?></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
