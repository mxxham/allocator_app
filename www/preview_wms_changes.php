<?php
/**
 * WMS Changes Preview
 * Print-friendly view of what changed in the WMS per order after confirmation.
 * Similar layout to print_picklist.php.
 */

session_start();
date_default_timezone_set('Asia/Jakarta');

$resultId = $_GET['id'] ?? null;
if (!$resultId) { header('Location: index.php'); exit; }

$resultFile = sys_get_temp_dir() . '/allocator_' . $resultId . '.json';
if (!file_exists($resultFile)) {
    header('Location: index.php?error=expired');
    exit;
}

$result = json_decode(file_get_contents($resultFile), true);
if (!$result) { header('Location: index.php?error=invalid'); exit; }

$picks = $result['picks'] ?? [];
$replenishments = $result['replenishments'] ?? [];
$decisions = $result['decisions'] ?? [];
$summary = $result['summary'] ?? [];
$createdAt = $result['created_at'] ?? date('Y-m-d H:i:s');
$finalWmsFile = $result['final_wms_file'] ?? null;

// Count decision types
$confirmCount = 0; $stageCount = 0; $cancelCount = 0;
foreach ($decisions as $d) {
    if ($d === 'confirm') $confirmCount++;
    elseif ($d === 'stage') $stageCount++;
    elseif ($d === 'cancel') $cancelCount++;
}

// Group picks by order_no — only confirmed picks (staged/cancelled shown as summary only)
$groupedPicks = [];
foreach ($picks as $pick) {
    $no = $pick['no'] ?: ($pick['order_no'] ?? 'Unknown');
    $decision = $decisions[$no] ?? 'cancel';
    // Only include picks from confirmed orders in the detail view
    // Staged/cancelled orders show as summary line only
    if ($decision === 'confirm') {
        if (!isset($groupedPicks[$no])) {
            $groupedPicks[$no] = [];
        }
        $groupedPicks[$no][] = $pick;
    }
}

// Collect staged/cancelled orders for summary section
$nonConfirmedOrders = [];
foreach ($decisions as $orderNo => $d) {
    if ($d !== 'confirm') {
        $orderPicks = array_filter($picks, fn($p) => ($p['no'] ?: ($p['order_no'] ?? '')) === $orderNo);
        $nonConfirmedOrders[$orderNo] = [
            'decision' => $d,
            'qty' => array_sum(array_column($orderPicks, 'quantity')),
            'items' => count(array_unique(array_column($orderPicks, 'item_code'))),
        ];
    }
}

