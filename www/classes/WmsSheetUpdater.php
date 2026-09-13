<?php
/**
 * WMS Sheet Updater
 * Applies allocation deltas directly onto the original uploaded workbook's
 * WMS sheet, preserving every other sheet, formula, and formatting untouched.
 */

require_once __DIR__ . '/../vendor/autoload.php';

class WmsSheetUpdater
{
    private const HEADER_ROW = 4;
    private const SHEET_NAME = 'WMS';

    // Column letters in the WMS sheet
    private const COL_LOKASI = 'H';
    private const COL_ITEM   = 'L';
    private const COL_BATCH  = 'I';
    private const COL_QTY    = 'N';

    /**
     * Apply stock deltas from an allocation run directly onto the original
     * uploaded workbook's WMS sheet.
     *
     * @param string $originalFilePath  Path to the original uploaded .xlsx
     * @param array  $allocationResult  Full result from Allocator::allocate()
     * @return string Path to the updated temp file
     */
    public function apply(string $originalFilePath, array $allocationResult): string
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($originalFilePath);
        $reader->setReadDataOnly(true); // read formula cells as their last calculated
                                        // value, not live formulas — eliminates the
                                        // risk of PhpSpreadsheet stripping/failing to
                                        // preserve cached results on untouched sheets
        $spreadsheet = $reader->load($originalFilePath);

        $wmsSheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if ($wmsSheet === null) {
            throw new \RuntimeException('Sheet "' . self::SHEET_NAME . '" not found in uploaded file');
        }

        // Build Lokasi -> row-number index once, so each delta is an O(1) lookup
        $rowIndex = $this->buildRowIndex($wmsSheet);

        // Collect every stock-changing event from this allocation run
        $deltas = $this->buildDeltas($allocationResult);

        // Apply deltas to the sheet
        foreach ($deltas as $lokasi => $change) {
            $row = $rowIndex[$lokasi] ?? null;
            if ($row === null) {
                // Bin not found in the sheet — skip silently (shouldn't happen normally)
                continue;
            }

            $currentQty = (float)$wmsSheet->getCell(self::COL_QTY . $row)->getValue();
            $newQty = $currentQty + $change['qty_delta'];

            // Update Qty
            $wmsSheet->getCell(self::COL_QTY . $row)->setValue($newQty);

            if ($newQty > 0) {
                // Bin now holds stock — set/confirm the item and batch
                $wmsSheet->getCell(self::COL_ITEM . $row)->setValue($change['item'] ?? '');
                if (isset($change['batch'])) {
                    $wmsSheet->getCell(self::COL_BATCH . $row)->setValue($change['batch']);
                }
            } else {
                // Bin emptied out — clear item/batch (matching sheet's empty-bin convention)
                $wmsSheet->getCell(self::COL_ITEM . $row)->setValue(null);
                $wmsSheet->getCell(self::COL_BATCH . $row)->setValue(null);
            }
        }

        $outPath = tempnam(sys_get_temp_dir(), 'wms_updated_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->save($outPath);

        return $outPath;
    }

    /**
     * Build a Lokasi -> row-number index from the WMS sheet.
     * Scans from header+1 to the last row with data.
     */
    private function buildRowIndex($wmsSheet): array
    {
        $rowIndex = [];
        $highestRow = $wmsSheet->getHighestRow();

        for ($r = self::HEADER_ROW + 1; $r <= $highestRow; $r++) {
            $lokasi = trim((string)$wmsSheet->getCell(self::COL_LOKASI . $r)->getValue());
            if ($lokasi !== '') {
                $rowIndex[$lokasi] = $r;
            }
        }

        return $rowIndex;
    }

    /**
     * Translate the allocation result into per-bin quantity changes.
     *
     * Picks decrement their source bin.
     * Replenishments decrement the source AND increment the destination.
     *
     * @return array [Lokasi => ['item'=>.., 'batch'=>.., 'qty_delta'=>+/-N]]
     */
    private function buildDeltas(array $allocationResult): array
    {
        $deltas = [];

        // Picks: each pick decrements the source bin
        foreach ($allocationResult['picks'] ?? [] as $pick) {
            $loc = $pick['location'];
            if (!isset($deltas[$loc])) {
                $deltas[$loc] = ['item' => $pick['item_code'], 'batch' => $pick['batch_number'] ?? null, 'qty_delta' => 0];
            }
            $deltas[$loc]['qty_delta'] -= $pick['quantity'];
            $deltas[$loc]['item'] = $pick['item_code'];
            if (isset($pick['batch_number'])) {
                $deltas[$loc]['batch'] = $pick['batch_number'];
            }
        }

        // Replenishments: decrement source, increment destination
        foreach ($allocationResult['replenishments'] ?? [] as $rep) {
            $from = $rep['from_location'];
            if (!isset($deltas[$from])) {
                $deltas[$from] = ['item' => $rep['item_code'], 'batch' => null, 'qty_delta' => 0];
            }
            $deltas[$from]['qty_delta'] -= $rep['quantity'];
            $deltas[$from]['item'] = $rep['item_code'];

            $to = $rep['to_location'];
            if (!isset($deltas[$to])) {
                $deltas[$to] = ['item' => $rep['item_code'], 'batch' => null, 'qty_delta' => 0];
            }
            $deltas[$to]['qty_delta'] += $rep['quantity'];
            $deltas[$to]['item'] = $rep['item_code'];
        }

        return $deltas;
    }
}
