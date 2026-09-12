<?php
/**
 * WMS Sheet Updater
 * Updates the original WMS Excel sheet with allocation deltas,
 * preserving formulas on untouched sheets.
 */

class WmsSheetUpdater
{
    /**
     * Apply allocation deltas to the WMS sheet of an existing workbook.
     * Returns the path to the updated temp file.
     *
     * @param string $originalFilePath Path to the uploaded Excel file
     * @param array  $allocationResult Full allocation result (picks + replenishments)
     * @return string Path to the updated .xlsx file
     */
    public function apply(string $originalFilePath, array $allocationResult): string
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($originalFilePath);
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($originalFilePath);

        $wmsSheet = $spreadsheet->getSheetByName('WMS');
        if ($wmsSheet === null) {
            throw new \Exception("Sheet 'WMS' not found in the uploaded file.");
        }

        $headerRow = 4;
        $lokasiCol = $this->findColumn($wmsSheet, $headerRow, 'Lokasi');
        $itemCol   = $this->findColumn($wmsSheet, $headerRow, 'item');
        $batchCol  = $this->findColumn($wmsSheet, $headerRow, 'Batch');
        $qtyCol    = $this->findColumn($wmsSheet, $headerRow, 'Qty');

        // Build row index: lokasi → row number (first-match-wins)
        $rowIndex = [];
        $highestRow = $wmsSheet->getHighestRow();
        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $lokasi = trim((string)$this->getCellValue($wmsSheet, $lokasiCol, $r));
            if ($lokasi !== '' && !isset($rowIndex[$lokasi])) {
                $rowIndex[$lokasi] = $r;
            }
            // NOTE: first-match-wins intentionally skips "Quarantine"-style
            // shared locations beyond their first row — those aren't 1:1
            // physical bins and are out of scope for this update.
        }

        $deltas = $this->buildDeltas($allocationResult);

        foreach ($deltas as $lokasi => $change) {
            $row = $rowIndex[$lokasi] ?? null;
            if ($row === null) {
                continue;
            }

            $currentQty = (float)$this->getCellValue($wmsSheet, $qtyCol, $row);
            $newQty = $currentQty + $change['qty_delta'];

            $this->setCellValue($wmsSheet, $qtyCol, $row, $newQty);
            if ($newQty > 0) {
                $this->setCellValue($wmsSheet, $itemCol, $row, $change['item']);
                $this->setCellValue($wmsSheet, $batchCol, $row, $change['batch']);
            } else {
                $this->setCellValue($wmsSheet, $itemCol, $row, null);
                $this->setCellValue($wmsSheet, $batchCol, $row, null);
            }
        }

        $outPath = tempnam(sys_get_temp_dir(), 'wms_updated_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        // CRITICAL — prevents PhpSpreadsheet from recalculating (and
        // corrupting/blanking) formulas on every OTHER untouched sheet
        $writer->setPreCalculateFormulas(false);
        $writer->save($outPath);
        return $outPath;
    }

    /**
     * Build per-location quantity deltas from picks and replenishments.
     *
     * Picks subtract from the bin; replenishments subtract from source
     * and add to destination.
     */
    private function buildDeltas(array $allocationResult): array
    {
        $deltas = [];

        foreach ($allocationResult['picks'] as $pick) {
            $loc = $pick['location'];
            $deltas[$loc]['qty_delta'] = ($deltas[$loc]['qty_delta'] ?? 0) - $pick['quantity'];
            $deltas[$loc]['item'] = $pick['item_code'];
            $deltas[$loc]['batch'] = $pick['batch_number'];
        }

        foreach ($allocationResult['replenishments'] as $rep) {
            $from = $rep['from_location'];
            $to   = $rep['to_location'];

            $deltas[$from]['qty_delta'] = ($deltas[$from]['qty_delta'] ?? 0) - $rep['quantity'];
            $deltas[$from]['item'] = $rep['item_code'];

            $deltas[$to]['qty_delta'] = ($deltas[$to]['qty_delta'] ?? 0) + $rep['quantity'];
            $deltas[$to]['item'] = $rep['item_code'];
        }

        return $deltas;
    }

    /**
     * Find a column index by header name (case-insensitive, trimmed).
     *
     * @throws \Exception if the column is not found
     */
    private function findColumn($sheet, int $headerRow, string $name): int
    {
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
            $sheet->getHighestColumn()
        );

        for ($c = 1; $c <= $highestCol; $c++) {
            $val = trim((string)$this->getCellValue($sheet, $c, $headerRow));
            if (strcasecmp($val, $name) === 0) {
                return $c;
            }
        }

        throw new \Exception("Column '{$name}' not found in header row {$headerRow}.");
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
