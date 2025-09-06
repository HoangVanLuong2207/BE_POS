<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Dashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;

class ProductController extends Controller
{
    /**
     * Hàm dùng chung: lấy hoặc tạo dashboard cho tháng hiện tại với error handling
     */
    private function getOrCreateDashboardForCurrentMonth()
    {
        try {
            $currentYear = now()->year;
            $currentMonth = now()->month;

            Log::info("Tìm/tạo dashboard cho tháng: {$currentMonth}/{$currentYear}");

            $dashboard = Dashboard::firstOrCreate(
                [
                    'year'  => $currentYear,
                    'month' => $currentMonth,
                ],
                [
                    'subtotal' => 0,
                    'total'    => 0,
                    'profit'   => 0,
                    'year'     => $currentYear,  // Đảm bảo giá trị được set
                    'month'    => $currentMonth, // Đảm bảo giá trị được set
                ]
            );

            // Double check: nếu year/month vẫn là 0, cập nhật lại
            if ($dashboard->year == 0 || $dashboard->month == 0) {
                $dashboard->year = $currentYear;
                $dashboard->month = $currentMonth;
                $dashboard->save();
                Log::info("Đã cập nhật year/month cho dashboard ID: {$dashboard->id}");
            }

            Log::info("Dashboard được sử dụng - ID: {$dashboard->id}, Year: {$dashboard->year}, Month: {$dashboard->month}");

            return $dashboard;

        } catch (Exception $e) {
            Log::error('Error creating/finding dashboard: ' . $e->getMessage(), [
                'current_year' => now()->year,
                'current_month' => now()->month,
                'stack_trace' => $e->getTraceAsString()
            ]);
            throw new Exception('Không thể tạo hoặc lấy dữ liệu dashboard: ' . $e->getMessage());
        }
    }

    /**
     * Cập nhật dashboard với error handling và rollback
     * - admin_management: chỉ cập nhật subtotal
     * - sales hoặc không có status: chỉ cập nhật total
     */
    private function updateDashboard($type, $product, $oldValues = null, $status = 'sales')
    {
        try {
            $dashboard = $this->getOrCreateDashboardForCurrentMonth();

            switch ($type) {
                case 'create':
                    if ($status === 'admin_management') {
                        // Chỉ cập nhật subtotal khi admin thêm sản phẩm
                        $dashboard->subtotal += $product->price * $product->quantity;
                    } else {
                        // Cập nhật total khi bán hàng
                        $dashboard->total += $product->payprice * $product->quantity;
                    }
                    break;

                case 'update':
                    if (!$oldValues) {
                        throw new Exception('Thiếu thông tin sản phẩm cũ để cập nhật dashboard');
                    }

                    $oldSubtotal = $oldValues['price'] * $oldValues['quantity'];
                    $oldTotal = $oldValues['payprice'] * $oldValues['quantity'];
                    $newSubtotal = $product->price * $product->quantity;
                    $newTotal = $product->payprice * $product->quantity;

                    if ($status === 'admin_management') {
                        // Chỉ cập nhật subtotal khi admin quản lý
                        $dashboard->subtotal += ($newSubtotal - $oldSubtotal);
                    } else {
                        // Chỉ cập nhật total khi bán hàng hoặc không có status
                        $dashboard->total += ($newTotal - $oldTotal);
                    }
                    break;

                case 'delete':
                    if ($status === 'admin_management') {
                        // Chỉ trừ subtotal khi admin xóa sản phẩm
                        $dashboard->subtotal -= $product->price * $product->quantity;
                    } else {
                        // Trừ total khi xóa do bán hàng
                        $dashboard->total -= $product->payprice * $product->quantity;
                    }
                    break;

                default:
                    throw new Exception('Loại cập nhật dashboard không hợp lệ: ' . $type);
            }

            // Tính lại profit
            $dashboard->profit = $dashboard->total - $dashboard->subtotal;
            $dashboard->save();

            Log::info("Dashboard updated - Status: {$status}, Type: {$type}, Subtotal: {$dashboard->subtotal}, Total: {$dashboard->total}, Profit: {$dashboard->profit}");

            return $dashboard;

        } catch (Exception $e) {
            Log::error("Error updating dashboard ({$type}): " . $e->getMessage());
            throw new Exception('Lỗi cập nhật dashboard: ' . $e->getMessage());
        }
    }

    public function index()
    {
        try {
            $keywords = request()->query('keyword');
            $query = Product::with('category');

            if (!empty($keywords)) {
                $query->where('name', 'like', "%$keywords%");
            }

            $products = $query->get();

            // Kiểm tra nếu không có sản phẩm nào
            if ($products->isEmpty() && !empty($keywords)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Không tìm thấy sản phẩm với từ khóa: ' . $keywords,
                    'data' => [
                        'product' => [],
                        'categories' => Category::all(),
                        'total' => 0,
                    ]
                ]);
            }

            foreach ($products as $product) {
                $product->category_name = $product->category ? $product->category->name : 'Chưa phân loại';
            }

