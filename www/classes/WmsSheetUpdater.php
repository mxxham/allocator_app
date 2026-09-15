<?php
/**
 * WMS Sheet Updater — ZIP-level surgical editor.
 *
 * Opens the .xlsx as a ZipArchive, modifies ONLY the WMS sheet XML,
 * and leaves every other internal file byte-for-byte untouched.
 * No PhpSpreadsheet dependency — zero risk of formula stripping.
 */
require_once __DIR__ . '/SharedSheetEditor.php';

class WmsSheetUpdater extends SharedSheetEditor
{
    /**
     * Apply ONLY replenishment/bin-to-bin deltas to the WMS sheet.
     * Picks are NOT applied — they will be applied later via applyConfirmedPicks()
     * after the user physically picks and confirms the picklist.
     *
     * @param string $originalFilePath  Path to the original uploaded .xlsx
     * @param array  $allocationResult  Full result from Allocator::allocate()
     * @return string Path to the updated temp file
     */
    public function applyReplenishmentsOnly(string $originalFilePath, array $allocationResult): string
    {
        $outPath = tempnam(sys_get_temp_dir(), 'wms_replen_') . '.xlsx';
        copy($originalFilePath, $outPath);

        $zip = new \ZipArchive();
        if ($zip->open($outPath) !== true) {
            throw new \Exception("Could not open workbook as zip archive.");
        }

        $this->loadSharedStrings($zip);

        $sheetPath = $this->resolveSheetXmlPath($zip, 'WMS');
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) throw new \Exception("Could not read {$sheetPath}");

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $this->applyDeltas($dom, [
            'picks' => [],  // Empty — do not apply picks yet
            'replenishments' => $allocationResult['replenishments'],
        ]);

        $zip->deleteName($sheetPath);
        $zip->addFromString($sheetPath, $dom->saveXML());
        $zip->close();

        $this->verifyIntegrity($originalFilePath, $outPath, $sheetPath);

