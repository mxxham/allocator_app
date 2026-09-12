<?php
/**
 * Inbound Merger
 *
 * Merges inbound receipt stock into the updated WMS workbook.
 * Produces the next day's ground-truth WMS snapshot.
 *
 * Daily rollover cycle:
 *   Day N:   WMS_day_N.xlsx  → Allocator → picks/bin-to-bin → updated_wms.xlsx
 *   Day N+1: updated_wms.xlsx + inbound_day_N.xlsx → InboundMerger → WMS_day_{N+1}.xlsx
 *            Then run Allocator again against Day N+1's outbound orders.
 */

class InboundMerger
{
    private int $headerRow;
    private string $sheetName;

    public function __construct(int $headerRow = 4, string $sheetName = 'WMS')
    {
        $this->headerRow = $headerRow;
        $this->sheetName = $sheetName;
    }

    /**
     * Merge inbound receipts into the WMS workbook.
     *
     * @param string $wmsFilePath   Path to the updated WMS workbook (from WmsSheetUpdater)
     * @param string $inboundFilePath Path to the inbound receipt workbook
     * @return string Path to the new merged .xlsx file
     */
    public function merge(string $wmsFilePath, string $inboundFilePath): string
    {
        // Load WMS workbook (the updated snapshot from yesterday)
        $wmsReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($wmsFilePath);
        $wmsReader->setReadDataOnly(false);
        $wmsBook = $wmsReader->load($wmsFilePath);

        $wmsSheet = $wmsBook->getSheetByName($this->sheetName);
        if ($wmsSheet === null) {
            throw new \Exception("Sheet '{$this->sheetName}' not found in WMS file.");
        }

        // Resolve WMS column layout
        $lokasiCol = $this->findColumn($wmsSheet, 'Lokasi');
        $descCol   = $this->findColumn($wmsSheet, 'Description');
        $itemCol   = $this->findColumn($wmsSheet, 'item');
        $batchCol  = $this->findColumn($wmsSheet, 'Batch');
        $qtyCol    = $this->findColumn($wmsSheet, 'Qty');
        $uomCol    = $this->findColumn($wmsSheet, 'UoM');

        // Build lokasi → row index from current WMS (first-match-wins)
        $rowIndex = $this->buildRowIndex($wmsSheet, $lokasiCol);

        // Load inbound workbook
        $inboundReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($inboundFilePath);
        $inboundReader->setReadDataOnly(false);
        $inboundBook = $inboundReader->load($inboundFilePath);

        $inboundSheet = $inboundBook->getSheetByName($this->sheetName);
        if ($inboundSheet === null) {
            throw new \Exception("Sheet '{$this->sheetName}' not found in inbound file.");
        }

        // Resolve inbound column layout (must match WMS headers)
        $iLokasiCol = $this->findColumn($inboundSheet, 'Lokasi');
        $iDescCol   = $this->findColumn($inboundSheet, 'Description');
        $iItemCol   = $this->findColumn($inboundSheet, 'item');
        $iBatchCol  = $this->findColumn($inboundSheet, 'Batch');
        $iQtyCol    = $this->findColumn($inboundSheet, 'Qty');
        $iUomCol    = $this->findColumn($inboundSheet, 'UoM');

        // Process inbound rows
        $highestInbound = $inboundSheet->getHighestRow();
        $nextRow = $wmsSheet->getHighestRow() + 1;

        for ($r = $this->headerRow + 1; $r <= $highestInbound; $r++) {
            $lokasi = trim((string)$this->getCellValue($inboundSheet, $iLokasiCol, $r));
            if ($lokasi === '') {
                continue; // skip empty rows
            }

            $desc  = $this->getCellValue($inboundSheet, $iDescCol, $r);
            $item  = trim((string)$this->getCellValue($inboundSheet, $iItemCol, $r));
            $batch = $this->getCellValue($inboundSheet, $iBatchCol, $r);
            $qty   = (float)$this->getCellValue($inboundSheet, $iQtyCol, $r);
            $uom   = $this->getCellValue($inboundSheet, $iUomCol, $r);

            if ($qty <= 0) {
                continue;
            }

            if (isset($rowIndex[$lokasi])) {
                // Location exists — add quantity to existing row
                $existingRow = $rowIndex[$lokasi];
                $currentQty = (float)$this->getCellValue($wmsSheet, $qtyCol, $existingRow);
                $this->setCellValue($wmsSheet, $qtyCol, $existingRow, $currentQty + $qty);
            } else {
                // New location — append row at the bottom
                $this->setCellValue($wmsSheet, $lokasiCol, $nextRow, $lokasi);
                $this->setCellValue($wmsSheet, $descCol,   $nextRow, $desc);
                $this->setCellValue($wmsSheet, $itemCol,   $nextRow, $item);
                $this->setCellValue($wmsSheet, $batchCol,  $nextRow, $batch);
                $this->setCellValue($wmsSheet, $qtyCol,    $nextRow, $qty);
                $this->setCellValue($wmsSheet, $uomCol,    $nextRow, $uom);

                $rowIndex[$lokasi] = $nextRow;
                $nextRow++;
            }
        }

        // Renumber the "No" column
        $this->renumberRows($wmsSheet);

        // Write merged workbook
        $outPath = tempnam(sys_get_temp_dir(), 'wms_merged_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($wmsBook, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->save($outPath);

        return $outPath;
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Build lokasi → row index (first-match-wins).
     */
    private function buildRowIndex($sheet, int $lokasiCol): array
    {
        $index = [];
        $highestRow = $sheet->getHighestRow();
        for ($r = $this->headerRow + 1; $r <= $highestRow; $r++) {
            $lokasi = trim((string)$this->getCellValue($sheet, $lokasiCol, $r));
            if ($lokasi !== '' && !isset($index[$lokasi])) {
                $index[$lokasi] = $r;
            }
        }
        return $index;
    }

    /**
     * Renumber the "No" column (column A) starting from 1.
     */
    private function renumberRows($sheet): void
    {
        $highestRow = $sheet->getHighestRow();
        $noCol = $this->findColumn($sheet, 'No');
        $counter = 1;
        for ($r = $this->headerRow + 1; $r <= $highestRow; $r++) {
            $this->setCellValue($sheet, $noCol, $r, $counter);
            $counter++;
        }
    }

    /**
     * Find a column index by header name (case-insensitive).
     *
     * @throws \Exception if the column is not found
     */
    private function findColumn($sheet, string $name): int
    {
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
            $sheet->getHighestColumn()
        );

        for ($c = 1; $c <= $highestCol; $c++) {
            $val = trim((string)$this->getCellValue($sheet, $c, $this->headerRow));
            if (strcasecmp($val, $name) === 0) {
                return $c;
            }
        }

        throw new \Exception("Column '{$name}' not found in header row {$this->headerRow}.");
    }

    /**
     * Read a cell value by 1-based column index and row number.
     * Compatible with PhpSpreadsheet 5.x+ (which removed getCellByColumnAndRow).
     */
    private function getCellValue($sheet, int $col, int $row)
    {
        $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
        return $sheet->getCell($coord)->getValue();
    }

    /**
     * Set a cell value by 1-based column index and row number.
     * Compatible with PhpSpreadsheet 5.x+ (which removed setCellValueByColumnAndRow).
     */
    private function setCellValue($sheet, int $col, int $row, $value): void
    {
        $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
        $sheet->getCell($coord)->setValue($value);
    }
}
