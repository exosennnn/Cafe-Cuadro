<?php
/**
 * POS DATABASE CONNECTION
 * Now simply delegates to the single unified connection so POS reads
 * and writes to the SAME database as HRMS/Inventory/Procurement/Finance.
 * Kept as its own file (same name, same $conn variable) so none of the
 * original POS pages needed to change their `require 'database.php'` line.
 */
require_once __DIR__ . '/../config/db.php';
// $pdo and $conn are both now available (see config/db.php for the alias).
