<?php

class InboundMerger extends SharedSheetEditor
{
    private const HEADER_ROW = 4;

    /**
     * Merge inbound receipts into a WMS workbook. Purely additive.
     * Uses ExcelParser::parsePutaway() for the inbound file, and the
     * same ZIP-level XML editing as WmsSheetUpdater for the WMS sheet —
     * every other sheet in the workbook is provably untouched.
     */
    public function apply(string $wmsFilePath, string $inboundFilePath): string
    {
        $receipts = $this->parseInboundReceipts($inboundFilePath);

        $outPath = tempnam(sys_get_temp_dir(), 'wms_inbound_') . '.xlsx';
        copy($wmsFilePath, $outPath);

        $zip = new \ZipArchive();
        if ($zip->open($outPath) !== true) {
            throw new \RuntimeException("Could not open workbook as zip archive.");
        }

        $this->loadSharedStrings($zip);
        $sheetPath = $this->resolveSheetXmlPath($zip, 'WMS');
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($zip->getFromName($sheetPath));

        $unmatched = $this->mergeReceipts($dom, $receipts);

        $zip->deleteName($sheetPath);
        $zip->addFromString($sheetPath, $dom->saveXML());
        $zip->close();

        $this->verifyOnlyWmsSheetChanged($wmsFilePath, $outPath, $sheetPath);

        return json_encode(['file' => $outPath, 'unmatched' => $unmatched], JSON_THROW_ON_ERROR);
    }

    private function parseInboundReceipts(string $inboundFilePath): array
    {
        $parser = new ExcelParser();
        if (!$parser->load($inboundFilePath)) {
            throw new \RuntimeException('Could not load inbound file: ' . implode('; ', $parser->getErrors()));
        }
        $rows = $parser->parsePutaway(); // [location, item_code, quantity, batch_number, expiry_date]
        if (empty($rows)) {
            throw new \RuntimeException('No inbound receipt rows found.');
        }
        return $rows;
    }

    private function mergeReceipts(\DOMDocument $dom, array $receipts): array
    {
        $sheetData = $dom->getElementsByTagName('sheetData')->item(0);
        $rows = $sheetData->getElementsByTagName('row');

        $colLetters = [];
        $lokasiRowIndex = [];

        foreach ($rows as $row) {
            $rNum = (int)$row->getAttribute('r');
            if ($rNum === self::HEADER_ROW) {
                foreach ($row->getElementsByTagName('c') as $cell) {
                    $text = $this->cellValue($cell);
                    if (in_array($text, ['Lokasi', 'item', 'Batch', 'Qty', 'Expired Date'], true)) {
                        $colLetters[$text] = $this->colLetterFromRef($cell->getAttribute('r'));
                    }
                }
            }
            if ($rNum > self::HEADER_ROW && isset($colLetters['Lokasi'])) {
                foreach ($row->getElementsByTagName('c') as $cell) {
                    if ($this->colLetterFromRef($cell->getAttribute('r')) === $colLetters['Lokasi']) {
                        $lokasi = $this->cellValue($cell);
                        if ($lokasi !== null && $lokasi !== '' && !isset($lokasiRowIndex[$lokasi])) {
                            $lokasiRowIndex[$lokasi] = $row;
                        }
                    }
                }
            }
        }

        if (count($colLetters) < 4) {
            throw new \RuntimeException('Could not locate required columns (Lokasi/item/Batch/Qty) in WMS header row ' . self::HEADER_ROW);
        }

        $unmatched = [];

        foreach ($receipts as $receipt) {
            $location = $receipt['location'];
            $row = $lokasiRowIndex[$location] ?? null;

            if ($row === null) {
                $unmatched[] = ['location' => $location, 'item' => $receipt['item_code'], 'qty' => $receipt['quantity'], 'reason' => 'Bin not found in WMS sheet'];
                continue;
            }
            $rNum = (int)$row->getAttribute('r');

            $currentItem = trim((string)($this->findCellValueInRow($row, $colLetters['item']) ?? ''));
            $currentQty = (float)($this->findCellValueInRow($row, $colLetters['Qty']) ?? 0);

            if ($currentItem !== '' && $currentItem !== (string)$receipt['item_code']) {
                $unmatched[] = ['location' => $location, 'item' => $receipt['item_code'], 'qty' => $receipt['quantity'], 'reason' => "Conflict — bin holds different item {$currentItem}", 'existing_item' => $currentItem];
                continue;
            }

            $newQty = $currentQty + $receipt['quantity'];
            $this->setNumericCell($dom, $row, $colLetters['Qty'] . $rNum, $newQty);
            $this->setInlineStringCell($dom, $row, $colLetters['item'] . $rNum, (string)$receipt['item_code']);
            if (!empty($receipt['batch_number'])) {
                $this->setInlineStringCell($dom, $row, $colLetters['Batch'] . $rNum, (string)$receipt['batch_number']);
            }
            if (!empty($receipt['expiry_date']) && isset($colLetters['Expired Date'])) {
                $this->setInlineStringCell($dom, $row, $colLetters['Expired Date'] . $rNum, (string)$receipt['expiry_date']);
            }
        }

        return $unmatched;
    }
}
