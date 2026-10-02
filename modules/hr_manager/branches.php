<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_MANAGER]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['branch_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $contact = preg_replace('/[^0-9]/', '', $contact);
        $contact = substr($contact, 0, 11);
        $status = ($_POST['status'] ?? 'ACTIVE') === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $latRaw = trim($_POST['latitude'] ?? '');
        $lngRaw = trim($_POST['longitude'] ?? '');
        $lat = ($latRaw !== '' && is_numeric($latRaw)) ? (float)$latRaw : null;
        $lng = ($lngRaw !== '' && is_numeric($lngRaw)) ? (float)$lngRaw : null;

        $errors = [];
        if ($name === '') { $errors[] = 'Branch name is required.'; }
        if ($contact !== '' && !preg_match('/^09\d{9}$/', $contact)) { $errors[] = 'Contact number must be a valid 11-digit PH mobile number (e.g. 09171234567).'; }
        if ($latRaw !== '' && !is_numeric($latRaw)) { $errors[] = 'Latitude must be a valid number.'; }
        if ($lngRaw !== '' && !is_numeric($lngRaw)) { $errors[] = 'Longitude must be a valid number.'; }
        if (($lat !== null) !== ($lng !== null)) { $errors[] = 'Please set both latitude and longitude together — click the map to drop a pin, or clear both fields.'; }
        if ($lat !== null && ($lat < -90 || $lat > 90)) { $errors[] = 'Latitude must be between -90 and 90.'; }
        if ($lng !== null && ($lng < -180 || $lng > 180)) { $errors[] = 'Longitude must be between -180 and 180.'; }

        if (empty($errors)) {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO branches (branch_name, address, contact_number, latitude, longitude, status) VALUES (?,?,?,?,?,?)")
                    ->execute([$name, $address ?: null, $contact ?: null, $lat, $lng, $status]);
                setFlash('success', 'Branch added.');
            } else {
                $id = (int)$_POST['branch_id'];
                $pdo->prepare("UPDATE branches SET branch_name=?, address=?, contact_number=?, latitude=?, longitude=?, status=? WHERE branch_id=?")
                    ->execute([$name, $address ?: null, $contact ?: null, $lat, $lng, $status, $id]);
                setFlash('success', 'Branch updated.');
            }
            logAudit($pdo, $userId, 'MANAGE_BRANCH', 'Branches', $name);
        } else {
            foreach ($errors as $error) { setFlash('error', $error); }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['branch_id'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE branch_id=?");
        $stmt->execute([$id]);
        $empCount = (int)$stmt->fetchColumn();
        if ($empCount > 0) {
            setFlash('error', "Cannot delete this branch — $empCount employee(s) are still assigned to it. Reassign them first.");
        } else {
            $pdo->prepare("DELETE FROM branches WHERE branch_id=?")->execute([$id]);
            setFlash('success', 'Branch deleted.');
            logAudit($pdo, $userId, 'DELETE_BRANCH', 'Branches', "branch_id=$id");
        }
    }
    redirect('modules/hr_manager/branches.php');
}

