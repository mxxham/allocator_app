<?php
/**
 * Allocator API Handler
 * Processes Excel upload and generates picklist
 */

session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/classes/ExcelParser.php';
require_once __DIR__ . '/classes/Allocator.php';
require_once __DIR__ . '/classes/PicklistGenerator.php';
require_once __DIR__ . '/classes/WmsSheetUpdater.php';
require_once __DIR__ . '/classes/InboundMerger.php';

// Standalone — no auth required

// Suppress HTML error output — we need clean JSON
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

// Set JSON response header
header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? 'allocate';

    switch ($action) {

        // ── Pickface status ──────────────────────────────────────────
        case 'pickface_status':
            // Check if file uploaded
            if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File tidak diupload atau error upload');
            }

            $file = $_FILES['excel_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, ['xlsx', 'xls'])) {
                throw new Exception('Format file harus .xlsx atau .xls');
            }

            // Raise memory limit for large files
            @ini_set('memory_limit', '512M');
            @set_time_limit(300);

            // Step 1: Parse Excel
            $parser = new ExcelParser();
            if (!$parser->load($file['tmp_name'], $file['name'])) {
                throw new Exception('Gagal membaca file: ' . implode(', ', $parser->getErrors()));
            }

            // Step 2: Extract data from sheets
            $putaway = $parser->parsePutaway();
            $masterSku = $parser->parseMasterSku();
            $wmsLocations = $parser->parseWmsLocations();

            // Step 3: Initialize allocator
            $allocator = new Allocator();
            $allocator->loadProducts($masterSku);
            $allocator->loadWmsLocations($wmsLocations);
            $allocator->loadStock($putaway);

            // Step 4: Get pickface status
            $status = $allocator->getPickfaceStatus();

            // Calculate summary
            $totalItems = count($status);
            $withPickface = 0;
            $withoutPickface = 0;
            foreach ($status as $item) {
                if ($item['has_pickface']) {
                    $withPickface++;
                } else {
                    $withoutPickface++;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Pickface status loaded',
                'items' => $status,
                'summary' => [
                    'total_items' => $totalItems,
                    'with_pickface' => $withPickface,
                    'without_pickface' => $withoutPickface,
                ],
            ]);
            ob_end_flush();
            break;

        // ── Inbound merge ──────────────────────────────────────────
        case 'merge_inbound':
            // Validate WMS file
            if (!isset($_FILES['wms_file']) || $_FILES['wms_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File WMS tidak diupload atau error upload');
            }
            $wmsFile = $_FILES['wms_file'];
            $wmsExt  = strtolower(pathinfo($wmsFile['name'], PATHINFO_EXTENSION));
            if ($wmsExt !== 'xlsx') {
                throw new Exception('Format file WMS harus .xlsx');
            }

            // Validate inbound file
            if (!isset($_FILES['inbound_file']) || $_FILES['inbound_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File inbound tidak diupload atau error upload');
            }
            $inboundFile = $_FILES['inbound_file'];
            $inboundExt  = strtolower(pathinfo($inboundFile['name'], PATHINFO_EXTENSION));
            if ($inboundExt !== 'xlsx') {
                throw new Exception('Format file inbound harus .xlsx');
            }

            // Raise limits for large files
            @ini_set('memory_limit', '512M');
            @set_time_limit(300);

            $merger = new InboundMerger();
            $rawResult = $merger->apply($wmsFile['tmp_name'], $inboundFile['tmp_name'], $inboundFile['name']);
            $result = json_decode($rawResult, true);

            $unmatched = $result['unmatched'] ?? [];
            $unmatchedCount = count($unmatched);

            // Compute stats — matched = receipts that were merged into WMS
            $parser = new ExcelParser();
            $parser->load($inboundFile['tmp_name'], $inboundFile['name']);
            $receiptCount = count($parser->parsePutaway());
            $matchedCount = $receiptCount - $unmatchedCount;

            echo json_encode([
                'success'       => true,
                'message'       => 'Inbound merge selesai',
                'merged_file'   => $result['file'],
                'unmatched'     => $unmatched,
                'unmatched_items' => array_map(fn($u) => "{$u['location']} — {$u['reason']}", $unmatched),
                'summary' => [
                    'merged'    => $matchedCount,
                    'matched'   => $matchedCount,
                    'unmatched' => $unmatchedCount,
                ],
            ]);
            ob_end_flush();
            break;

        // ── Default allocation flow ────────────────────────────────
        case 'allocate':
        default:
            // Check if file uploaded
            if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File tidak diupload atau error upload');
            }

            $file = $_FILES['excel_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, ['xlsx', 'xls'])) {
                throw new Exception('Format file harus .xlsx atau .xls');
            }

            // Raise memory limit for large files
            @ini_set('memory_limit', '512M');
            @set_time_limit(300);

            // Step 1: Parse Excel
            $parser = new ExcelParser();
            if (!$parser->load($file['tmp_name'], $file['name'])) {
                throw new Exception('Gagal membaca file: ' . implode(', ', $parser->getErrors()));
            }

            // Step 2: Extract data from sheets
            $schedule = $parser->parseSchedule();
            $putaway = $parser->parsePutaway();
            $masterSku = $parser->parseMasterSku();
            $wmsLocations = $parser->parseWmsLocations();

            // Step 3: Initialize allocator
            $allocator = new Allocator();
            $allocator->loadProducts($masterSku);
            $allocator->loadWmsLocations($wmsLocations);  // WMS = primary stock source
            $allocator->loadStock($putaway);              // Putaway fills in batch/expiry details

            // Step 4: Run allocation
            $result = $allocator->allocate($schedule);

            // Step 5: Generate picklist Excel
            $generator = new PicklistGenerator();
            $tempFile = $generator->generate($result);

            // Step 5b: Update original WMS sheet with allocation deltas
            $updater = new WmsSheetUpdater();
            $updatedWmsFile = $updater->apply($file['tmp_name'], $result);

            // Step 6: Save results to JSON for print access
            $resultId = uniqid('alloc_', true);
            $resultFile = sys_get_temp_dir() . '/allocator_' . $resultId . '.json';
            file_put_contents($resultFile, json_encode([
                'picks' => $result['picks'],
                'replenishments' => $result['replenishments'],
                'errors' => $result['errors'],
                'summary' => $result['summary'],
                'created_at' => date('Y-m-d H:i:s'),
            ]));

            // Step 7: Return results
            $response = [
                'success' => true,
                'message' => 'Allocation selesai',
                'summary' => $result['summary'],
                'errors' => $result['errors'],
                'picks' => $result['picks'],
                'replenishments' => $result['replenishments'],
                'picklist_file' => $tempFile,
                'updated_wms_file' => $updatedWmsFile,
                'result_id' => $resultId,
                'stats' => [
                    'sheets_processed' => count($parser->getSheets()),
                    'schedule_lines' => count($schedule),
                    'putaway_stock' => count($putaway),
                    'master_sku_products' => count($masterSku),
                    'wms_locations' => count($wmsLocations),
                ],
            ];

            echo json_encode($response);
            ob_end_flush();
            break;
    }

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
