<?php
require 'c:/xampp/htdocs/unified/config/db.php';
$cols = $pdo->query('SELECT employee_id, basic_salary FROM employees')->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);
