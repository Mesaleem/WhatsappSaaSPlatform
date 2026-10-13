<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 2 — a product or service the tenant sells. Documents copy its name,
 * price, tax and HSN/SAC into their own lines, so changing a product never changes an
 * issued document.
 */
class CrmProduct extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    protected $table = 'crm_products';

    /** @var list<string> */
    public const FREQUENCIES = ['one_time', 'monthly', 'yearly'];

    protected $fillable = ['account_id', 'code', 'name', 'description', 'unit', 'unit_price', 'tax_rate', 'hsn_sac', 'billing_frequency', 'is_active'];

    protected $attributes = ['unit' => 'nos', 'billing_frequency' => 'one_time', 'is_active' => true, 'unit_price' => '0.00', 'tax_rate' => '0.00'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'unit_price' => 'decimal:2', 'tax_rate' => 'decimal:2', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (CrmProduct $product): void {
            if ($product->exists && $product->isDirty('account_id')) {
                throw ValidationException::withMessages(['account_id' => ['A product cannot be moved to another account.']]);
            }
            $product->name = trim((string) $product->name);
            if ($product->name === '') {
                throw ValidationException::withMessages(['name' => ['The product name is required.']]);
            }
            $product->code = $product->code === null ? null : (trim($product->code) ?: null);
            if (! in_array($product->billing_frequency, self::FREQUENCIES, true)) {
                throw ValidationException::withMessages(['billing_frequency' => ['The selected billing frequency is invalid.']]);
            }
        });
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
