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
require_once __DIR__ . '/classes/PickConfirmationParser.php';

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

            // Merge MASTER DATA UPP values into Master SKU products
            $masterData = $parser->parseMasterData();
            foreach ($masterData as $mat => $data) {
                if (!isset($masterSku[$mat])) {
                    $masterSku[$mat] = $data;
                } else {
                    $masterSku[$mat]['upp'] = $data['upp'];
                    $masterSku[$mat]['uom_type'] = $data['uom_type'];
                }
            }

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

            // Merge MASTER DATA UPP values into Master SKU products
            $masterData = $parser->parseMasterData();
            foreach ($masterData as $mat => $data) {
                if (!isset($masterSku[$mat])) {
                    $masterSku[$mat] = $data;
                } else {
                    // Override UPP and UOM type from MASTER DATA (more reliable)
                    $masterSku[$mat]['upp'] = $data['upp'];
                    $masterSku[$mat]['uom_type'] = $data['uom_type'];
                }
            }

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

            // Step 5b: Apply ONLY replenishments/bin-to-bin to WMS (picks deferred)
            $updater = new WmsSheetUpdater();
            $updatedWmsFile = $updater->applyReplenishmentsOnly($file['tmp_name'], $result);

            // Step 6: Save results to JSON for print access
            $resultId = uniqid('alloc_', true);
            $resultFile = sys_get_temp_dir() . '/allocator_' . $resultId . '.json';
            file_put_contents($resultFile, json_encode([
                'picks' => $result['picks'],
                'replenishments' => $result['replenishments'],
                'errors' => $result['errors'],
                'summary' => $result['summary'],
                'order_meta' => $result['order_meta'] ?? [],
                'updated_wms_file' => $updatedWmsFile,
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

        // ── Stage 3: Apply confirmed picks ──────────────────────────
        case 'confirm_picks':
            if (!isset($_FILES['picklist_file']) || $_FILES['picklist_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Picklist file tidak diupload atau error upload');
            }

            if (!isset($_POST['wms_file']) || empty($_POST['wms_file'])) {
                throw new Exception('WMS file path tidak ditemukan');
            }

            $picklistPath = $_FILES['picklist_file']['tmp_name'];
            $wmsPath = $_POST['wms_file'];

            if (!file_exists($wmsPath)) {
                throw new Exception('WMS file tidak ditemukan di server');
            }

            // Parse the filled-in picklist
            $parser = new PickConfirmationParser();
            $confirmedPicks = $parser->parse($picklistPath);

            if (empty($confirmedPicks)) {
                throw new Exception('Tidak ada baris yang ditandai sebagai picked (Y/YES/DONE/1/TRUE)');
            }

            // Apply confirmed picks to WMS
            $updater = new WmsSheetUpdater();
            $updatedWmsFile = $updater->applyConfirmedPicks($wmsPath, $confirmedPicks);

            $response = [
                'success' => true,
                'message' => count($confirmedPicks) . ' picks dikonfirmasi dan diterapkan ke WMS',
                'confirmed_count' => count($confirmedPicks),
                'updated_wms_file' => $updatedWmsFile,
            ];

            echo json_encode($response);
            ob_end_flush();
            break;

        // ── Apply order decisions (confirm/stage/cancel) ──────────
        case 'apply_order_decisions':
            if (!isset($_POST['result_id']) || empty($_POST['result_id'])) {
                throw new Exception('result_id tidak ditemukan');
            }

            if (!isset($_POST['decisions']) || empty($_POST['decisions'])) {
                throw new Exception('Tidak ada keputusan order yang dikirim');
            }

            $resultId = $_POST['result_id'];
            $decisions = json_decode($_POST['decisions'], true);

            if (!is_array($decisions) || empty($decisions)) {
                throw new Exception('Format decisions tidak valid');
            }

            // Load allocation result from temp file
            $resultFile = sys_get_temp_dir() . '/allocator_' . $resultId . '.json';
            if (!file_exists($resultFile)) {
                throw new Exception('Allocation result tidak ditemukan — jalankan allocation ulang');
            }

            $allocationResult = json_decode(file_get_contents($resultFile), true);
            if (!$allocationResult) {
                throw new Exception('Gagal membaca allocation result');
            }

            // Load the WMS file path from the result
            $wmsPath = $allocationResult['updated_wms_file'] ?? null;
            if (!$wmsPath || !file_exists($wmsPath)) {
                throw new Exception('WMS file tidak ditemukan — jalankan allocation ulang');
            }

            @ini_set('memory_limit', '512M');
            @set_time_limit(300);

            // Apply all decisions at once
            $updater = new WmsSheetUpdater();
            $finalWmsFile = $updater->applyOrderDecisions($wmsPath, $allocationResult, $decisions);

            // Count decisions
            $confirmed = 0; $staged = 0; $cancelled = 0;
            foreach ($decisions as $d) {
                if ($d === 'confirm') $confirmed++;
                elseif ($d === 'stage') $staged++;
                elseif ($d === 'cancel') $cancelled++;
            }

            // Persist decisions + final WMS path into result JSON
            // (so preview_wms_changes.php can read them)
            $allocationResult['decisions'] = $decisions;
            $allocationResult['final_wms_file'] = $finalWmsFile;

            // Regenerate picklist with ONLY confirmed picks (exclude staged/cancelled)
            $confirmedPicks = array_values(array_filter($allocationResult['picks'] ?? [], function($pick) use ($decisions) {
                $orderNo = $pick['order_no'] ?? '';
                return ($decisions[$orderNo] ?? 'cancel') === 'confirm';
            }));
            $filteredResult = array_merge($allocationResult, ['picks' => $confirmedPicks]);
            $generator = new PicklistGenerator();
            $newPicklistFile = $generator->generate($filteredResult);
            $allocationResult['picklist_file'] = $newPicklistFile;

            file_put_contents($resultFile, json_encode($allocationResult, JSON_PRETTY_PRINT));

            $response = [
                'success' => true,
                'message' => "{$confirmed} confirmed, {$staged} staged, {$cancelled} cancelled",
                'confirmed' => $confirmed,
                'staged' => $staged,
                'cancelled' => $cancelled,
                'final_wms_file' => $finalWmsFile,
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
