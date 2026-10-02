<?php
require 'c:/xampp/htdocs/unified/config/db.php';
$deptDist = $pdo->query("SELECT d.department_name, COUNT(e.employee_id) AS cnt FROM departments d JOIN employees e ON e.department_id=d.department_id AND e.employment_status='ACTIVE' GROUP BY d.department_id")->fetchAll();
print_r($deptDist);

$roleDist = $pdo->query("
    SELECT r.role_name, COUNT(e.employee_id) AS cnt 
    FROM employees e 
    JOIN users u ON e.user_id = u.user_id 
    JOIN roles r ON u.role_id = r.role_id 
    WHERE e.employment_status = 'ACTIVE' 
    GROUP BY r.role_id
")->fetchAll();
print_r($roleDist);
