<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DebugImageDeletion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'debug:image-deletion {--product-id= : Test specific product} {--test-delete : Actually test deletion}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug image deletion issues';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Debugging image deletion...');

        // Check Cloudinary config
        $this->checkCloudinaryConfig();

        // List products with images
        $this->listProductsWithImages();

        // Test specific product if provided
        if ($productId = $this->option('product-id')) {
            $this->testProductDeletion($productId);
        }

        $this->info('Debug completed!');
    }

    private function checkCloudinaryConfig()
    {
        $this->info('📋 Checking Cloudinary configuration...');

        $cloudName = env('CLOUDINARY_CLOUD_NAME');
        $apiKey = env('CLOUDINARY_API_KEY');
        $apiSecret = env('CLOUDINARY_API_SECRET');

        $this->table(['Setting', 'Value', 'Status'], [
            ['CLOUDINARY_CLOUD_NAME', $cloudName ?: 'NOT SET', $cloudName ? '✅' : '❌'],
            ['CLOUDINARY_API_KEY', $apiKey ? substr($apiKey, 0, 8) . '...' : 'NOT SET', $apiKey ? '✅' : '❌'],
            ['CLOUDINARY_API_SECRET', $apiSecret ? '***' . substr($apiSecret, -4) : 'NOT SET', $apiSecret ? '✅' : '❌'],
        ]);

        if (!$cloudName || !$apiKey || !$apiSecret) {
            $this->error('❌ Cloudinary credentials are incomplete!');
            return false;
        }

        $this->info('✅ Cloudinary credentials are configured');
        return true;
    }

    private function listProductsWithImages()
    {
        $this->info('📦 Products with images:');

        $products = Product::whereNotNull('image_url')->get(['id', 'name', 'image_url']);

        if ($products->isEmpty()) {
            $this->warn('No products with images found');
            return;
        }

        $tableData = [];
        foreach ($products as $product) {
            $isCloudinary = str_contains($product->image_url, 'res.cloudinary.com');
            $tableData[] = [
                $product->id,
                $product->name,
                $isCloudinary ? 'Cloudinary' : 'Local',
                substr($product->image_url, 0, 50) . '...'
            ];
        }

        $this->table(['ID', 'Name', 'Type', 'Image URL'], $tableData);
    }

    private function testProductDeletion($productId)
    {
        $this->info("🧪 Testing deletion for product ID: {$productId}");

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

        // Extract public ID
        $publicId = $this->getCloudinaryPublicIdFromUrl($product->image_url);
        if (!$publicId) {
            $this->warn('Could not extract public ID (might be local image)');
            return;
        }

        $this->line("Extracted Public ID: {$publicId}");

        if ($this->option('test-delete')) {
            $this->warn('⚠️  This will actually delete the image from Cloudinary!');
            if (!$this->confirm('Are you sure you want to proceed?')) {
                $this->info('Test cancelled');
                return;
            }

            $result = $this->deleteCloudinaryImage($publicId);
            if ($result) {
                $this->info('✅ Image deletion successful');
            } else {
                $this->error('❌ Image deletion failed');
            }
        } else {
            $this->info('Use --test-delete flag to actually test deletion');
        }
    }

    /**
     * Extract Cloudinary public_id from URL
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
     * Delete image from Cloudinary
     */
    private function deleteCloudinaryImage(string $publicId): bool
    {
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $this->line("HTTP Code: {$httpCode}");
            if ($curlError) {
                $this->line("CURL Error: {$curlError}");
            }
            $this->line("Response: {$response}");

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                if (isset($result['result']) && $result['result'] === 'ok') {
                    return true;
                } else {
                    $this->error("Unexpected response: " . json_encode($result));
                    return false;
                }
            } else {
                $this->error("HTTP Error {$httpCode}");
                return false;
            }
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
            return false;
        }
    }
}
