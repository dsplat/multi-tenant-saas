<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 域名保证金（二级域名开通联动）
    |--------------------------------------------------------------------------
    |
    | 单位：分。>0 时 DomainService::approveDomain 自动经 SupplySettlementService
    | 锁定保证金（domain_deposit 台账，其他应付款），域名停用时退还。
    | 0 = 关闭生命周期联动（仅 admin 手工操作保证金）。
    |
    */
    'domain_deposit_fen' => (int) env('DOMAIN_DEPOSIT_FEN', 0),
];
