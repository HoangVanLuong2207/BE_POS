<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class UpdateProductImage extends Command
{
    protected $signature = 'test:update-image {product_id} {image_url}';
    protected $description = 'Update product image URL for testing';

    public function handle()
    {
        $productId = $this->argument('product_id');
        $imageUrl = $this->argument('image_url');

        $product = Product::find($productId);
        if (!$product) {
            $this->error("Product with ID {$productId} not found");
            return 1;
        }

        $product->update(['image_url' => $imageUrl]);
        $this->info("✅ Updated product {$productId} with image URL: {$imageUrl}");

        return 0;
    }
}
