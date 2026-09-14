<?php
session_start();
// Standalone allocator — no auth required
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allocator — Picklist Generator</title>
    <link rel="stylesheet" href="assets/css/wms-style.css">
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
        /* ── Allocator-specific components (not in wms-style.css) ── */

        @keyframes svg-spin { to { transform: rotate(360deg); } }
        .svg-spin { animation: svg-spin 1s linear infinite; }

        .alloc-container {
            max-width: 800px;
            margin: 24px auto;
            padding: 0 16px;
        }

        /* Drop zone */
        .alloc-dropzone {
            border: 3px dashed var(--wms-primary);
            border-radius: var(--radius-lg);
            padding: 48px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            background: var(--wms-lighter);
        }
        .alloc-dropzone:hover,
        .alloc-dropzone.is-drag {
            border-color: var(--wms-info);
            background: var(--wms-light);
        }
        .alloc-dropzone.has-file {
            border-color: var(--wms-success);
            background: var(--wms-light);
        }
        .alloc-dropzone:focus-visible {
            outline: 2px solid var(--wms-primary);
            outline-offset: 2px;
        }
        .alloc-dropzone-icon {
            font-size: 3rem;
            color: var(--wms-primary);
            margin-bottom: 12px;
            display: block;
        }
        .alloc-dropzone-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--wms-gray-600);
        }
        .alloc-dropzone-hint {
            color: var(--wms-gray-400);
            font-size: 0.875rem;
            margin-top: 4px;
        }

        /* File info */
        .alloc-file-info {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: var(--wms-light);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            margin-top: 12px;
        }
        .alloc-file-info.is-visible {
            display: flex;
        }
        .alloc-file-info-file {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alloc-file-info-icon {
            color: var(--wms-success);
            font-size: 1.5rem;
        }
        .alloc-file-info-name {
            font-weight: 600;
            color: var(--wms-heading);
        }
        .alloc-file-info-size {
            font-size: 0.8rem;
            color: var(--wms-gray-400);
        }
        .alloc-file-info-remove {
            background: none;
            border: none;
            color: var(--wms-primary);
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
            transition: background 0.15s;
        }
        .alloc-file-info-remove:hover {
            background: var(--wms-lighter);
        }
        .alloc-file-info-remove:focus-visible {
            outline: 2px solid var(--wms-primary);
            outline-offset: 2px;
        }

        /* Generate button (full-width CTA) */
        .alloc-generate-btn {
            width: 100%;
            margin-top: 16px;
            padding: 14px;
            font-size: 1rem;
        }
        .alloc-generate-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Sheet chips */
        .alloc-sheet-chip {
            display: inline-block;
            background: var(--wms-light);
            border: 1px solid var(--wms-gray-200);
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 0.8rem;
            color: var(--wms-primary);
            margin: 4px 4px 4px 0;
        }
        .alloc-sheet-chips {
            line-height: 1.9;
        }

        /* Step number badge */
        .alloc-step-num {
            background: var(--wms-primary);
            color: #fff;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
        }

        /* Progress bar */
        .alloc-progress-wrap {
            background: var(--wms-gray-200);
            border-radius: var(--radius-sm);
            height: 12px;
            overflow: hidden;
        }
        .alloc-progress-fill {
            background: linear-gradient(90deg, var(--wms-dark), var(--wms-primary));
            height: 100%;
            width: 0%;
            transition: width 0.3s;
            border-radius: var(--radius-sm);
        }
        .alloc-progress-text {
            margin-top: 8px;
            font-size: 0.875rem;
            color: var(--wms-gray-400);
            text-align: center;
        }

        /* Stat grid in results */
        .alloc-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .alloc-stat {
            border-radius: var(--radius-sm);
            padding: 14px;
            text-align: center;
        }
        .alloc-stat .num {
            font-size: 1.8rem;
            font-weight: 800;
            line-height: 1.1;
        }
        .alloc-stat .lbl {
            font-size: 0.78rem;
            color: var(--wms-gray-400);
            margin-top: 2px;
        }

        /* Pick card */
        .alloc-pick {
            background: var(--wms-gray-50);
            border: 1px solid var(--wms-gray-200);
            border-radius: var(--radius-sm);
            padding: 12px;
            margin-bottom: 8px;
            font-size: 0.875rem;
        }
        .alloc-pick--full {
            border-left: 4px solid var(--wms-success);
        }
        .alloc-pick--pickface {
            border-left: 4px solid var(--wms-info);
        }
        .alloc-pick--replenish {
            border-left: 4px solid var(--wms-warn);
        }

        /* Section title (within results card) */
        .alloc-section-title {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--wms-heading);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Error card */
        .alloc-error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            border-radius: var(--radius-sm);
            padding: 12px;
            margin-bottom: 8px;
            color: #991b1b;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Picks scroll area */
        .alloc-picks-scroll {
            max-height: 400px;
            overflow-y: auto;
        }

        /* Action button row */
        .alloc-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .alloc-actions .wms-btn,
        .alloc-actions a.wms-btn {
            flex: 1;
            min-width: 140px;
            justify-content: center;
        }

        /* Button loading state */
        .wms-btn.is-loading {
            pointer-events: none;
            opacity: 0.75;
            position: relative;
        }
        .wms-btn.is-loading .btn-icon {
            display: none;
        }
        .wms-btn.is-loading::before {
            content: '';
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid currentColor;
            border-top-color: transparent;
            border-radius: 50%;
            animation: wms-btn-spin 0.6s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
            flex-shrink: 0;
        }
        @keyframes wms-btn-spin {
            to { transform: rotate(360deg); }
        }

        /* Error grid message */
        .alloc-error-grid {
            grid-column: 1 / -1;
            background: #fee2e2;
            border-radius: var(--radius-sm);
            padding: 14px;
            color: #991b1b;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        /* Merge grid — side-by-side dropzones */
        .merge-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .merge-dropzone {
            border: 3px dashed var(--wms-primary);
            border-radius: var(--radius-lg);
            padding: 32px 16px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            background: var(--wms-lighter);
        }
        .merge-dropzone:hover,
        .merge-dropzone.is-drag {
            border-color: var(--wms-info);
            background: var(--wms-light);
        }
        .merge-dropzone.has-file {
            border-color: var(--wms-success);
            background: var(--wms-light);
        }
        .merge-dropzone:focus-visible {
            outline: 2px solid var(--wms-primary);
            outline-offset: 2px;
        }
        .merge-dropzone .alloc-dropzone-icon {
            font-size: 2rem;
        }
        .merge-dropzone .alloc-dropzone-title {
            font-size: 0.95rem;
        }
        .merge-label {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--wms-gray-600);
        }
        .merge-status {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: var(--wms-light);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            margin-top: 10px;
            font-size: 0.85rem;
        }
        .merge-status.is-visible {
            display: flex;
        }
        .merge-status-name {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: var(--wms-heading);
        }
        .merge-status-name svg {
            color: var(--wms-success);
            font-size: 1.2rem;
        }
        .merge-status-remove {
            background: none;
            border: none;
            color: var(--wms-primary);
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
            transition: background 0.15s;
        }
        .merge-status-remove:hover {
            background: var(--wms-lighter);
        }
        @media (max-width: 600px) {
            .merge-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ── Order Confirmation ── */
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
        .tally-badge--pending { background: var(--wms-light); color: var(--wms-gray-500); }

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
        .order-card-stats {
            display: flex;
            gap: 14px;
            font-size: 0.8rem;
            color: var(--wms-gray-500);
            margin-bottom: 10px;
        }
        .order-card-stats span {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .order-card-stats .num {
            font-weight: 700;
            color: var(--wms-heading);
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
    </style>
</head>
<body style="background:var(--wms-gray-100)">

<!-- ── Hero Banner ── -->
<div class="alloc-container">
    <div class="wms-banner" style="margin-bottom:20px">
        <h1><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px"><path d="M15 4V2"/><path d="M15 16v-2"/><path d="M8 9h2"/><path d="M20 9h2"/><path d="M17.8 11.8L19 13"/><path d="M15 9h.01"/><path d="M17.8 6.2L19 5"/><path d="m3 21 9-9"/><path d="M12.2 6.2 11 5 5 11l1.2 1.2"/></svg>Allocator</h1>
        <p>Generate picklist dari Excel order dengan alokasi FEFO</p>
        <a href="pickface_status.php" style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:0.85rem;font-weight:600;color:var(--wms-primary);text-decoration:none;padding:6px 14px;background:rgba(255,255,255,0.15);border-radius:var(--radius-sm);transition:background 0.15s" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Pickface Status
        </a>
    </div>
</div>

<!-- ── Main Content ── -->
<div class="alloc-container">

    <!-- Sheet Info Card -->
    <div class="wms-card" style="margin-bottom:20px">
        <div class="wms-card-header">
            <h2><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/></svg> Sheet yang diproses</h2>
        </div>
        <div class="wms-card-body">
            <div class="alloc-sheet-chips">
                <span class="alloc-sheet-chip">schedule of the day → Order lines</span>
                <span class="alloc-sheet-chip">wms → Stock fisik &amp; lokasi bin</span>
                <span class="alloc-sheet-chip">data putaway → Batch &amp; expiry</span>
                <span class="alloc-sheet-chip">master sku → UPP &amp; UOM per item</span>
            </div>
        </div>
    </div>

    <!-- Merge Inbound Card (Optional) -->
    <div class="wms-card" style="margin-bottom:20px">
        <div class="wms-card-header">
            <h2>
                <span class="alloc-step-num">1</span>
                Merge Inbound (Optional)
            </h2>
        </div>
        <div class="wms-card-body">
            <div class="merge-grid">
                <!-- WMS file dropzone -->
                <div>
                    <p class="merge-label">Yesterday's Updated WMS</p>
                    <div id="wmsDropZone"
                         class="merge-dropzone"
                         tabindex="0"
                         role="button"
                         aria-label="Upload WMS file — drag and drop or click to select"
                         onclick="document.getElementById('wmsFile').click()"
                         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('wmsFile').click()}">
                        <input type="file"
                               id="wmsFile"
                               accept=".xlsx,.xls"
                               style="display:none"
                               onchange="wmsFileSelected(this)"
                               aria-hidden="true">
                        <svg class="alloc-dropzone-icon" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h2"/><path d="M14 13h2"/><path d="M10 17c.5.3 1.2.5 2 .5s1.5-.2 2-.5"/></svg>
                        <p class="alloc-dropzone-title">Drop WMS file</p>
                    </div>
                    <div id="wmsFileInfo" class="merge-status" role="status">
                        <span class="merge-status-name"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg> <span id="wmsFileName"></span></span>
                        <button class="merge-status-remove" onclick="clearWmsFile()" aria-label="Remove WMS file">
                            <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Remove
                        </button>
                    </div>
                </div>
                <!-- Inbound file dropzone -->
                <div>
                    <p class="merge-label">Today's Inbound Receipts</p>
                    <div id="inboundDropZone"
                         class="merge-dropzone"
                         tabindex="0"
                         role="button"
                         aria-label="Upload inbound receipts — drag and drop or click to select"
                         onclick="document.getElementById('inboundFile').click()"
                         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('inboundFile').click()}">
                        <input type="file"
                               id="inboundFile"
                               accept=".xlsx,.xls"
                               style="display:none"
                               onchange="inboundFileSelected(this)"
                               aria-hidden="true">
                        <svg class="alloc-dropzone-icon" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                        <p class="alloc-dropzone-title">Drop inbound file</p>
                    </div>
                    <div id="inboundFileInfo" class="merge-status" role="status">
                        <span class="merge-status-name"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg> <span id="inboundFileName"></span></span>
                        <button class="merge-status-remove" onclick="clearInboundFile()" aria-label="Remove inbound file">
                            <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Remove
                        </button>
                    </div>
                </div>
            </div>
            <button id="mergeBtn"
                    class="wms-btn wms-btn-primary alloc-generate-btn"
                    onclick="startMerge()"
                    disabled>
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" x2="6" y1="3" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg> Merge Inbound
            </button>
        </div>
    </div>

    <!-- Upload Card -->
    <div class="wms-card" style="margin-bottom:20px">
        <div class="wms-card-header">
            <h2>
                <span class="alloc-step-num">2</span>
                Generate Picklist
            </h2>
        </div>
        <div class="wms-card-body">

            <div id="dropZone"
                 class="alloc-dropzone"
                 tabindex="0"
                 role="button"
                 aria-label="Upload file Excel — drag and drop atau klik untuk memilih file"
                 onclick="document.getElementById('excelFile').click()"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('excelFile').click()}">
                <input type="file"
                       id="excelFile"
                       accept=".xlsx,.xls"
                       style="display:none"
                       onchange="fileSelected(this)"
                       aria-hidden="true">
                <svg class="alloc-dropzone-icon" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M12 12v9"/><path d="m16 16-4-4-4 4"/></svg>
                <p class="alloc-dropzone-title">Drag &amp; drop atau klik untuk pilih file</p>
                <p class="alloc-dropzone-hint">Format: .xlsx atau .xls &bull; Max 10MB</p>
            </div>

            <div id="fileInfo" class="alloc-file-info" role="status">
                <div class="alloc-file-info-file">
                    <svg class="alloc-file-info-icon" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h2"/><path d="M14 13h2"/><path d="M10 17c.5.3 1.2.5 2 .5s1.5-.2 2-.5"/></svg>
                    <div>
                        <div id="fileName" class="alloc-file-info-name"></div>
                        <div id="fileSize" class="alloc-file-info-size"></div>
                    </div>
                </div>
                <button class="alloc-file-info-remove"
                        onclick="clearFile()"
                        aria-label="Hapus file yang dipilih">
                    <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Hapus
                </button>
            </div>

            <button id="importBtn"
                    class="wms-btn wms-btn-primary alloc-generate-btn"
                    onclick="startAllocation()"
                    disabled>
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 4V2"/><path d="M15 16v-2"/><path d="M8 9h2"/><path d="M20 9h2"/><path d="M17.8 11.8L19 13"/><path d="M15 9h.01"/><path d="M17.8 6.2L19 5"/><path d="m3 21 9-9"/><path d="M12.2 6.2 11 5 5 11l1.2 1.2"/></svg> Generate Picklist
            </button>

        </div>
    </div>

    <!-- Progress Card (hidden by default) -->
    <div id="progressSection" class="wms-card" style="margin-bottom:20px;display:none">
        <div class="wms-card-header">
            <h2><svg class="svg-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Memproses allocation...</h2>
        </div>
        <div class="wms-card-body">
            <div class="alloc-progress-wrap"
                 role="progressbar"
                 aria-valuenow="0"
                 aria-valuemin="0"
                 aria-valuemax="100"
                 aria-label="Progres allocation">
                <div id="progressBar" class="alloc-progress-fill"></div>
            </div>
            <p id="progressText" class="alloc-progress-text">Membaca file...</p>
        </div>
    </div>

    <!-- Results Card (hidden by default) -->
    <div id="resultsSection" class="wms-card" style="margin-bottom:20px;display:none">
        <div class="wms-card-header">
            <h2><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg> Allocation Selesai</h2>
        </div>
        <div class="wms-card-body">

            <div id="statsCards" class="alloc-stat-grid"></div>

            <div id="errorsSection" style="display:none;margin-bottom:16px">
                <div class="alloc-section-title" style="color:var(--wms-danger)">
                    <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> Errors
                </div>
                <div id="errorsList" role="alert"></div>
            </div>

            <div id="picksSection" style="margin-bottom:16px">
                <div class="alloc-section-title">
                    <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--wms-primary)" aria-hidden="true"><line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/></svg> Picks
                </div>
                <div id="picksList" class="alloc-picks-scroll"></div>
            </div>

            <div id="replenishmentsSection" style="display:none;margin-bottom:16px">
                <div class="alloc-section-title" style="color:var(--wms-warn)">
                    <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/></svg> Replenishments
                </div>
                <div id="replenishmentsList"></div>
            </div>

            <div class="alloc-actions">
                <a id="downloadBtn" href="#" class="wms-btn wms-btn-success">
                    <span class="btn-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg></span> Download Excel
                </a>
                <a id="downloadWmsBtn" href="#" class="wms-btn wms-btn-primary" style="display:none">
                    <span class="btn-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h2"/><path d="M14 13h2"/><path d="M10 17c.5.3 1.2.5 2 .5s1.5-.2 2-.5"/></svg></span> Download Updated WMS Sheet
                </a>
                <a id="printBtn" href="#" target="_blank" class="wms-btn wms-btn-primary">
                    <span class="btn-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect width="12" height="8" x="6" y="14" rx="1"/></svg></span> Print Picklist
                </a>
                <button onclick="resetAllocation()" class="wms-btn wms-btn-ghost">
                    <span class="btn-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg></span> Allocation Lagi
                </button>
            </div>

        </div>
    </div>

    <!-- Order Confirmation Section (hidden by default) -->
    <div id="confirmSection" class="wms-card" style="margin-bottom:20px;display:none">
        <div class="wms-card-header">
            <h2><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg> Konfirmasi Order</h2>
        </div>
        <div class="wms-card-body">
            <div class="order-confirm-header">
                <div class="order-confirm-tally">
                    <span class="tally-badge tally-badge--confirm" id="tallyConfirm">✓ 0 Confirmed</span>
                    <span class="tally-badge tally-badge--stage" id="tallyStage">📦 0 Staged</span>
                    <span class="tally-badge tally-badge--cancel" id="tallyCancel">✗ 0 Cancelled</span>
                    <span class="tally-badge tally-badge--pending" id="tallyPending">⏳ 0 Pending</span>
                </div>
            </div>
            <div id="orderCards"></div>
            <button id="applyDecisionsBtn" class="wms-btn wms-btn-success apply-decisions-btn" disabled onclick="applyDecisions()">
                <span class="btn-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg></span>
                Apply Semua ke WMS
            </button>
        </div>
    </div>

