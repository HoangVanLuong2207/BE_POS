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
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id'); // ID hóa đơn nhập hàng
            $table->unsignedBigInteger('product_id'); // ID sản phẩm
            $table->string('product_name'); // Tên sản phẩm (lưu để backup)
            $table->text('product_description')->nullable(); // Mô tả sản phẩm
            $table->decimal('purchase_price', 10, 2); // Giá nhập
            $table->decimal('selling_price', 10, 2); // Giá bán (để so sánh)
            $table->integer('quantity'); // Số lượng nhập
            $table->decimal('total_amount', 12, 2); // Thành tiền (purchase_price * quantity)
            $table->string('unit')->default('cái'); // Đơn vị tính
            $table->text('notes')->nullable(); // Ghi chú cho sản phẩm này
            $table->timestamps();

            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->index(['purchase_order_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
