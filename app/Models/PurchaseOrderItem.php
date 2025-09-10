<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'product_name',
        'product_description',
        'purchase_price',
        'selling_price',
        'quantity',
        'total_amount',
        'unit',
        'notes'
    ];

    protected $casts = [
        'purchase_price' => 'float',
        'selling_price' => 'float',
        'total_amount' => 'float',
        'quantity' => 'integer',
    ];

    /**
     * Get the purchase order that owns this item
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Get the product
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculate total amount
     */
    public function calculateTotalAmount(): void
    {
        $this->total_amount = (float) $this->purchase_price * (int) $this->quantity;
    }

    /**
     * Boot method to auto-calculate total amount
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($item) {
            $item->calculateTotalAmount();
        });

        static::saved(function ($item) {
            // Update purchase order total when item is saved
            $item->purchaseOrder->updateTotalAmount();
        });

        static::deleted(function ($item) {
            // Update purchase order total when item is deleted
            $item->purchaseOrder->updateTotalAmount();
        });
    }
}
