<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_number')->unique(); // Số hóa đơn nhập hàng
            $table->string('supplier_name')->nullable(); // Tên nhà cung cấp
            $table->string('supplier_phone')->nullable(); // SĐT nhà cung cấp
            $table->text('supplier_address')->nullable(); // Địa chỉ nhà cung cấp
            $table->decimal('total_amount', 12, 2)->default(0); // Tổng tiền nhập hàng
            $table->decimal('paid_amount', 12, 2)->default(0); // Số tiền đã thanh toán
            $table->decimal('remaining_amount', 12, 2)->default(0); // Số tiền còn nợ
            $table->enum('payment_status', ['pending', 'partial', 'paid'])->default('pending'); // Trạng thái thanh toán
            $table->enum('status', ['draft', 'confirmed', 'completed', 'cancelled'])->default('draft'); // Trạng thái hóa đơn
            $table->text('notes')->nullable(); // Ghi chú
            $table->date('purchase_date'); // Ngày nhập hàng
            $table->date('due_date')->nullable(); // Ngày đến hạn thanh toán
            $table->unsignedBigInteger('created_by')->nullable(); // Người tạo
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['purchase_date', 'status']);
            $table->index('supplier_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
