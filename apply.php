<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Public application form - applicants do NOT need to log in or create an
// account. They submit their info + resume + required documents directly
// and receive a Unique Reference Code to check their status later.

$jobId = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);
$job = $pdo->prepare("SELECT jv.*, d.department_name FROM job_vacancies jv
    LEFT JOIN departments d ON jv.department_id = d.department_id
    WHERE jv.job_id = ? AND jv.status = 'OPEN' AND (jv.closing_date IS NULL OR jv.closing_date >= CURDATE())");
$job->execute([$jobId]);
$job = $job->fetch();

if (!$job) {
    // Job not found, closed, or no job_id given - fall back to the job list.
    $openJobs = $pdo->query("SELECT jv.*, d.department_name
        FROM job_vacancies jv LEFT JOIN departments d ON jv.department_id = d.department_id
        WHERE jv.status = 'OPEN' AND (jv.closing_date IS NULL OR jv.closing_date >= CURDATE())
        ORDER BY jv.posted_at DESC")->fetchAll();
}

$errors = [];
$old = ['first_name'=>'','last_name'=>'','email'=>'','phone'=>''];
$documentTypes = ['Valid ID', "NBI Clearance / Police Clearance", 'Certificate / TOR', 'Other'];

if ($job && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    foreach ($old as $k => $v) {
        $old[$k] = trim($_POST[$k] ?? '');
    }
    $phoneDigits = preg_replace('/\D+/', '', $old['phone']);

    if ($old['first_name'] === '' || $old['last_name'] === '' || $old['email'] === '') {
        $errors[] = 'Please fill in all required fields (name and email).';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($old['phone'] === '' || !preg_match('/^09\d{9}$/', $phoneDigits)) {
        $errors[] = 'Please enter a valid 11-digit Philippine mobile number starting with 09.';
    }
    if (empty($_FILES['resume']['name'])) {
        $errors[] = 'Please upload your resume.';
    }

    if (empty($errors)) {
      $uploadedFiles = [];
        try {
            $pdo->beginTransaction();

            // Claim a slot right away (atomic) - if someone else just took the
            // last one, bail out before creating any records for this applicant.
            if (!decrementJobSlot($pdo, $jobId)) {
                throw new Exception('Sorry, this position just reached its applicant limit. Please check our other openings.');
            }

            // 1) Create a guest applicant record (no user account).
            $stmt = $pdo->prepare("INSERT INTO applicants (user_id, first_name, last_name, email, phone)
                VALUES (NULL, ?,?,?,?)");
            $stmt->execute([$old['first_name'], $old['last_name'], $old['email'], $phoneDigits]);
            $applicantId = (int)$pdo->lastInsertId();

            // 2) Resume upload (required).
            $resumeResult = handleFileUpload($_FILES['resume'], RESUME_UPLOAD_DIR, ALLOWED_RESUME_TYPES, MAX_RESUME_SIZE, 'resume_guest' . $applicantId);
            if (!$resumeResult['ok']) {
                throw new Exception($resumeResult['error']);
            }
            $uploadedFiles[] = rtrim(RESUME_UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $resumeResult['filename'];
            $pdo->prepare("UPDATE applicants SET resume_path=?, resume_original_name=? WHERE applicant_id=?")
                ->execute([$resumeResult['filename'], $_FILES['resume']['name'], $applicantId]);

            // 3) Reference code + the application itself.
            $referenceCode = generateReferenceCode($pdo);
            $stmt = $pdo->prepare("INSERT INTO job_applications (reference_code, job_id, applicant_id, status) VALUES (?,?,?,'SUBMITTED')");
            $stmt->execute([$referenceCode, $jobId, $applicantId]);
            $applicationId = (int)$pdo->lastInsertId();

            // 4) Other required documents (optional, multiple).
            if (isset($_FILES['documents'])) {
                $allowedDocTypes = array_merge(ALLOWED_RESUME_TYPES, ALLOWED_IMAGE_TYPES);
                $labels = $_POST['document_labels'] ?? [];
              if (!is_array($_FILES['documents']['name'] ?? null)) {
                throw new Exception('Invalid document upload data.');
              }
              foreach ($_FILES['documents']['name'] as $i => $name) {
                $error = $_FILES['documents']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                if ($name === '' && $error === UPLOAD_ERR_NO_FILE) continue;
                    $file = [
                  'name' => $name,
                  'type' => $_FILES['documents']['type'][$i] ?? '',
                  'tmp_name' => $_FILES['documents']['tmp_name'][$i] ?? '',
                  'error' => $error,
                  'size' => $_FILES['documents']['size'][$i] ?? 0,
                    ];
                    $docResult = handleFileUpload($file, DOCUMENT_UPLOAD_DIR, $allowedDocTypes, MAX_RESUME_SIZE, 'doc_' . $applicationId . '_' . $i);
                if (!$docResult['ok']) {
                  throw new Exception('Document upload failed: ' . $docResult['error']);
                    }
                $uploadedFiles[] = rtrim(DOCUMENT_UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $docResult['filename'];
                $docType = trim($labels[$i] ?? '') ?: 'Other';
                $pdo->prepare("INSERT INTO application_documents (application_id, document_type, file_path, original_name) VALUES (?,?,?,?)")
                  ->execute([$applicationId, $docType, $docResult['filename'], $name]);
                }
            }

            $pdo->commit();
            logAudit($pdo, null, 'GUEST_APPLY_JOB', 'Applicant', "job_id=$jobId reference_code=$referenceCode");
            redirect('application-submitted?ref=' . urlencode($referenceCode));
        } catch (PDOException $e) {
          if ($pdo->inTransaction()) { $pdo->rollBack(); }
          foreach ($uploadedFiles as $uploadedFile) {
            if (is_file($uploadedFile)) { @unlink($uploadedFile); }
          }
            error_log('apply.php DB error: ' . $e->getMessage());
            $errors[] = 'We could not submit your application due to a technical issue. Please try again in a moment.';
        } catch (Exception $e) {
          if ($pdo->inTransaction()) { $pdo->rollBack(); }
          foreach ($uploadedFiles as $uploadedFile) {
            if (is_file($uploadedFile)) { @unlink($uploadedFile); }
          }
            $errors[] = 'We could not submit your application: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $job ? 'Apply: ' . e($job['title']) : 'Apply' ?> | <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/hrms/css/style.css?v=<?= ASSET_VER ?>">
<style>
  /* Scoped override: align this page with the Purr'Coffee warm café theme */
  body.apply-page {
    font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    color: #2a1810;
  }
  body.apply-page h1, body.apply-page h2, body.apply-page h3,
  body.apply-page h4, body.apply-page h5, body.apply-page h6,
  body.apply-page .btn {
    font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
  }
  body.apply-page.auth-page {
    background: linear-gradient(160deg, rgba(29,16,10,0.85) 0%, rgba(42,24,16,0.8) 50%, rgba(42,24,16,0.92) 100%),
      url("assets/hrms/img/bg-hero-coffee.jpg");
    background-blend-mode: multiply;
    background-position: center;
    background-repeat: no-repeat;
    background-size: cover;
  }
  body.apply-page .text-white { color: #ffffff !important; }
  body.apply-page .auth-card { border-radius: 22px; }
  body.apply-page .auth-card h4, body.apply-page .auth-card h5, body.apply-page .auth-card h6 { color: #2a1810; }
  body.apply-page .badge.bg-primary { background-color: #5e6b46 !important; }
  body.apply-page .btn-primary {
    background-color: #5e6b46;
    border-color: #5e6b46;
    border-radius: 50px;
    color: #fff;
    font-weight: 700;
  }
  body.apply-page .btn-primary:hover, body.apply-page .btn-primary:focus {
    background-color: #4c5838;
    border-color: #4c5838;
    color: #fff;
  }
  body.apply-page .btn-outline-primary {
    color: #5e6b46;
    border-color: #5e6b46;
    border-radius: 50px;
    font-weight: 700;
  }
  body.apply-page .btn-outline-primary:hover {
    background-color: #5e6b46;
    border-color: #5e6b46;
    color: #fff;
  }
  body.apply-page .btn-outline-secondary {
    color: #2a1810;
    border-color: #e6d8c5;
    border-radius: 50px;
    font-weight: 600;
  }
  body.apply-page .btn-outline-secondary:hover {
    background-color: #eef1e4;
    border-color: #5e6b46;
    color: #5e6b46;
  }
  body.apply-page a { color: #5e6b46; }
  body.apply-page a:hover { color: #4c5838; }
  body.apply-page .form-control:focus, body.apply-page .form-select:focus {
    border-color: #5e6b46;
    box-shadow: 0 0 0 0.2rem rgba(94,107,70, 0.25);
  }
  body.apply-page .apply-brand {
    font-family: "Fraunces", Georgia, serif;
    font-weight: 600;
    font-size: 2.75rem;
    letter-spacing: -0.01em;
    text-transform: none;
    background: linear-gradient(rgba(255,255,255,0.98), rgba(255,255,255,0.55));
    -webkit-text-fill-color: transparent;
    background-clip: text;
    -webkit-background-clip: text;
    display: inline-block;
  }
  body.apply-page .auth-card p,
  body.apply-page .auth-card span,
  body.apply-page .auth-card label,
  body.apply-page .auth-card a {
    font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
  }

  /* ---------- Clean structure / spacing ---------- */
  body.apply-page .auth-card {
    border: none;
    border-top: 4px solid #5e6b46;
    overflow: hidden;
  }
  body.apply-page .apply-subtitle {
    color: #7a6558;
    font-size: 0.92rem;
  }
  body.apply-page .form-section + .form-section {
    margin-top: 2rem;
    padding-top: 1.75rem;
    border-top: 1px solid #e6d8c5;
  }
  body.apply-page .section-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: #2a1810;
    font-size: 0.8rem;
  }
  body.apply-page .section-title i {
    color: #5e6b46;
    font-size: 1rem;
  }
  body.apply-page .form-label {
    font-weight: 600;
    font-size: 0.88rem;
    color: #3b241a;
  }
  body.apply-page .form-control,
  body.apply-page .form-select {
    border-radius: 10px;
    border-color: #e6d8c5;
    padding: 0.55rem 0.85rem;
  }
  body.apply-page .form-control::placeholder { color: #b09b88; }
  body.apply-page .doc-row {
    background: #fbf6ee;
    border: 1px solid #ece0cf;
    border-radius: 12px;
    padding: 0.75rem;
    margin-left: 0;
    margin-right: 0;
  }
  body.apply-page .doc-row + .doc-row { margin-top: 0.6rem; }
  body.apply-page .btn-submit {
    padding: 0.7rem 1rem;
    border-radius: 10px;
    font-size: 0.95rem;
    letter-spacing: 0.04em;
  }
  body.apply-page .job-meta-badge {
    border-radius: 999px;
    padding: 0.35rem 0.85rem;
    font-weight: 600;
    letter-spacing: 0.02em;
  }
  body.apply-page .auth-card .footer-links {
    border-top: 1px solid #ece0cf;
    padding-top: 1rem;
    margin-top: 1.75rem;
    color: #7a6558;
  }
</style>
<link rel="stylesheet" href="assets/shared/responsive.css?v=<?= defined('ASSET_VER') ? ASSET_VER : time() ?>">
</head>
<body class="auth-page apply-page">
<div class="container py-5">
  <div class="text-center mb-4">
    <a href="<?= BASE_URL ?>" class="text-decoration-none">
      <span class="apply-brand"><?= APP_NAME ?></span>
    </a>
  </div>

  <?php if (!$job): ?>
    <div class="card auth-card shadow-lg mx-auto" style="max-width:640px;">
      <div class="card-body p-4 p-md-5">
        <h5 class="fw-bold mb-3"><i class="bi bi-exclamation-circle text-warning"></i> Job Not Available</h5>
        <p class="text-muted">That position is no longer accepting applications, or the link is invalid. Please pick one of the current openings below.</p>
        <?php if (empty($openJobs)): ?>
          <div class="alert alert-info small mb-0">No open positions at the moment. Please check back soon!</div>
        <?php else: ?>
          <div class="d-flex flex-column gap-2">
            <?php foreach ($openJobs as $j): ?>
              <a href="<?= BASE_URL ?>apply?job_id=<?= $j['job_id'] ?>" class="btn btn-outline-primary text-start">
                <strong><?= e($j['title']) ?></strong> &middot; <?= e($j['department_name'] ?? 'General') ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <p class="text-center small footer-links mb-0"><a href="<?= BASE_URL ?>">&larr; Back to Home</a></p>
      </div>
    </div>
  <?php else: ?>
    <div class="card auth-card shadow-lg mx-auto" style="max-width:720px;">
      <div class="card-body p-4 p-md-5">
        <div class="mb-4">
          <span class="badge bg-primary job-meta-badge mb-2"><?= e($job['department_name'] ?? 'General') ?> &middot; <?= e(str_replace('_',' ',$job['employment_type'])) ?></span>
          <h4 class="fw-bold mb-1">Apply for <?= e($job['title']) ?></h4>
          <p class="apply-subtitle mb-0">No account needed — just fill out this form and attach your documents. You'll get a Reference Code to track your application.</p>
        </div>

        <?php foreach ($errors as $err): ?>
          <div class="alert alert-danger small"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" enctype="multipart/form-data" novalidate>
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="job_id" value="<?= $job['job_id'] ?>">

          <div class="form-section">
            <div class="section-title text-uppercase mb-3"><i class="bi bi-person-vcard"></i> Contact Information</div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">First Name *</label>
                <input type="text" name="first_name" class="form-control" required value="<?= e($old['first_name']) ?>">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Last Name *</label>
                <input type="text" name="last_name" class="form-control" required value="<?= e($old['last_name']) ?>">
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-md-0 mb-3">
                <label class="form-label">Email Address *</label>
                <input type="email" name="email" class="form-control" required value="<?= e($old['email']) ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Mobile Number *</label>
                <input type="tel" name="phone" class="form-control" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX" required value="<?= e($old['phone']) ?>">
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="section-title text-uppercase mb-3"><i class="bi bi-file-earmark-text"></i> Resume &amp; Documents</div>
            <div class="mb-3">
              <label class="form-label">Resume / CV * (PDF or DOCX, max 5MB)</label>
              <input type="file" name="resume" class="form-control" accept=".pdf,.docx" required>
            </div>
            <div class="mb-1">
              <label class="form-label">Other Required Documents (Valid ID, Certificates, NBI Clearance, etc.)</label>
              <div id="docRows">
                <div class="row g-2 doc-row mx-0">
                  <div class="col-md-4">
                    <select name="document_labels[]" class="form-select form-select-sm">
                      <?php foreach ($documentTypes as $dt): ?><option value="<?= e($dt) ?>"><?= e($dt) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-8">
                    <input type="file" name="documents[]" class="form-control form-control-sm" accept=".pdf,.docx,.jpg,.jpeg,.png">
                  </div>
                </div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addDocRow()"><i class="bi bi-plus-lg"></i> Add another document</button>
              <div class="form-text">Optional, but recommended if the job posting requires them. PDF, DOCX, JPG, or PNG, max 5MB each.</div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-submit w-100 mt-4"><i class="bi bi-send"></i> Submit Application</button>
        </form>
        <p class="text-center small footer-links mb-0"><a href="<?= BASE_URL ?>">&larr; Back to Home</a> &middot; <a href="<?= BASE_URL ?>check-status">Already applied? Check status</a></p>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
function addDocRow() {
  const rows = document.getElementById('docRows');
  const row = rows.querySelector('.doc-row').cloneNode(true);
  row.querySelectorAll('input[type=file]').forEach(i => i.value = '');
  rows.appendChild(row);
}
</script>
</body>
</html>