</div>

<script>
/* ── Merge Inbound ── */
let selectedWmsFile = null;
let selectedInboundFile = null;

// --- WMS dropzone ---
const wmsDz = document.getElementById('wmsDropZone');
wmsDz.addEventListener('dragover', e => { e.preventDefault(); wmsDz.classList.add('is-drag'); });
wmsDz.addEventListener('dragleave', () => wmsDz.classList.remove('is-drag'));
wmsDz.addEventListener('drop', e => {
    e.preventDefault(); wmsDz.classList.remove('is-drag');
    const f = e.dataTransfer.files[0];
    if (f && (f.name.endsWith('.xlsx') || f.name.endsWith('.xls'))) wmsFileSelected({ files: [f] });
    else alert('Pilih file .xlsx atau .xls');
});

function wmsFileSelected(input) {
    const file = input.files ? input.files[0] : input;
    if (!file) return;
    selectedWmsFile = file;
    document.getElementById('wmsFileName').textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
    document.getElementById('wmsFileInfo').classList.add('is-visible');
    wmsDz.classList.add('has-file');
    updateMergeBtn();
}

function clearWmsFile() {
    selectedWmsFile = null;
    document.getElementById('wmsFile').value = '';
    document.getElementById('wmsFileInfo').classList.remove('is-visible');
    wmsDz.classList.remove('has-file');
    updateMergeBtn();
}

