<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Redeem extends Manage
{
    /** @throws \Kernel\Exception\ViewException */
    public function index(): string
    {
        \App\Util\Schema::ensureRedeemCode();
        \App\Util\Schema::ensureDolaPickup();
        return $this->render('兑换码管理', 'Trade/Redeem.html');
    }
}
