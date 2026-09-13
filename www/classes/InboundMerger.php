<?php
/**
 * Inbound Merger
 * Merges inbound receipts into a WMS snapshot.
 * Purely additive — adds quantities to existing bins, reports conflicts.
 */

require_once __DIR__ . '/../vendor/autoload.php';

class InboundMerger
{
    private const HEADER_ROW = 4;
    private const SHEET_NAME = 'WMS';

    // Column letters in the WMS sheet — must match WmsSheetUpdater exactly
    private const COL_LOKASI  = 'H';
    private const COL_ITEM    = 'L';
    private const COL_BATCH   = 'I';
    private const COL_EXPIRY  = 'K';
    private const COL_QTY     = 'N';

    /**
     * Merge inbound receipts into a WMS workbook.
     *
     * @param string $wmsFilePath      Path to the existing WMS .xlsx
     * @param string $inboundFilePath  Path to the inbound receipt .xlsx
     * @return string JSON { "file": "<path>", "unmatched": [...] }
     */
    public function apply(string $wmsFilePath, string $inboundFilePath): string
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($wmsFilePath);
        $reader->setReadDataOnly(true); // read formula cells as their last calculated
                                        // value, not live formulas — eliminates the
                                        // risk of PhpSpreadsheet stripping/failing to
                                        // preserve cached results on untouched sheets
        $spreadsheet = $reader->load($wmsFilePath);

        $wmsSheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if ($wmsSheet === null) {
            throw new \RuntimeException('Sheet "' . self::SHEET_NAME . '" not found in WMS file');
        }

        // Build Lokasi -> row-number index once
        $rowIndex = $this->buildRowIndex($wmsSheet);

        // Parse inbound file
        $inboundRows = $this->parseInbound($inboundFilePath);

        $unmatched = [];

        foreach ($inboundRows as $inbound) {
            $location   = $inbound['location'];
            $itemCode   = $inbound['item_code'];
            $qty        = $inbound['qty'];
            $batch      = $inbound['batch'];
            $expiry     = $inbound['expiry'];

            $row = $rowIndex[$location] ?? null;

            if ($row === null) {
                // Bin not found in the sheet
                $unmatched[] = [
                    'location' => $location,
                    'item'     => $itemCode,
                    'qty'      => $qty,
                    'reason'   => 'Bin not found in WMS sheet',
                ];
                continue;
            }

            // Read current bin state
            $currentItem = trim((string)$wmsSheet->getCell(self::COL_ITEM . $row)->getValue());
            $currentQty  = (float)$wmsSheet->getCell(self::COL_QTY . $row)->getValue();

            // Conflict: bin holds a different item
            if ($currentItem !== '' && $currentItem !== $itemCode) {
                $unmatched[] = [
                    'location'      => $location,
                    'item'          => $itemCode,
                    'qty'           => $qty,
                    'reason'        => 'Conflict — bin contains different item "' . $currentItem . '"',
                    'existing_item' => $currentItem,
                ];
                continue;
            }

            // Safe to merge: empty bin or same item — add qty
            $newQty = $currentQty + $qty;
            $wmsSheet->getCell(self::COL_QTY . $row)->setValue($newQty);
            $wmsSheet->getCell(self::COL_ITEM . $row)->setValue($itemCode);

            if ($batch !== '') {
                $wmsSheet->getCell(self::COL_BATCH . $row)->setValue($batch);
            }
            if ($expiry !== '') {
                $wmsSheet->getCell(self::COL_EXPIRY . $row)->setValue($expiry);
            }
        }

        $outPath = tempnam(sys_get_temp_dir(), 'wms_inbound_') . '.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->save($outPath);

        return json_encode([
            'file'     => $outPath,
            'unmatched' => $unmatched,
        ], JSON_THROW_ON_ERROR);
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
     * Parse an inbound receipt Excel file.
     * Expects header at row 1: Location | Item Code | Qty | Batch Number | Expiry Date
     *
     * @return array<int, array{location: string, item_code: string, qty: float, batch: string, expiry: string}>
     */
    private function parseInbound(string $filePath): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Discover column positions from header row
        $colLocation = $this->findColumn($sheet, 1, 'Location');
        $colItem     = $this->findColumn($sheet, 1, 'Item Code');
        $colQty      = $this->findColumn($sheet, 1, 'Qty');
        $colBatch    = $this->findColumn($sheet, 1, 'Batch Number');
        $colExpiry   = $this->findColumn($sheet, 1, 'Expiry Date');

        $rows = [];
        $highestRow = $sheet->getHighestRow();

        for ($r = 2; $r <= $highestRow; $r++) {
            $location = trim((string)$sheet->getCell($colLocation . $r)->getValue());
            if ($location === '') {
                continue; // skip empty rows
            }

            $itemCode = trim((string)$sheet->getCell($colItem . $r)->getValue());
            $qty      = (float)$sheet->getCell($colQty . $r)->getValue();
            $batch    = $colBatch ? trim((string)$sheet->getCell($colBatch . $r)->getValue()) : '';
            $expiry   = $colExpiry ? trim((string)$sheet->getCell($colExpiry . $r)->getValue()) : '';

            $rows[] = [
                'location'  => $location,
                'item_code' => $itemCode,
                'qty'       => $qty,
                'batch'     => $batch,
                'expiry'    => $expiry,
            ];
        }

        $spreadsheet->disconnectWorksheets();
        return $rows;
    }

    /**
     * Find the column letter that contains a given header name in a specific row.
     * Returns the column letter (e.g. 'A', 'B') or empty string if not found.
     */
    private function findColumn($sheet, int $row, string $name): string
    {
        $highestCol = $sheet->getHighestColumn();

        for ($c = 'A'; $c !== chr(ord($highestCol) + 1); $c++) {
            $value = trim((string)$sheet->getCell($c . $row)->getValue());
            if ($value === $name) {
                return $c;
            }
        }

        return '';
    }
}
