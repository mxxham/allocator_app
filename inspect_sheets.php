<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$files = glob('C:/Users/asust/Downloads/*Warehouse*Management*System*10*');
echo "Found: " . ($files[0] ?? 'NOTHING') . "\n";
$spreadsheet = IOFactory::load($files[0]);

foreach ($spreadsheet->getSheetNames() as $name) {
    echo "\n=== Sheet: [$name] ===\n";
    $sheet = $spreadsheet->getSheetByName($name);
    $highestRow = min($sheet->getHighestRow(), 8);
    $highestCol = min($sheet->getHighestColumn(), 40);
    for ($r = 1; $r <= $highestRow; $r++) {
        $row = [];
        for ($c = 1; $c <= $highestCol; $c++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $val = $sheet->getCell($colLetter . $r)->getValue();
            $row[] = $val !== null ? trim((string)$val) : '';
        }
        echo "  Row $r: " . implode(" | ", $row) . "\n";
    }
}
