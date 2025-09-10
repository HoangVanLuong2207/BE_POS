<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;

class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of purchase orders
     */
    public function index(Request $request)
    {
        $keyword = $request->query('keyword');
        $status = $request->query('status');
        $payment_status = $request->query('payment_status');
        $limit = $request->query('limit', 10);

        $query = PurchaseOrder::with('creator');

        if ($keyword) {
            $query->where(function($q) use ($keyword) {
                $q->where('purchase_number', 'like', "%$keyword%")
                  ->orWhere('supplier_name', 'like', "%$keyword%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($payment_status) {
            $query->where('payment_status', $payment_status);
        }

        $purchaseOrders = $query->orderBy('created_at', 'desc')->paginate($limit);

        return response()->json([
            'success' => true,
            'data' => [
                'purchase_orders' => $purchaseOrders->items(),
                'total' => $purchaseOrders->total(),
                'pagination' => [
                    'current_page' => $purchaseOrders->currentPage(),
                    'last_page' => $purchaseOrders->lastPage(),
                    'per_page' => $purchaseOrders->perPage(),
                ]
            ]
        ]);
    }

    /**
     * Store a newly created purchase order
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'supplier_name' => 'required|string|max:255',
                'supplier_phone' => 'nullable|string|max:20',
                'supplier_address' => 'nullable|string',
                'purchase_date' => 'required|date',
                'due_date' => 'nullable|date|after_or_equal:purchase_date',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.purchase_price' => 'required|numeric|min:0',
                'items.*.selling_price' => 'required|numeric|min:0',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit' => 'nullable|string|max:50',
                'items.*.notes' => 'nullable|string',
            ]);

            // Create purchase order
            $purchaseOrder = PurchaseOrder::create([
                'purchase_number' => PurchaseOrder::generatePurchaseNumber(),
                'supplier_name' => $validated['supplier_name'],
                'supplier_phone' => $validated['supplier_phone'],
                'supplier_address' => $validated['supplier_address'],
                'purchase_date' => $validated['purchase_date'],
                'due_date' => $validated['due_date'],
                'notes' => $validated['notes'],
                'status' => 'confirmed',
                'created_by' => 1, // Default user ID, can be updated when auth is implemented
            ]);

            // Create purchase order items and update products
            foreach ($validated['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);

                // Create purchase order item
                PurchaseOrderItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'product_id' => $itemData['product_id'],
                    'product_name' => $product->name,
                    'product_description' => $product->description,
                    'purchase_price' => $itemData['purchase_price'],
                    'selling_price' => $itemData['selling_price'],
                    'quantity' => $itemData['quantity'],
                    'unit' => $itemData['unit'] ?? 'cái',
                    'notes' => $itemData['notes'],
                ]);

                // Update product stock and prices
                $product->increment('quantity', $itemData['quantity']);

                // Update product prices if needed
                if ($itemData['purchase_price'] != $product->price) {
                    $product->update(['price' => $itemData['purchase_price']]);
                }
                if ($itemData['selling_price'] != $product->payprice) {
                    $product->update(['payprice' => $itemData['selling_price']]);
                }
            }

            // Update total amount
            $purchaseOrder->updateTotalAmount();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tạo hóa đơn nhập hàng thành công',
                'data' => $purchaseOrder->load('items.product')
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Create purchase order error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Lỗi server: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified purchase order
     */
    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['items.product', 'creator']);

        return response()->json([
            'success' => true,
            'data' => $purchaseOrder
        ]);
    }

    /**
     * Update the specified purchase order
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Không thể chỉnh sửa hóa đơn đã hoàn thành'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'supplier_name' => 'sometimes|required|string|max:255',
                'supplier_phone' => 'nullable|string|max:20',
                'supplier_address' => 'nullable|string',
                'purchase_date' => 'sometimes|required|date',
                'due_date' => 'nullable|date|after_or_equal:purchase_date',
                'notes' => 'nullable|string',
                'status' => 'sometimes|in:draft,confirmed,completed,cancelled',
                'paid_amount' => 'sometimes|numeric|min:0',
            ]);

            $purchaseOrder->update($validated);

            if (isset($validated['paid_amount'])) {
                $purchaseOrder->calculateRemainingAmount();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật hóa đơn nhập hàng thành công',
                'data' => $purchaseOrder->load('items.product')
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Update purchase order error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Lỗi server: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified purchase order
     */
    public function destroy(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa hóa đơn đã hoàn thành'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Reverse stock updates
            foreach ($purchaseOrder->items as $item) {
                $product = $item->product;
                if ($product) {
                    $product->decrement('quantity', $item->quantity);
                }
            }

            $purchaseOrder->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Xóa hóa đơn nhập hàng thành công'
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Delete purchase order error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Lỗi server: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products for bulk import
     */
    public function getProductsForImport(Request $request)
    {
        $keyword = $request->query('keyword');
        $category_id = $request->query('category_id');
        $limit = $request->query('limit', 50);

        $query = Product::with('category');

        if ($keyword) {
            $query->where('name', 'like', "%$keyword%");
        }

        if ($category_id) {
            $query->where('category_id', $category_id);
        }

        $products = $query->orderBy('name')->paginate($limit);

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products->items(),
                'categories' => Category::all(),
                'total' => $products->total(),
            ]
        ]);
    }

    /**
     * Bulk import products
     */
    public function bulkImportProducts(Request $request)
    {
        $validated = $request->validate([
            'products' => 'required|array|min:1',
            'products.*.name' => 'required|string|max:255',
            'products.*.description' => 'nullable|string',
            'products.*.category_id' => 'required|exists:categories,id',
            'products.*.purchase_price' => 'required|numeric|min:0',
            'products.*.selling_price' => 'required|numeric|min:0',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.unit' => 'nullable|string|max:50',
            'products.*.active' => 'boolean',
        ]);

        DB::beginTransaction();
        try {
            $createdProducts = [];
            $errors = [];

            foreach ($validated['products'] as $index => $productData) {
                try {
                    $product = Product::create([
                        'name' => $productData['name'],
                        'description' => $productData['description'],
                        'price' => $productData['purchase_price'],
                        'payprice' => $productData['selling_price'],
                        'quantity' => $productData['quantity'],
                        'category_id' => $productData['category_id'],
                        'active' => $productData['active'] ?? true,
                    ]);

                    $createdProducts[] = $product;
                } catch (Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'name' => $productData['name'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Tạo thành công " . count($createdProducts) . " sản phẩm",
                'data' => [
                    'created_products' => $createdProducts,
                    'errors' => $errors,
                    'total_created' => count($createdProducts),
                    'total_errors' => count($errors),
                ]
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Bulk import products error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Lỗi server: ' . $e->getMessage()
            ], 500);
        }
    }
}