// --- Inbound dropzone ---
const inboundDz = document.getElementById('inboundDropZone');
inboundDz.addEventListener('dragover', e => { e.preventDefault(); inboundDz.classList.add('is-drag'); });
inboundDz.addEventListener('dragleave', () => inboundDz.classList.remove('is-drag'));
inboundDz.addEventListener('drop', e => {
    e.preventDefault(); inboundDz.classList.remove('is-drag');
    const f = e.dataTransfer.files[0];
    if (f && (f.name.endsWith('.xlsx') || f.name.endsWith('.xls'))) inboundFileSelected({ files: [f] });
    else alert('Pilih file .xlsx atau .xls');
});

function inboundFileSelected(input) {
    const file = input.files ? input.files[0] : input;
    if (!file) return;
    selectedInboundFile = file;
    document.getElementById('inboundFileName').textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
    document.getElementById('inboundFileInfo').classList.add('is-visible');
    inboundDz.classList.add('has-file');
    updateMergeBtn();
}

function clearInboundFile() {
    selectedInboundFile = null;
    document.getElementById('inboundFile').value = '';
    document.getElementById('inboundFileInfo').classList.remove('is-visible');
    inboundDz.classList.remove('has-file');
    updateMergeBtn();
}

function updateMergeBtn() {
    document.getElementById('mergeBtn').disabled = !(selectedWmsFile && selectedInboundFile);
}

