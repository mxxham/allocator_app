<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ShortfallTest extends TestCase
{
    private function makeAllocator(): Allocator
    {
        return new Allocator();
    }

    /**
     * When bulk bins can't cover all requested pallets, the shortfall is
     * reported in errors with the exact count and missing quantity.
     */
    public function testBulkShortfallReportedWhenBinsExhausted(): void
    {
        $allocator = $this->makeAllocator();

        // Drum UPP=4 (valid)
        $allocator->loadProducts([
            'MAT-001' => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // 1 bulk bin with only 4 units (1 pallet worth)
        // Order needs 12 units = 3 pallets → shortfall of 2
        $allocator->loadWmsLocations([
            ['location' => 'A01-B01-01', 'item_code' => 'MAT-001', 'on_hand' => 4, 'batch_number' => 'B001', 'expiry_date' => '2027-01-01', 'is_pickface' => false],
        ]);

        // 1 pickface bin for remainder
        $allocator->loadWmsLocations([
            ['location' => 'A01-A01-01', 'item_code' => 'MAT-001', 'on_hand' => 10, 'batch_number' => 'B001', 'expiry_date' => '2027-01-01', 'is_pickface' => true],
        ]);

        // Order: need 12 units → 3 full pallets (3×4=12), remainder 0
        $result = $allocator->allocate([
            ['order_no' => 'ORD-001', 'material' => 'MAT-001', 'quantity' => 12],
        ]);

        // Only 1 pallet available from 4 units → 1 pick
        $fullPalletPicks = array_filter($result['picks'], fn($p) => ($p['type'] ?? '') === 'full_pallet');
        $this->assertCount(1, $fullPalletPicks);

        // Shortfall error must be present
        $shortfallErrors = array_filter($result['errors'], fn($e) => str_contains($e, 'shortfall'));
        $this->assertCount(1, $shortfallErrors, 'Expected exactly one shortfall error');

        $error = array_values($shortfallErrors)[0];
        $this->assertStringContainsString('2 full pallet(s)', $error);
        $this->assertStringContainsString('8 units unavailable', $error);
        $this->assertStringContainsString('ORD-001', $error);
        $this->assertStringContainsString('MAT-001', $error);
    }

    /**
     * When there are enough bulk bins, no shortfall is reported.
     */
    public function testNoShortfallWhenSufficientBins(): void
    {
        $allocator = $this->makeAllocator();

        $allocator->loadProducts([
            'MAT-002' => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // 3 bulk bins, each with 4+ units
        $allocator->loadWmsLocations([
            ['location' => 'A01-B01-01', 'item_code' => 'MAT-002', 'on_hand' => 4, 'batch_number' => 'B001', 'expiry_date' => '2027-01-01', 'is_pickface' => false],
            ['location' => 'A01-B02-01', 'item_code' => 'MAT-002', 'on_hand' => 4, 'batch_number' => 'B002', 'expiry_date' => '2027-02-01', 'is_pickface' => false],
            ['location' => 'A01-B03-01', 'item_code' => 'MAT-002', 'on_hand' => 4, 'batch_number' => 'B003', 'expiry_date' => '2027-03-01', 'is_pickface' => false],
        ]);

        $allocator->loadWmsLocations([
            ['location' => 'A01-A01-01', 'item_code' => 'MAT-002', 'on_hand' => 10, 'batch_number' => 'B001', 'expiry_date' => '2027-01-01', 'is_pickface' => true],
        ]);

        // 3 pallets x 4 = 12 units
        $result = $allocator->allocate([
            ['order_no' => 'ORD-002', 'material' => 'MAT-002', 'quantity' => 12],
        ]);

        $fullPalletPicks = array_filter($result['picks'], fn($p) => ($p['type'] ?? '') === 'full_pallet');
        $this->assertCount(3, $fullPalletPicks);

        $shortfallErrors = array_filter($result['errors'], fn($e) => str_contains($e, 'shortfall'));
        $this->assertCount(0, $shortfallErrors, 'No shortfall expected');
    }

    /**
     * When there is only 1 bulk bin and we need more than 1 pallet,
     * the shortfall reflects exactly the difference.
     */
    public function testPartialShortfallWithOneBin(): void
    {
        $allocator = $this->makeAllocator();

        // Drum UPP=4 (valid)
        $allocator->loadProducts([
            'MAT-003' => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // 1 bulk bin with 4 units (1 pallet)
        $allocator->loadWmsLocations([
            ['location' => 'A01-B01-01', 'item_code' => 'MAT-003', 'on_hand' => 4, 'batch_number' => 'B001', 'expiry_date' => '2027-01-01', 'is_pickface' => false],
        ]);

        $allocator->loadWmsLocations([
            ['location' => 'A01-A01-01', 'item_code' => 'MAT-003', 'on_hand' => 100, 'batch_number' => 'B001', 'expiry_date' => '2027-01-01', 'is_pickface' => true],
        ]);

        // Need 12 units = 3 pallets (3x4), only 1 available
        $result = $allocator->allocate([
            ['order_no' => 'ORD-003', 'material' => 'MAT-003', 'quantity' => 12],
        ]);

        $fullPalletPicks = array_filter($result['picks'], fn($p) => ($p['type'] ?? '') === 'full_pallet');
        $this->assertCount(1, $fullPalletPicks, 'Only 1 full pallet picked');

        $shortfallErrors = array_filter($result['errors'], fn($e) => str_contains($e, 'shortfall'));
        $this->assertCount(1, $shortfallErrors);

        $error = array_values($shortfallErrors)[0];
        $this->assertStringContainsString('2 full pallet(s)', $error);
        $this->assertStringContainsString('8 units unavailable', $error);
    }

    /**
     * Verify pickFullPallets returns both picks and shortfall_pallets.
     */
    public function testPickFullPalletsReturnFormat(): void
    {
        $allocator = $this->makeAllocator();

        // Drum UPP=4 (valid)
        $allocator->loadProducts([
            'MAT-004' => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // 1 bulk bin with 8 units (2 pallets)
        $allocator->loadWmsLocations([
            ['location' => 'A01-B01-01', 'item_code' => 'MAT-004', 'on_hand' => 8, 'batch_number' => 'B001', 'expiry_date' => '2027-01-01', 'is_pickface' => false],
        ]);

        // Need 20 units = 5 pallets (5x4=20), only 2 available
        $result = $allocator->allocate([
            ['order_no' => 'ORD-004', 'material' => 'MAT-004', 'quantity' => 20],
        ]);

        // Verify picks and shortfall error
        $fullPalletPicks = array_filter($result['picks'], fn($p) => ($p['type'] ?? '') === 'full_pallet');
        $this->assertCount(2, $fullPalletPicks, '2 full pallets available from 8 units');

        $shortfallErrors = array_filter($result['errors'], fn($e) => str_contains($e, 'shortfall'));
        $this->assertCount(1, $shortfallErrors);
        $this->assertStringContainsString('3 full pallet(s)', array_values($shortfallErrors)[0]);
        $this->assertStringContainsString('12 units unavailable', array_values($shortfallErrors)[0]);
    }

    /**
     * Edge case: zero bulk bins available -> all pallets are short.
     */
    public function testZeroBinsGivesFullShortfall(): void
    {
        $allocator = $this->makeAllocator();

        $allocator->loadProducts([
            'MAT-005' => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // No bulk bins at all -- only pickface
        $allocator->loadWmsLocations([
            ['location' => 'A01-A01-01', 'item_code' => 'MAT-005', 'on_hand' => 100, 'batch_number' => 'B001', 'expiry_date' => '2027-01-01', 'is_pickface' => true],
        ]);

        // Need 2 pallets (8 units)
        $result = $allocator->allocate([
            ['order_no' => 'ORD-005', 'material' => 'MAT-005', 'quantity' => 8],
        ]);

        $fullPalletPicks = array_filter($result['picks'], fn($p) => ($p['type'] ?? '') === 'full_pallet');
        $this->assertCount(0, $fullPalletPicks, 'No picks when no bulk bins');

        $shortfallErrors = array_filter($result['errors'], fn($e) => str_contains($e, 'shortfall'));
        $this->assertCount(1, $shortfallErrors);
        $this->assertStringContainsString('2 full pallet(s)', array_values($shortfallErrors)[0]);
        $this->assertStringContainsString('8 units unavailable', array_values($shortfallErrors)[0]);
    }
}
