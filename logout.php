<?php
session_start();
require_once __DIR__ . '/login_redirect.php';
require_once __DIR__ . '/db_connect.php';
login_remember_forget($conn);
$_SESSION = array();
session_destroy();
header('Location: index.php');
exit;
?>