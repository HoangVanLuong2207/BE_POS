<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
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

        return response()->json([
            'year'  => $year,
            'month' => $month,
            'data'  => [
                'subtotal' => (float) ($dash->subtotal ?? 0), // tiền nhập
                'total'    => (float) ($dash->total ?? 0),    // tiền bán
                'profit'   => (float) ($dash->profit ?? 0),
            ],
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

        return response()->json([
            'year'  => $year,
            'month' => $month,
            'data'  => [
                'subtotal' => (float) ($dash->subtotal ?? 0), // tiền nhập
                'total'    => (float) ($dash->total ?? 0),    // tiền bán
                'profit'   => (float) ($dash->profit ?? 0),
            ],
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
}