$branches = $pdo->query("SELECT b.*,
    (SELECT COUNT(*) FROM employees WHERE branch_id=b.branch_id AND employment_status='ACTIVE') AS emp_count
    FROM branches b ORDER BY b.branch_name")->fetchAll();

$pageTitle = 'Manage Branches';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Leaflet CSS & JS CDN -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- DataTables CDN -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4>Manage Branches</h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#branchModal" onclick="resetForm()"><i class="bi bi-plus-circle"></i> Add Branch</button>
</div>

<!-- SIDE-BY-SIDE LAYOUT -->
<div class="row g-3">
  <!-- Left Side: Table View -->
  <div class="col-lg-7 col-xl-8">
    <div class="card h-100">
      <div class="card-body table-responsive">
        <table class="table table-hover align-middle mb-0" id="branchesTable">
          <thead>
            <tr>
              <th>Branch</th>
              <th>Address</th>
              <th>Contact #</th>
              <th>Coordinates</th>
              <th>Status</th>
              <th>Employees</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($branches as $b): ?>
            <tr>
              <td class="fw-bold"><?= e($b['branch_name']) ?></td>
              <td><?= e($b['address'] ?? '-') ?></td>
              <td><?= e($b['contact_number'] ?? '-') ?></td>
              <td class="small text-muted"><?= ($b['latitude'] !== null && $b['longitude'] !== null) ? e($b['latitude'].', '.$b['longitude']) : '-' ?></td>
              <td><span class="badge <?= $b['status']=='ACTIVE'?'bg-success':'bg-secondary' ?>"><?= e($b['status']) ?></span></td>
              <td><span class="badge bg-info text-dark"><?= (int)$b['emp_count'] ?></span></td>
              <td>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-outline-primary" onclick='editBranch(<?= json_encode($b) ?>)' title="Edit"><i class="bi bi-pencil"></i></button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete this branch?')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="branch_id" value="<?= $b['branch_id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($branches)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No branches found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Right Side: Compact Overview Map -->
  <div class="col-lg-5 col-xl-4">
    <div class="card h-100">
      <div class="card-header fw-bold bg-light py-2">
        <span class="small"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Location Overview Map</span>
      </div>
      <div class="card-body p-0">
        <div id="branchesMap" style="height: 100%; min-height: 350px; width: 100%;" class="rounded-bottom"></div>
      </div>
    </div>
  </div>
</div>

<!-- Compact & Centered Add/Edit Branch Modal -->
<div class="modal fade" id="branchModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 560px;">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" id="formAction" value="create">
        <input type="hidden" name="branch_id" id="branch_id">
        <div class="modal-header py-2"><h5 class="modal-title fs-6 fw-bold" id="modalTitle">Add Branch</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-3">
          <div class="mb-2"><label class="form-label small fw-bold mb-1">Branch Name</label><input type="text" name="branch_name" id="branch_name" class="form-control form-control-sm" required></div>
          <div class="mb-2"><label class="form-label small fw-bold mb-1">Address</label><textarea name="address" id="address" class="form-control form-control-sm" rows="2"></textarea></div>
          <div class="mb-2"><label class="form-label small fw-bold mb-1">Contact Number</label><input type="tel" name="contact_number" id="contact_number" class="form-control form-control-sm" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter a valid 11-digit PH mobile number starting with 09 (e.g. 09171234567)" placeholder="e.g. 09171234567" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)"></div>
          
          <!-- Interactive Picker Map in Modal -->
          <div class="mt-3 mb-2">
            <label class="form-label small fw-bold mb-0">Location Pin</label>
            <div class="form-text mt-0 mb-2 text-muted" style="font-size: 0.75rem;">Click map or drag marker to set coordinates.</div>
            <div id="pickerMap" style="height: 180px;" class="rounded border"></div>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small fw-bold mb-1">Latitude</label><input type="text" name="latitude" id="latitude" class="form-control form-control-sm" placeholder="e.g. 14.5995"></div>
            <div class="col-6"><label class="form-label small fw-bold mb-1">Longitude</label><input type="text" name="longitude" id="longitude" class="form-control form-control-sm" placeholder="e.g. 120.9842"></div>
          </div>
          
          <div class="mb-1"><label class="form-label small fw-bold mb-1">Status</label>
            <select name="status" id="status" class="form-select form-select-sm">
              <option value="ACTIVE">Active</option>
              <option value="INACTIVE">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer py-2"><button class="btn btn-sm btn-primary px-4">Save</button></div>
      </form>
    </div>
  </div>
</div>

<script>
const branchesData = <?= json_encode($branches) ?>;
const defaultLat = 14.5995; 
const defaultLng = 120.9842;

$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#branchesTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});

// --- OVERVIEW MAP ---
const overviewMap = L.map('branchesMap').setView([defaultLat, defaultLng], 11);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '&copy; OpenStreetMap'
}).addTo(overviewMap);

const markerBounds = [];

branchesData.forEach(b => {
  if (b.latitude && b.longitude) {
    const lat = parseFloat(b.latitude);
    const lng = parseFloat(b.longitude);
    markerBounds.push([lat, lng]);

    const marker = L.marker([lat, lng]).addTo(overviewMap);
    marker.bindPopup(`
      <div style="min-width: 140px;">
        <h6 class="mb-1 fw-bold">${b.branch_name}</h6>
        <p class="small text-muted mb-1">${b.address || 'No address set'}</p>
        <span class="badge bg-info text-dark">${b.emp_count} Active Employees</span>
      </div>
    `);
  }
});

