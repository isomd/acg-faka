<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * 一次兑换码提货记录。保存独立快照，商品或订单删除后仍可凭兑换码查看。
 *
 * @property int $id
 * @property int $code_id
 * @property string $request_token
 * @property int $order_id
 * @property string $trade_no
 * @property string $product_name
 * @property int $quantity
 * @property string $secret
 * @property string|null $leave_message
 * @property string $used_ip
 * @property string $create_time
 */
class RedeemRecord extends Model
{
    protected $table = 'redeem_record';

    public $timestamps = false;

    protected $hidden = ['secret', 'leave_message', 'request_token'];

    protected $casts = [
        'id' => 'integer',
        'code_id' => 'integer',
        'order_id' => 'integer',
        'quantity' => 'integer',
    ];
}
