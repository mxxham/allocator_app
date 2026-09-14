<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$spreadsheet = IOFactory::load('C:/Users/asust/Downloads/Warehouse Management System_10 September 2026.xlsx');
$sheet = $spreadsheet->getSheetByName('WMS');

// Collect WMS data by item
$items = [];
for ($r = 5; $r <= $sheet->getHighestRow(); $r++) {
    $loc = trim((string)($sheet->getCell('H' . $r)->getValue() ?? ''));
    $item = trim((string)($sheet->getCell('L' . $r)->getValue() ?? ''));
    $qty = (int)($sheet->getCell('N' . $r)->getValue() ?? 0);
    $batch = trim((string)($sheet->getCell('I' . $r)->getValue() ?? ''));
    $expiry = trim((string)($sheet->getCell('K' . $r)->getValue() ?? ''));
    
    if ($item === '' || $loc === '') continue;
    if (!preg_match('/^[A-Z]{2}\d+[A-E]\d{2}$/', $loc)) continue;
    
    $level = $loc[4]; // 5th char = A(pickface) or B-E(bulk)
    $isPickface = ($level === 'A');
    
    if (!isset($items[$item])) {
        $items[$item] = ['pickface_bins' => 0, 'pickface_qty' => 0, 'bulk_bins' => 0, 'bulk_qty' => 0, 'locations' => []];
    }
    
    if ($isPickface) {
        $items[$item]['pickface_bins']++;
        $items[$item]['pickface_qty'] += $qty;
    } else {
        $items[$item]['bulk_bins']++;
        $items[$item]['bulk_qty'] += $qty;
    }
    $items[$item]['locations'][] = "$loc (qty=$qty, " . ($isPickface ? 'PF' : 'Bulk') . ")";
}

// Check specific items from the table
$checkItems = ['550024919', '550024921', '550024929', '550027259', '550044709', '550048593', '550062285'];

echo "=== WMS Sheet Verification ===\n\n";
foreach ($checkItems as $item) {
    if (!isset($items[$item])) {
        echo "$item: NOT FOUND IN WMS\n";
        continue;
    }
    $d = $items[$item];
    echo "$item:\n";
    echo "  Pickface: {$d['pickface_bins']} bins, {$d['pickface_qty']} qty\n";
    echo "  Bulk: {$d['bulk_bins']} bins, {$d['bulk_qty']} qty\n";
    echo "  Locations: " . implode(', ', $d['locations']) . "\n\n";
}

echo "=== Total WMS items: " . count($items) . " ===\n";
