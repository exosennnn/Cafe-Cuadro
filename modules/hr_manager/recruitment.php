<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_MANAGER]);

$jobs = $pdo->query("SELECT jv.*, d.department_name, (SELECT COUNT(*) FROM job_applications WHERE job_id=jv.job_id) AS app_count
    FROM job_vacancies jv LEFT JOIN departments d ON jv.department_id=d.department_id ORDER BY jv.posted_at DESC")->fetchAll();

$funnel = $pdo->query("SELECT status, COUNT(*) AS cnt FROM job_applications GROUP BY status")->fetchAll();

$pageTitle = 'Recruitment Overview';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">Recruitment Overview</h4>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <h6 class="card-title">Application Funnel</h6>
      <canvas id="funnelChart"></canvas>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <h6 class="card-title">Quick Links</h6>
      <a href="<?= BASE_URL ?>hr-staff/jobs" class="btn btn-sm btn-outline-primary w-100 mb-2">Manage Job Vacancies</a>
      <a href="<?= BASE_URL ?>hr-staff/applications" class="btn btn-sm btn-outline-primary w-100 mb-2">Review Applications</a>
      <a href="<?= BASE_URL ?>hr-staff/interviews" class="btn btn-sm btn-outline-primary w-100 mb-2">Schedule Interviews</a>
      <a href="<?= BASE_URL ?>hr-manager/interviews" class="btn btn-sm btn-outline-primary w-100 mb-2">Conduct HR Interviews</a>
      <a href="<?= BASE_URL ?>hr-manager/hiring-approvals" class="btn btn-sm btn-outline-primary w-100">Approve Hiring</a>
    </div></div>
  </div>
</div>

<div class="card">
  <div class="card-body table-responsive">
    <h6 class="card-title">All Job Postings</h6>
    <table class="table table-hover" id="recruitmentTable">
      <thead><tr><th>Title</th><th>Department</th><th>Status</th><th>Slots</th><th>Applicants</th><th>Posted</th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $j): ?>
        <tr>
          <td><?= e($j['title']) ?></td>
          <td><?= e($j['department_name'] ?? '-') ?></td>
          <td><span class="badge <?= $j['status']=='OPEN'?'bg-success':'bg-secondary' ?>"><?= $j['status'] ?></span></td>
          <td><?= (int)$j['slots'] ?></td>
          <td><?= (int)$j['app_count'] ?></td>
          <td><?= fdate($j['posted_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($jobs)): ?><tr><td colspan="6" class="text-center text-muted">No job postings yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#recruitmentTable').DataTable({ order: [] });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('funnelChart'), {
  type: 'bar',
  data: { labels: <?= json_encode(array_map(fn($f)=>str_replace('_',' ',$f['status']), $funnel)) ?>,
    datasets:[{ label:'Applications', data: <?= json_encode(array_column($funnel,'cnt')) ?>, backgroundColor:'#5e6b46' }] },
  options: { indexAxis:'y', plugins:{legend:{display:false}} }
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

