<?php
require 'c:/xampp/htdocs/unified/config/db.php';
$pdo->query("ALTER TABLE job_offers ADD COLUMN token VARCHAR(64) NULL AFTER application_id");
echo "Column added.";
