<?php
session_start();
// Standalone pickface status - no auth required
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pickface Status - K-one Allocator</title>
    <link rel="stylesheet" href="assets/css/wms-style.css">
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
        .pf-container { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
        .pf-back { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 600; color: var(--wms-gray-400); text-decoration: none; margin-bottom: 12px; transition: color 0.15s; }
        .pf-back:hover { color: var(--wms-primary); }
        .pf-back svg { width: 16px; height: 16px; }
        .pf-dropzone { border: 3px dashed var(--wms-primary); border-radius: var(--radius-lg); padding: 48px; text-align: center; cursor: pointer; transition: border-color 0.2s, background 0.2s; background: var(--wms-lighter); }
        .pf-dropzone:hover, .pf-dropzone.is-drag { border-color: var(--wms-info); background: var(--wms-light); }
        .pf-dropzone.has-file { border-color: var(--wms-success); background: var(--wms-light); }
        .pf-dropzone:focus-visible { outline: 2px solid var(--wms-primary); outline-offset: 2px; }
        .pf-dropzone-icon { display: block; margin: 0 auto 12px; color: var(--wms-primary); }
        .pf-dropzone-icon svg { width: 48px; height: 48px; }
        .pf-dropzone-title { font-size: 1.1rem; font-weight: 600; color: var(--wms-gray-600); }
        .pf-dropzone-hint { color: var(--wms-gray-400); font-size: 0.875rem; margin-top: 4px; }
        .pf-file-info { display: none; align-items: center; justify-content: space-between; gap: 10px; background: var(--wms-light); border-radius: var(--radius-sm); padding: 12px 16px; margin-top: 12px; }
        .pf-file-info.is-visible { display: flex; }
        .pf-file-info-file { display: flex; align-items: center; gap: 10px; }
        .pf-file-info-file svg { width: 28px; height: 28px; color: var(--wms-success); }
        .pf-file-info-name { font-weight: 600; color: var(--wms-heading); }
        .pf-file-info-size { font-size: 0.8rem; color: var(--wms-gray-400); }
        .pf-file-info-remove { background: none; border: none; color: var(--wms-primary); cursor: pointer; font-size: 0.875rem; font-weight: 600; padding: 4px 8px; border-radius: 6px; transition: background 0.15s; display: inline-flex; align-items: center; gap: 4px; }
        .pf-file-info-remove:hover { background: var(--wms-lighter); }
        .pf-file-info-remove:focus-visible { outline: 2px solid var(--wms-primary); outline-offset: 2px; }
        .pf-file-info-remove svg { width: 14px; height: 14px; }
        .pf-generate-btn { width: 100%; margin-top: 16px; padding: 14px; font-size: 1rem; }
        .pf-generate-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .pf-progress-wrap { background: var(--wms-gray-200); border-radius: var(--radius-sm); height: 12px; overflow: hidden; }
        .pf-progress-fill { background: linear-gradient(90deg, var(--wms-dark), var(--wms-primary)); height: 100%; width: 0%; transition: width 0.3s; border-radius: var(--radius-sm); }
        .pf-progress-text { margin-top: 8px; font-size: 0.875rem; color: var(--wms-gray-400); text-align: center; }
        .pf-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .pf-stat { border-radius: var(--radius-sm); padding: 14px; text-align: center; }
        .pf-stat .num { font-size: 1.8rem; font-weight: 800; line-height: 1.1; }
        .pf-stat .lbl { font-size: 0.78rem; color: var(--wms-gray-400); margin-top: 2px; }
        .pf-filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
        .pf-filter-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .pf-filter-btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1.5px solid var(--wms-gray-200); background: #fff; color: var(--wms-gray-600); transition: all 0.15s; }
        .pf-filter-btn:hover { border-color: var(--wms-primary); color: var(--wms-primary); }
        .pf-filter-btn.is-active { background: var(--wms-primary); border-color: var(--wms-primary); color: #fff; }
        .pf-filter-btn .pf-filter-count { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 18px; border-radius: 10px; font-size: 0.7rem; font-weight: 700; padding: 0 5px; background: rgba(0,0,0,0.08); }
        .pf-filter-btn.is-active .pf-filter-count { background: rgba(255,255,255,0.25); }
        .pf-search-wrap { position: relative; flex: 1; min-width: 200px; }
        .pf-search-wrap svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--wms-gray-400); pointer-events: none; }
        .pf-search-input { width: 100%; padding: 7px 12px 7px 32px; border: 1.5px solid var(--wms-gray-200); border-radius: var(--radius-sm); font-size: 0.85rem; font-family: inherit; outline: none; transition: border-color 0.15s, box-shadow 0.15s; background: #fff; color: var(--wms-text); }
        .pf-search-input:focus { border-color: var(--wms-primary); box-shadow: 0 0 0 3px rgba(2, 103, 102, 0.1); }
        .pf-search-input::placeholder { color: var(--wms-gray-400); }
        .pf-table-scroll { max-height: 600px; overflow-y: auto; border: 1px solid var(--wms-gray-200); border-radius: var(--radius-sm); }
        .pf-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .pf-table thead th { background: var(--wms-primary); color: #e8f5f5; padding: 10px 14px; text-align: left; font-weight: 600; font-size: 0.78rem; letter-spacing: 0.3px; white-space: nowrap; position: sticky; top: 0; z-index: 1; }
        .pf-table thead th.tc { text-align: center; }
        .pf-table thead th.tr { text-align: right; }
        .pf-table tbody tr { border-bottom: 1px solid var(--wms-gray-200); transition: background 0.1s; }
        .pf-table tbody tr:hover { background: var(--wms-lighter); }
        .pf-table tbody td { padding: 10px 14px; color: var(--wms-text); vertical-align: middle; }
        .pf-table tbody td.tc { text-align: center; }
        .pf-table tbody td.tr { text-align: right; }
        .pf-table tfoot td { padding: 10px 14px; font-weight: 700; background: var(--wms-gray-100); border-top: 2px solid var(--wms-primary); color: var(--wms-heading); }
        .pf-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 0.73rem; font-weight: 700; white-space: nowrap; }
        .pf-badge-yes { background: #dcfce7; color: #166534; }
        .pf-badge-no { background: #fee2e2; color: #b91c1c; }
        .pf-badge-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .pf-badge-yes .pf-badge-dot { background: #22c55e; }
        .pf-badge-no .pf-badge-dot { background: #ef4444; }
        .pf-qty-bar-wrap { display: flex; align-items: center; gap: 6px; }
        .pf-qty-bar { width: 60px; height: 6px; border-radius: 3px; background: var(--wms-gray-200); overflow: hidden; flex-shrink: 0; }
        .pf-qty-bar-fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
        .pf-bar-pickface { background: var(--wms-info); }
        .pf-bar-bulk { background: var(--wms-gray-400); }
        .pf-qty-num { font-size: 0.8rem; color: var(--wms-gray-600); white-space: nowrap; }
        .pf-row-no-pickface { background: #fef2f2; }
        .pf-row-no-pickface:hover { background: #fee2e2; }
        .pf-no-results { text-align: center; padding: 40px 20px; color: var(--wms-gray-400); }
        .pf-no-results svg { width: 48px; height: 48px; margin: 0 auto 12px; display: block; color: var(--wms-gray-200); }
        .pf-no-results p { font-size: 0.9rem; }
        .pf-error-grid { background: #fee2e2; border-radius: var(--radius-sm); padding: 14px; color: #991b1b; display: flex; align-items: center; gap: 8px; font-weight: 600; }
        .pf-error-grid svg { width: 18px; height: 18px; flex-shrink: 0; }
        .pf-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .pf-actions .wms-btn { flex: 1; min-width: 140px; justify-content: center; }
        .pf-row-no { color: var(--wms-gray-400); font-size: 0.8rem; font-weight: 500; }
        .pf-item-code { font-weight: 600; color: var(--wms-heading); }
        @keyframes pf-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
</head>
<body style="background:var(--wms-gray-100)">

<div class="pf-container">
    <a href="index.php" class="pf-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
        Back to Allocator
    </a>
    <div class="wms-banner" style="margin-bottom:20px">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px;margin-right:8px;vertical-align:middle"><path d="M21 8V16a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8"/><rect x="1" y="4" width="22" height="4" rx="2"/><path d="M10 12h4"/></svg>
            Pickface Status
        </h1>
        <p>Cek status pickface semua item dari file WMS</p>
    </div>
</div>

<div class="pf-container">

    <div class="wms-card" style="margin-bottom:20px">
        <div class="wms-card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;color:var(--wms-primary)"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Upload WMS File
            </h2>
        </div>
        <div class="wms-card-body">
            <div id="dropZone" class="pf-dropzone" tabindex="0" role="button" aria-label="Upload WMS Excel file" onclick="document.getElementById('excelFile').click()" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('excelFile').click()}">
                <input type="file" id="excelFile" accept=".xlsx,.xls" style="display:none" onchange="fileSelected(this)" aria-hidden="true">
                <span class="pf-dropzone-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18v-6"/><path d="M9.5 14.5 12 12l2.5 2.5"/></svg>
                </span>
                <p class="pf-dropzone-title">Drag &amp; drop atau klik untuk pilih file WMS</p>
                <p class="pf-dropzone-hint">Format: .xlsx atau .xls &bull; Max 10MB</p>
            </div>

            <div id="fileInfo" class="pf-file-info" role="status">
                <div class="pf-file-info-file">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <div>
                        <div id="fileName" class="pf-file-info-name"></div>
                        <div id="fileSize" class="pf-file-info-size"></div>
                    </div>
                </div>
                <button class="pf-file-info-remove" onclick="clearFile()" aria-label="Remove selected file">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Remove
                </button>
            </div>

            <button id="importBtn" class="wms-btn wms-btn-primary pf-generate-btn" onclick="startAnalysis()" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M21 8V16a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8"/><rect x="1" y="4" width="22" height="4" rx="2"/><path d="M10 12h4"/></svg>
                Analyze Pickface Status
            </button>
        </div>
    </div>

    <div id="progressSection" class="wms-card" style="margin-bottom:20px;display:none">
        <div class="wms-card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;color:var(--wms-primary);animation:pf-spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                Memproses pickface status...
            </h2>
        </div>
        <div class="wms-card-body">
            <div class="pf-progress-wrap" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="Progres analisis pickface">
                <div id="progressBar" class="pf-progress-fill"></div>
            </div>
            <p id="progressText" class="pf-progress-text">Membaca file...</p>
        </div>
    </div>

    <div id="resultsSection" class="wms-card" style="margin-bottom:20px;display:none">
        <div class="wms-card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;color:var(--wms-success)"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Pickface Status
            </h2>
        </div>
        <div class="wms-card-body">
            <div id="statsCards" class="pf-stat-grid"></div>

            <div id="filterBar" class="pf-filter-bar" style="display:none">
                <div class="pf-filter-group">
                    <button class="pf-filter-btn is-active" data-filter="all" onclick="setFilter('all')">
                        All <span class="pf-filter-count" id="countAll">0</span>
                    </button>
                    <button class="pf-filter-btn" data-filter="yes" onclick="setFilter('yes')">
                        Has Pickface <span class="pf-filter-count" id="countYes">0</span>
                    </button>
                    <button class="pf-filter-btn" data-filter="no" onclick="setFilter('no')">
                        No Pickface <span class="pf-filter-count" id="countNo">0</span>
                    </button>
                </div>
                <div class="pf-search-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="searchInput" class="pf-search-input" placeholder="Search by item code..." oninput="applyFilters()">
                </div>
            </div>

            <div id="tableWrap" class="pf-table-scroll" style="display:none">
                <table class="pf-table">
                    <thead>
                        <tr>
                            <th style="width:40px" class="tc">No.</th>
                            <th>Item Code</th>
                            <th class="tc">UOM</th>
                            <th class="tc">UPP</th>
                            <th class="tc">Status</th>
                            <th class="tc">Pickface Bins</th>
                            <th>Pickface Qty</th>
                            <th class="tc">Bulk Bins</th>
                            <th>Bulk Qty</th>
                            <th class="tr">Total</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody"></tbody>
                    <tfoot id="tableFoot" style="display:none">
                        <tr>
                            <td class="tc"></td>
                            <td><strong>TOTAL</strong></td>
                            <td class="tc"></td>
                            <td class="tc"></td>
                            <td class="tc"></td>
                            <td class="tc" id="footPfBins">0</td>
                            <td class="tr" id="footPfQty">0</td>
                            <td class="tc" id="footBkBins">0</td>
                            <td class="tr" id="footBkQty">0</td>
                            <td class="tr" id="footTotal">0</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div id="noResults" class="pf-no-results" style="display:none">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                <p>No items match your filter</p>
            </div>

            <div id="errorSection" style="display:none">
                <div class="pf-error-grid" id="errorContent"></div>
            </div>

            <div id="actionsBar" class="pf-actions" style="display:none">
                <button onclick="resetAll()" class="wms-btn wms-btn-ghost">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    Analyze Again
                </button>
            </div>
        </div>
    </div>

</div>
<script>
var selectedFile = null;
var allItems = [];
var currentFilter = 'all';

var dz = document.getElementById('dropZone');
dz.addEventListener('dragover', function(e) { e.preventDefault(); dz.classList.add('is-drag'); });
dz.addEventListener('dragleave', function() { dz.classList.remove('is-drag'); });
dz.addEventListener('drop', function(e) {
    e.preventDefault(); dz.classList.remove('is-drag');
    var f = e.dataTransfer.files[0];
    if (f && (f.name.endsWith('.xlsx') || f.name.endsWith('.xls'))) {
        setFile(f);
    } else {
        alert('Pilih file .xlsx atau .xls');
    }
});

function fileSelected(input) {
    if (input.files[0]) setFile(input.files[0]);
}

function setFile(file) {
    selectedFile = file;
    document.getElementById('fileName').textContent = file.name;
    document.getElementById('fileSize').textContent = (file.size / 1024).toFixed(1) + ' KB';
    document.getElementById('fileInfo').classList.add('is-visible');
    dz.classList.add('has-file');
    document.getElementById('importBtn').disabled = false;
}

function clearFile() {
    selectedFile = null;
    document.getElementById('excelFile').value = '';
    document.getElementById('fileInfo').classList.remove('is-visible');
    dz.classList.remove('has-file');
    document.getElementById('importBtn').disabled = true;
}

function startAnalysis() {
    if (!selectedFile) return;
    document.getElementById('progressSection').style.display = 'block';
    document.getElementById('resultsSection').style.display = 'none';
    document.getElementById('importBtn').disabled = true;

    var progressBar = document.getElementById('progressBar');
    var progressWrap = progressBar.parentElement;
    progressBar.style.width = '20%';
    progressWrap.setAttribute('aria-valuenow', '20');
    document.getElementById('progressText').textContent = 'Mengirim file...';

    var fd = new FormData();
    fd.append('action', 'pickface_status');
    fd.append('excel_file', selectedFile);

    progressBar.style.width = '50%';
    progressWrap.setAttribute('aria-valuenow', '50');
    document.getElementById('progressText').textContent = 'Membaca data WMS...';

    fetch('api.php', { method: 'POST', body: fd })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            progressBar.style.width = '100%';
            progressWrap.setAttribute('aria-valuenow', '100');
            document.getElementById('progressSection').style.display = 'none';
            document.getElementById('resultsSection').style.display = 'block';
            if (data.success) {
                renderResults(data);
            } else {
                document.getElementById('statsCards').innerHTML =
                    '<div class="pf-error-grid">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
                    escHtml(data.message || 'Gagal memuat pickface status') +
                    '</div>';
            }
        })
        .catch(function(err) {
            document.getElementById('progressSection').style.display = 'none';
            document.getElementById('resultsSection').style.display = 'block';
            document.getElementById('statsCards').innerHTML =
                '<div class="pf-error-grid">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>' +
                'Error: ' + escHtml(err.message) +
                '</div>';
        });
}

function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

function renderResults(data) {
    var summary = data.summary || {};
    var items = data.items || {};

    allItems = [];
    for (var code in items) {
        if (items.hasOwnProperty(code)) {
            allItems.push(items[code]);
        }
    }
    allItems.sort(function(a, b) { return String(a.item_code).localeCompare(String(b.item_code)); });

    var total = summary.total_items || allItems.length;
    var withPf = summary.with_pickface || 0;
    var withoutPf = summary.without_pickface || 0;
    var coverage = total > 0 ? Math.round((withPf / total) * 100) : 0;

    document.getElementById('statsCards').innerHTML =
        '<div class="pf-stat" style="background:var(--wms-light)">' +
            '<div class="num" style="color:var(--wms-primary)">' + total + '</div>' +
            '<div class="lbl">Total Items</div>' +
        '</div>' +
        '<div class="pf-stat" style="background:#dcfce7">' +
            '<div class="num" style="color:var(--wms-success)">' + withPf + '</div>' +
            '<div class="lbl">With Pickface</div>' +
        '</div>' +
        '<div class="pf-stat" style="background:#fee2e2">' +
            '<div class="num" style="color:var(--wms-danger)">' + withoutPf + '</div>' +
            '<div class="lbl">Without Pickface</div>' +
        '</div>' +
        '<div class="pf-stat" style="background:var(--wms-lighter)">' +
            '<div class="num" style="color:var(--wms-info)">' + coverage + '%</div>' +
            '<div class="lbl">Coverage</div>' +
        '</div>';

    document.getElementById('countAll').textContent = total;
    document.getElementById('countYes').textContent = withPf;
    document.getElementById('countNo').textContent = withoutPf;

    document.getElementById('filterBar').style.display = 'flex';
    document.getElementById('actionsBar').style.display = 'flex';

    currentFilter = 'all';
    document.getElementById('searchInput').value = '';
    var btns = document.querySelectorAll('.pf-filter-btn');
    for (var i = 0; i < btns.length; i++) {
        btns[i].classList.toggle('is-active', btns[i].getAttribute('data-filter') === 'all');
    }
    applyFilters();
}

function setFilter(filter) {
    currentFilter = filter;
    var btns = document.querySelectorAll('.pf-filter-btn');
    for (var i = 0; i < btns.length; i++) {
        btns[i].classList.toggle('is-active', btns[i].getAttribute('data-filter') === filter);
    }
    applyFilters();
}

function applyFilters() {
    var search = document.getElementById('searchInput').value.toLowerCase().trim();
    var filtered = allItems.filter(function(item) {
        var matchFilter = true;
        if (currentFilter === 'yes') matchFilter = item.has_pickface === true;
        else if (currentFilter === 'no') matchFilter = item.has_pickface === false;
        var matchSearch = !search || String(item.item_code).toLowerCase().indexOf(search) !== -1;
        return matchFilter && matchSearch;
    });

    renderTable(filtered);
}

function renderTable(items) {
    var tbody = document.getElementById('tableBody');
    var tableWrap = document.getElementById('tableWrap');
    var noResults = document.getElementById('noResults');
    var tableFoot = document.getElementById('tableFoot');

    if (items.length === 0) {
        tableWrap.style.display = 'none';
        noResults.style.display = 'block';
        tableFoot.style.display = 'none';
        return;
    }

    tableWrap.style.display = 'block';
    noResults.style.display = 'none';
    tableFoot.style.display = 'table-row';

    var maxQty = 0;
    for (var i = 0; i < items.length; i++) {
        if (items[i].total_qty > maxQty) maxQty = items[i].total_qty;
    }

    var html = '';
    var sumPfBins = 0, sumPfQty = 0, sumBkBins = 0, sumBkQty = 0, sumTotal = 0;

    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        var hasPf = item.has_pickface;
        var pfCount = item.pickface_count || 0;
        var pfQty = item.pickface_qty || 0;
        var bkCount = item.bulk_count || 0;
        var bkQty = item.bulk_qty || 0;
        var totalQty = item.total_qty || 0;
        var upp = item.upp || '';
        var uom = item.uom_type || '';

        sumPfBins += pfCount;
        sumPfQty += pfQty;
        sumBkBins += bkCount;
        sumBkQty += bkQty;
        sumTotal += totalQty;

        var pfBarPct = maxQty > 0 ? Math.round((pfQty / maxQty) * 100) : 0;
        var bkBarPct = maxQty > 0 ? Math.round((bkQty / maxQty) * 100) : 0;

        var statusBadge = hasPf
            ? '<span class="pf-badge pf-badge-yes"><span class="pf-badge-dot"></span>Yes</span>'
            : '<span class="pf-badge pf-badge-no"><span class="pf-badge-dot"></span>No</span>';

        var rowClass = hasPf ? '' : ' class="pf-row-no-pickface"';

        html += '<tr' + rowClass + '>' +
            '<td class="tc pf-row-no">' + (i + 1) + '</td>' +
            '<td class="pf-item-code">' + escHtml(String(item.item_code)) + '</td>' +
            '<td class="tc">' + escHtml(uom) + '</td>' +
            '<td class="tc">' + escHtml(String(upp)) + '</td>' +
            '<td class="tc">' + statusBadge + '</td>' +
            '<td class="tc">' + pfCount + '</td>' +
            '<td><div class="pf-qty-bar-wrap"><div class="pf-qty-bar"><div class="pf-qty-bar-fill pf-bar-pickface" style="width:' + pfBarPct + '%"></div></div><span class="pf-qty-num">' + pfQty + '</span></div></td>' +
            '<td class="tc">' + bkCount + '</td>' +
            '<td><div class="pf-qty-bar-wrap"><div class="pf-qty-bar"><div class="pf-qty-bar-fill pf-bar-bulk" style="width:' + bkBarPct + '%"></div></div><span class="pf-qty-num">' + bkQty + '</span></div></td>' +
            '<td class="tr"><strong>' + totalQty + '</strong></td>' +
            '</tr>';
    }

    tbody.innerHTML = html;

    document.getElementById('footPfBins').textContent = sumPfBins;
    document.getElementById('footPfQty').textContent = sumPfQty;
    document.getElementById('footBkBins').textContent = sumBkBins;
    document.getElementById('footBkQty').textContent = sumBkQty;
    document.getElementById('footTotal').textContent = sumTotal;
}

function resetAll() {
    clearFile();
    allItems = [];
    currentFilter = 'all';
    document.getElementById('resultsSection').style.display = 'none';
    document.getElementById('progressBar').style.width = '0%';
    document.getElementById('progressBar').parentElement.setAttribute('aria-valuenow', '0');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>
</body>
</html>
