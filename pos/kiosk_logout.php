<?php
require 'kiosk_bootstrap.php';

unset($_SESSION['customer_id'], $_SESSION['customer_username'], $_SESSION['customer_full_name']);
header('Location: ' . BASE_URL . 'pos/kiosk');
exit;