$orderKeys = array_keys($groupedPicks);
$lastOrderKey = end($orderKeys);
$totalPicks = count($picks);
$confirmedQty = array_sum(array_column(array_filter($picks, fn($p) => ($decisions[$p['no'] ?: ($p['order_no'] ?? '')] ?? 'cancel') === 'confirm'), 'quantity'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>WMS Changes — Allocator <?= date('d/m/Y') ?></title>
<link rel="stylesheet" href="assets/css/print-shared.css">
<style>
.picklist-specific .document{width:182mm;max-width:182mm;padding:12mm 14mm;margin:0 auto;box-sizing:border-box}
@media print{.picklist-specific .document{width:100%;max-width:none;padding:12mm 14mm}}

.company-name{font-size:13px;font-weight:800;color:#0f172a;letter-spacing:-.5px}
.company-name span{color:#64748b;font-weight:400}

.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:16px}
.info-cell{padding:10px 14px;border-bottom:1px solid #e2e8f0}
.info-cell:nth-child(odd){background:#f8fafc}
.info-cell .lbl{font-size:9px;letter-spacing:.6px;color:#64748b;text-transform:uppercase;font-weight:600}
.info-cell .val{font-size:12px;line-height:1.6;color:#0f172a;font-weight:500}

.section-title{font-size:11px;font-weight:700;color:#013d3c;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;padding-bottom:4px;border-bottom:2px solid #e2e8f0}

thead th{background:#013d3c;color:#fff;padding:8px 10px;font-size:13px;letter-spacing:.4px;text-transform:uppercase;text-align:left}
thead th:first-child{border-radius:6px 0 0 0}
thead th:last-child{border-radius:0 6px 0 0}
tbody td{padding:8px 10px;line-height:1.5;border-bottom:1px solid #e2e8f0}
tfoot td{padding:10px;font-size:13px;color:#0f172a;background:#f1f5f9;font-weight:700}

.chip-location{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.chip-staging{background:#fef9c3;color:#854d0e;border:1px solid #fde68a}
.chip-decrement{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.chip-increment{background:#dcfce7;color:#166534;border:1px solid #86efac}
.chip-cancel{background:#f3f4f6;color:#6b7280;border:1px solid #d1d5db}

.doc-footer{font-size:9px;color:#64748b;display:flex;justify-content:space-between;margin-top:16px;padding-top:8px;border-top:1px solid #e2e8f0}

/* Order header */
.order-page-header{font-size:11px;font-weight:700;color:#013d3c;margin-bottom:6px;padding:6px 10px;background:#e6f7f7;border-radius:6px;display:flex;justify-content:space-between;align-items:center}
.order-page-header .badge{padding:2px 8px;border-radius:4px;font-size:9px;font-weight:600;color:#fff}
.badge-confirm{background:#16a34a}
.badge-stage{background:#ca8a04}
.badge-cancel{background:#dc2626}

.print-page{page-break-after:always}
.print-page:last-child{page-break-after:auto}

@media print{#back-to-app{display:none!important}.print-bar{display:none!important}}
</style>
</head>
<body class="picklist-specific">

<div id="back-to-app" style="position:fixed;top:12px;right:12px;z-index:9999">
  <a href="javascript:window.close()" style="background:#0f172a;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif;border:1px solid rgba(255,255,255,.15)">
    Close &amp; Back
  </a>
</div>
<div class="print-bar no-print">
  <div class="print-bar-title">WMS Changes — Allocator</div>
  <div class="btns">
    <a class="btn-back" href="javascript:window.close()">Close</a>
    <?php if ($finalWmsFile): ?>
    <a class="btn-print" href="download.php?file=<?= urlencode($finalWmsFile) ?>&filename=final_wms_updated" style="text-decoration:none">Download WMS</a>
    <?php endif; ?>
    <button class="btn-print" onclick="window.print()">Print / PDF</button>
  </div>
</div>

<div class="document">

  <!-- Header -->
  <div class="doc-header">
    <div class="logo-area">
      <div class="logo-mark">K</div>
      <div class="company-name">K<span>-one</span></div>
    </div>
    <div style="text-align:right">
      <div class="doc-title">WMS CHANGES</div>
      <div class="doc-subtitle">Updated Stock Locations</div>
      <div class="doc-orderno"><?= date('d/m/Y H:i', strtotime($createdAt)) ?></div>
    </div>
  </div>

  <!-- Summary Info -->
  <div class="info-grid">
    <div class="info-cell">
      <div class="lbl">Tanggal</div>
      <div class="val"><?= date('d/m/Y', strtotime($createdAt)) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Total Orders</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace"><?= count($groupedPicks) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Total Picks</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace"><?= $totalPicks ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Confirmed</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace;color:#16a34a"><?= $confirmCount ?> orders</div>
    </div>
    <div class="info-cell">
      <div class="lbl">Rescheduled</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace;color:#ca8a04"><?= $stageCount ?> orders</div>
    </div>
    <div class="info-cell">
      <div class="lbl">Cancelled</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace;color:#dc2626"><?= $cancelCount ?> orders</div>
    </div>
  </div>

  <!-- Per-Order Changes -->
  <?php foreach ($groupedPicks as $no => $orderPicks): ?>
  <?php
    $decision = $decisions[$no] ?? 'cancel';
    $firstPick = $orderPicks[0] ?? [];
    $dest = $firstPick['destination'] ?? '';
    $destLoc = $firstPick['ship_to_location'] ?? '';
    $shipmentNo = $firstPick['shipment_no'] ?? '';
    $orderQty = array_sum(array_column($orderPicks, 'quantity'));
  ?>

  <div class="print-page">
    <!-- Order header -->
    <div class="order-page-header">
      <div>
        <span>Order: <?= htmlspecialchars($no) ?></span>
        <?php if ($shipmentNo): ?>
          <span style="font-weight:400;font-size:13px;color:#64748b;margin-left:8px">Shipment: <?= htmlspecialchars($shipmentNo) ?></span>
        <?php endif; ?>
        <?php if ($dest): ?>
          <span style="font-weight:400;font-size:13px;color:#64748b;margin-left:8px"><?= htmlspecialchars($dest) ?></span>
        <?php endif; ?>
        <?php if ($destLoc): ?>
          <span style="font-weight:400;font-size:13px;color:#94a3b8;margin-left:4px">(<?= htmlspecialchars($destLoc) ?>)</span>
        <?php endif; ?>
      </div>
      <?php
        $badgeClass = $decision === 'confirm' ? 'badge-confirm' : ($decision === 'stage' ? 'badge-stage' : 'badge-cancel');
        $badgeLabel = $decision === 'confirm' ? '✓ CONFIRMED' : ($decision === 'stage' ? '↻ RESCHEDULED' : '✗ CANCELLED');
      ?>
      <span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
    </div>

    <?php if ($decision === 'cancel'): ?>
    <!-- Cancelled — no changes -->
    <div style="padding:20px;text-align:center;color:#9ca3af;font-size:13px;border:2px dashed #e5e7eb;border-radius:8px;margin:12px 0">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 8px;display:block;opacity:0.4"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
      Order dibatalkan — tidak ada perubahan WMS
      <div style="font-size:11px;margin-top:4px;color:#d1d5db"><?= $orderPicks[0]['item_code'] ?? '' ?> × <?= number_format($orderQty) ?> qty tidak diproses</div>
    </div>

    <?php else: ?>
    <!-- Changes table -->
    <table>
      <thead>
        <tr>
          <th class="c" style="width:24px">No.</th>
          <th>Item Code</th>
          <th>Source Lokasi</th>
          <th class="r" style="width:50px">Qty</th>
          <th>Batch</th>
          <?php if ($decision === 'stage'): ?>
          <th>→ STAGING</th>
          <?php endif; ?>
          <th>Effect</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orderPicks as $idx => $pick): ?>
      <tr>
        <td class="c" style="color:#94a3b8;font-size:13px"><?= $idx + 1 ?></td>
        <td style="font-family:'SF Mono',Consolas,monospace;font-size:13px;font-weight:700;color:#0f172a"><?= htmlspecialchars($pick['item_code'] ?? '—') ?></td>
        <td><span class="chip chip-location"><?= htmlspecialchars($pick['location'] ?? '—') ?></span></td>
        <td class="r" style="font-weight:700"><?= number_format((float)($pick['quantity'] ?? 0), 0) ?></td>
        <td>
          <?php $bn = $pick['batch_number'] ?? null; ?>
          <?php if ($bn): ?>
          <span style="font-family:'SF Mono',Consolas,monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px"><?= htmlspecialchars($bn) ?></span>
          <?php else: ?>
          <span style="color:#cbd5e1;font-size:13px">—</span>
          <?php endif; ?>
        </td>
        <?php if ($decision === 'stage'): ?>
        <td><span class="chip chip-staging">STAGING</span></td>
        <?php endif; ?>
        <td>
          <?php if ($decision === 'confirm'): ?>
          <span class="chip chip-decrement">−<?= number_format((float)($pick['quantity'] ?? 0), 0) ?> <?= htmlspecialchars($pick['location'] ?? '') ?></span>
          <?php elseif ($decision === 'stage'): ?>
          <span class="chip chip-decrement">−<?= number_format((float)($pick['quantity'] ?? 0), 0) ?> <?= htmlspecialchars($pick['location'] ?? '') ?></span>
          <span style="color:#94a3b8;margin:0 2px">→</span>
          <span class="chip chip-increment">+<?= number_format((float)($pick['quantity'] ?? 0), 0) ?> STAGING</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="text-align:right;padding:8px 10px;font-size:11px;font-weight:700;color:#013d3c;border-top:2px solid #013d3c;background:#f1f5f9">TOTAL QTY — Order <?= htmlspecialchars($no) ?></td>
          <td style="padding:8px 10px;font-size:13px;font-weight:800;color:#013d3c;border-top:2px solid #013d3c;background:#f1f5f9;text-align:right"><?= number_format((float)$orderQty, 0) ?></td>
          <td colspan="<?= $decision === 'stage' ? 3 : 2 ?>" style="border-top:2px solid #013d3c;background:#f1f5f9"></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>

  </div>
  <?php endforeach; ?>

  <!-- Staged / Cancelled orders — summary only (not in picklist) -->
  <?php if (!empty($nonConfirmedOrders)): ?>
  <div style="margin-top:20px;page-break-before:always">
    <div class="section-title" style="color:#6b7280">Not in Picklist — <?= count($nonConfirmedOrders) ?> orders</div>
    <table style="page-break-inside:avoid">
      <thead>
        <tr>
          <th class="c" style="width:24px">No.</th>
          <th>Order No</th>
          <th>Status</th>
          <th>Item Types</th>
          <th class="r" style="width:50px">Total Qty</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
      <?php $idx = 0; foreach ($nonConfirmedOrders as $orderNo => $info): $idx++; ?>
      <tr>
        <td class="c" style="color:#94a3b8;font-size:13px"><?= $idx ?></td>
        <td style="font-family:'SF Mono',Consolas,monospace;font-size:13px;font-weight:700;color:#0f172a"><?= htmlspecialchars($orderNo) ?></td>
        <td>
          <?php if ($info['decision'] === 'stage'): ?>
          <span class="chip chip-staging">↻ Rescheduled</span>
          <?php else: ?>
          <span class="chip chip-cancel">✗ Cancelled</span>
          <?php endif; ?>
        </td>
        <td style="font-size:13px;color:#64748b"><?= $info['items'] ?></td>
        <td class="r" style="font-weight:700"><?= number_format((float)$info['qty'], 0) ?></td>
        <td style="font-size:12px;color:#9ca3af">
          <?= $info['decision'] === 'stage' ? 'Picks moved to STAGING — not in picklist' : 'Order dibatalkan — tidak ada perubahan' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Replenishments -->
  <?php if (!empty($replenishments)): ?>
  <div style="margin-top:20px;page-break-before:always">
    <div class="section-title" style="color:#f59e0b">Replenishment (<?= count($replenishments) ?> tasks — already applied)</div>
    <table style="page-break-inside:avoid">
      <thead>
        <tr>
          <th class="c" style="width:24px">No.</th>
          <th>Item Code</th>
          <th>From Location</th>
          <th>To Location</th>
          <th class="r" style="width:50px">Qty</th>
          <th>Effect</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($replenishments as $idx => $rep): ?>
      <tr>
        <td class="c" style="color:#94a3b8;font-size:13px"><?= $idx + 1 ?></td>
        <td style="font-family:'SF Mono',Consolas,monospace;font-size:13px;font-weight:600;color:#0f172a"><?= htmlspecialchars($rep['item_code'] ?? '—') ?></td>
        <td><span class="chip chip-location"><?= htmlspecialchars($rep['from_location'] ?? '—') ?></span></td>
        <td><span class="chip chip-location"><?= htmlspecialchars($rep['to_location'] ?? '—') ?></span></td>
        <td class="r" style="font-weight:700"><?= number_format((float)($rep['quantity'] ?? 0), 0) ?></td>
        <td>
          <span class="chip chip-decrement">−<?= number_format((float)($rep['quantity'] ?? 0), 0) ?> <?= htmlspecialchars($rep['from_location'] ?? '') ?></span>
          <span style="color:#94a3b8;margin:0 2px">→</span>
          <span class="chip chip-increment">+<?= number_format((float)($rep['quantity'] ?? 0), 0) ?> <?= htmlspecialchars($rep['to_location'] ?? '') ?></span>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="doc-footer">
    <span>K-one Allocator</span>
    <span>Dicetak: <?= date('d F Y H:i') ?> WIB</span>
    <span><?= number_format($confirmedQty) ?> confirmed qty / <?= $confirmCount ?> confirmed / <?= $stageCount ?> rescheduled / <?= $cancelCount ?> cancelled</span>
  </div>

</div>

</body>
</html>
