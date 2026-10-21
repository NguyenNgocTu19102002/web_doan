<?php
require __DIR__ . '/../db_connect.php';

function ht_cell($row, $col) {
    if (!isset($row->c)) return '';
    foreach ($row->c as $c) {
        $ref = (string)$c['r'];
        if (preg_match('/^' . preg_quote($col, '/') . '\d+$/', $ref)) {
            if (isset($c->is->t)) {
                return html_entity_decode((string)$c->is->t, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            if (isset($c->v)) return (string)$c->v;
        }
    }
    return '';
}

$xlsx = __DIR__ . '/../danh_sach_huynh_truong.xlsx';
$zip = new ZipArchive();
$zip->open($xlsx);
$xml = $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
$sx = simplexml_load_string($xml);
$sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
$rows = $sx->xpath('//m:sheetData/m:row');

echo "=== HEADER + 3 rows ===\n";
$n = 0;
foreach ($rows as $row) {
    $rnum = (int)$row['r'];
    $cols = [];
    foreach (['A','B','C','D','E','F','G','H','I','J','K','L'] as $col) {
        $v = trim(ht_cell($row, $col));
        if ($v !== '') $cols[] = "$col=$v";
    }
    echo "R$rnum | " . implode(' || ', $cols) . "\n";
    if (++$n >= 4) break;
}
echo 'Total rows: ' . count($rows) . "\n\n";

echo "=== users ===\n";
$r = $conn->query('DESCRIBE users');
while ($x = $r->fetch_assoc()) echo $x['Field'] . ' | ' . $x['Type'] . "\n";

echo "\n=== teachers ===\n";
$r = $conn->query('DESCRIBE teachers');
while ($x = $r->fetch_assoc()) echo $x['Field'] . ' | ' . $x['Type'] . "\n";

echo "\n=== sample teacher ===\n";
$r = $conn->query('SELECT * FROM teachers LIMIT 1');
print_r($r->fetch_assoc());

echo "\n=== sample user ===\n";
$r = $conn->query("SELECT id,username,role,full_name,teacher_id FROM users WHERE role='huynh_truong' LIMIT 1");
print_r($r->fetch_assoc());
