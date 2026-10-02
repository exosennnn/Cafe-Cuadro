<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole([ROLE_OWNER]);

$userId = $_SESSION['user_id'];

/*
 * Only these roles may be manually created/assigned
 * through User Management.
 *
 * OWNER    -> cannot be created/assigned here
 * EMPLOYEE -> system-generated after hiring/contract
 * APPLICANT -> handled by recruitment/application workflow
 */
$ASSIGNABLE_ROLES = [
    ROLE_HR_MANAGER,
    ROLE_HR_STAFF,
    ROLE_EMPLOYEE_MANAGER
];

$VALID_USER_STATUSES = ['ACTIVE', 'SUSPENDED'];

$validateName = static function ($value): bool {
    $value = trim((string)$value);

    return $value !== ''
        && strlen($value) <= 100
        && !preg_match('/[\x00-\x1F\x7F]/', $value);
};

$validatePassword = static function ($value): bool {
    return is_string($value)
        && strlen($value) >= 8
        && strlen($value) <= 255;
};

$validateDepartment = static function (PDO $pdo, $value): array {
    if ($value === '' || $value === null) {
        return [true, null];
    }

    $departmentId = filter_var($value, FILTER_VALIDATE_INT);

    if ($departmentId === false || $departmentId < 1) {
        return [false, null];
    }

    $stmt = $pdo->prepare(
        'SELECT department_id FROM departments WHERE department_id = ?'
    );
    $stmt->execute([$departmentId]);

    return [$stmt->fetch() !== false, $departmentId];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrfVerify();

    $action = $_POST['action'] ?? '';

    /*
     * CREATE USER
     * Only HR Manager, HR Staff, and Employee Manager
     * can be manually created here.
     */
    if ($action === 'create') {

        $roleId = filter_var(
            $_POST['role_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $email = strtolower(trim($_POST['email'] ?? ''));
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        $phoneDigits = preg_replace('/\D+/', '', $phone);

        [$departmentValid, $deptId] = $validateDepartment(
            $pdo,
            $_POST['department_id'] ?? null
        );

        $position = trim($_POST['position'] ?? '');

        $errors = [];

        /*
         * SECURITY:
         * Never trust the role submitted by the browser.
         */
        if (
            $roleId === false ||
            !in_array($roleId, $ASSIGNABLE_ROLES, true)
        ) {
            $errors[] = 'You are not allowed to assign that role.';
        }

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 150
        ) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (
            !$validateName($first) ||
            !$validateName($last)
        ) {
            $errors[] =
                'First and last names are required and must be 100 characters or fewer.';
        }

        if (!$validatePassword($password)) {
            $errors[] =
                'Password must be between 8 and 255 characters.';
        }

        if (
            $phone !== '' &&
            !preg_match('/^09\d{9}$/', $phoneDigits)
        ) {
            $errors[] =
                'Please enter a valid 11-digit Philippine mobile number starting with 09.';
        }

        if (!$departmentValid) {
            $errors[] = 'Please select a valid department.';
        }

        if (strlen($position) > 100) {
            $errors[] =
                'Position must be 100 characters or fewer.';
        }

        if (!$errors) {

            try {

                $pdo->beginTransaction();

                /*
                 * Check duplicate email.
                 */
                $exists = $pdo->prepare(
                    "SELECT user_id
                     FROM users
                     WHERE email = ?
                     LIMIT 1"
                );

                $exists->execute([$email]);

                if ($exists->fetch()) {
                    throw new RuntimeException(
                        'A user with that email already exists.'
                    );
                }

                /*
                 * Hash password before storing.
                 */
                $hash = password_hash(
                    $password,
                    PASSWORD_BCRYPT
                );

                /*
                 * Create only an authorized staff/manager account.
                 */
                $insertUser = $pdo->prepare(
                    "INSERT INTO users
                    (
                        role_id,
                        email,
                        password_hash,
                        first_name,
                        last_name,
                        phone,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE')"
                );

                $insertUser->execute([
                    $roleId,
                    $email,
                    $hash,
                    $first,
                    $last,
                    $phoneDigits ?: null
                ]);

                $newUserId = (int)$pdo->lastInsertId();

                /*
                 * Department and position are relevant to staff/manager
                 * accounts. Do NOT create employees or applicants here.
                 */
                if ($deptId !== null || $position !== '') {

                    /*
                     * If your users table already has department/position
                     * fields, put the update here.
                     *
                     * Otherwise these values are simply not stored.
                     */
                }

                $pdo->commit();

                setFlash(
                    'success',
                    'User account created successfully.'
                );

                logAudit(
                    $pdo,
                    $userId,
                    'CREATE_USER',
                    'User Management',
                    "user_id=$newUserId email=$email role_id=$roleId"
                );

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                setFlash(
                    'error',
                    $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'User account could not be created. No records were changed.'
                );
            }

        } else {

            foreach ($errors as $error) {
                setFlash('error', $error);
            }
        }

    /*
     * UPDATE USER STATUS
     */
    } elseif ($action === 'update_status') {

        $targetId = filter_var(
            $_POST['user_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $status = $_POST['status'] ?? '';

        $target = null;

        if ($targetId !== false && $targetId > 0) {

            $targetStmt = $pdo->prepare(
                "SELECT role_id
                 FROM users
                 WHERE user_id = ?"
            );

            $targetStmt->execute([$targetId]);
            $target = $targetStmt->fetch();
        }

        $targetRole = (int)($target['role_id'] ?? 0);

        if (
            $targetId === false ||
            $targetId < 1 ||
            !$target
        ) {

            setFlash(
                'error',
                'User account not found.'
            );

        } elseif ($targetId === (int)$userId) {

            setFlash(
                'error',
                'You cannot change your own account status here.'
            );

        } elseif (
            !in_array(
                $status,
                $VALID_USER_STATUSES,
                true
            )
        ) {

            setFlash(
                'error',
                'Invalid account status.'
            );

        } elseif ($targetRole === ROLE_OWNER) {

            setFlash(
                'error',
                'You cannot change the status of the Owner account.'
            );

        } else {

            $update = $pdo->prepare(
                "UPDATE users
                 SET status = ?
                 WHERE user_id = ?
                   AND role_id <> ?"
            );

            $update->execute([
                $status,
                $targetId,
                ROLE_OWNER
            ]);

            if ($update->rowCount() === 1) {

                setFlash(
                    'success',
                    'User status updated.'
                );

                logAudit(
                    $pdo,
                    $userId,
                    'UPDATE_USER_STATUS',
                    'User Management',
                    "user_id=$targetId status=$status"
                );

            } else {

                setFlash(
                    'error',
                    'User status could not be updated.'
                );
            }
        }

    /*
     * RESET PASSWORD
     */
    } elseif ($action === 'reset_password') {

        $targetId = filter_var(
            $_POST['user_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $newPass = $_POST['new_password'] ?? '';

        $target = null;

        if ($targetId !== false && $targetId > 0) {

            $targetStmt = $pdo->prepare(
                "SELECT role_id
                 FROM users
                 WHERE user_id = ?"
            );

            $targetStmt->execute([$targetId]);
            $target = $targetStmt->fetch();
        }

        $targetRole = (int)($target['role_id'] ?? 0);

        if (
            $targetId === false ||
            $targetId < 1 ||
            !$target
        ) {

            setFlash(
                'error',
                'User account not found.'
            );

        } elseif (!$validatePassword($newPass)) {

            setFlash(
                'error',
                'Password must be between 8 and 255 characters.'
            );

        } elseif ($targetRole === ROLE_OWNER) {

            setFlash(
                'error',
                'You cannot reset the Owner account password.'
            );

        } else {

            $hash = password_hash(
                $newPass,
                PASSWORD_BCRYPT
            );

            $update = $pdo->prepare(
                "UPDATE users
                 SET password_hash = ?
                 WHERE user_id = ?
                   AND role_id <> ?"
            );

            $update->execute([
                $hash,
                $targetId,
                ROLE_OWNER
            ]);

            if ($update->rowCount() === 1) {

                setFlash(
                    'success',
                    'Password reset successfully.'
                );

                logAudit(
                    $pdo,
                    $userId,
                    'RESET_PASSWORD',
                    'User Management',
                    "user_id=$targetId"
                );

            } else {

                setFlash(
                    'error',
                    'Password could not be reset.'
                );
            }
        }

    /*
     * CHANGE ROLE
     */
    } elseif ($action === 'change_role') {

        $targetId = filter_var(
            $_POST['user_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $newRole = filter_var(
            $_POST['role_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $target = null;

        if ($targetId !== false && $targetId > 0) {

            $targetStmt = $pdo->prepare(
                "SELECT role_id
                 FROM users
                 WHERE user_id = ?"
            );

            $targetStmt->execute([$targetId]);
            $target = $targetStmt->fetch();
        }

        $targetRole = (int)($target['role_id'] ?? 0);

        if (
            $targetId === false ||
            $targetId < 1 ||
            !$target
        ) {

            setFlash(
                'error',
                'User account not found.'
            );

        } elseif ($targetId === (int)$userId) {

            setFlash(
                'error',
                'You cannot change your own role.'
            );

        } elseif ($targetRole === ROLE_OWNER) {

            setFlash(
                'error',
                'You cannot change the role of the Owner account.'
            );

        } elseif (
            $newRole === false ||
            !in_array(
                $newRole,
                $ASSIGNABLE_ROLES,
                true
            )
        ) {

            /*
             * This blocks:
             * OWNER
             * EMPLOYEE
             * APPLICANT
             */
            setFlash(
                'error',
                'You are not allowed to assign that role.'
            );

        } else {

            try {

                $pdo->beginTransaction();

                $update = $pdo->prepare(
                    "UPDATE users
                     SET role_id = ?
                     WHERE user_id = ?
                       AND role_id <> ?"
                );

                $update->execute([
                    $newRole,
                    $targetId,
                    ROLE_OWNER
                ]);

                if ($update->rowCount() !== 1) {
                    throw new RuntimeException(
                        'User role could not be updated.'
                    );
                }

                $pdo->commit();

                setFlash(
                    'success',
                    'User role updated.'
                );

                logAudit(
                    $pdo,
                    $userId,
                    'CHANGE_ROLE',
                    'User Management',
                    "user_id=$targetId from_role=$targetRole new_role=$newRole"
                );

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                setFlash(
                    'error',
                    'User role could not be updated. No records were changed.'
                );
            }
        }
    }

    redirect('modules/hr_manager/users.php');
}


/*
 * SEARCH / FILTER
 */
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';

$where = '1=1';
$params = [];

if ($search !== '') {

    $where .=
        " AND (
            u.first_name LIKE ?
            OR u.last_name LIKE ?
            OR u.email LIKE ?
        )";

    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($roleFilter !== '') {

    $roleIdFilter = filter_var(
        $roleFilter,
        FILTER_VALIDATE_INT
    );

    if (
        $roleIdFilter !== false &&
        $roleIdFilter > 0
    ) {
        $where .= " AND u.role_id = ?";
        $params[] = $roleIdFilter;
    }
}


/*
 * PAGINATION
 */
$totalStmt = $pdo->prepare(
    "SELECT COUNT(*) AS c
     FROM users u
     WHERE $where"
);

$totalStmt->execute($params);

$total = (int)$totalStmt->fetch()['c'];

[$offset, $limit, $page, $totalPages] =
    paginate($total, 10);


/*
 * USERS
 */
$stmt = $pdo->prepare(
    "SELECT
        u.*,
        r.role_name
     FROM users u
     JOIN roles r
        ON u.role_id = r.role_id
     WHERE $where
     ORDER BY u.user_id DESC
     LIMIT $limit OFFSET $offset"
);

$stmt->execute($params);

$users = $stmt->fetchAll();


/*
 * Only load roles that are actually assignable.
 */
$roleStmt = $pdo->query(
    "SELECT *
     FROM roles
     ORDER BY role_id"
);

$allRoles = $roleStmt->fetchAll();

$roles = array_values(
    array_filter(
        $allRoles,
        static function ($role) use ($ASSIGNABLE_ROLES) {
            return in_array(
                (int)$role['role_id'],
                $ASSIGNABLE_ROLES,
                true
            );
        }
    )
);


$departments = $pdo
    ->query(
        "SELECT *
         FROM departments
         ORDER BY department_name"
    )
    ->fetchAll();


$pageTitle = 'Manage Users';

require_once __DIR__ . '/../../includes/header.php';

?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4>Manage Users</h4>

    <button
        class="btn btn-primary"
        data-bs-toggle="modal"
        data-bs-target="#createModal"
    >
        <i class="bi bi-person-plus"></i>
        Create User
    </button>
</div>


<form method="GET" class="row g-2 mb-3">

    <div class="col-md-4">
        <input
            type="text"
            name="search"
            class="form-control"
            placeholder="Search name/email..."
            value="<?= e($search) ?>"
        >
    </div>

    <div class="col-md-3">

        <select name="role" class="form-select">

            <option value="">
                All Roles
            </option>

            <?php foreach ($allRoles as $r): ?>

                <option
                    value="<?= $r['role_id'] ?>"
                    <?= $roleFilter == $r['role_id'] ? 'selected' : '' ?>
                >
                    <?= e(str_replace('_', ' ', $r['role_name'])) ?>
                </option>

            <?php endforeach; ?>

        </select>

    </div>

    <div class="col-md-2">

        <button class="btn btn-outline-secondary w-100">
            <i class="bi bi-search"></i>
        </button>

    </div>

</form>


<div class="card">

    <div class="card-body table-responsive">

        <table
            class="table table-hover align-middle"
            id="usersTable"
        >

            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($users as $u): ?>

                    <tr>

                        <td>
                            <?= e(
                                $u['first_name'] . ' ' . $u['last_name']
                            ) ?>
                        </td>

                        <td>
                            <?= e($u['email']) ?>
                        </td>

                        <td>
                            <span class="badge bg-info">
                                <?= e(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $u['role_name']
                                    )
                                ) ?>
                            </span>
                        </td>

                        <td>
                            <span
                                class="badge <?= $u['status'] === 'ACTIVE'
                                    ? 'bg-success'
                                    : 'bg-secondary' ?>"
                            >
                                <?= e($u['status']) ?>
                            </span>
                        </td>

                        <td>
                            <?= $u['last_login']
                                ? fdate(
                                    $u['last_login'],
                                    'M d, Y g:i A'
                                )
                                : 'Never' ?>
                        </td>

                        <td class="text-nowrap">

                            <?php if (
                                (int)$u['role_id'] === ROLE_OWNER
                            ): ?>

                                <span class="text-muted">
                                    No actions
                                </span>

                            <?php else: ?>

                                <div class="d-flex gap-1">

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        title="Change Role"
                                        data-bs-toggle="modal"
                                        data-bs-target="#roleModal<?= $u['user_id'] ?>"
                                    >
                                        <i class="bi bi-shield-lock"></i>
                                    </button>


                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-warning"
                                        title="Reset Password"
                                        data-bs-toggle="modal"
                                        data-bs-target="#pwModal<?= $u['user_id'] ?>"
                                    >
                                        <i class="bi bi-key"></i>
                                    </button>


                                    <form
                                        method="POST"
                                        class="d-inline"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= csrfToken() ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="update_status"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= $u['user_id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="status"
                                            value="<?= $u['status'] === 'ACTIVE'
                                                ? 'SUSPENDED'
                                                : 'ACTIVE' ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            title="<?= $u['status'] === 'ACTIVE'
                                                ? 'Suspend'
                                                : 'Activate' ?>"
                                        >
                                            <i class="bi bi-<?= $u['status'] === 'ACTIVE'
                                                ? 'lock'
                                                : 'unlock' ?>"></i>
                                        </button>

                                    </form>

                                </div>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (empty($users)): ?>

                    <tr>
                        <td
                            colspan="6"
                            class="text-center text-muted"
                        >
                            No users found.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

        <?= renderPagination($page, $totalPages) ?>

    </div>

</div>


<?php foreach ($users as $u): ?>

    <?php
    if ((int)$u['role_id'] === ROLE_OWNER) {
        continue;
    }
    ?>


    <!-- CHANGE ROLE MODAL -->

    <div
        class="modal fade"
        id="roleModal<?= $u['user_id'] ?>"
        tabindex="-1"
    >

        <div class="modal-dialog">

            <div class="modal-content">

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= csrfToken() ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="change_role"
                    >

                    <input
                        type="hidden"
                        name="user_id"
                        value="<?= $u['user_id'] ?>"
                    >


                    <div class="modal-header">

                        <h6 class="modal-title">
                            Change Role
                        </h6>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                        ></button>

                    </div>


                    <div class="modal-body">

                        <select
                            name="role_id"
                            class="form-select"
                            required
                        >

                            <?php foreach ($roles as $r): ?>

                                <option
                                    value="<?= $r['role_id'] ?>"
                                    <?= (int)$u['role_id'] === (int)$r['role_id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $r['role_name']
                                        )
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="modal-footer">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Save
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>


    <!-- RESET PASSWORD MODAL -->

    <div
        class="modal fade"
        id="pwModal<?= $u['user_id'] ?>"
        tabindex="-1"
    >

        <div class="modal-dialog">

            <div class="modal-content">

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= csrfToken() ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="reset_password"
                    >

                    <input
                        type="hidden"
                        name="user_id"
                        value="<?= $u['user_id'] ?>"
                    >


                    <div class="modal-header">

                        <h6 class="modal-title">
                            Reset Password
                        </h6>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                        ></button>

                    </div>


                    <div class="modal-body">

                        <input
                            type="password"
                            name="new_password"
                            class="form-control"
                            minlength="8"
                            maxlength="255"
                            required
                            placeholder="New password (min 8 chars)"
                        >

                    </div>


                    <div class="modal-footer">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Reset
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

<?php endforeach; ?>


<link
    rel="stylesheet"
    href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css"
>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<script>
$(function () {

    $.fn.dataTable.ext.errMode = 'none';

    $('#usersTable').DataTable({
        order: [],
        paging: false,
        info: false,
        columnDefs: [
            {
                orderable: false,
                targets: -1
            }
        ]
    });

});
</script>


<!-- CREATE USER MODAL -->

<div
    class="modal fade"
    id="createModal"
    tabindex="-1"
>

    <div class="modal-dialog modal-lg">

        <div class="modal-content">

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= csrfToken() ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="create"
                >


                <div class="modal-header">

                    <h5 class="modal-title">
                        Create New User
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>


                <div class="modal-body">

                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                First Name
                            </label>

                            <input
                                type="text"
                                name="first_name"
                                class="form-control"
                                maxlength="100"
                                required
                            >

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Last Name
                            </label>

                            <input
                                type="text"
                                name="last_name"
                                class="form-control"
                                maxlength="100"
                                required
                            >

                        </div>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Email
                        </label>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            maxlength="150"
                            required
                        >

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Phone
                        </label>

                        <input
                            type="tel"
                            name="phone"
                            class="form-control"
                            pattern="09\d{9}"
                            maxlength="11"
                            placeholder="09XXXXXXXXX"
                        >

                    </div>


                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Role
                            </label>

                            <select
                                name="role_id"
                                class="form-select"
                                required
                            >

                                <?php foreach ($roles as $r): ?>

                                    <option
                                        value="<?= $r['role_id'] ?>"
                                    >
                                        <?= e(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $r['role_name']
                                            )
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Department
                            </label>

                            <select
                                name="department_id"
                                class="form-select"
                            >

                                <option value="">
                                    -- N/A --
                                </option>

                                <?php foreach ($departments as $d): ?>

                                    <option
                                        value="<?= $d['department_id'] ?>"
                                    >
                                        <?= e($d['department_name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Position
                        </label>

                        <input
                            type="text"
                            name="position"
                            class="form-control"
                            maxlength="100"
                        >

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Temporary Password
                        </label>

                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            minlength="8"
                            maxlength="255"
                            required
                        >

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Create User
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<?php require_once __DIR__ . '/../../includes/footer.php'; ?>