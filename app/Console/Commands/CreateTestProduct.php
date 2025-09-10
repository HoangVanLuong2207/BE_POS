<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Console\Command;

class CreateTestProduct extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:create-product {--with-image : Create with a test image URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a test product for debugging';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Creating test product...');

        // Get or create a category
        $category = Category::first();
        if (!$category) {
            $category = Category::create([
                'name' => 'Test Category',
                'description' => 'Test category for debugging'
            ]);
            $this->info('Created test category');
        }

        // Create test product
        $productData = [
            'name' => 'Test Product ' . time(),
            'description' => 'Test product for debugging image deletion',
            'price' => 100.00,
            'payprice' => 80.00,
            'quantity' => 10,
            'category_id' => $category->id,
            'active' => true,
        ];

        if ($this->option('with-image')) {
            // Use a sample Cloudinary URL for testing
            $productData['image_url'] = 'https://res.cloudinary.com/dhogukev1/image/upload/v1234567890/products/test-product.jpg';
        }

        $product = Product::create($productData);

        $this->info("✅ Created test product with ID: {$product->id}");
        $this->line("Name: {$product->name}");
        $this->line("Image URL: " . ($product->image_url ?: 'None'));

        return 0;
    }
}
