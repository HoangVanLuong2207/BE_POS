<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Dashboard;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;

class ProductController extends Controller
{
    /**
     * Create a purchase order for a single product movement (incoming stock)
     */
    private function createPurchaseOrderForIncoming(Product $product, int $incomingQuantity, float $purchasePrice, float $sellingPrice, ?string $note = null): void
    {
        if ($incomingQuantity <= 0) {
            return;
        }

        $purchaseOrder = PurchaseOrder::create([
            'purchase_number'   => PurchaseOrder::generatePurchaseNumber(),
            'supplier_name'     => 'Internal Supplier',
            'supplier_phone'    => null,
            'supplier_address'  => null,
            'purchase_date'     => now(),
            'due_date'          => null,
            'notes'             => $note,
            'status'            => 'confirmed',
            'paid_amount'       => 0,
            'created_by'        => 1,
        ]);

        $purchaseOrder->items()->create([
            'product_id'          => $product->id,
            'product_name'        => $product->name,
            'product_description' => $product->description,
            'purchase_price'      => $purchasePrice,
            'selling_price'       => $sellingPrice,
            'quantity'            => $incomingQuantity,
            'unit'                => 'cái',
            'notes'               => $note,
        ]);

        $purchaseOrder->refresh();
        $purchaseOrder->updateTotalAmount();
    }
    /**
     *
     * Extract Cloudinary public_id (including folders) from a secure URL
     */
    private function getCloudinaryPublicIdFromUrl(?string $url): ?string
    {
        if (!$url || !str_contains($url, 'res.cloudinary.com')) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        // Expecting something like: /<cloud_name>/image/upload/v1699999999/products/filename.jpg
        $uploadPos = strpos($path, '/upload/');
        if ($uploadPos === false) {
            return null;
        }
        $afterUpload = substr($path, $uploadPos + strlen('/upload/'));
        // Remove version prefix if present (e.g., v1699999999/)
        $afterUpload = preg_replace('#^v\d+/#', '', $afterUpload);
        // Remove leading slash
        $afterUpload = ltrim($afterUpload, '/');
        // Strip extension
        $dotPos = strrpos($afterUpload, '.');
        if ($dotPos !== false) {
            $afterUpload = substr($afterUpload, 0, $dotPos);
        }
        return $afterUpload ?: null;
    }