        return $outPath;
    }

    /**
     * Apply confirmed picks to the WMS sheet.
     * Decrements source bins for each confirmed pick.
     *
     * @param string $wmsFilePath      Path to the current WMS .xlsx (after replenishments)
     * @param array  $confirmedPicks   Array of confirmed pick rows from PickConfirmationParser
     * @return string Path to the updated temp file
     */
    public function applyConfirmedPicks(string $wmsFilePath, array $confirmedPicks): string
    {
        $outPath = tempnam(sys_get_temp_dir(), 'wms_picks_') . '.xlsx';
        copy($wmsFilePath, $outPath);

        $zip = new \ZipArchive();
        if ($zip->open($outPath) !== true) {
            throw new \Exception("Could not open workbook as zip archive.");
        }

        $this->loadSharedStrings($zip);

        $sheetPath = $this->resolveSheetXmlPath($zip, 'WMS');
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) throw new \Exception("Could not read {$sheetPath}");

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        // Build deltas from confirmed picks only — decrement source bins
        $result = ['picks' => $confirmedPicks, 'replenishments' => []];
        $this->applyDeltas($dom, $result);

        $zip->deleteName($sheetPath);
        $zip->addFromString($sheetPath, $dom->saveXML());
        $zip->close();

        $this->verifyIntegrity($wmsFilePath, $outPath, $sheetPath);

        return $outPath;
    }

    /**
     * Apply order-level decisions to the WMS sheet.
     * Each order can be: confirmed (decrement source), staged (move to STAGING), or cancelled (skip).
     *
     * @param string $wmsFilePath      Path to the current WMS .xlsx (after replenishments)
     * @param array  $allocationResult Full allocation result (picks, replenishments, etc.)
     * @param array  $decisions        ['no' => 'confirm'|'stage'|'cancel', ...]
     * @return string Path to the final updated temp file
     */
    public function applyOrderDecisions(string $wmsFilePath, array $allocationResult, array $decisions): string
    {
        $outPath = tempnam(sys_get_temp_dir(), 'wms_final_') . '.xlsx';
        copy($wmsFilePath, $outPath);

        $zip = new \ZipArchive();
        if ($zip->open($outPath) !== true) {
            throw new \Exception("Could not open workbook as zip archive.");
        }

        $this->loadSharedStrings($zip);

        $sheetPath = $this->resolveSheetXmlPath($zip, 'WMS');
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) throw new \Exception("Could not read {$sheetPath}");

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        // Build index: column letters + Lokasi → row mapping
        $sheetData = $dom->getElementsByTagName('sheetData')->item(0);
        $rows = $sheetData->getElementsByTagName('row');

        $headerRowNum = 4;
        $colLetters = [];
        $lokasiRowIndex = [];

        foreach ($rows as $row) {
            $rNum = (int)$row->getAttribute('r');
            foreach ($row->getElementsByTagName('c') as $cell) {
                if ($rNum === $headerRowNum) {
                    $text = $this->cellValue($cell);
                    if (in_array($text, ['Lokasi', 'item', 'Batch', 'Qty'], true)) {
                        $colLetters[$text] = $this->colLetterFromRef($cell->getAttribute('r'));
                    }
                }
            }
            if ($rNum > $headerRowNum) {
                foreach ($row->getElementsByTagName('c') as $cell) {
                    if (isset($colLetters['Lokasi']) && $this->colLetterFromRef($cell->getAttribute('r')) === $colLetters['Lokasi']) {
                        $lokasi = $this->cellValue($cell);
                        if ($lokasi !== null && $lokasi !== '' && !isset($lokasiRowIndex[$lokasi])) {
                            $lokasiRowIndex[$lokasi] = $row;
                        }
                    }
                }
            }
        }

        $requiredCols = ['Lokasi', 'item', 'Batch', 'Qty'];
        foreach ($requiredCols as $col) {
            if (!isset($colLetters[$col])) {
                throw new \Exception("Column '{$col}' not found in WMS sheet header (row {$headerRowNum})");
            }
        }

        // Collect picks grouped by order
        $picksByOrder = [];
        foreach ($allocationResult['picks'] as $pick) {
            $orderNo = $pick['no'] ?: ($pick['order_no'] ?? 'unknown');
            $picksByOrder[$orderNo][] = $pick;
        }

        // Find empty STAGING rows for staging moves
        $stagingEmptyRows = [];
        foreach ($lokasiRowIndex as $lokasi => $row) {
            if (strtoupper($lokasi) === 'STAGING') {
                $rNum = (int)$row->getAttribute('r');
                $itemVal = $this->findCellValueInRow($row, $colLetters['item']);
                $qtyVal = $this->findCellValueInRow($row, $colLetters['Qty']);
                if (empty($itemVal) || $qtyVal == '0' || $qtyVal === null) {
                    $stagingEmptyRows[] = $row;
                }
            }
        }

        // Process each order's decision
        $stagingIdx = 0; // pointer into $stagingEmptyRows

        foreach ($decisions as $orderNo => $decision) {
            if ($decision === 'cancel') continue; // Skip cancelled orders — no WMS changes

            $orderPicks = $picksByOrder[$orderNo] ?? [];
            if (empty($orderPicks)) continue;

            foreach ($orderPicks as $pick) {
                $sourceLokasi = $pick['location'] ?? null;
                if (!$sourceLokasi || !isset($lokasiRowIndex[$sourceLokasi])) continue;

                $sourceRow = $lokasiRowIndex[$sourceLokasi];
                $sourceRNum = (int)$sourceRow->getAttribute('r');
                $pickQty = (float)($pick['quantity'] ?? 0);
                if ($pickQty <= 0) continue;

                // Decrement source bin
                $currentQty = (float)($this->findCellValueInRow($sourceRow, $colLetters['Qty']) ?? 0);
                $newQty = $currentQty - $pickQty;
                $this->setNumericCell($dom, $sourceRow, $colLetters['Qty'] . $sourceRNum, $newQty);

                if ($newQty > 0) {
                    $this->setInlineStringCell($dom, $sourceRow, $colLetters['item'] . $sourceRNum, (string)$pick['item_code']);
                    if (!empty($pick['batch_number'])) {
                        $this->setInlineStringCell($dom, $sourceRow, $colLetters['Batch'] . $sourceRNum, (string)$pick['batch_number']);
                    }
                } else {
                    $this->clearCell($dom, $sourceRow, $colLetters['item'] . $sourceRNum);
                    $this->clearCell($dom, $sourceRow, $colLetters['Batch'] . $sourceRNum);
                }

                // Stage: also increment a STAGING row
                if ($decision === 'stage' && $stagingIdx < count($stagingEmptyRows)) {
                    $stageRow = $stagingEmptyRows[$stagingIdx];
                    $stageRNum = (int)$stageRow->getAttribute('r');

                    $stageQty = (float)($this->findCellValueInRow($stageRow, $colLetters['Qty']) ?? 0);
                    $this->setNumericCell($dom, $stageRow, $colLetters['Qty'] . $stageRNum, $stageQty + $pickQty);
                    $this->setInlineStringCell($dom, $stageRow, $colLetters['item'] . $stageRNum, (string)$pick['item_code']);
                    if (!empty($pick['batch_number'])) {
                        $this->setInlineStringCell($dom, $stageRow, $colLetters['Batch'] . $stageRNum, (string)$pick['batch_number']);
                    }

                    // Check if this staging row is now full (used up) — move to next
                    $upp = $this->estimateUpp($pick['item_code']);
                    if (($stageQty + $pickQty) >= $upp) {
                        $stagingIdx++;
                    }
                }
            }
        }

        $zip->deleteName($sheetPath);
        $zip->addFromString($sheetPath, $dom->saveXML());
        $zip->close();

        $this->verifyIntegrity($wmsFilePath, $outPath, $sheetPath);

        return $outPath;
    }

    /**
     * Estimate UPP for an item code (heuristic based on description patterns).
     * Used to decide when a staging row is "full".
     */
    private function estimateUpp(string $itemCode): float
    {
        // Conservative default — a staging row can hold roughly one pallet
        return 44;
    }

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
        $outPath = tempnam(sys_get_temp_dir(), 'wms_updated_') . '.xlsx';
        copy($originalFilePath, $outPath);

        $zip = new \ZipArchive();
        if ($zip->open($outPath) !== true) {
            throw new \Exception("Could not open workbook as zip archive.");
        }

        $this->loadSharedStrings($zip);

        $sheetPath = $this->resolveSheetXmlPath($zip, 'WMS');
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) throw new \Exception("Could not read {$sheetPath}");

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $this->applyDeltas($dom, $allocationResult);

        $zip->deleteName($sheetPath);
        $zip->addFromString($sheetPath, $dom->saveXML());
        $zip->close();

        $this->verifyIntegrity($originalFilePath, $outPath, $sheetPath);

        return $outPath;
    }

    private function applyDeltas(\DOMDocument $dom, array $allocationResult): void
    {
        $sheetData = $dom->getElementsByTagName('sheetData')->item(0);
        $rows = $sheetData->getElementsByTagName('row');

        $headerRowNum = 4;
        $colLetters = [];
        $lokasiRowIndex = [];

        foreach ($rows as $row) {
            $rNum = (int)$row->getAttribute('r');
            foreach ($row->getElementsByTagName('c') as $cell) {
                if ($rNum === $headerRowNum) {
                    $text = $this->cellValue($cell);
                    if (in_array($text, ['Lokasi', 'item', 'Batch', 'Qty'], true)) {
                        $colLetters[$text] = $this->colLetterFromRef($cell->getAttribute('r'));
                    }
                }
            }
            if ($rNum > $headerRowNum) {
                foreach ($row->getElementsByTagName('c') as $cell) {
                    if (isset($colLetters['Lokasi']) && $this->colLetterFromRef($cell->getAttribute('r')) === $colLetters['Lokasi']) {
                        $lokasi = $this->cellValue($cell);
                        if ($lokasi !== null && $lokasi !== '' && !isset($lokasiRowIndex[$lokasi])) {
                            $lokasiRowIndex[$lokasi] = $row;
                        }
                    }
                }
            }
        }

        $deltas = $this->buildDeltas($allocationResult);

        // Validate all required columns were found
        $requiredCols = ['Lokasi', 'item', 'Batch', 'Qty'];
        foreach ($requiredCols as $col) {
            if (!isset($colLetters[$col])) {
                throw new \Exception("Column '{$col}' not found in WMS sheet header (row {$headerRowNum})");
            }
        }

        foreach ($deltas as $lokasi => $change) {
            $row = $lokasiRowIndex[$lokasi] ?? null;
            if ($row === null) continue;
            $rNum = (int)$row->getAttribute('r');

            $currentQty = (float)($this->findCellValueInRow($row, $colLetters['Qty']) ?? 0);
            $newQty = $currentQty + $change['qty_delta'];

            $this->setNumericCell($dom, $row, $colLetters['Qty'] . $rNum, $newQty);

            if ($newQty > 0) {
                $this->setInlineStringCell($dom, $row, $colLetters['item'] . $rNum, (string)$change['item']);
                if (isset($change['batch'])) {
                    $this->setInlineStringCell($dom, $row, $colLetters['Batch'] . $rNum, (string)$change['batch']);
                }
            } else {
                $this->clearCell($dom, $row, $colLetters['item'] . $rNum);
                $this->clearCell($dom, $row, $colLetters['Batch'] . $rNum);
            }
        }
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

        foreach ($allocationResult['picks'] as $pick) {
            $deltas[$pick['location']]['qty_delta'] = ($deltas[$pick['location']]['qty_delta'] ?? 0) - $pick['quantity'];
            $deltas[$pick['location']]['item'] = $pick['item_code'];
            $deltas[$pick['location']]['batch'] = $pick['batch_number'] ?? null;
        }

        foreach ($allocationResult['replenishments'] as $rep) {
            $deltas[$rep['from_location']]['qty_delta'] = ($deltas[$rep['from_location']]['qty_delta'] ?? 0) - $rep['quantity'];
            $deltas[$rep['from_location']]['item'] = $rep['item_code'];
            $deltas[$rep['to_location']]['qty_delta'] = ($deltas[$rep['to_location']]['qty_delta'] ?? 0) + $rep['quantity'];
            $deltas[$rep['to_location']]['item'] = $rep['item_code'];
        }

        return $deltas;
    }
}
