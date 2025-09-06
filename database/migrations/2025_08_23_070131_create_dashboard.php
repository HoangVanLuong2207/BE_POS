<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard', function (Blueprint $table) {
            $table->id();
            $table->decimal('subtotal', 15, 2)->default('0');
            $table->decimal('total', 15, 2)->default('0');
            $table->decimal('profit', 15, 2)->default('0');
            $table->integer('year')->default(0);
            $table->integer('month')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
