<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A paid module offer a Super Admin can change. See the module_addon_offers migration. */
class ModuleAddonOffer extends Model
{
    public const KIND_MODULE = 'module';
    public const KIND_CAPABILITY = 'capability';

    protected $table = 'module_addon_offers';

    protected $fillable = ['module', 'label', 'price', 'term_months', 'units_included', 'is_active', 'is_free', 'kind', 'capability_slug'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'term_months' => 'integer',
            'units_included' => 'integer',
            'is_active' => 'boolean',
            'is_free' => 'boolean',
        ];
    }
}
