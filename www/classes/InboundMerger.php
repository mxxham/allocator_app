<?php

class InboundMerger extends SharedSheetEditor
{
    private const HEADER_ROW = 4;

    /**
     * Merge inbound receipts AND schedule of the day into a WMS workbook.
     * Inbound is purely additive (stock increases).
     * Schedule replaces the existing "Schedule of the day" sheet.
     *
     * @param string $wmsFilePath       Path to the current WMS .xlsx
     * @param string|null $inboundFilePath  Path to inbound receipts file (optional)
     * @param string|null $scheduleFilePath Path to new Schedule of the day file (optional)
     * @return string JSON with file path and unmatched items
     */
    public function apply(string $wmsFilePath, ?string $inboundFilePath = null, ?string $scheduleFilePath = null): string
    {
        $outPath = tempnam(sys_get_temp_dir(), 'wms_merged_') . '.xlsx';
        copy($wmsFilePath, $outPath);

        $unmatched = [];

        // Merge inbound if provided
        if ($inboundFilePath) {
            $receipts = $this->parseInboundReceipts($inboundFilePath);
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
        }

        if ($scheduleFilePath) {
            $this->mergeSchedule($outPath, $scheduleFilePath);
        }

        return json_encode(['file' => $outPath, 'unmatched' => $unmatched], JSON_THROW_ON_ERROR);
    }

    /**
     * Replace or add the "Schedule of the day" sheet in the WMS workbook.
     */
    private function mergeSchedule(string $wmsFilePath, string $scheduleFilePath): void
    {
        // Parse the new schedule file to get raw sheet data
        $parser = new ExcelParser();
        if (!$parser->load($scheduleFilePath)) {
            throw new \RuntimeException('Could not load schedule file: ' . implode('; ', $parser->getErrors()));
        }

        $sheets = $parser->getSheets();
        $scheduleSheetName = null;
        $scheduleRows = null;

        // Find the Schedule sheet
        foreach ($sheets as $name => $rows) {
            if (str_contains(strtolower($name), 'schedule')) {
                $scheduleSheetName = $name;
                $scheduleRows = $rows;
                break;
            }
        }

        if ($scheduleSheetName === null || $scheduleRows === null) {
            throw new \RuntimeException('No "Schedule of the day" sheet found in the schedule file.');
        }

        // Read the raw XML from the schedule file
        $scheduleZip = new \ZipArchive();
        if ($scheduleZip->open($scheduleFilePath) !== true) {
            throw new \RuntimeException("Could not open schedule file as zip archive.");
        }

        // Find the sheet XML path for the schedule sheet
        $scheduleSheetPath = $this->resolveSheetXmlPath($scheduleZip, $scheduleSheetName);
        $scheduleXml = $scheduleZip->getFromName($scheduleSheetPath);
        $scheduleZip->close();

        if ($scheduleXml === false) {
            throw new \RuntimeException("Could not read schedule sheet XML.");
        }

        // Now open the WMS file and replace/add the schedule sheet
        $zip = new \ZipArchive();
        if ($zip->open($wmsFilePath) !== true) {
            throw new \RuntimeException("Could not open WMS workbook as zip archive.");
        }

        // Check if Schedule sheet already exists in WMS
        $existingSchedulePath = null;
        $wb = new \DOMDocument();
        $wb->loadXML($zip->getFromName('xl/workbook.xml'));
        $rels = new \DOMDocument();
        $rels->loadXML($zip->getFromName('xl/_rels/workbook.xml.rels'));

        $rIdMap = [];
        foreach ($rels->getElementsByTagName('Relationship') as $rel) {
            $rIdMap[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
        }

        $sheets = $wb->getElementsByTagName('sheet');
        $maxRid = 0;
        $nextSheetNum = 1;

        foreach ($sheets as $sheet) {
            $rId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if (preg_match('/rId(\d+)/', $rId, $m)) {
                $maxRid = max($maxRid, (int)$m[1]);
            }
            if (str_contains(strtolower($sheet->getAttribute('name')), 'schedule')) {
                $existingSchedulePath = 'xl/' . ($rIdMap[$rId] ?? '');
            }
            $nextSheetNum++;
        }

        if ($existingSchedulePath && $zip->locateName($existingSchedulePath) !== false) {
            // Replace existing schedule sheet
            $zip->deleteName($existingSchedulePath);
            $zip->addFromString($existingSchedulePath, $scheduleXml);
        } else {
            // Add new schedule sheet
            $newRid = 'rId' . ($maxRid + 1);
            $newSheetNum = $nextSheetNum;
            $newSheetFile = 'sheet' . $newSheetNum . '.xml';
            $newSheetPath = 'xl/worksheets/' . $newSheetFile;

            $zip->addFromString($newSheetPath, $scheduleXml);

            // Update workbook.xml
            $sheetEl = $wb->createElement('sheet');
            $sheetEl->setAttribute('name', 'Schedule of the day');
            $sheetEl->setAttribute('sheetId', (string)$newSheetNum);
            $sheetEl->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', $newRid);
            $sheets->item($sheets->length - 1)->parentNode->insertBefore($sheetEl, null);

            $zip->deleteName('xl/workbook.xml');
            $zip->addFromString('xl/workbook.xml', $wb->saveXML());

            // Update workbook.xml.rels
            $relEl = $rels->createElement('Relationship');
            $relEl->setAttribute('Id', $newRid);
            $relEl->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet');
            $relEl->setAttribute('Target', 'worksheets/' . $newSheetFile);
            $rels->getElementsByTagName('Relationships')->item(0)->appendChild($relEl);

            $zip->deleteName('xl/_rels/workbook.xml.rels');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $rels->saveXML());
        }

        $zip->close();
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
