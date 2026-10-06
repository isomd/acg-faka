<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class MercuryOrder extends Model
{
    protected $table = 'mercury_order';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'id' => 'integer',
        'pay_config_id' => 'integer',
        'quantity' => 'integer'
    ];
}
