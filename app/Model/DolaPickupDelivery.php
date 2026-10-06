<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Dola 每一次实际向上游提货的本地账本。
 *
 * payload 含账号明文，只能由专用详情接口按单条读取，列表序列化永不返回。
 *
 * @property int $id
 * @property int $shared_id
 * @property string $request_no
 * @property int $sequence
 * @property int $order_quantity
 * @property string $source_hash
 * @property string $request_tag
 * @property int $requested
 * @property int $before_remaining
 * @property int $before_times
 * @property int $status
 * @property string|null $payload
 * @property string|null $error
 * @property string $create_time
 * @property string $update_time
 */
final class DolaPickupDelivery extends Model
{
    protected $table = 'dola_pickup_delivery';

    public $timestamps = false;

    protected $hidden = ['payload', 'source_hash'];

    protected $casts = [
        'id' => 'integer',
        'shared_id' => 'integer',
        'sequence' => 'integer',
        'order_quantity' => 'integer',
        'requested' => 'integer',
        'before_remaining' => 'integer',
        'before_times' => 'integer',
        'status' => 'integer',
    ];

    public function shared(): ?HasOne
    {
        return $this->hasOne(Shared::class, 'id', 'shared_id');
    }

    public function orderByRequest(): ?HasOne
    {
        return $this->hasOne(Order::class, 'request_no', 'request_no');
    }

    public function orderByTradeNo(): ?HasOne
    {
        return $this->hasOne(Order::class, 'trade_no', 'request_no');
    }
}
