<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class MercuryWebhookEvent extends Model
{
    protected $table = 'mercury_webhook_event';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['id' => 'integer'];
}
