<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $code_hash
 * @property string $code_mask
 * @property int $commodity_id
 * @property int $quantity
 * @property int $used_quantity
 * @property int $status
 * @property int|null $order_id
 * @property string|null $result_trade_no
 * @property string|null $result_product_name
 * @property string|null $result_secret
 * @property string|null $result_leave_message
 * @property string|null $batch_no
 * @property string|null $note
 * @property string $create_time
 * @property string|null $used_time
 * @property string|null $used_ip
 */
class RedeemCode extends Model
{
    protected $table = 'redeem_code';

    public $timestamps = false;

    protected $hidden = ['code_hash', 'result_secret', 'result_leave_message'];

    protected $casts = [
        'id' => 'integer',
        'commodity_id' => 'integer',
        'quantity' => 'integer',
        'used_quantity' => 'integer',
        'status' => 'integer',
        'order_id' => 'integer',
    ];

    public function commodity(): ?HasOne
    {
        return $this->hasOne(Commodity::class, 'id', 'commodity_id');
    }

    public function order(): ?HasOne
    {
        return $this->hasOne(Order::class, 'id', 'order_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(RedeemRecord::class, 'code_id', 'id');
    }
}
