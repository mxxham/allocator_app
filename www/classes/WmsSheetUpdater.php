<?php
/**
 * WMS Sheet Updater — ZIP-level surgical editor.
 *
 * Opens the .xlsx as a ZipArchive, modifies ONLY the WMS sheet XML,
 * and leaves every other internal file byte-for-byte untouched.
 * No PhpSpreadsheet dependency — zero risk of formula stripping.
 */

class WmsSheetUpdater
{
    private array $sharedStrings = [];

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

        $this->loadSharedStrings($zip); // read-only; this file is never written back

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

        // Byte-level integrity check: every file except the one we changed
        // must be identical between original and output.
        $origZip = new \ZipArchive();
        $origZip->open($originalFilePath);
        $newZip = new \ZipArchive();
        $newZip->open($outPath);
        for ($i = 0; $i < $origZip->numFiles; $i++) {
            $name = $origZip->getNameIndex($i);
            if ($name === $sheetPath) continue; // the one file we intentionally changed
            if ($origZip->getFromIndex($i) !== $newZip->getFromName($name)) {
                $origZip->close();
                $newZip->close();
                throw new \Exception("INTEGRITY FAILURE: {$name} was unexpectedly modified!");
            }
        }
        $origZip->close();
        $newZip->close();

        return $outPath;
    }

    private function loadSharedStrings(\ZipArchive $zip): void
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return;
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $items = $dom->getElementsByTagName('si');
        foreach ($items as $i => $si) {
            $this->sharedStrings[$i] = trim($si->textContent);
        }
    }

    private function resolveSheetXmlPath(\ZipArchive $zip, string $sheetName): string
    {
        $wb = new \DOMDocument(); $wb->loadXML($zip->getFromName('xl/workbook.xml'));
        $rels = new \DOMDocument(); $rels->loadXML($zip->getFromName('xl/_rels/workbook.xml.rels'));

        $rIdMap = [];
        foreach ($rels->getElementsByTagName('Relationship') as $rel) {
            $rIdMap[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
        }
        foreach ($wb->getElementsByTagName('sheet') as $sheet) {
            if ($sheet->getAttribute('name') === $sheetName) {
                $rId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                return 'xl/' . $rIdMap[$rId];
            }
        }
        throw new \Exception("Sheet '{$sheetName}' not found.");
    }

    private function cellValue(\DOMElement $cell): ?string
    {
        $vNode = $cell->getElementsByTagName('v')->item(0);
        if ($cell->getAttribute('t') === 'inlineStr') {
            $isNode = $cell->getElementsByTagName('is')->item(0);
            return $isNode ? trim($isNode->textContent) : null;
        }
        if ($vNode === null) return null;
        $val = trim($vNode->textContent);
        if ($cell->getAttribute('t') === 's') {
            return $this->sharedStrings[(int)$val] ?? null;
        }
        return $val;
    }

    private function colLetterFromRef(string $ref): string
    {
        preg_match('/^([A-Z]+)\d+$/', $ref, $m);
        return $m[1];
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

    private function findCellValueInRow(\DOMElement $row, string $colLetter): ?string
    {
        foreach ($row->getElementsByTagName('c') as $cell) {
            if ($this->colLetterFromRef($cell->getAttribute('r')) === $colLetter) {
                return $this->cellValue($cell);
            }
        }
        return null;
    }

    private function getOrCreateCell(\DOMDocument $dom, \DOMElement $row, string $ref): \DOMElement
    {
        foreach ($row->getElementsByTagName('c') as $cell) {
            if ($cell->getAttribute('r') === $ref) return $cell;
        }
        $cell = $dom->createElement('c');
        $cell->setAttribute('r', $ref);
        $row->appendChild($cell);
        return $cell;
    }

    private function setNumericCell(\DOMDocument $dom, \DOMElement $row, string $ref, float $value): void
    {
        $cell = $this->getOrCreateCell($dom, $row, $ref);
        $cell->removeAttribute('t');
        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
        $v = $dom->createElement('v', (string)$value);
        $cell->appendChild($v);
    }

    private function setInlineStringCell(\DOMDocument $dom, \DOMElement $row, string $ref, string $value): void
    {
        $cell = $this->getOrCreateCell($dom, $row, $ref);
        $cell->setAttribute('t', 'inlineStr');
        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
        $is = $dom->createElement('is');
        $t = $dom->createElement('t');
        $t->appendChild($dom->createTextNode($value));
        $is->appendChild($t);
        $cell->appendChild($is);
    }

    private function clearCell(\DOMDocument $dom, \DOMElement $row, string $ref): void
    {
        $cell = $this->getOrCreateCell($dom, $row, $ref);
        $cell->removeAttribute('t');
        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
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
        foreach ($allocationResult['picks'] as $pick) {
            $deltas[$pick['location']]['qty_delta'] = ($deltas[$pick['location']]['qty_delta'] ?? 0) - $pick['quantity'];
            $deltas[$pick['location']]['item'] = $pick['item_code'];
            $deltas[$pick['location']]['batch'] = $pick['batch_number'] ?? null;
        }

        // Replenishments: decrement source, increment destination
        foreach ($allocationResult['replenishments'] as $rep) {
            $deltas[$rep['from_location']]['qty_delta'] = ($deltas[$rep['from_location']]['qty_delta'] ?? 0) - $rep['quantity'];
            $deltas[$rep['from_location']]['item'] = $rep['item_code'];
            $deltas[$rep['to_location']]['qty_delta'] = ($deltas[$rep['to_location']]['qty_delta'] ?? 0) + $rep['quantity'];
            $deltas[$rep['to_location']]['item'] = $rep['item_code'];
        }

        return $deltas;
    }
}
