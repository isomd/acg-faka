<?php
declare(strict_types=1);

return [
    ['name' => 'endpoint', 'type' => 'input', 'title' => 'API 地址', 'default' => 'https://pay.tmlab.top', 'placeholder' => 'https://pay.tmlab.top', 'required' => true],
    ['name' => 'tenant_id', 'type' => 'input', 'title' => '租户 ID', 'default' => 'tml', 'placeholder' => 'tml', 'required' => true],
    ['name' => 'app_id', 'type' => 'input', 'title' => '应用 ID', 'default' => 'account', 'placeholder' => 'account', 'required' => true],
    ['name' => 'app_key', 'type' => 'input', 'title' => 'App Key', 'default' => '1790742045352075261', 'required' => true],
    ['name' => 'app_secret', 'type' => 'password', 'title' => 'App Secret', 'default' => '', 'placeholder' => '请在 Mercury 重置后粘贴新 Secret', 'required' => true],
    ['name' => 'payment_method_code', 'type' => 'input', 'title' => '支付方式代码', 'default' => '', 'placeholder' => '需与 Mercury 应用启用的方式一致', 'required' => true],
    ['name' => 'currency', 'type' => 'input', 'title' => '币种', 'default' => 'CNY', 'required' => true]
];
