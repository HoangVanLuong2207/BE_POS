<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupSampleData extends Command
{
	protected $signature = 'cleanup:sample {--force : Do not prompt for confirmation}';

	protected $description = 'Delete sample test data: purchase orders, sales orders, and products created for testing';

	public function handle()
	{
		$this->info('Cleaning up sample data...');

		if (!$this->option('force')) {
			if (!$this->confirm('This will delete test purchase orders, sales orders, and products. Continue?')) {
				$this->warn('Cleanup cancelled.');
				return Command::SUCCESS;
			}
		}

		DB::beginTransaction();
		try {
			// Heuristics to detect sample data
			// 1) Purchase orders with supplier_name like "Test%" or number starting with 'PO' today
			$todayPrefix = 'PO' . date('Ymd');
			$poQuery = PurchaseOrder::query()
				->where('supplier_name', 'like', 'Test%')
				->orWhere('purchase_number', 'like', $todayPrefix.'%');
			$poCount = (clone $poQuery)->count();
			$poIds = (clone $poQuery)->pluck('id');
			$deletedPO = 0;
			foreach ($poIds as $id) {
				$po = PurchaseOrder::with('items')->find($id);
				if ($po) {
					$po->delete();
					$deletedPO++;
				}
			}

			// 2) Sales orders without customer info and created today (likely test)
			$salesQuery = Order::query()
				->whereNull('customer_name')
				->whereNull('customer_phone')
				->whereDate('created_at', now()->toDateString());
			$deletedSales = $salesQuery->delete();

			// 3) Products with names like 'Test %' or 'Bulk Product %'
			$deletedProducts = Product::where('name', 'like', 'Test%')
				->orWhere('name', 'like', 'Bulk Product%')
				->delete();

			DB::commit();

			$this->info("Deleted: {$deletedPO} purchase orders, {$deletedSales} sales orders, {$deletedProducts} products.");
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			DB::rollBack();
			$this->error('Cleanup failed: ' . $e->getMessage());
			return Command::FAILURE;
		}
	}
}
