<?php
require_once 'config/db.php';

$token = $_GET['token'] ?? '';
if (!$token) {
    die("Invalid token.");
}

$stmt = $pdo->prepare("SELECT jo.*, ja.job_id, ja.applicant_id, 
                              jv.title as job_title,
                              COALESCE(a.first_name, u.first_name) as first_name,
                              COALESCE(a.last_name, u.last_name) as last_name,
                              ja.status as application_status
                       FROM job_offers jo
                       JOIN job_applications ja ON jo.application_id = ja.application_id
                       JOIN job_vacancies jv ON ja.job_id = jv.job_id
                       JOIN applicants a ON ja.applicant_id = a.applicant_id
                       LEFT JOIN users u ON a.user_id = u.user_id
                       WHERE jo.token = ?");
$stmt->execute([$token]);
$offer = $stmt->fetch();

if (!$offer) {
    die("Invalid or expired offer link.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($offer['application_status'] !== 'OFFERED') {
        $error = "This offer is no longer valid or has already been responded to.";
    } else {
        if ($action === 'accept') {
            $pdo->beginTransaction();
            try {
                // Update application status
                $updApp = $pdo->prepare("UPDATE job_applications SET status = 'ACCEPTED', updated_at = NOW() WHERE application_id = ?");
                $updApp->execute([$offer['application_id']]);
                
                // Update offer accepted_date
                $updOffer = $pdo->prepare("UPDATE job_offers SET accepted_date = NOW() WHERE offer_id = ?");
                $updOffer->execute([$offer['offer_id']]);
                
                $pdo->commit();
                $success = "You have successfully accepted the job offer!";
                $offer['application_status'] = 'ACCEPTED';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "An error occurred while accepting the offer. Please try again.";
            }
        } elseif ($action === 'reject') {
            $pdo->beginTransaction();
            try {
                // Update application status
                $updApp = $pdo->prepare("UPDATE job_applications SET status = 'REJECTED', updated_at = NOW() WHERE application_id = ?");
                $updApp->execute([$offer['application_id']]);
                
                // We might want to track rejection date if we add it, but for now just status
                $pdo->commit();
                $success = "You have rejected the job offer.";
                $offer['application_status'] = 'REJECTED';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "An error occurred while rejecting the offer. Please try again.";
            }
        }
    }
}

require_once 'includes/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white text-center">
                    <h4>Job Offer Details</h4>
                </div>
                <div class="card-body">
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success"><?= e($success) ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>

                    <h5 class="card-title">Dear <?= e($offer['first_name']) ?> <?= e($offer['last_name']) ?>,</h5>
                    <p class="card-text">We are pleased to offer you the position of <strong><?= e($offer['job_title']) ?></strong>.</p>
                    
                    <ul class="list-group mb-4">
                        <li class="list-group-item"><strong>Salary:</strong> <?= e(fmoney($offer['offered_salary'])) ?></li>
                        <li class="list-group-item"><strong>Employment Type:</strong> <?= e(str_replace('_', ' ', $offer['employment_type'])) ?></li>
                        <li class="list-group-item"><strong>Offer Date:</strong> <?= e(fdate($offer['offer_date'])) ?></li>
                        <?php if ($offer['remarks']): ?>
                            <li class="list-group-item"><strong>Remarks/Details:</strong> <?= nl2br(e($offer['remarks'])) ?></li>
                        <?php endif; ?>
                    </ul>

                    <?php if ($offer['application_status'] === 'OFFERED'): ?>
                        <div class="alert alert-info">
                            Please review the terms and your contract. You can accept or reject this offer below.
                        </div>
                        <form method="POST" class="d-flex justify-content-center gap-3">
                            <button type="submit" name="action" value="accept" class="btn btn-success btn-lg" onclick="return confirm('Are you sure you want to accept this offer?')">
                                <i class="bi bi-check-circle"></i> Accept Offer
                            </button>
                            <button type="submit" name="action" value="reject" class="btn btn-danger btn-lg" onclick="return confirm('Are you sure you want to reject this offer?')">
                                <i class="bi bi-x-circle"></i> Reject Offer
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-<?= $offer['application_status'] === 'ACCEPTED' ? 'success' : 'secondary' ?>">
                            You have already <strong><?= strtolower(e($offer['application_status'])) ?></strong> this offer.
                            <?php if ($offer['application_status'] === 'ACCEPTED'): ?>
                                HR will contact you shortly regarding your onboarding.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