            $categories = Category::all();

            return response()->json([
                'success' => true,
                'data' => [
                    'product' => $products,
                    'categories' => $categories,
                    'total' => $products->count(),
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Error in ProductController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi lấy danh sách sản phẩm',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validation với custom error messages
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric|min:0',
                'payprice' => 'required|numeric|min:0',
                'quantity' => 'required|integer|min:0',
                'category_id' => 'required|exists:categories,id',
                'active' => 'boolean',
                'status' => 'nullable|string|in:admin_management,sales', // Thêm validation cho status
            ], [
                'name.required' => 'Tên sản phẩm không được để trống',
                'price.required' => 'Giá nhập không được để trống',
                'price.min' => 'Giá nhập phải lớn hơn hoặc bằng 0',
                'payprice.required' => 'Giá bán không được để trống',
                'payprice.min' => 'Giá bán phải lớn hơn hoặc bằng 0',
                'quantity.required' => 'Số lượng không được để trống',
                'quantity.min' => 'Số lượng phải lớn hơn hoặc bằng 0',
                'category_id.required' => 'Danh mục không được để trống',
                'category_id.exists' => 'Danh mục không tồn tại',
                'status.in' => 'Trạng thái không hợp lệ',
            ]);

            $validated['active'] = filter_var($request->active, FILTER_VALIDATE_BOOLEAN);

            // Xử lý upload ảnh với error handling
            if ($request->hasFile('image')) {
                try {
                    $file = $request->file('image');

                    // Kiểm tra file có hợp lệ không
                    if (!$file->isValid()) {
                        throw new Exception('File upload không hợp lệ');
                    }

                    // Kiểm tra kích thước file (max 5MB)
                    if ($file->getSize() > 5 * 1024 * 1024) {
                        throw new Exception('Kích thước file không được vượt quá 5MB');
                    }

                    // Kiểm tra loại file
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if (!in_array($file->getMimeType(), $allowedTypes)) {
                        throw new Exception('Chỉ chấp nhận file ảnh (JPEG, PNG, GIF, WEBP)');
                    }

                    $path = $file->store('products', 'public');
                    $validated['image_url'] = '/storage/' . $path;

                } catch (Exception $e) {
                    throw new Exception('Lỗi upload ảnh: ' . $e->getMessage());
                }
            }

            // Tạo sản phẩm
            $product = Product::create($validated);

            if (!$product) {
                throw new Exception('Không thể tạo sản phẩm');
            }

            // Cập nhật dashboard với status
            $status = $request->input('status', 'sales'); // Default là sales
            $this->updateDashboard('create', $product, null, $status);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Thêm sản phẩm thành công',
                'data' => $product
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error in ProductController@store: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi thêm sản phẩm: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(Product $product)
    {
        try {
            $product->load('category');

            return response()->json([
                'success' => true,
                'data' => $product
            ]);

        } catch (Exception $e) {
            Log::error('Error in ProductController@show: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Không thể lấy thông tin sản phẩm'
            ], 500);
        }
    }

    public function update(Request $request, Product $product)
    {
        DB::beginTransaction();

        try {
            // Lưu giá trị cũ để tính toán dashboard
            $oldValues = [
                'price' => $product->price,
                'payprice' => $product->payprice,
                'quantity' => $product->quantity,
                'image_url' => $product->image_url
            ];

            $validated = $request->validate([
                'name' => 'string|max:255',
                'description' => 'nullable|string',
                'price' => 'numeric|min:0',
                'payprice' => 'numeric|min:0',
                'quantity' => 'integer|min:0',
                'category_id' => 'exists:categories,id',
                'active' => 'boolean',
                'status' => 'nullable|string|in:admin_management,sales',
            ], [
                'price.min' => 'Giá nhập phải lớn hơn hoặc bằng 0',
                'payprice.min' => 'Giá bán phải lớn hơn hoặc bằng 0',
                'quantity.min' => 'Số lượng phải lớn hơn hoặc bằng 0',
                'category_id.exists' => 'Danh mục không tồn tại',
                'status.in' => 'Trạng thái không hợp lệ',
            ]);

            if ($request->has('active')) {
                $validated['active'] = filter_var($request->active, FILTER_VALIDATE_BOOLEAN);
            }

            // Xử lý upload ảnh mới
            if ($request->hasFile('image')) {
                try {
                    $file = $request->file('image');

                    if (!$file->isValid()) {
                        throw new Exception('File upload không hợp lệ');
                    }

                    if ($file->getSize() > 5 * 1024 * 1024) {
                        throw new Exception('Kích thước file không được vượt quá 5MB');
                    }

                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if (!in_array($file->getMimeType(), $allowedTypes)) {
                        throw new Exception('Chỉ chấp nhận file ảnh (JPEG, PNG, GIF, WEBP)');
                    }

                    // Xóa ảnh cũ
                    if ($product->image_url && Storage::disk('public')->exists(str_replace('/storage/', '', $product->image_url))) {
                        Storage::disk('public')->delete(str_replace('/storage/', '', $product->image_url));
                    }

                    $path = $file->store('products', 'public');
                    $validated['image_url'] = '/storage/' . $path;

                } catch (Exception $e) {
                    throw new Exception('Lỗi upload ảnh: ' . $e->getMessage());
                }
            }

            // Cập nhật sản phẩm
            $updated = $product->update($validated);

            if (!$updated) {
                throw new Exception('Không thể cập nhật sản phẩm');
            }

            // Cập nhật dashboard với status
            $status = $request->input('status', 'sales'); // Default là sales
            $this->updateDashboard('update', $product, $oldValues, $status);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật sản phẩm thành công',
                'data' => $product->fresh()
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error in ProductController@update: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi cập nhật sản phẩm: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Thêm số lượng sản phẩm (+SL) - luôn là admin_management
     */
    public function addQuantity(Request $request, Product $product)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'quantity' => 'required|integer|min:1',
            ], [
                'quantity.required' => 'Số lượng cần thêm không được để trống',
                'quantity.integer' => 'Số lượng phải là số nguyên',
                'quantity.min' => 'Số lượng cần thêm phải lớn hơn 0',
            ]);

