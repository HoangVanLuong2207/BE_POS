<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_number',
        'supplier_name',
        'supplier_phone',
        'supplier_address',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'payment_status',
        'status',
        'notes',
        'purchase_date',
        'due_date',
        'created_by'
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'remaining_amount' => 'float',
    ];

    /**
     * Get the user who created this purchase order
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the purchase order items
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Generate unique purchase number
     */
    public static function generatePurchaseNumber(): string
    {
        $prefix = 'PO' . date('Ymd');
        $lastOrder = self::where('purchase_number', 'like', $prefix . '%')
            ->orderBy('purchase_number', 'desc')
            ->first();

        if ($lastOrder) {
            $lastNumber = (int) substr($lastOrder->purchase_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate remaining amount
     */
    public function calculateRemainingAmount(): void
    {
        $this->remaining_amount = (float) $this->total_amount - (float) $this->paid_amount;

        if ($this->remaining_amount <= 0) {
            $this->payment_status = 'paid';
        } elseif ($this->paid_amount > 0) {
            $this->payment_status = 'partial';
        } else {
            $this->payment_status = 'pending';
        }
    }

    /**
     * Update total amount from items
     */
    public function updateTotalAmount(): void
    {
        $this->total_amount = $this->items()->sum('total_amount');
        $this->calculateRemainingAmount();
        $this->save();
    }
}