if (markerBounds.length > 0) {
  overviewMap.fitBounds(markerBounds, { padding: [30, 30] });
}

// --- MODAL PICKER MAP ---
let pickerMap, pickerMarker;

function initPickerMap() {
  if (!pickerMap) {
    pickerMap = L.map('pickerMap').setView([defaultLat, defaultLng], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap'
    }).addTo(pickerMap);

    pickerMap.on('click', function(e) {
      setPickerMarker(e.latlng.lat, e.latlng.lng);
    });
  }
}

function setPickerMarker(lat, lng) {
  lat = parseFloat(lat);
  lng = parseFloat(lng);
  
  document.getElementById('latitude').value = lat.toFixed(6);
  document.getElementById('longitude').value = lng.toFixed(6);
  syncCoordRequired();

  if (pickerMarker) {
    pickerMarker.setLatLng([lat, lng]);
  } else {
    pickerMarker = L.marker([lat, lng], { draggable: true }).addTo(pickerMap);
    pickerMarker.on('dragend', function(e) {
      const pos = pickerMarker.getLatLng();
      document.getElementById('latitude').value = pos.lat.toFixed(6);
      document.getElementById('longitude').value = pos.lng.toFixed(6);
      syncCoordRequired();
    });
  }
  pickerMap.panTo([lat, lng]);
}

// Latitude/Longitude must travel together: if either has a value, both become
// required so a branch can never be saved with only one coordinate set.
function syncCoordRequired() {
  const lat = document.getElementById('latitude');
  const lng = document.getElementById('longitude');
  const hasEither = lat.value.trim() !== '' || lng.value.trim() !== '';
  lat.required = hasEither;
  lng.required = hasEither;
}

document.getElementById('branchModal').addEventListener('shown.bs.modal', function () {
  initPickerMap();
  pickerMap.invalidateSize();
  
  const currentLat = document.getElementById('latitude').value;
  const currentLng = document.getElementById('longitude').value;
  
  if (currentLat && currentLng) {
    setPickerMarker(currentLat, currentLng);
  } else if (pickerMarker) {
    pickerMap.removeLayer(pickerMarker);
    pickerMarker = null;
  }
  syncCoordRequired();
});

document.getElementById('latitude').addEventListener('input', syncCoordRequired);
document.getElementById('longitude').addEventListener('input', syncCoordRequired);
document.getElementById('latitude').addEventListener('change', updateMarkerFromInput);
document.getElementById('longitude').addEventListener('change', updateMarkerFromInput);

function updateMarkerFromInput() {
  const lat = parseFloat(document.getElementById('latitude').value);
  const lng = parseFloat(document.getElementById('longitude').value);
  if (!isNaN(lat) && !isNaN(lng) && pickerMap) {
    setPickerMarker(lat, lng);
  } else if (pickerMarker && document.getElementById('latitude').value.trim() === '' && document.getElementById('longitude').value.trim() === '') {
    // Both cleared manually — remove the pin so it doesn't get saved stale.
    pickerMap.removeLayer(pickerMarker);
    pickerMarker = null;
  }
}

function resetForm() {
  document.getElementById('modalTitle').innerText = 'Add Branch';
  document.getElementById('formAction').value = 'create';
  document.querySelector('#branchModal form').reset();
  syncCoordRequired();
}

function editBranch(b) {
  document.getElementById('modalTitle').innerText = 'Edit Branch';
  document.getElementById('formAction').value = 'update';
  document.getElementById('branch_id').value = b.branch_id;
  document.getElementById('branch_name').value = b.branch_name;
  document.getElementById('address').value = b.address || '';
  document.getElementById('contact_number').value = b.contact_number || '';
  document.getElementById('latitude').value = b.latitude || '';
  document.getElementById('longitude').value = b.longitude || '';
  document.getElementById('status').value = b.status || 'ACTIVE';
  syncCoordRequired();
  
  new bootstrap.Modal(document.getElementById('branchModal')).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>