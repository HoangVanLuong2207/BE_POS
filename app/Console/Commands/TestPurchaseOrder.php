<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Console\Command;

class TestPurchaseOrder extends Command
{
    protected $signature = 'test:purchase-order {--create-sample : Create sample data}';
    protected $description = 'Test purchase order functionality';

    public function handle()
    {
        $this->info('🧪 Testing Purchase Order functionality...');

        if ($this->option('create-sample')) {
            $this->createSampleData();
        }

        $this->testPurchaseOrderCreation();
        $this->testBulkImport();
        $this->testDashboardStats();

        $this->info('✅ All tests completed!');
    }

    private function createSampleData()
    {
        $this->info('📦 Creating sample data...');

        // Create sample category if not exists
        $category = Category::firstOrCreate(
            ['name' => 'Test Category'],
            ['description' => 'Test category for purchase orders']
        );

        // Create sample products if not exist
        $products = [
            ['name' => 'Test Product 1', 'price' => 100, 'payprice' => 150, 'quantity' => 0],
            ['name' => 'Test Product 2', 'price' => 200, 'payprice' => 300, 'quantity' => 0],
            ['name' => 'Test Product 3', 'price' => 300, 'payprice' => 450, 'quantity' => 0],
        ];

        foreach ($products as $productData) {
            Product::firstOrCreate(
                ['name' => $productData['name']],
                array_merge($productData, [
                    'category_id' => $category->id,
                    'description' => 'Test product for purchase orders',
                    'active' => true,
                ])
            );
        }

        $this->info('✅ Sample data created');
    }

    private function testPurchaseOrderCreation()
    {
        $this->info('🧾 Testing purchase order creation...');

        $products = Product::take(3)->get();
        if ($products->isEmpty()) {
            $this->warn('No products found. Run with --create-sample first.');
            return;
        }

        $purchaseOrder = PurchaseOrder::create([
            'purchase_number' => PurchaseOrder::generatePurchaseNumber(),
            'supplier_name' => 'Test Supplier',
            'supplier_phone' => '0123456789',
            'supplier_address' => 'Test Address',
            'purchase_date' => now(),
            'notes' => 'Test purchase order',
            'status' => 'confirmed',
        ]);

        $totalAmount = 0;
        foreach ($products as $product) {
            $quantity = rand(10, 50);
            $purchasePrice = $product->price;
            $sellingPrice = $product->payprice;
            $itemTotal = $purchasePrice * $quantity;

            $purchaseOrder->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_description' => $product->description,
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'quantity' => $quantity,
                'unit' => 'cái',
            ]);

            // Update product stock
            $product->increment('quantity', $quantity);
            $totalAmount += $itemTotal;
        }

        $purchaseOrder->updateTotalAmount();

        $this->info("✅ Created purchase order: {$purchaseOrder->purchase_number}");
        $this->info("   Total amount: {$purchaseOrder->total_amount}");
        $this->info("   Items count: {$purchaseOrder->items->count()}");
    }

    private function testBulkImport()
    {
        $this->info('📥 Testing bulk import...');

        $category = Category::first();
        if (!$category) {
            $this->warn('No category found. Run with --create-sample first.');
            return;
        }

        $bulkProducts = [
            [
                'name' => 'Bulk Product 1',
                'description' => 'Bulk imported product 1',
                'category_id' => $category->id,
                'price' => 50,
                'payprice' => 75,
                'quantity' => 100,
                'active' => true,
            ],
            [
                'name' => 'Bulk Product 2',
                'description' => 'Bulk imported product 2',
                'category_id' => $category->id,
                'price' => 80,
                'payprice' => 120,
                'quantity' => 50,
                'active' => true,
            ],
        ];

        $createdCount = 0;
        foreach ($bulkProducts as $productData) {
            try {
                Product::create($productData);
                $createdCount++;
            } catch (\Exception $e) {
                $this->warn("Failed to create product: {$productData['name']} - {$e->getMessage()}");
            }
        }

        $this->info("✅ Bulk import completed: {$createdCount} products created");
    }

    private function testDashboardStats()
    {
        $this->info('📊 Testing dashboard stats...');

        $year = now()->year;
        $month = now()->month;

        $purchaseOrders = PurchaseOrder::whereYear('purchase_date', $year)
            ->whereMonth('purchase_date', $month)
            ->get();

        $totalAmount = $purchaseOrders->sum('total_amount');
        $totalOrders = $purchaseOrders->count();

        $this->info("📈 Purchase stats for {$month}/{$year}:");
        $this->info("   Total orders: {$totalOrders}");
        $this->info("   Total amount: {$totalAmount}");

        $products = Product::count();
        $this->info("   Total products: {$products}");
    }
}
