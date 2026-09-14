<?php
/**
 * Order Confirmation Page
 * Standalone page for confirming/staging/cancelling orders after allocation.
 * Loads allocation result by ?id= parameter.
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
$summary = $result['summary'] ?? [];
$createdAt = $result['created_at'] ?? date('Y-m-d H:i:s');
$totalPicks = count($picks);
$totalReplenishments = count($replenishments);
$totalQty = array_sum(array_column($picks, 'quantity'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Order — Allocator</title>
    <link rel="stylesheet" href="assets/css/wms-style.css">
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }

        @keyframes svg-spin { to { transform: rotate(360deg); } }
        .svg-spin { animation: svg-spin 1s linear infinite; }

        .alloc-container {
            max-width: 800px;
            margin: 24px auto;
            padding: 0 16px;
        }

        /* ── Tally badges ── */
        .order-confirm-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }
        .order-confirm-tally {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .tally-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 700;
        }
        .tally-badge--confirm { background: #dcfce7; color: #166534; }
        .tally-badge--stage { background: #fef9c3; color: #854d0e; }
        .tally-badge--cancel { background: #fee2e2; color: #991b1b; }
        .tally-badge--pending { background: var(--wms-light); color: var(--wms-gray-400); }

        /* ── Order cards ── */
        .order-card {
            background: var(--wms-gray-50);
            border: 1px solid var(--wms-gray-200);
            border-radius: var(--radius-sm);
            padding: 16px;
            margin-bottom: 10px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .order-card.is-confirm { border-left: 4px solid #16a34a; }
        .order-card.is-stage { border-left: 4px solid #ca8a04; }
        .order-card.is-cancel { border-left: 4px solid #dc2626; opacity: 0.7; }

        .order-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        .order-card-info h3 {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--wms-heading);
            margin: 0 0 2px;
        }
        .order-card-info p {
            font-size: 0.8rem;
            color: var(--wms-gray-400);
            margin: 0;
        }

        .order-card-picks {
            font-size: 0.78rem;
            color: var(--wms-gray-400);
            margin-bottom: 10px;
            max-height: 80px;
            overflow-y: auto;
            line-height: 1.6;
        }

        .order-card-btns {
            display: flex;
            gap: 8px;
        }
        .order-card-btns button {
            flex: 1;
            padding: 8px 10px;
            border: 2px solid transparent;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .btn-confirm {
            background: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }
        .btn-confirm:hover { background: #dcfce7; border-color: #16a34a; }
        .btn-confirm.active { background: #16a34a; color: #fff; border-color: #16a34a; }

        .btn-stage {
            background: #fefce8;
            color: #854d0e;
            border-color: #fde68a;
        }
        .btn-stage:hover { background: #fef9c3; border-color: #ca8a04; }
        .btn-stage.active { background: #ca8a04; color: #fff; border-color: #ca8a04; }

        .btn-cancel {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        .btn-cancel:hover { background: #fee2e2; border-color: #dc2626; }
        .btn-cancel.active { background: #dc2626; color: #fff; border-color: #dc2626; }

        .order-card.is-cancel .order-card-picks { text-decoration: line-through; }

        /* ── Apply button ── */
        .apply-decisions-btn {
            width: 100%;
            margin-top: 16px;
            padding: 14px;
            font-size: 1rem;
        }
        .apply-decisions-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* ── Confirm All shortcut ── */
        .confirm-all-btn {
            padding: 6px 14px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid var(--wms-gray-200);
            border-radius: var(--radius-sm);
            background: #fff;
            color: var(--wms-primary);
            cursor: pointer;
            transition: all 0.15s;
        }
        .confirm-all-btn:hover {
            background: var(--wms-light);
            border-color: var(--wms-primary);
        }

        /* ── Final results ── */
        .alloc-stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .alloc-stat {
            text-align: center;
            padding: 16px 8px;
            border-radius: var(--radius-sm);
        }
        .alloc-stat .num {
            font-size: 1.6rem;
            font-weight: 800;
            line-height: 1;
        }
        .alloc-stat .lbl {
            font-size: 0.75rem;
            color: var(--wms-gray-400);
            margin-top: 4px;
        }

        .final-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }
        .final-actions a,
        .final-actions button {
            flex: 1;
            padding: 12px;
            text-align: center;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .wms-btn { display: inline-flex; align-items: center; gap: 6px; }
        .btn-icon { display: inline-flex; }

        .alloc-error {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            background: #fee2e2;
            color: #991b1b;
            border-radius: var(--radius-sm);
            font-size: 0.82rem;
            margin-bottom: 8px;
        }

        /* ── Summary section ── */
        .summary-row {
            display: flex;
            gap: 20px;
            font-size: 0.82rem;
            color: var(--wms-gray-600);
            margin-bottom: 16px;
            padding: 10px 14px;
            background: var(--wms-gray-50);
            border-radius: var(--radius-sm);
        }
        .summary-row span {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .summary-row strong { color: var(--wms-heading); }

        @media (max-width: 600px) {
            .alloc-stat-grid { grid-template-columns: repeat(2, 1fr); }
            .order-card-btns { flex-direction: column; }
            .final-actions { flex-direction: column; }
        }
    </style>
</head>
<body style="background:var(--wms-gray-100)">

<div class="alloc-container">

    <!-- Header -->
    <div class="wms-banner">
        <div style="display:flex;align-items:center;justify-content:space-between;position:relative">
            <div>
                <h1>
                    <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-0.12em"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    Konfirmasi Order
                </h1>
                <p><?= $summary['total_orders'] ?? 0 ?> orders · <?= $totalPicks ?> picks · <?= number_format($totalQty) ?> total qty</p>
            </div>
            <a href="index.php" style="background:rgba(255,255,255,.15);color:#fff;padding:8px 16px;border-radius:var(--radius-sm);text-decoration:none;font-size:0.8rem;font-weight:600;border:1px solid rgba(255,255,255,.15);white-space:nowrap">
                ← Back
            </a>
        </div>
    </div>

    <!-- Summary row -->
    <div class="summary-row">
        <span>Created: <strong><?= date('d/m/Y H:i', strtotime($createdAt)) ?></strong></span>
        <span>Picks: <strong><?= $totalPicks ?></strong></span>
        <span>Replenishments: <strong><?= $totalReplenishments ?></strong></span>
    </div>

    <!-- Confirmation section -->
    <div class="wms-card">
        <div class="wms-card-header">
            <h2>
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                Keputusan Order
            </h2>
            <button class="confirm-all-btn" onclick="confirmAll()">✓ Confirm All</button>
        </div>
        <div class="wms-card-body">
            <div class="order-confirm-header">
                <div class="order-confirm-tally">
                    <span class="tally-badge tally-badge--confirm" id="tallyConfirm">✓ 0 Confirmed</span>
                    <span class="tally-badge tally-badge--stage" id="tallyStage">📦 0 Staged</span>
                    <span class="tally-badge tally-badge--cancel" id="tallyCancel">✗ 0 Cancelled</span>
                    <span class="tally-badge tally-badge--pending" id="tallyPending">⏳ <?= count(array_unique(array_column($picks, 'order_no'))) ?> Pending</span>
                </div>
            </div>
            <div id="orderCards"></div>
            <button id="applyDecisionsBtn" class="wms-btn wms-btn-success apply-decisions-btn" disabled onclick="applyDecisions()">
                <span class="btn-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg></span>
                Apply Semua ke WMS
            </button>
        </div>
    </div>

    <!-- Final results (hidden initially) -->
    <div id="resultsSection" class="wms-card" style="margin-top:20px;display:none">
        <div class="wms-card-header">
            <h2>
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                Hasil
            </h2>
        </div>
        <div class="wms-card-body">
            <div class="alloc-stat-grid" id="statsCards"></div>
            <div class="final-actions">
                <a id="downloadWmsBtn" class="wms-btn wms-btn-success" href="#" style="display:none">
                    <span class="btn-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg></span>
                    Download Final WMS
                </a>
                <a id="previewBtn" class="wms-btn" href="#" style="background:var(--wms-primary);color:#fff">
                    <span class="btn-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>
                    Lihat Perubahan
                </a>
                <a id="printBtn" class="wms-btn wms-btn-ghost" href="print_picklist.php?id=<?= htmlspecialchars($resultId) ?>" style="display:none">
                    <span class="btn-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg></span>
                    Print Picklist
                </a>
                <a id="downloadPicklistBtn" class="wms-btn wms-btn-excel" href="#" style="display:none">
                    <span class="btn-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                    Download Picklist
                </a>
            </div>
        </div>
    </div>

    <!-- Errors (hidden initially) -->
    <div id="errorsSection" class="wms-card" style="margin-top:20px;display:none">
        <div class="wms-card-header" style="border-bottom-color:#fecaca">
            <h2 style="color:#991b1b">
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                Errors
            </h2>
        </div>
        <div class="wms-card-body" id="errorsList"></div>
    </div>

</div>

<script>
const RESULT_ID = <?= json_encode($resultId) ?>;
const ALLOCATION_DATA = <?= json_encode([
    'result_id' => $resultId,
    'picks'     => $picks,
    'replenishments' => $replenishments,
    'summary'   => $summary,
    'picklist_file' => $result['picklist_file'] ?? null,
    'order_meta' => $result['order_meta'] ?? null,
]) ?>;

let allocationData = ALLOCATION_DATA;
let orderDecisions = {};

/* ── Render order cards ── */
function renderOrderCards(picks) {
    const byOrder = {};
    picks.forEach(p => {
        const key = p.order_no || 'unknown';
        if (!byOrder[key]) byOrder[key] = { picks: [], totalQty: 0, items: new Set() };
        byOrder[key].picks.push(p);
        byOrder[key].totalQty += parseFloat(p.quantity || 0);
        byOrder[key].items.add(p.item_code);
    });

    const orderNos = Object.keys(byOrder).sort();
    orderDecisions = {};

    let html = '';
    orderNos.forEach((orderNo) => {
        const o = byOrder[orderNo];
        orderDecisions[orderNo] = null; // undecided

        const pickLines = o.picks.map(p =>
            `${p.location} → ${p.item_code} × ${p.quantity}` + (p.batch_number ? ` (${p.batch_number})` : '')
        ).join('<br>');

        html += `
            <div class="order-card" id="card-${orderNo}">
                <div class="order-card-top">
                    <div class="order-card-info">
                        <h3>Order ${orderNo}</h3>
                        <p>${o.items.size} item types · ${o.picks.length} pick lines</p>
                    </div>
                    <div style="font-size:1.2rem;font-weight:800;color:var(--wms-primary)">${Math.round(o.totalQty)} <span style="font-size:0.7rem;font-weight:400;color:var(--wms-gray-400)">qty</span></div>
                </div>
                <div class="order-card-picks">${pickLines}</div>
                <div class="order-card-btns">
                    <button class="btn-confirm" onclick="setDecision('${orderNo}','confirm')">✓ Confirm</button>
                    <button class="btn-stage" onclick="setDecision('${orderNo}','stage')">📦 Stage</button>
                    <button class="btn-cancel" onclick="setDecision('${orderNo}','cancel')">✗ Cancel</button>
                </div>
            </div>
        `;
    });

    document.getElementById('orderCards').innerHTML = html;
    updateTally();
}

/* ── Set decision ── */
function setDecision(orderNo, decision) {
    orderDecisions[orderNo] = decision;

    const card = document.getElementById('card-' + orderNo);
    card.className = 'order-card is-' + decision;

    card.querySelectorAll('.order-card-btns button').forEach(btn => btn.classList.remove('active'));
    const activeBtn = decision === 'confirm' ? '.btn-confirm' : decision === 'stage' ? '.btn-stage' : '.btn-cancel';
    card.querySelector(activeBtn).classList.add('active');

    updateTally();
}

/* ── Confirm all ── */
function confirmAll() {
    Object.keys(orderDecisions).forEach(orderNo => setDecision(orderNo, 'confirm'));
}

/* ── Update tally ── */
function updateTally() {
    let confirm = 0, stage = 0, cancel = 0, pending = 0;
    Object.values(orderDecisions).forEach(d => {
        if (d === 'confirm') confirm++;
        else if (d === 'stage') stage++;
        else if (d === 'cancel') cancel++;
        else pending++;
    });

    document.getElementById('tallyConfirm').textContent = '✓ ' + confirm + ' Confirmed';
    document.getElementById('tallyStage').textContent = '📦 ' + stage + ' Staged';
    document.getElementById('tallyCancel').textContent = '✗ ' + cancel + ' Cancelled';
    document.getElementById('tallyPending').textContent = '⏳ ' + pending + ' Pending';

    document.getElementById('applyDecisionsBtn').disabled = (pending > 0);
}

/* ── Apply decisions to WMS ── */
async function applyDecisions() {
    if (!allocationData || !allocationData.result_id) return;

    const btn = document.getElementById('applyDecisionsBtn');
    const btnText = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('is-loading');
    btn.innerHTML = '<svg class="svg-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Applying to WMS...';

    const fd = new FormData();
    fd.append('action', 'apply_order_decisions');
    fd.append('result_id', allocationData.result_id);
    fd.append('decisions', JSON.stringify(orderDecisions));

    try {
        const resp = await fetch('api.php', { method: 'POST', body: fd });
        const data = await resp.json();

        btn.classList.remove('is-loading');
        btn.disabled = false;

        if (data.success) {
            // Show final results
            document.querySelector('.wms-card').style.display = 'none';
            document.getElementById('resultsSection').style.display = 'block';

            const s = allocationData.summary;
            document.getElementById('statsCards').innerHTML = `
                <div class="alloc-stat" style="background:#dcfce7"><div class="num" style="color:#16a34a">${data.confirmed}</div><div class="lbl">Confirmed</div></div>
                <div class="alloc-stat" style="background:#fef9c3"><div class="num" style="color:#ca8a04">${data.staged}</div><div class="lbl">Staged</div></div>
                <div class="alloc-stat" style="background:#fee2e2"><div class="num" style="color:#dc2626">${data.cancelled}</div><div class="lbl">Cancelled</div></div>
                <div class="alloc-stat" style="background:var(--wms-light)"><div class="num" style="color:var(--wms-primary)">${s.total_items}</div><div class="lbl">Total Items</div></div>
            `;

            // Setup action buttons
            document.getElementById('previewBtn').href = 'preview_wms_changes.php?id=' + allocationData.result_id;

            if (allocationData.picklist_file) {
                document.getElementById('printBtn').style.display = '';
                document.getElementById('printBtn').href = 'print_picklist.php?id=' + allocationData.result_id;
                document.getElementById('downloadPicklistBtn').style.display = '';
                document.getElementById('downloadPicklistBtn').href = 'download.php?file=' + encodeURIComponent(allocationData.picklist_file) + '&filename=picklist';
            }
        } else {
            alert('Error: ' + (data.message || 'Gagal apply decisions'));
            btn.innerHTML = btnText;
        }
    } catch (err) {
        btn.classList.remove('is-loading');
        btn.disabled = false;
        btn.innerHTML = btnText;
        alert('Error: ' + err.message);
    }
}

// Init
renderOrderCards(ALLOCATION_DATA.picks);

<?php if (!empty($result['errors'])): ?>
document.getElementById('errorsSection').style.display = 'block';
document.getElementById('errorsList').innerHTML = <?= json_encode(array_map(function($e) {
    return '<div class="alloc-error"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>' . htmlspecialchars($e) . '</div>';
}, $result['errors'])) ?>;
<?php endif; ?>
</script>
</body>
</html>
