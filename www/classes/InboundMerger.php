<?php
/**
 * Inbound Merger — ZIP-level surgical editor.
 *
 * Merges inbound receipts into a WMS snapshot. Accepts either:
 *   - A standalone single-sheet file with putaway-format columns
 *   - A full multi-sheet workbook with a sheet containing "putaway" in the name
 *
 * Uses ExcelParser's column-matching logic (BIN Location / Item Code /
 * ACTUAL QTY / Batch No / Expired Date) without rewriting it.
 *
 * Merge is purely additive: adds quantities to existing bins, flags conflicts
 * (different item in same bin) rather than silently overwriting.
 */
require_once __DIR__ . '/SharedSheetEditor.php';
require_once __DIR__ . '/ExcelParser.php';

class InboundMerger extends SharedSheetEditor
{
    // WMS sheet column letters — must match WmsSheetUpdater exactly
    private const WMS_COL_LOKASI = 'H';
    private const WMS_COL_ITEM   = 'L';
    private const WMS_COL_BATCH  = 'I';
    private const WMS_COL_EXPIRY = 'K';
    private const WMS_COL_QTY    = 'N';

    private const WMS_HEADER_ROW = 4;

    /**
     * Merge inbound receipts into a WMS workbook.
     *
     * @param string $wmsFilePath      Path to the existing WMS .xlsx
     * @param string $inboundFilePath  Path to the inbound receipt .xlsx
     * @return string Path to the merged output file
     * @throws \Exception on zip/integrity errors
     */
    public function apply(string $wmsFilePath, string $inboundFilePath): string
    {
        // 1. Parse inbound file (standalone or multi-sheet)
        $receipts = $this->parseInboundReceipts($inboundFilePath);

        // 2. Copy WMS to temp output
        $outPath = tempnam(sys_get_temp_dir(), 'wms_merged_') . '.xlsx';
        copy($wmsFilePath, $outPath);

        // 3. Open output as zip, load shared strings
        $zip = new \ZipArchive();
        if ($zip->open($outPath) !== true) {
            throw new \Exception("Could not open workbook as zip archive.");
        }
        $this->loadSharedStrings($zip);

        // 4. Load WMS sheet XML
        $sheetPath = $this->resolveSheetXmlPath($zip, 'WMS');
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) throw new \Exception("Could not read {$sheetPath}");

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        // 5. Build WMS row index and merge receipts
        $unmatched = $this->mergeReceipts($dom, $receipts);

        // 6. Write modified sheet back to zip
        $zip->deleteName($sheetPath);
        $zip->addFromString($sheetPath, $dom->saveXML());
        $zip->close();

        // 7. Verify byte-level integrity (only WMS sheet changed)
        $this->verifyIntegrity($wmsFilePath, $outPath, $sheetPath);

        // 8. Surface conflicts/missing bins to the caller
        if (!empty($unmatched)) {
            file_put_contents($outPath . '.unmatched.json', json_encode($unmatched, JSON_PRETTY_PRINT));
        }

        return $outPath;
    }

    /**
     * Parse inbound file. Supports two modes:
     *   1. Single-sheet file — uses the active sheet directly
     *   2. Multi-sheet workbook — finds sheet with "putaway" in the name (case-insensitive)
     *
     * Reuses ExcelParser::parsePutaway()'s column-detection logic:
     *   Scans header row for keywords, then reads columns by position.
     *
     * @return array<int, array{location: string, item_code: string, qty: float, batch_number: string, expiry_date: string|null}>
     */
    private function parseInboundReceipts(string $filePath): array
    {
        // Delegate parsing to ExcelParser which handles both single-sheet and
        // multi-sheet detection using the same logic as parsePutaway().
        $parser = new ExcelParser();
        if (!$parser->load($filePath)) {
            throw new \Exception('Gagal membaca file inbound: ' . implode(', ', $parser->getErrors()));
        }

        $putaway = $parser->parsePutaway();
        if (empty($putaway)) {
            throw new \Exception('Tidak ada data ditemukan di file inbound. Pastikan file memiliki kolom: BIN Location, Item Code, ACTUAL QTY, Batch No, Expired Date.');
        }

        return $putaway;
    }

    /**
     * Build WMS Lokasi → DOMElement row index, then merge each receipt.
     *
     * @return array<int, array> Unmatched receipts (missing bins, conflicts)
     */
    private function mergeReceipts(\DOMDocument $dom, array $receipts): array
    {
        $sheetData = $dom->getElementsByTagName('sheetData')->item(0);
        $rows = $sheetData->getElementsByTagName('row');

        // Build index: Lokasi → DOMElement row
        $lokasiRowIndex = [];
        foreach ($rows as $row) {
            $rNum = (int)$row->getAttribute('r');
            if ($rNum <= self::WMS_HEADER_ROW) continue;

            $lokasi = $this->findCellValueInRow($row, self::WMS_COL_LOKASI);
            if ($lokasi !== null && $lokasi !== '' && !isset($lokasiRowIndex[$lokasi])) {
                $lokasiRowIndex[$lokasi] = $row;
            }
        }

        $unmatched = [];

        foreach ($receipts as $receipt) {
            $location = strtoupper($receipt['location']);
            $row = $lokasiRowIndex[$location] ?? null;

            if ($row === null) {
                $unmatched[] = $receipt + ['reason' => 'Bin not found in WMS sheet'];
                continue;
            }

            $rNum = (int)$row->getAttribute('r');

            // Read current bin state
            $currentItem = trim((string)($this->findCellValueInRow($row, self::WMS_COL_ITEM) ?? ''));
            $currentQty  = (float)($this->findCellValueInRow($row, self::WMS_COL_QTY) ?? 0);

            // Conflict: bin holds a different item
            if ($currentQty > 0 && $currentItem !== '' && $currentItem !== (string)$receipt['item_code']) {
                $unmatched[] = $receipt + ['reason' => "Bin already holds different item {$currentItem}"];
                continue;
            }

            // Safe to merge: empty bin or same item — add qty
            $newQty = $currentQty + $receipt['quantity'];
            $this->setNumericCell($dom, $row, self::WMS_COL_QTY . $rNum, $newQty);

            if ($receipt['item_code'] !== '') {
                $this->setInlineStringCell($dom, $row, self::WMS_COL_ITEM . $rNum, (string)$receipt['item_code']);
            }
            if (!empty($receipt['batch_number'])) {
                $this->setInlineStringCell($dom, $row, self::WMS_COL_BATCH . $rNum, (string)$receipt['batch_number']);
            }
            if (!empty($receipt['expiry_date'])) {
                $this->setInlineStringCell($dom, $row, self::WMS_COL_EXPIRY . $rNum, (string)$receipt['expiry_date']);
            }
        }

        return $unmatched;
    }
}
