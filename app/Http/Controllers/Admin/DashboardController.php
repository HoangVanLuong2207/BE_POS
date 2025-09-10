<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\PurchaseOrder;
use App\Models\Order;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    // Lấy thống kê tháng hiện tại
    public function currentMonth()
    {
        $year  = now()->year;
        $month = now()->month;

        $dash = Dashboard::where('year', $year)
            ->where('month', $month)
            ->first();

        // Thống kê nhập hàng tháng hiện tại
        $purchaseStats = $this->getPurchaseStats($year, $month);

        // Thống kê bán hàng tháng hiện tại
        $salesStats = $this->getSalesStats($year, $month);

        return response()->json([
            'year'  => $year,
            'month' => $month,
            'data'  => [
                'subtotal' => (float) ($dash->subtotal ?? 0), // tiền nhập
                'total'    => (float) ($dash->total ?? 0),    // tiền bán
                'profit'   => (float) ($dash->profit ?? 0),
            ],
            'purchase_stats' => $purchaseStats,
            'sales_stats' => $salesStats,
        ]);
    }

    // Lấy thống kê theo tháng/năm được chọn
    public function monthlyOne(Request $request)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        $dash = Dashboard::where('year', $year)
            ->where('month', $month)
            ->first();

        // Thống kê nhập hàng theo tháng/năm được chọn
        $purchaseStats = $this->getPurchaseStats($year, $month);

        // Thống kê bán hàng theo tháng/năm được chọn
        $salesStats = $this->getSalesStats($year, $month);

        return response()->json([
            'year'  => $year,
            'month' => $month,
            'data'  => [
                'subtotal' => (float) ($dash->subtotal ?? 0), // tiền nhập
                'total'    => (float) ($dash->total ?? 0),    // tiền bán
                'profit'   => (float) ($dash->profit ?? 0),
            ],
            'purchase_stats' => $purchaseStats,
            'sales_stats' => $salesStats,
        ]);
    }

    // Thống kê tổng hợp theo tháng (group by)
    public function monthlyReport()
    {
        $statsByMonth = Dashboard::selectRaw('year, month,
                SUM(subtotal) as subtotal,
                SUM(total)    as total,
                SUM(profit)   as profit')
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        return response()->json($statsByMonth);
    }

    // Thống kê tổng hợp theo năm (group by)
    public function yearlyReport()
    {
        $statsByYear = Dashboard::selectRaw('year,
                SUM(subtotal) as subtotal,
                SUM(total)    as total,
                SUM(profit)   as profit')
            ->groupBy('year')
            ->orderBy('year', 'desc')
            ->get();

        return response()->json($statsByYear);
    }

    /**
     * Lấy thống kê nhập hàng theo tháng/năm
     */
    private function getPurchaseStats($year, $month)
    {
        $purchaseOrders = PurchaseOrder::whereYear('purchase_date', $year)
            ->whereMonth('purchase_date', $month)
            ->get();

        $totalAmount = $purchaseOrders->sum('total_amount');
        $paidAmount = $purchaseOrders->sum('paid_amount');
        $remainingAmount = $purchaseOrders->sum('remaining_amount');
        $totalOrders = $purchaseOrders->count();
        $completedOrders = $purchaseOrders->where('status', 'completed')->count();
        $pendingOrders = $purchaseOrders->where('status', '!=', 'completed')->count();

        // Thống kê theo trạng thái thanh toán
        $paymentStats = [
            'paid' => $purchaseOrders->where('payment_status', 'paid')->count(),
            'partial' => $purchaseOrders->where('payment_status', 'partial')->count(),
            'pending' => $purchaseOrders->where('payment_status', 'pending')->count(),
        ];

        // Top 5 nhà cung cấp
        $topSuppliers = $purchaseOrders->groupBy('supplier_name')
            ->map(function ($orders) {
                return [
                    'name' => $orders->first()->supplier_name,
                    'total_amount' => $orders->sum('total_amount'),
                    'orders_count' => $orders->count(),
                ];
            })
            ->sortByDesc('total_amount')
            ->take(5)
            ->values();

        return [
            'total_amount' => (float) $totalAmount,
            'paid_amount' => (float) $paidAmount,
            'remaining_amount' => (float) $remainingAmount,
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'pending_orders' => $pendingOrders,
            'payment_stats' => $paymentStats,
            'top_suppliers' => $topSuppliers,
        ];
    }

    /**
     * Lấy thống kê bán hàng theo tháng/năm
     */
    private function getSalesStats($year, $month)
    {
        $orders = Order::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->get();

        $totalAmount = $orders->sum('total_amount');
        $totalOrders = $orders->count();
        $completedOrders = $orders->where('status', 'completed')->count();
        $pendingOrders = $orders->where('status', '!=', 'completed')->count();

        // Thống kê theo trạng thái thanh toán
        $paymentStats = [
            'paid' => $orders->where('payment_status', 'paid')->count(),
            'partial' => $orders->where('payment_status', 'partial')->count(),
            'pending' => $orders->where('payment_status', 'pending')->count(),
        ];

        return [
            'total_amount' => (float) $totalAmount,
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'pending_orders' => $pendingOrders,
            'payment_stats' => $paymentStats,
        ];
    }
}