/* ── Merge flow ── */
let mergeResult = null;

async function startMerge() {
    if (!selectedWmsFile || !selectedInboundFile) return;

    const mergeBtn = document.getElementById('mergeBtn');
    const mergeBtnText = mergeBtn.innerHTML;

    // Show progress section
    document.getElementById('progressSection').style.display = 'block';
    document.getElementById('resultsSection').style.display = 'none';
    mergeBtn.disabled = true;
    mergeBtn.classList.add('is-loading');
    mergeBtn.innerHTML = '<svg class="svg-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Merging...';

    // Scroll progress into view
    document.getElementById('progressSection').scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    const progressBar = document.getElementById('progressBar');
    const progressWrap = progressBar.parentElement;
    progressBar.style.width = '20%';
    progressWrap.setAttribute('aria-valuenow', '20');
    document.getElementById('progressText').textContent = 'Mengirim file...';

    const fd = new FormData();
    fd.append('action', 'merge_inbound');
    fd.append('wms_file', selectedWmsFile);
    fd.append('inbound_file', selectedInboundFile);

    try {
        progressBar.style.width = '50%';
        progressWrap.setAttribute('aria-valuenow', '50');
        document.getElementById('progressText').textContent = 'Merging inbound receipts...';

        const resp = await fetch('api.php', { method: 'POST', body: fd });
        const data = await resp.json();

        progressBar.style.width = '100%';
        progressWrap.setAttribute('aria-valuenow', '100');
        document.getElementById('progressText').textContent = 'Selesai!';
        document.getElementById('progressSection').style.display = 'none';

        // Reset button
        mergeBtn.classList.remove('is-loading');
        mergeBtn.innerHTML = mergeBtnText;
        mergeBtn.disabled = false;

        document.getElementById('resultsSection').style.display = 'block';

        if (data.success) {
            mergeResult = data;
            const stats = data.summary || {};
            document.getElementById('statsCards').innerHTML = `
                <div class="alloc-stat" style="background:var(--wms-light)"><div class="num" style="color:var(--wms-primary)">${stats.merged || 0}</div><div class="lbl">Merged</div></div>
                <div class="alloc-stat" style="background:var(--wms-lighter)"><div class="num" style="color:var(--wms-info)">${stats.matched || 0}</div><div class="lbl">Matched</div></div>
                <div class="alloc-stat" style="background:var(--wms-light)"><div class="num" style="color:var(--wms-warn)">${stats.unmatched || 0}</div><div class="lbl">Unmatched</div></div>
            `;

            // Hide allocation-only sections for merge results
            document.getElementById('picksSection').style.display = 'none';
            document.getElementById('replenishmentsSection').style.display = 'none';
            document.getElementById('printBtn').style.display = 'none';
            document.getElementById('downloadWmsBtn').style.display = 'none';

            // Show download button — use downloadBtn for the merged WMS file
            var dlBtn = document.getElementById('downloadBtn');
            dlBtn.href = 'download.php?file=' + encodeURIComponent(data.merged_file) + '&filename=merged_wms_inbound';
            dlBtn.querySelector('.btn-icon').innerHTML = '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>';
            // Fix: childNodes[0]=whitespace, [1]=<i>, [2]=text node — target the text node
            var txtNode = dlBtn.childNodes[2];
            if (txtNode) txtNode.textContent = ' Download Merged WMS';

            // Show unmatched items if any
            if (data.unmatched_items && data.unmatched_items.length > 0) {
                document.getElementById('errorsSection').style.display = 'block';
                document.getElementById('errorsSection').querySelector('.alloc-section-title').innerHTML =
                    '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> Unmatched Inbound Items';
                document.getElementById('errorsList').innerHTML = data.unmatched_items.map(item =>
                    `<div class="alloc-error"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>${item}</div>`
                ).join('');
            }
        } else {
            document.getElementById('statsCards').innerHTML = `
                <div class="alloc-error-grid">
                    <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>${data.message || 'Merge gagal'}
                </div>
            `;
        }
    } catch (err) {
        document.getElementById('progressSection').style.display = 'none';
        mergeBtn.classList.remove('is-loading');
        mergeBtn.innerHTML = mergeBtnText;
        mergeBtn.disabled = false;
        alert('Error: ' + err.message);
    }
}

