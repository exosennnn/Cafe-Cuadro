<?php
require 'c:/xampp/htdocs/unified/config/db.php';
$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM employees e JOIN users u ON e.user_id = u.user_id WHERE e.employment_status='ACTIVE' AND u.role_id NOT IN (?, ?, ?)");
$stmt->execute([3, 1, 4]); // 3=owner, 1=hr manager, 4=employee manager
echo 'Filtered: ' . $stmt->fetchColumn() . "\n";
echo 'All: ' . $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status='ACTIVE'")->fetchColumn() . "\n";
