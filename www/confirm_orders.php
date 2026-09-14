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
$orderCount = count(array_unique(array_column($picks, 'order_no')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Order — Allocator</title>
    <link rel="stylesheet" href="assets/css/wms-style.css">
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: var(--wms-gray-100); }

        @keyframes svg-spin { to { transform: rotate(360deg); } }
        .svg-spin { animation: svg-spin 1s linear infinite; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .6; } }

        .alloc-container { max-width: 860px; margin: 24px auto; padding: 0 16px; }

        /* ── Progress bar ── */
        .progress-section { background: #fff; border-radius: var(--radius-md); padding: 16px 20px; margin-bottom: 16px; box-shadow: var(--shadow-sm); border: 1px solid var(--wms-gray-200); }
        .progress-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .progress-header .label { font-size: 0.82rem; font-weight: 600; color: var(--wms-gray-600); }
        .progress-header .count { font-size: 0.82rem; font-weight: 700; color: var(--wms-primary); }
        .progress-track { height: 6px; background: var(--wms-gray-200); border-radius: 3px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, var(--wms-primary), #0ea5e9); border-radius: 3px; transition: width 0.4s cubic-bezier(.4,0,.2,1); width: 0%; }

        /* ── Tally chips ── */
        .tally-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .tally-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; border: 1px solid transparent; transition: all 0.2s; }
        .tally-chip .dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
        .tally-chip--confirm { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
        .tally-chip--confirm .dot { background: #22c55e; }
        .tally-chip--stage { background: #fefce8; color: #854d0e; border-color: #fde68a; }
        .tally-chip--stage .dot { background: #eab308; }
        .tally-chip--cancel { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .tally-chip--cancel .dot { background: #ef4444; }
        .tally-chip--pending { background: var(--wms-gray-50); color: var(--wms-gray-400); border-color: var(--wms-gray-200); }
        .tally-chip--pending .dot { background: var(--wms-gray-400); animation: pulse 1.5s infinite; }

        /* ── Order cards ── */
        .order-card { background: #fff; border: 1px solid var(--wms-gray-200); border-radius: var(--radius-md); padding: 0; margin-bottom: 10px; box-shadow: var(--shadow-sm); transition: box-shadow 0.2s, transform 0.15s, border-color 0.2s; overflow: hidden; animation: fadeInUp 0.3s ease both; }
        .order-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .order-card.is-confirm { border-color: #22c55e; border-left: 4px solid #22c55e; }
        .order-card.is-stage { border-color: #eab308; border-left: 4px solid #eab308; }
        .order-card.is-cancel { border-color: #ef4444; border-left: 4px solid #ef4444; opacity: 0.7; }
        .order-card.is-cancel:hover { opacity: 0.85; }

        .card-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px 10px; }
        .card-identity { display: flex; align-items: center; gap: 10px; }
        .card-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: var(--wms-light); color: var(--wms-primary); flex-shrink: 0; }
        .card-title { font-size: 0.92rem; font-weight: 700; color: var(--wms-heading); margin: 0; }
        .card-subtitle { font-size: 0.75rem; color: var(--wms-gray-400); margin: 1px 0 0; }
        .card-qty { display: flex; align-items: baseline; gap: 4px; }
        .card-qty-num { font-size: 1.3rem; font-weight: 800; color: var(--wms-primary); line-height: 1; }
        .card-qty-label { font-size: 0.68rem; font-weight: 500; color: var(--wms-gray-400); text-transform: uppercase; letter-spacing: 0.5px; }

        /* ── Pick lines ── */
        .card-picks { padding: 0 18px 12px; }
        .pick-line { display: flex; align-items: center; gap: 8px; padding: 5px 10px; font-size: 0.78rem; color: var(--wms-gray-600); background: var(--wms-gray-50); border-radius: 6px; margin-bottom: 4px; font-family: 'SF Mono', 'Cascadia Code', 'Consolas', monospace; line-height: 1.4; }
        .pick-line .loc { font-weight: 700; color: var(--wms-primary); min-width: 54px; }
        .pick-line .item { font-weight: 600; color: var(--wms-heading); }
        .pick-line .qty-badge { margin-left: auto; background: var(--wms-light); color: var(--wms-dark); padding: 1px 8px; border-radius: 10px; font-weight: 700; font-size: 0.72rem; flex-shrink: 0; }
        .pick-line .batch { color: var(--wms-gray-400); font-size: 0.72rem; }
        .order-card.is-cancel .pick-line { text-decoration: line-through; opacity: 0.5; }

        /* ── Decision buttons ── */
        .card-actions { display: flex; border-top: 1px solid var(--wms-gray-200); }
        .dec-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: none; background: transparent; color: var(--wms-gray-400); transition: all 0.15s; position: relative; }
        .dec-btn:not(:last-child)::after { content: ''; position: absolute; right: 0; top: 20%; height: 60%; width: 1px; background: var(--wms-gray-200); }
        .dec-btn:hover { background: var(--wms-gray-50); }
        .dec-btn .icon { font-size: 1rem; }
        .dec-btn--confirm:hover { color: #16a34a; background: #f0fdf4; }
        .dec-btn--confirm.active { color: #fff; background: linear-gradient(135deg, #22c55e, #16a34a); font-weight: 700; }
        .dec-btn--stage:hover { color: #ca8a04; background: #fefce8; }
        .dec-btn--stage.active { color: #fff; background: linear-gradient(135deg, #eab308, #ca8a04); font-weight: 700; }
        .dec-btn--cancel:hover { color: #dc2626; background: #fef2f2; }
        .dec-btn--cancel.active { color: #fff; background: linear-gradient(135deg, #ef4444, #dc2626); font-weight: 700; }

        /* ── Apply button ── */
        .apply-section { margin-top: 16px; }
        .apply-btn { width: 100%; padding: 14px 20px; font-size: 0.95rem; font-weight: 700; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; gap: 8px; border: none; cursor: pointer; transition: all 0.2s; background: linear-gradient(135deg, var(--wms-primary), #0ea5e9); color: #fff; box-shadow: 0 4px 14px rgba(2, 103, 102, 0.3); }
        .apply-btn:hover { box-shadow: 0 6px 20px rgba(2, 103, 102, 0.4); transform: translateY(-1px); }
        .apply-btn:active { transform: translateY(0); }
        .apply-btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; box-shadow: none; }

        /* ── Confirm All ── */
        .confirm-all-btn { padding: 5px 14px; font-size: 0.78rem; font-weight: 600; border: 1px solid var(--wms-gray-200); border-radius: 20px; background: #fff; color: var(--wms-primary); cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 5px; }
        .confirm-all-btn:hover { background: var(--wms-light); border-color: var(--wms-primary); }

        /* ── Final results ── */
        .alloc-stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
        .alloc-stat { text-align: center; padding: 16px 8px; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
        .alloc-stat .num { font-size: 1.6rem; font-weight: 800; line-height: 1; }
        .alloc-stat .lbl { font-size: 0.75rem; color: var(--wms-gray-400); margin-top: 4px; }

        .final-actions { display: flex; gap: 10px; margin-top: 16px; }
        .final-actions a, .final-actions button { flex: 1; padding: 12px; text-align: center; border-radius: var(--radius-md); font-size: 0.85rem; font-weight: 700; text-decoration: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.15s; border: none; }

        .wms-btn { display: inline-flex; align-items: center; gap: 6px; }
        .btn-icon { display: inline-flex; }

        .alloc-error { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #fee2e2; color: #991b1b; border-radius: var(--radius-sm); font-size: 0.82rem; margin-bottom: 8px; }

        /* ── Summary row ── */
        .summary-row { display: flex; gap: 20px; font-size: 0.82rem; color: var(--wms-gray-600); margin-bottom: 16px; padding: 10px 14px; background: #fff; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); border: 1px solid var(--wms-gray-200); }
        .summary-row span { display: flex; align-items: center; gap: 4px; }
        .summary-row strong { color: var(--wms-heading); }

        @media (max-width: 600px) { .alloc-stat-grid { grid-template-columns: repeat(2, 1fr); } .card-actions { flex-direction: column; } .card-actions .dec-btn::after { display: none; } .final-actions { flex-direction: column; } }
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

    <!-- Progress bar -->
    <div class="progress-section">
        <div class="progress-header">
            <span class="label">Decision Progress</span>
            <span class="count" id="progressCount">0 / <?= $orderCount ?></span>
        </div>
        <div class="progress-track">
            <div class="progress-fill" id="progressFill"></div>
        </div>
    </div>

    <!-- Confirmation section -->
    <div class="wms-card">
        <div class="wms-card-header">
            <h2>
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                Keputusan Order
            </h2>
            <div class="tally-row">
                <span class="tally-chip tally-chip--pending" id="tallyPending"><span class="dot"></span><?= $orderCount ?> Pending</span>
                <span class="tally-chip tally-chip--confirm" id="tallyConfirm" style="display:none"><span class="dot"></span><span id="tallyConfirmNum">0</span> Confirm</span>
                <span class="tally-chip tally-chip--stage" id="tallyStage" style="display:none"><span class="dot"></span><span id="tallyStageNum">0</span> Stage</span>
                <span class="tally-chip tally-chip--cancel" id="tallyCancel" style="display:none"><span class="dot"></span><span id="tallyCancelNum">0</span> Cancel</span>
                <button class="confirm-all-btn" onclick="confirmAll()">✓ Confirm All</button>
            </div>
        </div>
        <div class="wms-card-body">
            <div id="orderCards"></div>
            <div class="apply-section">
                <button id="applyDecisionsBtn" class="apply-btn" disabled onclick="applyDecisions()">
                    <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                    Apply Semua ke WMS
                </button>
            </div>
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
const TOTAL_ORDERS = <?= $orderCount ?>;

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
    orderNos.forEach((orderNo, i) => {
        const o = byOrder[orderNo];
        orderDecisions[orderNo] = null;

        const pickLines = o.picks.map(p => {
            const batch = p.batch_number ? `<span class="batch">(${p.batch_number})</span>` : '';
            return `<div class="pick-line">
                <span class="loc">${p.location}</span>
                <span class="item">${p.item_code}</span>
                ${batch}
                <span class="qty-badge">&times;${p.quantity}</span>
            </div>`;
        }).join('');

        html += `
            <div class="order-card" id="card-${orderNo}" style="animation-delay:${i * 0.04}s">
                <div class="card-top">
                    <div class="card-identity">
                        <div class="card-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                        </div>
                        <div>
                            <div class="card-title">Order ${orderNo}</div>
                            <div class="card-subtitle">${o.items.size} item types &middot; ${o.picks.length} pick lines</div>
                        </div>
                    </div>
                    <div class="card-qty">
                        <span class="card-qty-num">${Math.round(o.totalQty)}</span>
                        <span class="card-qty-label">qty</span>
                    </div>
                </div>
                <div class="card-picks">${pickLines}</div>
                <div class="card-actions">
                    <button class="dec-btn dec-btn--confirm" onclick="setDecision('${orderNo}','confirm')">
                        <span class="icon">&#10003;</span> Confirm
                    </button>
                    <button class="dec-btn dec-btn--stage" onclick="setDecision('${orderNo}','stage')">
                        <span class="icon">&#128230;</span> Stage
                    </button>
                    <button class="dec-btn dec-btn--cancel" onclick="setDecision('${orderNo}','cancel')">
                        <span class="icon">&#10007;</span> Cancel
                    </button>
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

    card.querySelectorAll('.dec-btn').forEach(btn => btn.classList.remove('active'));
    const activeBtn = card.querySelector('.dec-btn--' + decision);
    if (activeBtn) activeBtn.classList.add('active');

    updateTally();
}

/* ── Confirm all ── */
function confirmAll() {
    Object.keys(orderDecisions).forEach(orderNo => setDecision(orderNo, 'confirm'));
}

/* ── Update tally + progress ── */
function updateTally() {
    let confirm = 0, stage = 0, cancel = 0, pending = 0;
    Object.values(orderDecisions).forEach(d => {
        if (d === 'confirm') confirm++;
        else if (d === 'stage') stage++;
        else if (d === 'cancel') cancel++;
        else pending++;
    });

    const decided = confirm + stage + cancel;

    // Show/hide tally chips based on count
    const chips = {
        confirm: { el: 'tallyConfirm', numEl: 'tallyConfirmNum', count: confirm },
        stage:   { el: 'tallyStage',   numEl: 'tallyStageNum',   count: stage },
        cancel:  { el: 'tallyCancel',  numEl: 'tallyCancelNum',  count: cancel },
    };
    Object.values(chips).forEach(c => {
        const el = document.getElementById(c.el);
        if (c.count > 0) {
            el.style.display = '';
            document.getElementById(c.numEl).textContent = c.count;
        } else {
            el.style.display = 'none';
        }
    });

    // Pending chip always visible
    document.getElementById('tallyPending').innerHTML = '<span class="dot"></span>' + pending + ' Pending';

    // Progress bar
    const pct = TOTAL_ORDERS > 0 ? (decided / TOTAL_ORDERS) * 100 : 0;
    document.getElementById('progressFill').style.width = pct + '%';
    document.getElementById('progressCount').textContent = decided + ' / ' + TOTAL_ORDERS;

    // Apply button
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
            // Hide confirmation UI, show results
            document.querySelector('.wms-card').style.display = 'none';
            document.querySelector('.progress-section').style.display = 'none';
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
            btn.innerHTML = 'Apply Semua ke WMS';
        }
    } catch (err) {
        btn.classList.remove('is-loading');
        btn.disabled = false;
        btn.innerHTML = 'Apply Semua ke WMS';
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
