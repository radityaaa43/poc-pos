<?php

namespace App\Support\Credit;

class CreditStanding
{
    public function __construct(
        public readonly bool $mayBuyOnCredit,
        public readonly ?int $limit,
        public readonly int $used,
        public readonly bool $hasOverdue,
    ) {}

    /** What is left of the limit, never below zero; null when there is no limit. */
    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }

    /**
     * @return array{may_buy_on_credit: bool, credit_limit: ?int, used: int, remaining: ?int, has_overdue: bool}
     */
    public function toArray(): array
    {
        return [
            'may_buy_on_credit' => $this->mayBuyOnCredit,
            'credit_limit' => $this->limit,
            'used' => $this->used,
            'remaining' => $this->remaining(),
            'has_overdue' => $this->hasOverdue,
        ];
    }
}
