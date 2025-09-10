<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestCloudinaryDeletion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloudinary:test-deletion {--product-id= : Test deletion for specific product} {--cleanup : Clean up orphaned images}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Cloudinary image deletion functionality and cleanup orphaned images';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing Cloudinary deletion functionality...');

        // Check Cloudinary configuration
        $this->checkCloudinaryConfig();

        // Test specific product if provided
        if ($productId = $this->option('product-id')) {
            $this->testProductDeletion($productId);
        }

        // Cleanup orphaned images if requested
        if ($this->option('cleanup')) {
            $this->cleanupOrphanedImages();
        }

        $this->info('Test completed!');
    }

    private function checkCloudinaryConfig()
    {
        $this->info('Checking Cloudinary configuration...');

        $cloudName = env('CLOUDINARY_CLOUD_NAME');
        $apiKey = env('CLOUDINARY_API_KEY');
        $apiSecret = env('CLOUDINARY_API_SECRET');

        if (!$cloudName || !$apiKey || !$apiSecret) {
            $this->error('❌ Cloudinary credentials are missing!');
            $this->line('Please add the following to your .env file:');
            $this->line('CLOUDINARY_CLOUD_NAME=your_cloud_name');
            $this->line('CLOUDINARY_API_KEY=your_api_key');
            $this->line('CLOUDINARY_API_SECRET=your_api_secret');
            return false;
        }

        $this->info('✅ Cloudinary credentials are configured');
        $this->line("Cloud Name: {$cloudName}");
        $this->line("API Key: " . substr($apiKey, 0, 8) . '...');
        return true;
    }

    private function testProductDeletion($productId)
    {
        $this->info("Testing deletion for product ID: {$productId}");

        $product = Product::find($productId);
        if (!$product) {
            $this->error("Product with ID {$productId} not found");
            return;
        }

        $this->line("Product: {$product->name}");
        $this->line("Image URL: {$product->image_url}");

        if (!$product->image_url) {
            $this->warn('Product has no image URL');
            return;
        }

        // Test the deletion logic
        $publicId = $this->getCloudinaryPublicIdFromUrl($product->image_url);
        if (!$publicId) {
            $this->warn('Could not extract public ID from URL (might be local image)');
            return;
        }

        $this->line("Extracted Public ID: {$publicId}");

        // Test deletion
        $result = $this->destroyCloudinaryPublicId($publicId);
        if ($result) {
            $this->info('✅ Image deletion test successful');
        } else {
            $this->error('❌ Image deletion test failed');
        }
    }

    private function cleanupOrphanedImages()
    {
        $this->info('Starting cleanup of orphaned images...');

        // Get all products with Cloudinary images
        $products = Product::whereNotNull('image_url')
            ->where('image_url', 'like', '%res.cloudinary.com%')
            ->get();

        $this->line("Found {$products->count()} products with Cloudinary images");

        $orphanedCount = 0;
        foreach ($products as $product) {
            $publicId = $this->getCloudinaryPublicIdFromUrl($product->image_url);
            if ($publicId) {
                // Check if image still exists on Cloudinary
                if (!$this->checkImageExists($publicId)) {
                    $this->line("Orphaned image found for product {$product->id}: {$product->name}");
                    $orphanedCount++;
                }
            }
        }

        $this->info("Found {$orphanedCount} orphaned images");
    }

    /**
     * Extract Cloudinary public_id from URL (copied from ProductController)
     */
    private function getCloudinaryPublicIdFromUrl(?string $url): ?string
    {
        if (!$url || !str_contains($url, 'res.cloudinary.com')) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $uploadPos = strpos($path, '/upload/');
        if ($uploadPos === false) {
            return null;
        }
        $afterUpload = substr($path, $uploadPos + strlen('/upload/'));
        $afterUpload = preg_replace('#^v\d+/#', '', $afterUpload);
        $afterUpload = ltrim($afterUpload, '/');
        $dotPos = strrpos($afterUpload, '.');
        if ($dotPos !== false) {
            $afterUpload = substr($afterUpload, 0, $dotPos);
        }
        return $afterUpload ?: null;
    }

    /**
     * Delete image from Cloudinary (copied from ProductController)
     */
    private function destroyCloudinaryPublicId(?string $publicId): bool
    {
        if (!$publicId) {
            return false;
        }

        try {
            $cloudName = env('CLOUDINARY_CLOUD_NAME');
            $apiKey = env('CLOUDINARY_API_KEY');
            $apiSecret = env('CLOUDINARY_API_SECRET');

            if (!$cloudName || !$apiKey || !$apiSecret) {
                $this->error('Cloudinary credentials not configured');
                return false;
            }

            $timestamp = time();
            $paramsToSign = "public_id={$publicId}&timestamp={$timestamp}";
            $signature = sha1($paramsToSign.$apiSecret);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'public_id' => $publicId,
                'api_key' => $apiKey,
                'timestamp' => $timestamp,
                'signature' => $signature,
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                if (isset($result['result']) && $result['result'] === 'ok') {
                    $this->line("Successfully deleted image: {$publicId}");
                    return true;
                } else {
                    $this->error("Deletion failed: " . json_encode($result));
                    return false;
                }
            } else {
                $this->error("HTTP Error {$httpCode}: {$curlError}");
                $this->error("Response: {$response}");
                return false;
            }
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if image exists on Cloudinary
     */
    private function checkImageExists(string $publicId): bool
    {
        try {
            $cloudName = env('CLOUDINARY_CLOUD_NAME');
            $apiKey = env('CLOUDINARY_API_KEY');
            $apiSecret = env('CLOUDINARY_API_SECRET');

            if (!$cloudName || !$apiKey || !$apiSecret) {
                return false;
            }

            $timestamp = time();
            $paramsToSign = "public_id={$publicId}&timestamp={$timestamp}";
            $signature = sha1($paramsToSign.$apiSecret);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/{$publicId}");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . base64_encode($apiKey . ':' . $apiSecret)
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Exception $e) {
            return false;
        }
    }
}
