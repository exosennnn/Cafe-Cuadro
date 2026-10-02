<?php
require 'database.php';
require 'app.php';
require_admin();

$message     = "";
$edit_user   = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $requestedRoleId = (int)($_POST['role_id'] ?? 0);
        $role_id   = in_array($requestedRoleId, [ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF, ROLE_OWNER], true) ? $requestedRoleId : ROLE_CASHIER;
        $password  = $_POST['password'] ?? '';
        $existingRoleId = null;
        if ($id) {
            $existingRoleStmt = $conn->prepare('SELECT role_id FROM users WHERE user_id=?');
            $existingRoleStmt->execute([$id]);
            $existingRoleId = (int)($existingRoleStmt->fetchColumn() ?: 0);
        }

        // Only an actual Owner account may create, edit, or promote an
        // account to the Owner role. require_admin() already restricts
        // this whole page to Owner-level sessions, but this check is kept
        // explicit so Owner-role assignment can never depend solely on
        // the page-level gate.
        if (in_array($role_id, [ROLE_CASHIER, ROLE_INVENTORY_STAFF], true)
            && (!$id || $existingRoleId !== $role_id)) {
            $message = "<div class='alert error'>Cashier and Inventory Staff accounts must be provisioned through recruitment and hiring.</div>";
        } elseif ($role_id === ROLE_OWNER && (int) currentRoleId() !== ROLE_OWNER) {
            $message = "<div class='alert error'>Only an Owner account can assign the Owner role.</div>";
        } elseif ($username === '' || $email === '' || $full_name === '') {
            $message = "<div class='alert error'>Please fill in all required fields.</div>";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "<div class='alert error'>Please enter a valid email address.</div>";
        } elseif (!$id && $password === '') {
            $message = "<div class='alert error'>Password is required when creating a new user.</div>";
        } elseif ($password !== '' && strlen($password) < 6) {
            $message = "<div class='alert error'>Password must be at least 6 characters.</div>";
        } else {
            try {
                $nameParts = preg_split('/\s+/', $full_name, 2);
                $firstName = $nameParts[0];
                $lastName  = $nameParts[1] ?? '';

                if ($id) {
                    if ($password !== '') {
                        $hashed = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare("UPDATE users SET username=?, email=?, first_name=?, last_name=?, role_id=?, password_hash=? WHERE user_id=?");
                        $stmt->execute([$username, $email, $firstName, $lastName, $role_id, $hashed, $id]);
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET username=?, email=?, first_name=?, last_name=?, role_id=? WHERE user_id=?");
                        $stmt->execute([$username, $email, $firstName, $lastName, $role_id, $id]);
                    }
                    $message = "<div class='alert success'>User updated successfully.</div>";
                } else {
                    $hashed = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("INSERT INTO users (username, email, first_name, last_name, role_id, password_hash) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $email, $firstName, $lastName, $role_id, $hashed]);
                    $message = "<div class='alert success'>User added successfully.</div>";
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $message = "<div class='alert error'>Username or email already exists. Please use a different one.</div>";
                } else {
                    $message = "<div class='alert error'>Database save failure. Could not save user. Please try again.</div>";
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id && $id !== (int) $_SESSION['user_id']) {
            $targetRoleStmt = $conn->prepare("SELECT role_id FROM users WHERE user_id = ?");
            $targetRoleStmt->execute([$id]);
            $targetRoleId = (int) ($targetRoleStmt->fetchColumn() ?: 0);

            if ($targetRoleId === ROLE_OWNER && (int) currentRoleId() !== ROLE_OWNER) {
                $message = "<div class='alert error'>Only an Owner account can delete an Owner account.</div>";
            } else {
                try {
                    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                    $stmt->execute([$id]);
                    $message = "<div class='alert success'>User deleted.</div>";
                } catch (PDOException $e) {
                    $message = "<div class='alert error'>Cannot delete this user — they may have associated transactions.</div>";
                }
            }
        } elseif ($id === (int) $_SESSION['user_id']) {
            $message = "<div class='alert error'>You cannot delete your own account.</div>";
        }
    }
}

$edit_id = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($edit_id) {
    $stmt = $conn->prepare("SELECT user_id AS id, username, email, CONCAT(first_name, ' ', last_name) AS full_name, role_id FROM users WHERE user_id = ?");
    $stmt->execute([$edit_id]);
    $edit_user = $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    // Scoped to staff (admin/cashier) only — kiosk customer self-registrations
    // (role='customer', added by the new kiosk_register.php) use this same
    // users table but belong on the customer side, not in staff management.
    $roleFilter = implode(',', [ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF, ROLE_OWNER]);
    $users = $conn->query("SELECT u.user_id AS id, u.username, u.email, CONCAT(u.first_name, ' ', u.last_name) AS full_name, u.role_id, r.role_name, u.created_at, COUNT(t.id) AS txn_count FROM users u JOIN roles r ON r.role_id = u.role_id LEFT JOIN transactions t ON t.user_id = u.user_id WHERE u.role_id IN ($roleFilter) GROUP BY u.user_id ORDER BY u.role_id, full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users   = [];
    $message = "<div class='alert error'>Database retrieval failure. Could not load users.</div>";
}

$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/shared/pos-legacy.css">
<div class="mb-4">
    <h1 class="h4 fw-bold mb-1">User Management</h1>
</div>
<?php
?>
<?= $message ?>

<section class="panel">
    <h2><?= $edit_user ? 'Edit User' : 'Add User' ?></h2>
    <form method="POST" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($edit_user['id'] ?? '') ?>">

        <div>
            <label for="full_name">Full Name</label>
            <input id="full_name" name="full_name" value="<?= h($edit_user['full_name'] ?? '') ?>" required placeholder="Juan dela Cruz">
        </div>
        <div>
            <label for="username">Username</label>
            <input id="username" name="username" value="<?= h($edit_user['username'] ?? '') ?>" required placeholder="juandc">
        </div>
        <div>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?= h($edit_user['email'] ?? '') ?>" required placeholder="juan@pos.com">
        </div>
        <div>
            <label for="role_id">Role</label>
            <select id="role_id" name="role_id">
                <option value="<?= ROLE_FINANCE_STAFF ?>" <?= (($edit_user['role_id'] ?? ROLE_FINANCE_STAFF) == ROLE_FINANCE_STAFF) ? 'selected' : '' ?>>Finance Staff</option>
                <option value="<?= ROLE_OWNER ?>" <?= (($edit_user['role_id'] ?? '') == ROLE_OWNER) ? 'selected' : '' ?>>Owner</option>
                <?php if ((int)($edit_user['role_id'] ?? 0) === ROLE_CASHIER): ?><option value="<?= ROLE_CASHIER ?>" selected>Cashier (existing role)</option><?php endif; ?>
                <?php if ((int)($edit_user['role_id'] ?? 0) === ROLE_INVENTORY_STAFF): ?><option value="<?= ROLE_INVENTORY_STAFF ?>" selected>Inventory Staff (existing role)</option><?php endif; ?>
            </select>
        </div>
        <div>
            <label for="password">Password <?= $edit_user ? '(leave blank to keep current)' : '' ?></label>
            <input id="password" name="password" type="password" placeholder="<?= $edit_user ? 'Leave blank to keep' : 'Min 6 characters' ?>" <?= $edit_user ? '' : 'required' ?> minlength="6">
        </div>
        <div style="display:flex; gap:8px; align-items:flex-end;">
            <button type="submit"><?= $edit_user ? 'Save Changes' : 'Add User' ?></button>
            <?php if ($edit_user): ?>
                <a class="button secondary" href="<?= BASE_URL ?>pos/users">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="panel" style="margin-top:18px;">
    <h2>Users</h2>
    <table>
        <thead>
            <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Transactions</th><th>Created</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
            <tr><td colspan="7">No users found.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= h($user['full_name']) ?></td>
                <td><?= h($user['username']) ?></td>
                <td><?= h($user['email']) ?></td>
                <td>
                    <span class="<?= $user['role_id'] == ROLE_CASHIER ? 'badge-role-cashier' : 'badge-role-admin' ?>">
                        <?= h($user['role_name']) ?>
                    </span>
                </td>
                <td><?= h($user['txn_count']) ?></td>
                <td><?= h(substr($user['created_at'], 0, 10)) ?></td>
                <td>
                    <div class="actions">
                        <a class="button secondary" href="<?= BASE_URL ?>pos/users?edit=<?= h($user['id']) ?>">Edit</a>
                        <?php if ($user['id'] !== (int) $_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="return confirm('Delete user <?= h(addslashes($user['full_name'])) ?>? This cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= h($user['id']) ?>">
                                <button class="danger" type="submit">Delete</button>
                            </form>
                        <?php else: ?>
                            <span class="muted" style="font-size:12px;">(you)</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>