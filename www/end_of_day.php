<?php
/**
 * End of Day Page
 * Shows only confirmed orders with Reschedule/Cancel buttons for end-of-shift processing.
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
$decisions = $result['decisions'] ?? [];
$summary = $result['summary'] ?? [];
$createdAt = $result['created_at'] ?? date('Y-m-d H:i:s');

// Filter picks to only those with decision === 'confirm'
$confirmedPicks = array_values(array_filter($picks, function($pick) use ($decisions) {
    $no = $pick['no'] ?: ($pick['order_no'] ?? '');
    return ($decisions[$no] ?? null) === 'confirm';
}));

// Group by order 'no'
$grouped = [];
foreach ($confirmedPicks as $pick) {
    $no = $pick['no'] ?: ($pick['order_no'] ?? 'Unknown');
    if (!isset($grouped[$no])) {
        $grouped[$no] = [
            'picks' => [],
            'totalQty' => 0,
            'items' => new \SplObjectStorage(),
            'itemCodes' => [],
            'shipmentNo' => '',
            'destination' => '',
            'shipToLocation' => '',
        ];
    }
    $grouped[$no]['picks'][] = $pick;
    $grouped[$no]['totalQty'] += floatval($pick['quantity'] ?? 0);
    $grouped[$no]['itemCodes'][$pick['item_code']] = true;
    if (empty($grouped[$no]['shipmentNo'])) $grouped[$no]['shipmentNo'] = $pick['shipment_no'] ?? '';
    if (empty($grouped[$no]['destination'])) $grouped[$no]['destination'] = $pick['destination'] ?? '';
    if (empty($grouped[$no]['shipToLocation'])) $grouped[$no]['shipToLocation'] = $pick['ship_to_location'] ?? '';
}

$confirmedOrderCount = count($grouped);
$totalConfirmedQty = array_sum(array_column($confirmedPicks, 'quantity'));
$stagedCount = 0;
$cancelledCount = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akhir Hari — Allocator</title>
    <link rel="stylesheet" href="assets/css/wms-style.css">
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: var(--wms-gray-100); }

        @keyframes svg-spin { to { transform: rotate(360deg); } }
        .svg-spin { animation: svg-spin 1s linear infinite; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .6; } }
        @keyframes slideOut { to { opacity: 0; transform: translateX(40px); max-height: 0; padding: 0; margin: 0; } }

        .alloc-container { max-width: 860px; margin: 24px auto; padding: 0 16px; }

        /* ── Summary stats ── */
        .eod-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
        .eod-stat { text-align: center; padding: 16px 8px; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); border: 1px solid var(--wms-gray-200); background: #fff; }
        .eod-stat .num { font-size: 1.8rem; font-weight: 800; line-height: 1; }
        .eod-stat .lbl { font-size: 0.75rem; color: var(--wms-gray-400); margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .eod-stat--confirmed .num { color: var(--wms-primary); }
        .eod-stat--staged .num { color: #ca8a04; }
        .eod-stat--cancelled .num { color: #dc2626; }

        /* ── Order cards ── */
        .order-card { background: #fff; border: 1px solid var(--wms-gray-200); border-left: 4px solid #22c55e; border-radius: var(--radius-md); padding: 0; margin-bottom: 10px; box-shadow: var(--shadow-sm); transition: box-shadow 0.2s, transform 0.15s, border-color 0.2s; overflow: hidden; animation: fadeInUp 0.3s ease both; }
        .order-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .order-card.is-stage { border-color: #eab308; border-left: 4px solid #eab308; }
        .order-card.is-cancel { border-color: #ef4444; border-left: 4px solid #ef4444; opacity: 0.7; }
        .order-card.is-removed { animation: slideOut 0.35s ease forwards; pointer-events: none; }

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

        /* ── Action buttons ── */
        .card-actions { display: flex; border-top: 1px solid var(--wms-gray-200); }
        .eod-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 8px; font-size: 0.82rem; font-weight: 600; cursor: pointer; border: none; background: transparent; transition: all 0.15s; position: relative; }
        .eod-btn:not(:last-child)::after { content: ''; position: absolute; right: 0; top: 20%; height: 60%; width: 1px; background: var(--wms-gray-200); }
        .eod-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .eod-btn .icon { font-size: 1rem; }

        .eod-btn--stage { color: var(--wms-gray-400); }
        .eod-btn--stage:hover:not(:disabled) { color: #ca8a04; background: #fefce8; }
        .eod-btn--stage.active { color: #fff; background: linear-gradient(135deg, #eab308, #ca8a04); font-weight: 700; }

        .eod-btn--cancel { color: var(--wms-gray-400); }
        .eod-btn--cancel:hover:not(:disabled) { color: #dc2626; background: #fef2f2; }
        .eod-btn--cancel.active { color: #fff; background: linear-gradient(135deg, #ef4444, #dc2626); font-weight: 700; }

        /* ── Summary row ── */
        .summary-row { display: flex; gap: 20px; font-size: 0.82rem; color: var(--wms-gray-600); margin-bottom: 16px; padding: 10px 14px; background: #fff; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); border: 1px solid var(--wms-gray-200); }
        .summary-row span { display: flex; align-items: center; gap: 4px; }
        .summary-row strong { color: var(--wms-heading); }

        /* ── Empty state ── */
        .empty-state { text-align: center; padding: 40px 20px; color: var(--wms-gray-400); }
        .empty-state .icon { font-size: 2.5rem; margin-bottom: 12px; }
        .empty-state p { font-size: 0.9rem; }

        /* ── Done state ── */
        .done-banner { background: linear-gradient(135deg, #22c55e, #16a34a); border-radius: var(--radius-lg); padding: 30px; color: #fff; text-align: center; margin-top: 20px; box-shadow: 0 4px 24px rgba(34,197,94,0.28); display: none; }
        .done-banner h2 { font-size: 1.3rem; margin: 0 0 6px; }
        .done-banner p { font-size: 0.9rem; opacity: 0.88; margin: 0; }
        .done-banner .done-stats { display: flex; gap: 24px; justify-content: center; margin-top: 16px; }
        .done-banner .done-stat { text-align: center; }
        .done-banner .done-stat .num { font-size: 1.6rem; font-weight: 800; }
        .done-banner .done-stat .lbl { font-size: 0.75rem; opacity: 0.8; }

        @media (max-width: 600px) { .eod-stats { grid-template-columns: repeat(3, 1fr); } .card-actions { flex-direction: column; } .card-actions .eod-btn::after { display: none; } }
    </style>
</head>
<body style="background:var(--wms-gray-100)">

<div class="alloc-container">

    <!-- Header -->
    <div class="wms-banner">
        <div style="display:flex;align-items:center;justify-content:space-between;position:relative">
            <div>
                <h1>
                    <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-0.12em"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Akhir Hari
                </h1>
                <p><?= $confirmedOrderCount ?> confirmed orders · <?= number_format($totalConfirmedQty) ?> total qty</p>
            </div>
            <a href="confirm_orders.php?id=<?= htmlspecialchars($resultId) ?>" style="background:rgba(255,255,255,.15);color:#fff;padding:8px 16px;border-radius:var(--radius-sm);text-decoration:none;font-size:0.8rem;font-weight:600;border:1px solid rgba(255,255,255,.15);white-space:nowrap">
                ← Konfirmasi
            </a>
        </div>
    </div>

    <!-- Summary row -->
    <div class="summary-row">
        <span>Created: <strong><?= date('d/m/Y H:i', strtotime($createdAt)) ?></strong></span>
        <span>Confirmed: <strong id="summaryConfirmed"><?= $confirmedOrderCount ?></strong></span>
        <span>Result ID: <strong><?= htmlspecialchars($resultId) ?></strong></span>
    </div>

    <!-- Stats -->
    <div class="eod-stats">
        <div class="eod-stat eod-stat--confirmed">
            <div class="num" id="statConfirmed"><?= $confirmedOrderCount ?></div>
            <div class="lbl">Confirmed</div>
        </div>
        <div class="eod-stat eod-stat--staged">
            <div class="num" id="statStaged"><?= $stagedCount ?></div>
            <div class="lbl">Rescheduled</div>
        </div>
        <div class="eod-stat eod-stat--cancelled">
            <div class="num" id="statCancelled"><?= $cancelledCount ?></div>
            <div class="lbl">Cancelled</div>
        </div>
    </div>

    <!-- Order cards -->
    <div class="wms-card">
        <div class="wms-card-header">
            <h2>
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                Order Tersisa
            </h2>
        </div>
        <div class="wms-card-body">
            <div id="orderCards"></div>
        </div>
    </div>

    <!-- Done banner -->
    <div class="done-banner" id="doneBanner">
        <h2>Semua Order Selesai</h2>
        <p>Semua confirmed order sudah di-reschedule atau di-cancel.</p>
        <div class="done-stats">
            <div class="done-stat"><div class="num" id="doneStaged">0</div><div class="lbl">Rescheduled</div></div>
            <div class="done-stat"><div class="num" id="doneCancelled">0</div><div class="lbl">Cancelled</div></div>
        </div>
    </div>

</div>

<script>
const RESULT_ID = <?= json_encode($resultId) ?>;
const CONFIRMED_ORDERS = <?= json_encode($grouped) ?>;

let stagedCount = 0;
let cancelledCount = 0;
const totalConfirmed = Object.keys(CONFIRMED_ORDERS).length;

/* ── Render order cards ── */
function renderOrderCards() {
    const nos = Object.keys(CONFIRMED_ORDERS).sort();
    let html = '';

    nos.forEach((no, i) => {
        const o = CONFIRMED_ORDERS[no];
        const pickCount = o.picks.length;
        const itemCount = Object.keys(o.itemCodes).length;

        const pickLines = o.picks.map(p => {
            const batch = p.batch_number ? `<span class="batch">(${p.batch_number})</span>` : '';
            return `<div class="pick-line">
                <span class="loc">${p.location}</span>
                <span class="item">${p.item_code}</span>
                ${batch}
                <span class="qty-badge">&times;${p.quantity}</span>
            </div>`;
        }).join('');

        const metaParts = [];
        if (o.shipmentNo) metaParts.push(`Shipment: ${o.shipmentNo}`);
        if (o.destination) metaParts.push(o.destination);
        if (o.shipToLocation) metaParts.push(`(${o.shipToLocation})`);
        const subtitle = metaParts.length > 0
            ? metaParts.join(' &middot; ')
            : `${itemCount} item types &middot; ${pickCount} pick lines`;

        html += `
            <div class="order-card" id="card-${no}" data-no="${no}" style="animation-delay:${i * 0.04}s">
                <div class="card-top">
                    <div class="card-identity">
                        <div class="card-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                        </div>
                        <div>
                            <div class="card-title">Order ${no}</div>
                            <div class="card-subtitle">${subtitle}</div>
                        </div>
                    </div>
                    <div class="card-qty">
                        <span class="card-qty-num">${Math.round(o.totalQty)}</span>
                        <span class="card-qty-label">qty</span>
                    </div>
                </div>
                <div class="card-picks">${pickLines}</div>
                <div class="card-actions">
                    <button class="eod-btn eod-btn--stage" onclick="stageOrder('${no}')">
                        <span class="icon">&#128230;</span> Reschedule
                    </button>
                    <button class="eod-btn eod-btn--cancel" onclick="cancelOrder('${no}')">
                        <span class="icon">&#10007;</span> Cancel
                    </button>
                </div>
            </div>
        `;
    });

    document.getElementById('orderCards').innerHTML = html || '<div class="empty-state"><div class="icon">&#10003;</div><p>Tidak ada confirmed order tersisa.</p></div>';
}

/* ── Stage a single order ── */
async function stageOrder(orderNo) {
    const card = document.getElementById('card-' + orderNo);
    if (!card) return;

    const btns = card.querySelectorAll('.eod-btn');
    btns.forEach(b => b.disabled = true);

    const stageBtn = card.querySelector('.eod-btn--stage');
    if (stageBtn) stageBtn.classList.add('active');
    card.classList.add('is-stage');

    try {
        const fd = new FormData();
        fd.append('action', 'apply_order_decisions');
        fd.append('result_id', RESULT_ID);
        fd.append('decisions', JSON.stringify({ [orderNo]: 'stage' }));

        const resp = await fetch('api.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.success) {
            stagedCount++;
            updateStats();
            removeCard(orderNo);
        } else {
            alert('Error: ' + (data.message || 'Gagal reschedule order'));
            btns.forEach(b => b.disabled = false);
            if (stageBtn) stageBtn.classList.remove('active');
            card.classList.remove('is-stage');
        }
    } catch (err) {
        alert('Error: ' + err.message);
        btns.forEach(b => b.disabled = false);
        if (stageBtn) stageBtn.classList.remove('active');
        card.classList.remove('is-stage');
    }
}

/* ── Cancel a single order ── */
async function cancelOrder(orderNo) {
    const card = document.getElementById('card-' + orderNo);
    if (!card) return;

    const btns = card.querySelectorAll('.eod-btn');
    btns.forEach(b => b.disabled = true);

    const cancelBtn = card.querySelector('.eod-btn--cancel');
    if (cancelBtn) cancelBtn.classList.add('active');
    card.classList.add('is-cancel');

    try {
        const fd = new FormData();
        fd.append('action', 'apply_order_decisions');
        fd.append('result_id', RESULT_ID);
        fd.append('decisions', JSON.stringify({ [orderNo]: 'cancel' }));

        const resp = await fetch('api.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.success) {
            cancelledCount++;
            updateStats();
            removeCard(orderNo);
        } else {
            alert('Error: ' + (data.message || 'Gagal cancel order'));
            btns.forEach(b => b.disabled = false);
            if (cancelBtn) cancelBtn.classList.remove('active');
            card.classList.remove('is-cancel');
        }
    } catch (err) {
        alert('Error: ' + err.message);
        btns.forEach(b => b.disabled = false);
        if (cancelBtn) cancelBtn.classList.remove('active');
        card.classList.remove('is-cancel');
    }
}

/* ── Remove card with animation ── */
function removeCard(orderNo) {
    const card = document.getElementById('card-' + orderNo);
    if (!card) return;
    card.classList.add('is-removed');
    setTimeout(() => {
        card.remove();
        checkDone();
    }, 350);
}

/* ── Update stats ── */
function updateStats() {
    const remaining = totalConfirmed - stagedCount - cancelledCount;
    document.getElementById('statConfirmed').textContent = remaining;
    document.getElementById('summaryConfirmed').textContent = remaining;
    document.getElementById('statStaged').textContent = stagedCount;
    document.getElementById('statCancelled').textContent = cancelledCount;
}

/* ── Check if all done ── */
function checkDone() {
    const remaining = totalConfirmed - stagedCount - cancelledCount;
    if (remaining <= 0) {
        document.getElementById('doneBanner').style.display = 'block';
        document.getElementById('doneStaged').textContent = stagedCount;
        document.getElementById('doneCancelled').textContent = cancelledCount;
    }
}

// Init
renderOrderCards();
</script>
</body>
</html>
