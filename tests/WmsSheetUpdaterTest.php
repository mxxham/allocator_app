<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class WmsSheetUpdaterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/wms_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    /**
     * Create a minimal .xlsx workbook with a WMS sheet matching the
     * expected layout (header row 4, columns: Lokasi, item, Batch, Qty).
     * Also adds a "Master SKU" sheet with formulas to verify they survive.
     */
    private function createTestWorkbook(array $wmsRows): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // --- Master SKU sheet (formula preservation test) ---
        $master = $spreadsheet->getActiveSheet();
        $master->setTitle('Master SKU');
        $master->getCell('A1')->setValue('Material');
        $master->getCell('B1')->setValue('Description');
        $master->getCell('C1')->setValue('Ratio');
        $master->getCell('A2')->setValue('MAT-001');
        $master->getCell('B2')->setValue('Widget');
        $master->getCell('C2')->setValue('=B2&" test"');
        $master->getCell('A3')->setValue('MAT-002');
        $master->getCell('B3')->setValue('Gadget');
        $master->getCell('C3')->setValue('=B3&" ratio"');

        // --- WMS sheet ---
        $wms = $spreadsheet->createSheet();
        $wms->setTitle('WMS');

        // Header row 4
        $headers = ['No', 'Lokasi', 'Description', 'item', 'Batch', 'Qty', 'UoM'];
        foreach ($headers as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $wms->getCell($col . '4')->setValue($h);
        }

        // Data rows starting at row 5
        foreach ($wmsRows as $idx => $row) {
            $r = 5 + $idx;
            $wms->getCell('A' . $r)->setValue($idx + 1);           // No
            $wms->getCell('B' . $r)->setValue($row['lokasi']);      // Lokasi
            $wms->getCell('C' . $r)->setValue($row['desc'] ?? '');  // Description
            $wms->getCell('D' . $r)->setValue($row['item'] ?? '');  // item
            $wms->getCell('E' . $r)->setValue($row['batch'] ?? ''); // Batch
            $wms->getCell('F' . $r)->setValue($row['qty'] ?? 0);    // Qty
            $wms->getCell('G' . $r)->setValue($row['uom'] ?? '');   // UoM
        }

        $path = $this->tempDir . '/test_workbook.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $path;
    }

    /**
     * Core test: picks subtract from the correct bin, replenishments move
     * stock between locations, and untouched sheets are not corrupted.
     */
    public function testApplyUpdatesWmsSheetAndPreservesFormulas(): void
    {
        $wmsRows = [
            ['lokasi' => 'A01-A01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 50, 'uom' => 'Drum'],
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 100, 'uom' => 'Drum'],
            ['lokasi' => 'A01-B02-01', 'item' => 'MAT-002', 'batch' => 'B002', 'qty' => 80, 'uom' => 'Carton'],
        ];

        $original = $this->createTestWorkbook($wmsRows);

        $allocationResult = [
            'picks' => [
                [
                    'order_no' => 'ORD-001',
                    'item_code' => 'MAT-001',
                    'location' => 'A01-B01-01',
                    'quantity' => 4,
                    'type' => 'full_pallet',
                    'batch_number' => 'B001',
                    'expiry_date' => '2027-01-01',
                ],
                [
                    'order_no' => 'ORD-001',
                    'item_code' => 'MAT-001',
                    'location' => 'A01-A01-01',
                    'quantity' => 10,
                    'type' => 'pickface',
                    'batch_number' => 'B001',
                    'expiry_date' => '2027-01-01',
                ],
            ],
            'replenishments' => [
                [
                    'item_code' => 'MAT-001',
                    'from_location' => 'A01-B01-01',
                    'to_location' => 'A01-A01-01',
                    'quantity' => 4,
                ],
            ],
        ];

        $updater = new WmsSheetUpdater();
        $outPath = $updater->apply($original, $allocationResult);

        $this->assertFileExists($outPath);
        $this->assertStringEndsWith('.xlsx', $outPath);

        // Reload and verify WMS sheet deltas
        $spreadsheet = IOFactory::load($outPath);
        $wms = $spreadsheet->getSheetByName('WMS');
        $this->assertNotNull($wms, 'WMS sheet must exist');

        // Build lokasi -> row index using B column (Lokasi)
        $rowMap = [];
        $highestRow = $wms->getHighestRow();
        for ($r = 5; $r <= $highestRow; $r++) {
            $loc = trim((string)$wms->getCell('B' . $r)->getValue());
            if ($loc !== '') {
                $rowMap[$loc] = $r;
            }
        }

        // A01-B01-01: picked 4, replenished out 4 -> 100 - 4 - 4 = 92
        $rowB01 = $rowMap['A01-B01-01'] ?? null;
        $this->assertNotNull($rowB01, 'Row for A01-B01-01 must exist');
        $this->assertEqualsWithDelta(92.0, (float)$wms->getCell('F' . $rowB01)->getValue(), 0.01);

        // A01-A01-01: picked 10, replenished in 4 -> 50 - 10 + 4 = 44
        $rowA01 = $rowMap['A01-A01-01'] ?? null;
        $this->assertNotNull($rowA01, 'Row for A01-A01-01 must exist');
        $this->assertEqualsWithDelta(44.0, (float)$wms->getCell('F' . $rowA01)->getValue(), 0.01);

        // A01-B02-01: untouched -> still 80
        $rowB02 = $rowMap['A01-B02-01'] ?? null;
        $this->assertNotNull($rowB02);
        $this->assertEqualsWithDelta(80.0, (float)$wms->getCell('F' . $rowB02)->getValue(), 0.01);

        // Verify Master SKU formulas preserved (not blanked by preCalculateFormulas)
        $master = $spreadsheet->getSheetByName('Master SKU');
        $this->assertNotNull($master, 'Master SKU sheet must exist');

        $c2Value = $master->getCell('C2')->getValue();
        $this->assertNotEmpty($c2Value, 'Formula in Master SKU C2 must not be blank');
        $this->assertStringContainsString('=B2', $c2Value, 'C2 must still contain the formula');

        $c3Value = $master->getCell('C3')->getValue();
        $this->assertNotEmpty($c3Value, 'Formula in Master SKU C3 must not be blank');
        $this->assertStringContainsString('=B3', $c3Value, 'C3 must still contain the formula');

        $spreadsheet->disconnectWorksheets();
        @unlink($outPath);
    }

    /**
     * When a bin is picked down to zero, item and batch should be cleared.
     */
    public function testZeroQtyClearsItemAndBatch(): void
    {
        $wmsRows = [
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 4],
        ];

        $original = $this->createTestWorkbook($wmsRows);

        $allocationResult = [
            'picks' => [
                [
                    'order_no' => 'ORD-002',
                    'item_code' => 'MAT-001',
                    'location' => 'A01-B01-01',
                    'quantity' => 4,
                    'type' => 'full_pallet',
                    'batch_number' => 'B001',
                    'expiry_date' => null,
                ],
            ],
            'replenishments' => [],
        ];

        $updater = new WmsSheetUpdater();
        $outPath = $updater->apply($original, $allocationResult);

        $spreadsheet = IOFactory::load($outPath);
        $wms = $spreadsheet->getSheetByName('WMS');

        // Row 5 = first data row. Columns: B=Lokasi, D=item, E=Batch, F=Qty
        $this->assertEqualsWithDelta(0.0, (float)$wms->getCell('F5')->getValue(), 0.01);
        $this->assertEmpty((string)$wms->getCell('D5')->getValue(), 'Item should be cleared at zero qty');
        $this->assertEmpty((string)$wms->getCell('E5')->getValue(), 'Batch should be cleared at zero qty');

        $spreadsheet->disconnectWorksheets();
        @unlink($outPath);
    }

    /**
     * Location not found in the WMS sheet should be silently skipped.
     */
    public function testUnknownLocationSkipped(): void
    {
        $wmsRows = [
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 10],
        ];

        $original = $this->createTestWorkbook($wmsRows);

        $allocationResult = [
            'picks' => [
                [
                    'order_no' => 'ORD-003',
                    'item_code' => 'MAT-001',
                    'location' => 'NONEXISTENT-LOC',
                    'quantity' => 4,
                    'type' => 'full_pallet',
                    'batch_number' => 'B001',
                    'expiry_date' => null,
                ],
            ],
            'replenishments' => [],
        ];

        $updater = new WmsSheetUpdater();
        $outPath = $updater->apply($original, $allocationResult);
        $this->assertFileExists($outPath);

        // Original row should be untouched
        $spreadsheet = IOFactory::load($outPath);
        $wms = $spreadsheet->getSheetByName('WMS');
        $this->assertEqualsWithDelta(10.0, (float)$wms->getCell('F5')->getValue(), 0.01);

        $spreadsheet->disconnectWorksheets();
        @unlink($outPath);
    }

    /**
     * Missing WMS sheet throws an exception.
     */
    public function testMissingWmsSheetThrows(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('SomethingElse');
        $sheet->getCell('A1')->setValue('test');

        $path = $this->tempDir . '/no_wms.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        $updater = new WmsSheetUpdater();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Sheet 'WMS' not found");

        $updater->apply($path, ['picks' => [], 'replenishments' => []]);
    }

    /**
     * Missing column header throws an exception.
     */
    public function testMissingColumnHeaderThrows(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $wms = $spreadsheet->createSheet();
        $wms->setTitle('WMS');
        // Only "Lokasi" and "Qty" -- missing "item" and "Batch"
        $wms->getCell('A4')->setValue('Lokasi');
        $wms->getCell('B4')->setValue('Qty');
        $wms->getCell('A5')->setValue('A01-B01-01');
        $wms->getCell('B5')->setValue(10);

        $path = $this->tempDir . '/missing_cols.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        $updater = new WmsSheetUpdater();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Column 'item' not found");

        $updater->apply($path, ['picks' => [], 'replenishments' => []]);
    }
}
