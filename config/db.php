<?php
/**
 * UNIFIED DATABASE CONNECTION
 * Single shared connection used by every module: HRMS, POS, Inventory,
 * Procurement, Finance, Reports. Do not create additional DB connections
 * elsewhere - always require this file.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'unified_cafe_system');
define('DB_USER', 'root');
define('DB_PASS', ''); // default XAMPP password is blank - change in production

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Backwards-compatible alias: the original POS codebase used $conn instead
// of $pdo. Keeping both names means POS pages that were not touched during
// integration keep working unchanged.
$conn = $pdo;
