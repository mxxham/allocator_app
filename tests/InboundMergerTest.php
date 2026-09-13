<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class InboundMergerTest extends TestCase
{
    /**
     * Create a workbook with a WMS sheet in-memory (no temp file needed for assertions).
     */
    private function makeWorkbook(array $wmsRows): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $wb->getSheet(0);
        $sheet->setTitle('WMS');

        $headers = ['No', 'Lokasi', 'Description', 'item', 'Batch', 'Qty', 'UoM'];
        foreach ($headers as $i => $h) {
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '4';
            $sheet->getCell($coord)->setValue($h);
        }

        foreach ($wmsRows as $idx => $row) {
            $r = 5 + $idx;
            $sheet->getCell('A' . $r)->setValue($idx + 1);
            $sheet->getCell('B' . $r)->setValue($row['lokasi']);
            $sheet->getCell('C' . $r)->setValue($row['desc'] ?? '');
            $sheet->getCell('D' . $r)->setValue($row['item'] ?? '');
            $sheet->getCell('E' . $r)->setValue($row['batch'] ?? '');
            $sheet->getCell('F' . $r)->setValue($row['qty']);
            $sheet->getCell('G' . $r)->setValue($row['uom'] ?? '');
        }

        return $wb;
    }

    /**
     * Save a workbook to a temp file and return its path.
     */
    private function saveTemp(\PhpOffice\PhpSpreadsheet\Spreadsheet $wb): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inbound_test_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($wb, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        return $path;
    }

    /**
     * Read a cell from a loaded workbook by sheet name and coordinate.
     */
    private function readCell(string $filePath, string $sheetName, string $coord)
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(false);
        $book = $reader->load($filePath);
        $sheet = $book->getSheetByName($sheetName);
        return $sheet->getCell($coord)->getValue();
    }

    // ── tests ────────────────────────────────────────────────────────

    public function testExistingLocationGetsQuantityAdded(): void
    {
        // WMS has A01-B01-01 with qty 10
        $wms = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 10, 'uom' => 'Drum'],
        ]);

        // Inbound brings 5 more to the same location
        $inbound = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 5, 'uom' => 'Drum'],
        ]);

        $wmsPath     = $this->saveTemp($wms);
        $inboundPath = $this->saveTemp($inbound);

        $merger = new InboundMerger();
        $result = $merger->merge($wmsPath, $inboundPath);

        $this->assertEquals(15.0, $this->readCell($result, 'WMS', 'F5'));
        unlink($wmsPath);
        unlink($inboundPath);
        unlink($result);
    }

    public function testNewLocationAppendedAsNewRow(): void
    {
        // WMS has one location
        $wms = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 10, 'uom' => 'Drum'],
        ]);

        // Inbound brings a completely new location
        $inbound = $this->makeWorkbook([
            ['lokasi' => 'A02-C03-02', 'desc' => 'New Bin', 'item' => 'MAT-002', 'batch' => 'B002', 'qty' => 8, 'uom' => 'Carton'],
        ]);

        $wmsPath     = $this->saveTemp($wms);
        $inboundPath = $this->saveTemp($inbound);

        $merger = new InboundMerger();
        $result = $merger->merge($wmsPath, $inboundPath);

        // Original row unchanged
        $this->assertEquals('A01-B01-01', $this->readCell($result, 'WMS', 'B5'));
        $this->assertEquals(10.0, $this->readCell($result, 'WMS', 'F5'));

        // New row appended at row 6
        $this->assertEquals('A02-C03-02', $this->readCell($result, 'WMS', 'B6'));
        $this->assertEquals(8.0, $this->readCell($result, 'WMS', 'F6'));
        $this->assertEquals('MAT-002', $this->readCell($result, 'WMS', 'D6'));

        unlink($wmsPath);
        unlink($inboundPath);
        unlink($result);
    }

    public function testMultipleInboundRowsMergedCorrectly(): void
    {
        // WMS has two locations
        $wms = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 10, 'uom' => 'Drum'],
            ['lokasi' => 'A01-B02-01', 'item' => 'MAT-002', 'batch' => 'B002', 'qty' => 5, 'uom' => 'Carton'],
        ]);

        // Inbound: add to existing + new location
        $inbound = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 3, 'uom' => 'Drum'],
            ['lokasi' => 'A03-D01-01', 'item' => 'MAT-003', 'batch' => 'B003', 'qty' => 20, 'uom' => 'Drum'],
        ]);

        $wmsPath     = $this->saveTemp($wms);
        $inboundPath = $this->saveTemp($inbound);

        $merger = new InboundMerger();
        $result = $merger->merge($wmsPath, $inboundPath);

        // Row 5: existing, qty should be 10 + 3 = 13
        $this->assertEquals(13.0, $this->readCell($result, 'WMS', 'F5'));
        // Row 6: existing, unchanged at 5
        $this->assertEquals(5.0, $this->readCell($result, 'WMS', 'F6'));
        // Row 7: new location
        $this->assertEquals('A03-D01-01', $this->readCell($result, 'WMS', 'B7'));
        $this->assertEquals(20.0, $this->readCell($result, 'WMS', 'F7'));

        unlink($wmsPath);
        unlink($inboundPath);
        unlink($result);
    }

    public function testEmptyInboundRowsSkipped(): void
    {
        $wms = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 10, 'uom' => 'Drum'],
        ]);

        // Inbound has one real row and one empty row
        $inbound = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 5, 'uom' => 'Drum'],
            ['lokasi' => '', 'qty' => 0], // empty — should be skipped
        ]);

        $wmsPath     = $this->saveTemp($wms);
        $inboundPath = $this->saveTemp($inbound);

        $merger = new InboundMerger();
        $result = $merger->merge($wmsPath, $inboundPath);

        // Only one data row (row 5)
        $this->assertEquals(15.0, $this->readCell($result, 'WMS', 'F5'));
        // Row 6 should be empty (no extra row from the blank inbound line)
        $this->assertNull($this->readCell($result, 'WMS', 'B6'));

        unlink($wmsPath);
        unlink($inboundPath);
        unlink($result);
    }

    public function testRenumberAfterAppend(): void
    {
        $wms = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 10, 'uom' => 'Drum'],
        ]);

        $inbound = $this->makeWorkbook([
            ['lokasi' => 'A02-C01-01', 'item' => 'MAT-002', 'batch' => 'B002', 'qty' => 5, 'uom' => 'Carton'],
        ]);

        $wmsPath     = $this->saveTemp($wms);
        $inboundPath = $this->saveTemp($inbound);

        $merger = new InboundMerger();
        $result = $merger->merge($wmsPath, $inboundPath);

        // No column: row 5 = 1, row 6 = 2
        $this->assertEquals(1, $this->readCell($result, 'WMS', 'A5'));
        $this->assertEquals(2, $this->readCell($result, 'WMS', 'A6'));

        unlink($wmsPath);
        unlink($inboundPath);
        unlink($result);
    }

    public function testMissingWmsSheetThrows(): void
    {
        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $wb->getSheet(0)->setTitle('SomethingElse');
        $path = $this->saveTemp($wb);

        $inbound = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 5, 'uom' => 'Drum'],
        ]);
        $inboundPath = $this->saveTemp($inbound);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Sheet 'WMS' not found in WMS file.");

        $merger = new InboundMerger();
        $merger->merge($path, $inboundPath);

        unlink($path);
        unlink($inboundPath);
    }

    public function testMissingInboundSheetThrows(): void
    {
        $wms = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 10, 'uom' => 'Drum'],
        ]);
        $wmsPath = $this->saveTemp($wms);

        $badInbound = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $badInbound->getSheet(0)->setTitle('Inbound');
        $inboundPath = $this->saveTemp($badInbound);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Sheet 'WMS' not found in inbound file.");

        $merger = new InboundMerger();
        $merger->merge($wmsPath, $inboundPath);

        unlink($wmsPath);
        unlink($inboundPath);
    }

    public function testZeroQtyInboundSkipped(): void
    {
        $wms = $this->makeWorkbook([
            ['lokasi' => 'A01-B01-01', 'item' => 'MAT-001', 'batch' => 'B001', 'qty' => 10, 'uom' => 'Drum'],
        ]);

        // Inbound with zero qty should not add a row
        $inbound = $this->makeWorkbook([
            ['lokasi' => 'A02-ZZZ-00', 'item' => 'MAT-999', 'batch' => 'B999', 'qty' => 0, 'uom' => 'Drum'],
        ]);

        $wmsPath     = $this->saveTemp($wms);
        $inboundPath = $this->saveTemp($inbound);

        $merger = new InboundMerger();
        $result = $merger->merge($wmsPath, $inboundPath);

        // Only original row should exist
        $this->assertEquals('A01-B01-01', $this->readCell($result, 'WMS', 'B5'));
        $this->assertNull($this->readCell($result, 'WMS', 'B6'));

        unlink($wmsPath);
        unlink($inboundPath);
        unlink($result);
    }
}