    /**
     * Delete an image from Cloudinary using a signed API request
     */
    private function destroyCloudinaryPublicId(?string $publicId): bool
    {
        if (!$publicId) {
            Log::info('No public ID provided for Cloudinary deletion');
            return false;
        }

        try {
            $cloudName = env('CLOUDINARY_CLOUD_NAME');
            $apiKey = env('CLOUDINARY_API_KEY');
            $apiSecret = env('CLOUDINARY_API_SECRET');

            if (!$cloudName || !$apiKey || !$apiSecret) {
                Log::error('Cloudinary credentials not configured for deletion', [
                    'cloud_name_set' => !empty($cloudName),
                    'api_key_set' => !empty($apiKey),
                    'api_secret_set' => !empty($apiSecret)
                ]);
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

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                if (isset($result['result']) && $result['result'] === 'ok') {
                    Log::info('Successfully deleted image from Cloudinary', ['public_id' => $publicId]);
                    return true;
                } else {
                    Log::warning('Cloudinary deletion returned unexpected result', [
                        'public_id' => $publicId,
                        'response' => $result
                    ]);
                    return false;
                }
            } else {
                Log::error('Cloudinary destroy failed', [
                    'public_id' => $publicId,
                    'http_code' => $httpCode,
                    'curl_error' => $curlError,
                    'response' => $response
                ]);
                return false;
            }
        } catch (Exception $e) {
            Log::error('Cloudinary destroy exception', [
                'public_id' => $publicId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    /**
     * Lấy hoặc tạo dashboard cho tháng hiện tại
     */
    private function getOrCreateDashboard()
    {
        $year = now()->year;
        $month = now()->month;

        $dashboard = Dashboard::firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['subtotal' => 0, 'total' => 0, 'profit' => 0]
        );

        // Ensure all values are properly initialized
        $dashboard->subtotal = $dashboard->subtotal ?? 0;
        $dashboard->total = $dashboard->total ?? 0;
        $dashboard->profit = $dashboard->profit ?? 0;

        return $dashboard;
    }

    /**
     * Cập nhật dashboard
     */
    private function updateDashboard(string $type, Product $product, ?array $oldValues = null, string $status = 'sales')
    {
        try {
            // Get or create the dashboard record
            $dashboard = $this->getOrCreateDashboard();

            // Initialize values if they are null to prevent calculation errors
            $dashboard->subtotal = $dashboard->subtotal ?? 0;
            $dashboard->total = $dashboard->total ?? 0;
            $dashboard->profit = $dashboard->profit ?? 0;

            switch ($type) {
                case 'create':
                    if ($status === 'admin_management') {
                        $dashboard->subtotal += $product->price * $product->quantity;
                    } else {
                        $dashboard->total += $product->payprice * $product->quantity;
                    }
                    break;

                case 'update':
                    // Check if old values exist to avoid a null reference error
                    if (!$oldValues) {
                        break;
                    }

                    // Calculate the change in values
                    $oldSubtotal = $oldValues['price'] * $oldValues['quantity'];
                    $oldTotal    = $oldValues['payprice'] * $oldValues['quantity'];
                    $newSubtotal = $product->price * $product->quantity;
                    $newTotal    = $product->payprice * $product->quantity;

                    if ($status === 'admin_management') {
                        $dashboard->subtotal += ($newSubtotal - $oldSubtotal);
                    } else {
                        $dashboard->total += ($newTotal - $oldTotal);
                    }
                    break;

                case 'delete':
                    if ($status === 'admin_management') {
                        $dashboard->subtotal -= $product->price * $product->quantity;
                    } else {
                        $dashboard->total -= $product->payprice * $product->quantity;
                    }
                    break;
            }

            // Recalculate and save the dashboard profit and totals
            $dashboard->profit = $dashboard->total - $dashboard->subtotal;
            $dashboard->save();
        } catch (Exception $e) {
            // Log the error but don't re-throw to prevent the main action from failing
            Log::error('updateDashboard error: ' . $e->getMessage());
        }
    }


    /**
     * Danh sách sản phẩm (có phân trang FE gửi limit/page)
     */
    public function index(Request $request)
    {
        $keyword = $request->query('keyword');
        $limit   = $request->query('limit', 10);

        $query = Product::with('category');
        if ($keyword) {
            $query->where('name', 'like', "%$keyword%");
        }

        $products = $query->orderBy('id', 'desc')->paginate($limit);

        foreach ($products as $product) {
            $product->category_name = $product->category->name ?? 'Chưa phân loại';
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'product'    => $products->items(),
                'categories' => Category::all(),
                'total'      => $products->total(),
            ]
        ]);
    }

    /**
     * Tạo sản phẩm
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'name'        => 'required|string|max:255',
                'description' => 'nullable|string',
                'price'       => 'required|numeric|min:0',
                'payprice'    => 'required|numeric|min:0',
                'quantity'    => 'required|integer|min:0',
                'category_id' => 'required|exists:categories,id',
                'active'      => 'boolean',
                'image'       => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            ]);

            $validated['active'] = filter_var($request->active, FILTER_VALIDATE_BOOLEAN);

            if ($request->hasFile('image')) {
                try {
                    // Use Cloudinary API directly
                    $cloudinaryUrl = env('CLOUDINARY_URL');
                    $cloudName = env('CLOUDINARY_CLOUD_NAME');
                    $apiKey = env('CLOUDINARY_API_KEY');
                    $apiSecret = env('CLOUDINARY_API_SECRET');

                    if (!$cloudName || !$apiKey || !$apiSecret) {
                        throw new Exception('Cloudinary credentials not configured');
                    }

                    $file = $request->file('image');
                    $filename = time() . '_' . $file->getClientOriginalName();

                    // Signed upload to Cloudinary using cURL
                    $timestamp = time();
                    $paramsToSign = "folder=products&timestamp={$timestamp}";
                    $signature = sha1($paramsToSign.$apiSecret);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, [
                        'file' => new \CURLFile($file->getRealPath(), $file->getMimeType(), $filename),
                        'api_key' => $apiKey,
                        'timestamp' => $timestamp,
                        'signature' => $signature,
                        'folder' => 'products',
                    ]);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);

                    if ($httpCode === 200) {
                        $result = json_decode($response, true);
                        $validated['image_url'] = $result['secure_url'];
                    } else {
                        throw new Exception('Cloudinary upload failed: HTTP '.$httpCode.'; CURL '.$curlError.'; Resp '.$response);
                    }
                } catch (Exception $e) {
                    Log::error('Cloudinary upload error: ' . $e->getMessage());
                    // Fallback to local storage if Cloudinary fails
                    $filename = time() . '_' . $request->file('image')->getClientOriginalName();
                    $request->file('image')->move(public_path('storage/products'), $filename);
                    $validated['image_url'] = 'storage/products/' . $filename;
                }
            }

            $product = Product::create($validated);

            // Create purchase order if initial quantity > 0
            if (($validated['quantity'] ?? 0) > 0) {
                $this->createPurchaseOrderForIncoming(
                    $product,
                    (int) $validated['quantity'],
                    (float) $validated['price'],
                    (float) $validated['payprice'],
                    'Initial stock on product creation'
                );
            }

            // Update dashboard for admin management (default) or provided status
            $this->updateDashboard('create', $product, null, $request->input('status', 'admin_management'));

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Thêm sản phẩm thành công', 'data' => $product], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('store product error: '.$e->getMessage());
            Log::error('store product error trace: '.$e->getTraceAsString());
            return response()->json(['success' => false, 'message' => 'Lỗi server: '.$e->getMessage()], 500);
        }
    }

    /**
     * Cập nhật sản phẩm (bao gồm +SL)
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $product = Product::findOrFail($id);

            $validated = $request->validate([
                'name'        => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'price'       => 'sometimes|required|numeric|min:0',
                'payprice'    => 'sometimes|required|numeric|min:0',
                'quantity'    => 'sometimes|required|integer|min:0',
                'category_id' => 'sometimes|required|exists:categories,id',
                'active'      => 'boolean',
                'image'       => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            ]);

            $validated['active'] = isset($request->active)
                ? filter_var($request->active, FILTER_VALIDATE_BOOLEAN)
                : $product->active;

            $oldValues = [
                'price'    => $product->price,
                'quantity' => $product->quantity,
                'payprice' => $product->payprice,
            ];

            if ($request->hasFile('image')) {
                // Keep old image to delete after successful upload
                $oldImageUrl = $product->image_url;

                try {
                    // Use same signed upload logic as in store
                    $cloudName = env('CLOUDINARY_CLOUD_NAME');
                    $apiKey = env('CLOUDINARY_API_KEY');
                    $apiSecret = env('CLOUDINARY_API_SECRET');
                    if (!$cloudName || !$apiKey || !$apiSecret) {
                        throw new Exception('Cloudinary credentials not configured');
                    }
                    $file = $request->file('image');
                    $filename = time() . '_' . $file->getClientOriginalName();
                    $timestamp = time();
                    $paramsToSign = "folder=products&timestamp={$timestamp}";
                    $signature = sha1($paramsToSign.$apiSecret);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, [
                        'file' => new \CURLFile($file->getRealPath(), $file->getMimeType(), $filename),
                        'api_key' => $apiKey,
                        'timestamp' => $timestamp,
                        'signature' => $signature,
                        'folder' => 'products',
                    ]);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    if ($httpCode === 200) {
                        $result = json_decode($response, true);
                        $validated['image_url'] = $result['secure_url'];
                        // delete old cloudinary image if applicable
                        $oldPublicId = $this->getCloudinaryPublicIdFromUrl($oldImageUrl);
                        if ($oldPublicId) {
                            $deletionResult = $this->destroyCloudinaryPublicId($oldPublicId);
                            if (!$deletionResult) {
                                Log::warning('Failed to delete old image during product update', [
                                    'product_id' => $product->id,
                                    'old_public_id' => $oldPublicId
                                ]);
                            }
                        }
                    } else {
                        throw new Exception('Cloudinary upload failed: HTTP '.$httpCode.'; CURL '.$curlError.'; Resp '.$response);
                    }
                } catch (Exception $e) {
                    Log::error('Cloudinary upload error (update): ' . $e->getMessage());
                    // Fallback to local storage if Cloudinary fails
                    $filename = time() . '_' . $request->file('image')->getClientOriginalName();
                    $request->file('image')->move(public_path('storage/products'), $filename);
                    $validated['image_url'] = 'storage/products/' . $filename;
                    // if old image was local, optionally remove it
                    if ($oldImageUrl && str_starts_with($oldImageUrl, 'storage/products/')) {
                        @unlink(public_path($oldImageUrl));
                    }
                }
            }

            // Detect quantity increase to create purchase order item
            $oldQuantity = (int) $product->quantity;
            $newQuantity = isset($validated['quantity']) ? (int) $validated['quantity'] : $oldQuantity;

            $product->update($validated);

            $increasedBy = $newQuantity - $oldQuantity;
            if ($increasedBy > 0) {
                $this->createPurchaseOrderForIncoming(
                    $product,
                    $increasedBy,
                    (float) ($validated['price'] ?? $product->price),
                    (float) ($validated['payprice'] ?? $product->payprice),
                    'Stock increase via product update'
                );
            }

            $this->updateDashboard('update', $product, $oldValues, $request->input('status', 'admin_management'));

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Cập nhật thành công', 'data' => $product]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('update product error: '.$e->getMessage());
            return response()->json(['success' => false, 'message' => 'Lỗi server'], 500);
        }
    }

    /**
     * Xóa sản phẩm
     */
    public function destroy(Product $product, Request $request)
    {
        if ($product->quantity > 0) {
            return response()->json(['success' => false, 'message' => 'Chỉ được xóa khi số lượng = 0'], 400);
        }

        DB::beginTransaction();
        try {
            // Delete image from Cloudinary if applicable
            $publicId = $this->getCloudinaryPublicIdFromUrl($product->image_url);
            if ($publicId) {
                $deletionResult = $this->destroyCloudinaryPublicId($publicId);
                if (!$deletionResult) {
                    Log::warning('Failed to delete image from Cloudinary during product deletion', [
                        'product_id' => $product->id,
                        'public_id' => $publicId
                    ]);
                    // Continue with product deletion even if image deletion fails
                }
            }

            $status = $request->input('status', 'admin_management');
            $product->delete();

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Đã xóa sản phẩm']);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('delete product error: '.$e->getMessage());
            return response()->json(['success' => false, 'message' => 'Lỗi server'], 500);
        }
    }
}
