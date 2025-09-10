<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class UpdateProductQuantity extends Command
{
    protected $signature = 'test:update-quantity {product_id} {quantity}';
    protected $description = 'Update product quantity for testing';

    public function handle()
    {
        $productId = $this->argument('product_id');
        $quantity = $this->argument('quantity');

        $product = Product::find($productId);
        if (!$product) {
            $this->error("Product with ID {$productId} not found");
            return 1;
        }

        $product->update(['quantity' => $quantity]);
        $this->info("✅ Updated product {$productId} quantity to {$quantity}");

        return 0;
    }
}
