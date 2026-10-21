<?php
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/db_connect.php';
require_once __DIR__ . '/loichua_parser.php';

$date = date('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ngay']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['ngay'])) {
    $date = $_POST['ngay'];
}

$result = loichua_fetch_and_save($conn, $date);
if ($result['success']) {
    $data = loichua_load_from_db($conn, $date);
    echo json_encode([
        'success' => true,
        'ngay' => $date,
        'title' => isset($data['title']) ? $data['title'] : '',
        'gospel' => isset($data['gospel']) ? $data['gospel'] : '',
        'reflection' => isset($data['reflection']) ? $data['reflection'] : '',
        'feast' => isset($data['feast']) ? $data['feast'] : '',
        'liturgy_calendar' => isset($data['liturgy_calendar']) ? $data['liturgy_calendar'] : '',
        'readings_summary' => isset($data['readings_summary']) ? $data['readings_summary'] : '',
        'liturgical_color' => isset($data['liturgical_color']) ? $data['liturgical_color'] : '',
        'reading_1' => isset($data['reading_1']) ? $data['reading_1'] : '',
        'gospel_ref' => isset($data['gospel_ref']) ? $data['gospel_ref'] : '',
        'liturgy_year' => isset($data['liturgy_year']) ? $data['liturgy_year'] : '',
    ]);
} else {
    echo json_encode($result);
}

