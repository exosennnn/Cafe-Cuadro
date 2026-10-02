<?php
require 'c:/xampp/htdocs/unified/config/db.php';
$cols = $pdo->query('SHOW COLUMNS FROM payroll')->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) echo $c['Field'] . " " . $c['Type'] . "\n";
