<?php
/**
 * Pick Confirmation Parser
 * Reads a filled-in picklist Excel and returns only the rows marked as picked.
 */

require_once __DIR__ . '/../vendor/autoload.php';

class PickConfirmationParser
{
    /**
     * Reads a picklist Excel (the same structure PicklistGenerator produced)
     * after someone has filled in the "Picked?" column, and returns only
     * the rows marked as actually completed.
     *
     * Accepted values for "Picked?": Y, YES, DONE, 1, TRUE (case-insensitive)
     *
     * @param string $filePath Path to the filled-in picklist .xlsx
     * @return array Array of confirmed picks with keys: order_no, item_code, location, quantity, type, batch_number
     * @throws \RuntimeException if file cannot be loaded or Picks sheet is missing
     */
    public function parse(string $filePath): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        $picksSheet = null;
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if ($sheet->getTitle() === 'Picks') {
                $picksSheet = $sheet;
                break;
            }
        }

        if ($picksSheet === null) {
            throw new \RuntimeException("Could not find 'Picks' sheet in confirmation file.");
        }

        // Read header row to find column indices
        $headerRow = 1;
        $col = [];
        $highestCol = $picksSheet->getHighestColumn();
        for ($c = 'A'; $c <= $highestCol; $c++) {
            $val = trim((string)$picksSheet->getCell("{$c}{$headerRow}")->getValue());
            if ($val !== '') {
                $col[$val] = $c;
            }
        }

        // Validate required columns exist
        $requiredCols = ['Order No', 'Item Code', 'Location', 'Quantity', 'Type', 'Picked?'];
        foreach ($requiredCols as $reqCol) {
            if (!isset($col[$reqCol])) {
                throw new \RuntimeException("Required column '{$reqCol}' not found in Picks sheet.");
            }
        }

        $confirmed = [];
        $highestRow = $picksSheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $pickedVal = strtoupper(trim((string)$picksSheet->getCell("{$col['Picked?']}{$row}")->getValue()));

            if (in_array($pickedVal, ['Y', 'YES', 'DONE', '1', 'TRUE'], true)) {
                $confirmed[] = [
                    'order_no' => $picksSheet->getCell("{$col['Order No']}{$row}")->getValue(),
                    'item_code' => $picksSheet->getCell("{$col['Item Code']}{$row}")->getValue(),
                    'location' => $picksSheet->getCell("{$col['Location']}{$row}")->getValue(),
                    'quantity' => (float)$picksSheet->getCell("{$col['Quantity']}{$row}")->getValue(),
                    'type' => $picksSheet->getCell("{$col['Type']}{$row}")->getValue(),
                    'batch_number' => isset($col['Batch']) ? $picksSheet->getCell("{$col['Batch']}{$row}")->getValue() : null,
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();
        return $confirmed;
    }
}
