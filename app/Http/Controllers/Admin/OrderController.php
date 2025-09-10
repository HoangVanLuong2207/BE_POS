<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Dashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class OrderController extends Controller
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
     * Cập nhật dashboard khi bán hàng - chỉ cập nhật total
     */
    private function updateDashboardForSale($totalSaleAmount)
    {
        try {
            $dashboard = $this->getOrCreateDashboardForCurrentMonth();

            // Cập nhật total (doanh thu bán hàng)
            $dashboard->total += $totalSaleAmount;

            // Tính lại profit
            $dashboard->profit = $dashboard->total - $dashboard->subtotal;

            $dashboard->save();

            Log::info("Dashboard updated for sale - Amount: {$totalSaleAmount}, New Total: {$dashboard->total}, New Profit: {$dashboard->profit}");

            return $dashboard;

        } catch (Exception $e) {
            Log::error("Error updating dashboard for sale: " . $e->getMessage());
            throw new Exception('Lỗi cập nhật dashboard khi bán hàng: ' . $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        try {
            $keyword = $request->query('keyword');
            $limit   = (int) $request->query('limit', 10);

            $query = Order::with('items');
            if ($keyword) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('customer_name', 'like', "%$keyword%")
                      ->orWhere('customer_phone', 'like', "%$keyword%")
                      ->orWhere('id', $keyword);
                });
            }

            $orders = $query->orderBy('id', 'desc')->paginate($limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $orders->items(),
                    'total'  => $orders->total(),
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'last_page'    => $orders->lastPage(),
                        'per_page'     => $orders->perPage(),
                    ]
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Error in OrderController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi lấy danh sách đơn hàng',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Order $order)
    {
        try {
            $order->load('items');
            return response()->json([
                'success' => true,
                'data' => $order
            ]);
        } catch (Exception $e) {
            Log::error('Error in OrderController@show: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi lấy chi tiết đơn hàng',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'customer_phone' => 'nullable|string',
                'customer_name' => 'nullable|string',
            ], [
                'items.required' => 'Danh sách sản phẩm không được để trống',
                'items.*.product_id.required' => 'ID sản phẩm không được để trống',
                'items.*.product_id.exists' => 'Sản phẩm không tồn tại',
                'items.*.quantity.required' => 'Số lượng không được để trống',
                'items.*.quantity.min' => 'Số lượng phải lớn hơn 0',
            ]);

            return DB::transaction(function () use ($validated) {
                $totalAmount = 0;
                $orderItems = [];

                // Tạo order
                $order = Order::create([
                    'total_amount'   => 0, // cập nhật sau
                    'customer_phone' => $validated['customer_phone'] ?? null,
                    'customer_name'  => $validated['customer_name'] ?? null,
                ]);

                foreach ($validated['items'] as $item) {
                    // Khóa bản ghi để tránh race condition
                    $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                    // Kiểm tra tồn kho
                    if ($product->quantity < $item['quantity']) {
                        throw new Exception("Sản phẩm '{$product->name}' không đủ tồn kho. Còn: {$product->quantity}, yêu cầu: {$item['quantity']}");
                    }

                    // Dùng giá bán nếu có (payprice), fallback sang price
                    $unitPrice = $product->payprice ?? $product->price;
                    $subtotal  = $unitPrice * $item['quantity'];
                    $totalAmount += $subtotal;

                    // Lưu thông tin để log
                    $oldQuantity = $product->quantity;

                    // Trừ tồn kho
                    $product->decrement('quantity', $item['quantity']);

                    // Tạo order item
                    $orderItem = OrderItem::create([
                        'order_id'   => $order->id,
                        'product_id' => $product->id,
                        'quantity'   => $item['quantity'],
                    ]);

                    $orderItems[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity_sold' => $item['quantity'],
                        'unit_price' => $unitPrice,
                        'subtotal' => $subtotal,
                        'old_quantity' => $oldQuantity,
                        'remaining_quantity' => $product->quantity,
                    ];

                    Log::info("Order item created - Product: {$product->name}, Quantity: {$item['quantity']}, Amount: {$subtotal}");
                }

                // Cập nhật total amount cho order
                $order->update(['total_amount' => $totalAmount]);

                // Cập nhật dashboard - chỉ cập nhật total (bán hàng)
                $dashboard = $this->updateDashboardForSale($totalAmount);

                Log::info("Order created successfully - ID: {$order->id}, Total: {$totalAmount}");

                return response()->json([
                    'success' => true,
                    'message' => 'Đặt hàng thành công',
                    'data' => [
                        'order' => $order->load('items'),
                        'order_items' => $orderItems,
                        'total_amount' => $totalAmount,
                        'customer_info' => [
                            'name' => $validated['customer_name'],
                            'phone' => $validated['customer_phone'],
                        ],
                        'dashboard_impact' => [
                            'added_to_total' => $totalAmount,
                            'dashboard_total' => $dashboard->total,
                            'dashboard_profit' => $dashboard->profit,
                        ]
                    ]
                ], 201);
            });

        } catch (Exception $e) {
            Log::error('Error in OrderController@store: ' . $e->getMessage());

            // Kiểm tra nếu là lỗi validation tùy chỉnh
            if (str_contains($e->getMessage(), 'không đủ tồn kho')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi tạo đơn hàng: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Order $order)
    {
        try {
            // Logic cập nhật đơn hàng
            // Nếu cần cập nhật dashboard khi sửa đơn hàng, có thể thêm logic ở đây

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật đơn hàng thành công',
                'data' => $order
            ]);

        } catch (Exception $e) {
            Log::error('Error in OrderController@update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi cập nhật đơn hàng: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Order $order)
    {
        DB::beginTransaction();

        try {
            // Nếu muốn hoàn lại dashboard khi xóa order
            // $this->revertDashboardForOrder($order);

            $order->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Xóa đơn hàng thành công'
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error in OrderController@destroy: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi xóa đơn hàng: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Hoàn lại dashboard khi xóa/hủy đơn hàng (optional)
     */
    private function revertDashboardForOrder($order)
    {
        try {
            $dashboard = $this->getOrCreateDashboardForCurrentMonth();

            // Trừ total khi hủy đơn hàng
            $dashboard->total -= $order->total_amount;
            $dashboard->profit = $dashboard->total - $dashboard->subtotal;
            $dashboard->save();

            // Hoàn lại số lượng sản phẩm nếu cần
            foreach ($order->items as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    $product->increment('quantity', $item->quantity);
                }
            }

            Log::info("Dashboard reverted for cancelled order - Amount: {$order->total_amount}");

        } catch (Exception $e) {
            Log::error("Error reverting dashboard for order: " . $e->getMessage());
            throw $e;
        }
    }
}
