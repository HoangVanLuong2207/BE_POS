<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Table;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function index()
    {
        $tables = Table::all();
        return response()->json($tables);
    }

    public function show(Table $table)
    {
        $table->load('orders.products');
        return response()->json($table);
    }

    public function updateStatus(Request $request, Table $table)
    {
        $validated = $request->validate([
            'status' => 'required|in:available,occupied,reserved',
        ]);

        $table->update(['status' => $validated['status']]);
        return response()->json($table);
    }
}
