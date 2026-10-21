<?php
session_start();
unset($_SESSION['show_bo_nhiem']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true]);
