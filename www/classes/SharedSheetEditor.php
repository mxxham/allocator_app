<?php
/**
 * SharedSheetEditor — Base class for ZIP-level .xlsx sheet editing.
 *
 * Provides the common XML manipulation primitives used by WmsSheetUpdater
 * and InboundMerger. Both open the .xlsx as a ZipArchive, modify ONLY
 * specific sheet XML, and verify byte-level integrity of untouched files.
 */
abstract class SharedSheetEditor
{
    protected array $sharedStrings = [];

    /**
     * Load shared strings from xl/sharedStrings.xml into $this->sharedStrings.
     * Read-only — never written back.
     */
    protected function loadSharedStrings(\ZipArchive $zip): void
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

    /**
     * Resolve the XML path for a sheet by name.
     * Looks up workbook.xml → relationships → sheet rId.
     */
    protected function resolveSheetXmlPath(\ZipArchive $zip, string $sheetName): string
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

    /**
     * Resolve the XML path for a sheet by case-insensitive substring match.
     * Falls back to first sheet if no match found.
     */
    protected function resolveSheetBySubstring(\ZipArchive $zip, string $substring): string
    {
        $wb = new \DOMDocument(); $wb->loadXML($zip->getFromName('xl/workbook.xml'));
        $rels = new \DOMDocument(); $rels->loadXML($zip->getFromName('xl/_rels/workbook.xml.rels'));

        $rIdMap = [];
        foreach ($rels->getElementsByTagName('Relationship') as $rel) {
            $rIdMap[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
        }

        $allSheets = [];
        foreach ($wb->getElementsByTagName('sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            $rId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $allSheets[] = ['name' => $name, 'path' => 'xl/' . $rIdMap[$rId]];

            if (str_contains(strtolower($name), strtolower($substring))) {
                return 'xl/' . $rIdMap[$rId];
            }
        }

        // No substring match — return first sheet as fallback
        if (!empty($allSheets)) {
            return $allSheets[0]['path'];
        }

        throw new \Exception("No sheets found in workbook.");
    }

    /**
     * Get the text value of a cell element.
     * Handles shared strings (t="s"), inline strings (t="inlineStr"), and plain values.
     */
    protected function cellValue(\DOMElement $cell): ?string
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

    /**
     * Extract the column letter (e.g. "A", "BC") from a cell reference (e.g. "A1", "BC5").
     */
    protected function colLetterFromRef(string $ref): string
    {
        preg_match('/^([A-Z]+)\d+$/', $ref, $m);
        return $m[1];
    }

    /**
     * Find a cell value in a row by column letter.
     */
    protected function findCellValueInRow(\DOMElement $row, string $colLetter): ?string
    {
        foreach ($row->getElementsByTagName('c') as $cell) {
            if ($this->colLetterFromRef($cell->getAttribute('r')) === $colLetter) {
                return $this->cellValue($cell);
            }
        }
        return null;
    }

    /**
     * Get or create a cell element at a given reference within a row.
     */
    protected function getOrCreateCell(\DOMDocument $dom, \DOMElement $row, string $ref): \DOMElement
    {
        foreach ($row->getElementsByTagName('c') as $cell) {
            if ($cell->getAttribute('r') === $ref) return $cell;
        }
        $cell = $dom->createElement('c');
        $cell->setAttribute('r', $ref);
        $row->appendChild($cell);
        return $cell;
    }

    /**
     * Set a cell to a numeric value (clears type attribute, sets plain <v>).
     */
    protected function setNumericCell(\DOMDocument $dom, \DOMElement $row, string $ref, float $value): void
    {
        $cell = $this->getOrCreateCell($dom, $row, $ref);
        $cell->removeAttribute('t');
        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
        $v = $dom->createElement('v', (string)$value);
        $cell->appendChild($v);
    }

    /**
     * Set a cell to an inline string value (t="inlineStr" with <is><t>...</t></is>).
     */
    protected function setInlineStringCell(\DOMDocument $dom, \DOMElement $row, string $ref, string $value): void
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

    /**
     * Clear a cell (remove type and children).
     */
    protected function clearCell(\DOMDocument $dom, \DOMElement $row, string $ref): void
    {
        $cell = $this->getOrCreateCell($dom, $row, $ref);
        $cell->removeAttribute('t');
        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
    }

    /**
     * Verify that every file in the zip except the one we changed is byte-identical
     * between original and output. Throws on any unexpected modification.
     */
    protected function verifyIntegrity(string $originalPath, string $outputPath, string $changedSheetPath): void
    {
        $origZip = new \ZipArchive();
        $origZip->open($originalPath);
        $newZip = new \ZipArchive();
        $newZip->open($outputPath);

        for ($i = 0; $i < $origZip->numFiles; $i++) {
            $name = $origZip->getNameIndex($i);
            if ($name === $changedSheetPath) continue;
            if ($origZip->getFromIndex($i) !== $newZip->getFromName($name)) {
                $origZip->close();
                $newZip->close();
                throw new \Exception("INTEGRITY FAILURE: {$name} was unexpectedly modified!");
            }
        }
        $origZip->close();
        $newZip->close();
    }
}
