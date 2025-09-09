<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Dashboard;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;

class ProductController extends Controller
{
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
  private function updateDashboard(string $type, Product $product, array $oldValues = null, string $status = 'sales')
{
    try {
        $dashboard = $this->getOrCreateDashboard();

        // Initialize values if they are null
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
                if (!$oldValues) {
                    // Không có dữ liệu cũ thì bỏ qua, tránh lỗi null
                    break;
                }
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

        $dashboard->profit = $dashboard->total - $dashboard->subtotal;
        $dashboard->save();
    } catch (Exception $e) {
        Log::error('updateDashboard error: ' . $e->getMessage());
        // Don't throw the error, just log it to prevent product creation from failing
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

            // Temporarily disable dashboard update to isolate the issue
            // $this->updateDashboard('create', $product, null, $request->input('status', 'sales'));

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
                $validated['image_url'] = Cloudinary::upload(
                    $request->file('image')->getRealPath(),
                    ['folder' => 'products']
                )->getSecurePath();
            }

            $product->update($validated);

            $this->updateDashboard('update', $product, $oldValues, $request->input('status', 'sales'));

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
            $status = $request->input('status', 'sales');
            $this->updateDashboard('delete', $product, null, $status);
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
