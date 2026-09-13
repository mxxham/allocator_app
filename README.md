<div align="center">

# K-one Allocator Desktop

### Standalone Warehouse Picking Tool

A desktop-first PHP application for warehouse picking allocation with FEFO logic, automatic replenishment, and Excel picklist generation. Wrapped in a C# WebView2 desktop app for zero-config deployment.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![.NET](https://img.shields.io/badge/.NET-8.0-512BD4?style=for-the-badge&logo=dotnet&logoColor=white)](https://dotnet.microsoft.com)
[![WebView2](https://img.shields.io/badge/WebView2-Chromium-4285F4?style=for-the-badge&logo=googlechrome&logoColor=white)](https://developer.microsoft.com/en-us/microsoft-edge/webview2/)
[![PhpSpreadsheet](https://img.shields.io/badge/PhpSpreadsheet-5.5-3DA343?style=flat-square)](#)

<br>

[![License](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](#license)
[![Version](https://img.shields.io/badge/Version-1.0.0-013d3c?style=flat-square)](#)

</div>

---

## Overview

K-one Allocator is a **standalone desktop tool** designed for warehouse picking operations. It takes your WMS snapshot (Excel), applies FEFO-based allocation logic, and generates a picklist ready for printing.

**Key capabilities:**
- **FEFO allocation** — First Expired, First Out ordering for batch/expiry management
- **Automatic replenishment** — triggers bulk-to-pickface moves when pickface stock is insufficient
- **Inbound merger** — merge daily inbound receipts into the WMS snapshot before allocation
- **Surgical Excel editing** — ZIP-level WMS sheet updates that preserve all formulas and styles
- **Desktop wrapper** — no server setup required, just double-click the `.exe`

---

## Features

<table>
<tr>
<td width="50%" valign="top">

### Allocation Engine

| Feature | Description |
|---------|-------------|
| **FEFO Logic** | Picks earliest expiry first across all bins |
| **Pallet Splitting** | Full pallets from bulk, remainder from pickface |
| **Auto-Replenishment** | Bulk → pickface moves when stock is low |
| **Bin-to-Bin Tracking** | Shows exactly where replenishment moved stock |
| **Qty Moved Column** | Inline replenishment quantity per pick line |

</td>
<td width="50%" valign="top">

### Picklist Output

| Feature | Description |
|---------|-------------|
| **Excel Export** | Full picklist with Summary, Picks, Replenishments sheets |
| **Print View** | Print-friendly HTML picklist with batch/expiry info |
| **Grouped by Order** | Picks organized by shipment/delivery number |
| **Qty Moved** | Shows how much was relocated during replenishment |
| **Conflict Detection** | Reports bin mismatches and shortfalls |

</td>
</tr>
</table>

### Inbound Merger

<details>
<summary><strong>Merge daily inbound receipts into WMS snapshot</strong></summary>

| Capability | Description |
|------------|-------------|
| **Additive Stock** | If a location already exists in WMS, quantities are added |
| **Conflict Detection** | Reports when inbound data conflicts with existing WMS data |
| **Unmatched Bin Report** | Shows inbound bins not found in current WMS |
| **Formula Preservation** | Uses `setReadDataOnly(true)` to keep formulas intact |

</details>

---

## Tech Stack

<table>
<tr>
<td width="50%" valign="top">

#### Backend
- **PHP 8.2+** with strict typing
- **PhpSpreadsheet ^5.5** — Excel read/write
- **No database** — operates on Excel files

#### Desktop Wrapper
- **C# .NET 8.0** — WebView2 host
- **PHP 8.4 bundled** — embedded PHP runtime
- **Zero-config** — just run the `.exe`

</td>
<td width="50%" valign="top">

#### Frontend
- **Vanilla HTML/CSS/JS** — no build step
- **WMS Design System** — consistent with K-one styling
- **Print CSS** — optimized for paper output
- **Drag & Drop** — intuitive file upload

#### File Processing
- **ZIP-level editing** — surgical WMS sheet updates
- **Formula preservation** — no style/formula stripping
- **Byte-level integrity** — verification on every write

</td>
</tr>
</table>

---

## Quick Start

### Prerequisites

| Requirement | Version |
|-------------|---------|
| .NET Runtime | 8.0+ ([download](https://dotnet.microsoft.com/download/dotnet/8.0)) |
| Windows | 10/11 |

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/mxxham/allocator_app.git
cd allocator_app

# 2. Install PHP dependencies
composer install

# 3. Build the desktop wrapper
.\build.bat
```

### Running

```bash
# Option A: Double-click the executable
wrapper\AllocatorDesktop\bin\Release\net8.0-windows\K-oneAllocator.exe

# Option B: Run from source (development)
php -S localhost:8080 -t www
# Then open http://localhost:8080
```

---

## Usage

### 1. Upload WMS Snapshot
Drag and drop your WMS Excel file (`.xlsx`) into the upload zone. The system reads bin locations, stock quantities, and batch/expiry data.

### 2. Upload Order File
Drag and drop your order Excel file. The system reads order lines with material codes, quantities, and delivery info.

### 3. Generate Picklist
Click **Generate Picklist** to run the allocation engine. The system:
- Splits orders into full pallets (bulk) + remainder (pickface)
- Triggers replenishment when pickface stock is insufficient
- Generates a grouped picklist with bin-to-bin tracking

### 4. Print or Download
- **Print** — opens a print-friendly view of the picklist
- **Download Excel** — exports the full picklist as `.xlsx`
- **Merge Inbound** — optionally merge inbound receipts before allocation

---

## Architecture

```
┌─────────────────────────────────────────────────────┐
│  Desktop Wrapper (C# WebView2)                      │
│  └── www/ (PHP application root)                    │
├─────────────────────────────────────────────────────┤
│  Frontend                                           │
│  ├── index.php (upload + results UI)                │
│  ├── print_picklist.php (print view)                │
│  └── assets/css/ (WMS design system)                │
├─────────────────────────────────────────────────────┤
│  Backend                                            │
│  ├── api.php (action router)                        │
│  └── classes/                                       │
│      ├── Allocator.php (FEFO allocation engine)     │
│      ├── ExcelParser.php (file parsing)             │
│      ├── InboundMerger.php (receipt merging)        │
│      ├── PicklistGenerator.php (Excel output)       │
│      └── WmsSheetUpdater.php (ZIP-level editor)     │
└─────────────────────────────────────────────────────┘
```

### Class Responsibilities

| Class | Purpose |
|-------|---------|
| `Allocator` | Core allocation logic — FEFO ordering, pallet splitting, replenishment triggering |
| `ExcelParser` | Reads WMS snapshots, order files, and Master SKU data from Excel |
| `InboundMerger` | Merges inbound receipts into WMS snapshot (additive, with conflict detection) |
| `PicklistGenerator` | Generates picklist Excel with Summary, Picks, Replenishments, and Errors sheets |
| `WmsSheetUpdater` | Surgical ZIP-level editor — updates only the WMS sheet without touching formulas/styles |

---

## File Structure

```
allocator_app/
├── www/                            # Application root
│   ├── classes/                    # Core business logic
│   │   ├── Allocator.php           # FEFO allocation engine
│   │   ├── ExcelParser.php         # Excel file parsing
│   │   ├── InboundMerger.php       # Inbound receipt merging
│   │   ├── PicklistGenerator.php   # Picklist Excel generation
│   │   └── WmsSheetUpdater.php     # ZIP-level WMS editor
│   ├── api.php                     # Backend API router
│   ├── index.php                   # Main UI
│   ├── print_picklist.php          # Print-friendly picklist
│   ├── download.php                # File download handler
│   └── assets/                     # CSS, images
├── wrapper/                        # C# WebView2 desktop wrapper
│   └── AllocatorDesktop/           # .NET project
├── tests/                          # PHPUnit tests
├── build.bat                       # Build script
├── composer.json                   # PHP dependencies
├── DESIGN.md                       # Design system documentation
└── README.md
```

---

## Allocation Logic

### Flow Diagram

```
Order Line
    │
    ├── UPP = 1? (Fluidbag/IBC)
    │   └── Pick all from pickface (or bulk)
    │
    └── UPP > 1? (Drum/Carton/Pail)
        │
        ├── Full Pallets = qty ÷ UPP
        │   └── Pick from bulk bins (FEFO)
        │
        └── Remainder = qty mod UPP
            │
            ├── Pickface stock ≥ remainder?
            │   └── Pick from pickface
            │
            └── Pickface stock < remainder?
                ├── Trigger replenishment (bulk → pickface)
                │   └── Bin to Bin: CB08C01 → CA03A01
                │   └── Qty Moved: 4 (full pallet)
                │
                └── Pick remainder from pickface
                    └── Qty: 2 (order need)
```

### Key Concepts

| Term | Meaning |
|------|---------|
| **UPP** | Units Per Pallet — determines full pallet size |
| **FEFO** | First Expired, First Out — expiry-based picking order |
| **Pickface** | A-level bins at ground level for easy picking |
| **Bulk** | B/C/D/E level bins for full pallet storage |
| **Replenishment** | Moving stock from bulk to pickface when needed |
| **Bin to Bin** | The FROM → TO path of a replenishment move |
| **Qty Moved** | How much was relocated during replenishment |

---

## Testing

```bash
# Run all tests
.\vendor\bin\phpunit

# Run with coverage
.\vendor\bin\phpunit --coverage-html coverage
```

---

## Distribution

### Sharing with Colleagues

The app is **self-contained** — no install required:

1. Zip the entire build folder:
   ```
   wrapper\AllocatorDesktop\bin\Release\net8.0-windows\
   ```

2. Send the zip to your colleague

3. They extract and run `K-oneAllocator.exe`

**Requirements:**
- Windows 10/11
- .NET 8 Runtime installed

**What's included:**
- PHP 8.4 runtime
- PhpSpreadsheet library
- All application code

---

## License

This project is licensed under the **MIT License**.

---

<div align="center">

**Version:** 1.0.0 · **Built for:** Shell CKB · **By:** [mxxham](https://github.com/mxxham)

</div>