/* ── Allocation flow (original) ── */
let selectedFile = null;
let allocationData = null; // Store full allocation response
let orderDecisions = {};   // { order_no: 'confirm'|'stage'|'cancel' }

const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('is-drag'); });
dz.addEventListener('dragleave', () => dz.classList.remove('is-drag'));
dz.addEventListener('drop', e => {
  e.preventDefault(); dz.classList.remove('is-drag');
  const f = e.dataTransfer.files[0];
  if (f && (f.name.endsWith('.xlsx') || f.name.endsWith('.xls'))) setFile(f);
  else alert('Pilih file .xlsx atau .xls');
});

function fileSelected(input) { if (input.files[0]) setFile(input.files[0]); }

function setFile(file) {
  selectedFile = file;
  document.getElementById('fileName').textContent = file.name;
  document.getElementById('fileSize').textContent = (file.size/1024).toFixed(1) + ' KB';
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

async function startAllocation() {
  if (!selectedFile) return;

  const importBtn = document.getElementById('importBtn');
  const importBtnText = importBtn.innerHTML;

  document.getElementById('progressSection').style.display = 'block';
  document.getElementById('resultsSection').style.display = 'none';
  importBtn.disabled = true;
  importBtn.classList.add('is-loading');
    importBtn.innerHTML = '<svg class="svg-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Generating...';

  // Scroll progress into view
  document.getElementById('progressSection').scrollIntoView({ behavior: 'smooth', block: 'nearest' });

  const progressBar = document.getElementById('progressBar');
  const progressWrap = progressBar.parentElement;
  progressBar.style.width = '20%';
  progressWrap.setAttribute('aria-valuenow', '20');
  document.getElementById('progressText').textContent = 'Mengirim file...';

  const fd = new FormData();
  fd.append('excel_file', selectedFile);

  try {
    progressBar.style.width = '50%';
    progressWrap.setAttribute('aria-valuenow', '50');
    document.getElementById('progressText').textContent = 'Memproses allocation...';

    const resp = await fetch('api.php', { method: 'POST', body: fd });
    const data = await resp.json();

    progressBar.style.width = '100%';
    progressWrap.setAttribute('aria-valuenow', '100');
    document.getElementById('progressText').textContent = 'Selesai!';
    document.getElementById('progressSection').style.display = 'none';

    // Reset button
    importBtn.classList.remove('is-loading');
    importBtn.innerHTML = importBtnText;
    importBtn.disabled = false;

    document.getElementById('resultsSection').style.display = 'block';

    if (data.success) {
      allocationData = data;
      const s = data.summary;

      // Hide old results, show order confirmation
      document.getElementById('resultsSection').style.display = 'none';
      document.getElementById('confirmSection').style.display = 'block';

      // Build order cards from picks grouped by order_no
      renderOrderCards(data.picks, data.replenishments);

      // Show errors if any
      if (data.errors && data.errors.length > 0) {
        document.getElementById('errorsSection').style.display = 'block';
        document.getElementById('errorsList').innerHTML = data.errors.map(e =>
          `<div class="alloc-error"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>${e}</div>`
        ).join('');
      }
    } else {
      document.getElementById('statsCards').innerHTML = `
        <div class="alloc-error-grid">
          <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>${data.message || 'Allocation gagal'}
        </div>
      `;
    }
  } catch (err) {
    document.getElementById('progressSection').style.display = 'none';
    importBtn.classList.remove('is-loading');
    importBtn.innerHTML = importBtnText;
    importBtn.disabled = false;
    alert('Error: ' + err.message);
  }
}

function resetAllocation() {
  clearFile();
  allocationData = null;
  orderDecisions = {};
  document.getElementById('resultsSection').style.display = 'none';
  document.getElementById('confirmSection').style.display = 'none';
  document.getElementById('errorsSection').style.display = 'none';
  document.getElementById('progressBar').style.width = '0%';
  document.getElementById('progressBar').parentElement.setAttribute('aria-valuenow', '0');

  // Restore sections that merge flow may have hidden
  document.getElementById('picksSection').style.display = '';
  document.getElementById('printBtn').style.display = '';
  document.getElementById('downloadWmsBtn').style.display = 'none';

  // Reset download button text
  var dlBtn = document.getElementById('downloadBtn');
  dlBtn.href = '#';
  dlBtn.querySelector('.btn-icon').innerHTML = '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>';
  var txtNode = dlBtn.childNodes[2];
  if (txtNode) txtNode.textContent = ' Download Excel';

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ── Order Confirmation ── */
function renderOrderCards(picks, replenishments) {
  // Group picks by order_no
  const byOrder = {};
  picks.forEach(p => {
    const key = p.order_no || 'unknown';
    if (!byOrder[key]) byOrder[key] = { picks: [], totalQty: 0, items: new Set() };
    byOrder[key].picks.push(p);
    byOrder[key].totalQty += parseFloat(p.quantity || 0);
    byOrder[key].items.add(p.item_code);
  });

  const orderNos = Object.keys(byOrder);
  orderDecisions = {};

  // Get order_meta from allocation data for shipment/destination info
  const meta = allocationData && allocationData.summary ? allocationData.summary : {};

  let html = '';
  orderNos.forEach((orderNo, idx) => {
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

function setDecision(orderNo, decision) {
  orderDecisions[orderNo] = decision;

  // Update card visual state
  const card = document.getElementById('card-' + orderNo);
  card.className = 'order-card is-' + decision;

  // Update button active states
  card.querySelectorAll('.order-card-btns button').forEach(btn => btn.classList.remove('active'));
  card.querySelector('.btn-' + (decision === 'confirm' ? 'confirm' : decision === 'stage' ? 'stage' : 'cancel')).classList.add('active');

  updateTally();
}

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

  // Enable apply button only when all orders are decided
  document.getElementById('applyDecisionsBtn').disabled = (pending > 0);
}

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
      document.getElementById('confirmSection').style.display = 'none';
      document.getElementById('resultsSection').style.display = 'block';

      const s = allocationData.summary;
      document.getElementById('statsCards').innerHTML = `
        <div class="alloc-stat" style="background:#dcfce7"><div class="num" style="color:#16a34a">${data.confirmed}</div><div class="lbl">Confirmed</div></div>
        <div class="alloc-stat" style="background:#fef9c3"><div class="num" style="color:#ca8a04">${data.staged}</div><div class="lbl">Staged</div></div>
        <div class="alloc-stat" style="background:#fee2e2"><div class="num" style="color:#dc2626">${data.cancelled}</div><div class="lbl">Cancelled</div></div>
        <div class="alloc-stat" style="background:var(--wms-light)"><div class="num" style="color:var(--wms-primary)">${s.total_items}</div><div class="lbl">Total Items</div></div>
      `;

      // Setup download button for final WMS
      var dlBtn = document.getElementById('downloadBtn');
      dlBtn.href = 'preview_wms_changes.php?id=' + allocationData.result_id;
      dlBtn.querySelector('.btn-icon').innerHTML = '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>';
      var txtNode = dlBtn.childNodes[2];
      if (txtNode) txtNode.textContent = ' Download Final WMS';

      // Show picklist download too
      document.getElementById('printBtn').style.display = '';
      if (allocationData.picklist_file) {
        document.getElementById('printBtn').href = 'print_picklist.php?id=' + allocationData.result_id;
      }
      // Hide old WMS button (replaced by final WMS)
      document.getElementById('downloadWmsBtn').style.display = 'none';

      // Hide picks/replenishments sections (already processed)
      document.getElementById('picksSection').style.display = 'none';
      document.getElementById('replenishmentsSection').style.display = 'none';

      btn.innerHTML = btnText;
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
</script>
</body>
</html>