            $addQuantity = $validated['quantity'];
            $oldQuantity = $product->quantity;

            // Cập nhật số lượng sản phẩm
            $product->quantity += $addQuantity;
            $updated = $product->save();

            if (!$updated) {
                throw new Exception('Không thể cập nhật số lượng sản phẩm');
            }

            // Cập nhật dashboard - luôn là admin_management cho addQuantity
            $dashboard = $this->getOrCreateDashboardForCurrentMonth();

            // Chỉ tính phần tăng thêm vào subtotal
            $addedSubtotal = $product->price * $addQuantity;

            $dashboard->subtotal += $addedSubtotal;
            $dashboard->profit = $dashboard->total - $dashboard->subtotal;
            $dashboard->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Đã thêm {$addQuantity} sản phẩm. Số lượng từ {$oldQuantity} thành {$product->quantity}",
                'data' => [
                    'product' => $product->fresh(),
                    'added_quantity' => $addQuantity,
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $product->quantity,
                    'dashboard_impact' => [
                        'added_subtotal' => $addedSubtotal,
                    ]
                ]
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error in ProductController@addQuantity: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi thêm số lượng sản phẩm: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Giảm số lượng sản phẩm khi bán hàng - luôn là sales
     */
    public function reduceQuantity(Request $request, Product $product)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'quantity' => 'required|integer|min:1',
            ], [
                'quantity.required' => 'Số lượng cần bán không được để trống',
                'quantity.integer' => 'Số lượng phải là số nguyên',
                'quantity.min' => 'Số lượng cần bán phải lớn hơn 0',
            ]);

            $reduceQuantity = $validated['quantity'];
            $oldQuantity = $product->quantity;

            // Kiểm tra số lượng có đủ không
            if ($product->quantity < $reduceQuantity) {
                throw new Exception("Không đủ hàng trong kho. Hiện có: {$product->quantity}, yêu cầu: {$reduceQuantity}");
            }

            // Cập nhật số lượng sản phẩm
            $product->quantity -= $reduceQuantity;
            $updated = $product->save();

            if (!$updated) {
                throw new Exception('Không thể cập nhật số lượng sản phẩm');
            }

            // Cập nhật dashboard - chỉ cập nhật total (doanh thu bán hàng)
            $dashboard = $this->getOrCreateDashboardForCurrentMonth();

            // Tăng total vì đây là doanh thu từ bán hàng
            $soldTotal = $product->payprice * $reduceQuantity;
            $dashboard->total += $soldTotal;
            $dashboard->profit = $dashboard->total - $dashboard->subtotal;
            $dashboard->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Đã bán {$reduceQuantity} sản phẩm. Số lượng từ {$oldQuantity} còn {$product->quantity}",
                'data' => [
                    'product' => $product->fresh(),
                    'sold_quantity' => $reduceQuantity,
                    'old_quantity' => $oldQuantity,
                    'remaining_quantity' => $product->quantity,
                    'dashboard_impact' => [
                        'sold_total' => $soldTotal,
                        'is_out_of_stock' => $product->quantity == 0
                    ]
                ]
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error in ProductController@reduceQuantity: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi bán sản phẩm: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Product $product)
    {

        DB::beginTransaction();

        try {
            // Xác định status để cập nhật dashboard đúng cách
            $status = request()->input('status'); // Không set default, để null



            // Xóa ảnh nếu có
            if ($product->image_url && Storage::disk('public')->exists(str_replace('/storage/', '', $product->image_url))) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $product->image_url));
            }

            $deleted = $product->delete();

            if (!$deleted) {
                throw new Exception('Không thể xóa sản phẩm');
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Xóa sản phẩm thành công'
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error in ProductController@destroy: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi xóa sản phẩm: ' . $e->getMessage()
            ], 500);
        }
    }
}
