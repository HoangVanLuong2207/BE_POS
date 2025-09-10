<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupCloudinaryImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloudinary:cleanup {--dry-run : Show what would be deleted without actually deleting} {--force : Force deletion without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up orphaned images on Cloudinary that are no longer referenced by products';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Cloudinary image cleanup...');

        // Check Cloudinary configuration
        if (!$this->checkCloudinaryConfig()) {
            return 1;
        }

        // Get all images from Cloudinary
        $cloudinaryImages = $this->getAllCloudinaryImages();
        if (empty($cloudinaryImages)) {
            $this->info('No images found on Cloudinary or failed to fetch images');
            return 0;
        }

        $this->info("Found " . count($cloudinaryImages) . " images on Cloudinary");

        // Get all product image URLs
        $productImages = Product::whereNotNull('image_url')
            ->where('image_url', 'like', '%res.cloudinary.com%')
            ->pluck('image_url')
            ->toArray();

        $this->info("Found " . count($productImages) . " products with Cloudinary images");

        // Find orphaned images
        $orphanedImages = $this->findOrphanedImages($cloudinaryImages, $productImages);

        if (empty($orphanedImages)) {
            $this->info('✅ No orphaned images found!');
            return 0;
        }

        $this->warn("Found " . count($orphanedImages) . " orphaned images:");
        foreach ($orphanedImages as $image) {
            $this->line("  - {$image['public_id']} (created: {$image['created_at']})");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run mode - no images will be deleted');
            return 0;
        }

        // Confirm deletion
        if (!$this->option('force')) {
            if (!$this->confirm('Do you want to delete these orphaned images?')) {
                $this->info('Cleanup cancelled');
                return 0;
            }
        }

        // Delete orphaned images
        $deletedCount = $this->deleteOrphanedImages($orphanedImages);

        $this->info("✅ Cleanup completed! Deleted {$deletedCount} orphaned images");

        return 0;
    }

    private function checkCloudinaryConfig(): bool
    {
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
        return true;
    }

    private function getAllCloudinaryImages(): array
    {
        try {
            $cloudName = env('CLOUDINARY_CLOUD_NAME');
            $apiKey = env('CLOUDINARY_API_KEY');
            $apiSecret = env('CLOUDINARY_API_SECRET');

            $timestamp = time();
            $paramsToSign = "max_results=500&timestamp={$timestamp}";
            $signature = sha1($paramsToSign.$apiSecret);

            $url = "https://api.cloudinary.com/v1_1/{$cloudName}/resources/image";
            $postData = [
                'max_results' => 500,
                'api_key' => $apiKey,
                'timestamp' => $timestamp,
                'signature' => $signature
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                return $result['resources'] ?? [];
            } else {
                $this->error("Failed to fetch images from Cloudinary: HTTP {$httpCode}");
                if ($curlError) {
                    $this->error("CURL Error: {$curlError}");
                }
                $this->error("Response: " . substr($response, 0, 500) . "...");
                return [];
            }
        } catch (\Exception $e) {
            $this->error("Exception while fetching images: " . $e->getMessage());
            return [];
        }
    }

    private function findOrphanedImages(array $cloudinaryImages, array $productImages): array
    {
        $orphanedImages = [];

        // Extract public IDs from product image URLs
        $productPublicIds = [];
        foreach ($productImages as $imageUrl) {
            $publicId = $this->getCloudinaryPublicIdFromUrl($imageUrl);
            if ($publicId) {
                $productPublicIds[] = $publicId;
            }
        }

        // Find images that are not referenced by any product
        foreach ($cloudinaryImages as $image) {
            if (!in_array($image['public_id'], $productPublicIds)) {
                $orphanedImages[] = $image;
            }
        }

        return $orphanedImages;
    }

    private function deleteOrphanedImages(array $orphanedImages): int
    {
        $deletedCount = 0;
        $progressBar = $this->output->createProgressBar(count($orphanedImages));
        $progressBar->start();

        foreach ($orphanedImages as $image) {
            if ($this->deleteCloudinaryImage($image['public_id'])) {
                $deletedCount++;
                Log::info('Deleted orphaned image', ['public_id' => $image['public_id']]);
            } else {
                Log::warning('Failed to delete orphaned image', ['public_id' => $image['public_id']]);
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        return $deletedCount;
    }

    private function deleteCloudinaryImage(string $publicId): bool
    {
        try {
            $cloudName = env('CLOUDINARY_CLOUD_NAME');
            $apiKey = env('CLOUDINARY_API_KEY');
            $apiSecret = env('CLOUDINARY_API_SECRET');

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
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                return isset($result['result']) && $result['result'] === 'ok';
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Exception while deleting image', [
                'public_id' => $publicId,
                'error' => $e->getMessage()
            ]);
            return false;
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
}
