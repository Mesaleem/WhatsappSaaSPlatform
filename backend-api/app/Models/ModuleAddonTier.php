<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A price tier of a module add-on: units from..to (to null = and above) cost price. Editable by a Super Admin. */
class ModuleAddonTier extends Model
{
    protected $table = 'module_addon_tiers';

    protected $fillable = ['module', 'from_units', 'to_units', 'price'];

    protected function casts(): array
    {
        return [
            'from_units' => 'integer',
            'to_units' => 'integer',
            'price' => 'decimal:2',
        ];
    }
}
