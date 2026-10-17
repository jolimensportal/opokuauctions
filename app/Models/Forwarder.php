<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A freight forwarder. A record, not a user — they never log in. Admin books
 * on their behalf. Promoting them to real accounts later is an added role, not
 * a migration.
 */
class Forwarder extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'forwarders';

    protected $fillable = ['id', 'name', 'contact_name', 'contact_phone', 'contact_email'];

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'forwarder_id');
    }
}
