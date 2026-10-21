<?php
require __DIR__ . '/loichua_parser.php';
$date = date('Y-m-d');
$html = loichua_fetch_html($date);
$parsed = loichua_parse_html($html, $date);
echo json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
