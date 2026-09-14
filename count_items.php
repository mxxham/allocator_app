<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$spreadsheet = IOFactory::load('C:/Users/asust/Downloads/Warehouse Management System_10 September 2026.xlsx');

// Master SKU
$sheet = $spreadsheet->getSheetByName('Master SKU');
$count = 0;
for ($r = 3; $r <= $sheet->getHighestRow(); $r++) {
    $mat = trim((string)($sheet->getCell('B' . $r)->getValue() ?? ''));
    if ($mat !== '') $count++;
}
echo "Master SKU items: $count\n";

// MASTER DATA
$sheet2 = $spreadsheet->getSheetByName('MASTER DATA');
$count2 = 0;
for ($r = 2; $r <= $sheet2->getHighestRow(); $r++) {
    $mat = trim((string)($sheet2->getCell('A' . $r)->getValue() ?? ''));
    if ($mat !== '') $count2++;
}
echo "MASTER DATA items: $count2\n";

// WMS unique items
$sheet3 = $spreadsheet->getSheetByName('WMS');
$items = [];
for ($r = 5; $r <= $sheet3->getHighestRow(); $r++) {
    $mat = trim((string)($sheet3->getCell('L' . $r)->getValue() ?? ''));
    if ($mat !== '') $items[$mat] = true;
}
echo "WMS unique items: " . count($items) . "\n";
