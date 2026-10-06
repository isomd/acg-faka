<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\Waf;
use App\Pay\Mercury\Webhook as MercuryWebhook;
use App\Service\Order;
use App\Service\Recharge;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;

#[Interceptor(Waf::class, Interceptor::TYPE_API)]
final class Mercury extends User
{
    #[Inject]
    private Order $order;

    #[Inject]
    private Recharge $recharge;

    public function webhook(Request $request): string
    {
        header('Content-Type: text/plain; charset=utf-8');
        return (new MercuryWebhook())->handle($request, $this->order, $this->recharge);
    }
}
