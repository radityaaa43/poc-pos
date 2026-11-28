<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/** The shift still holds unhanded items, unpaid bills (REQ-003 AC-12) or partly paid splits (#396): `$blockers` lists them. */
class ShiftCloseBlockedException extends ValidationException
{
    public const MESSAGE = 'Shift tidak bisa ditutup: masih ada item belum diserahkan atau tagihan belum lunas.';

    /** @var array<int, array{type: string, order: string, label: ?string, amount: int}> */
    public array $blockers = [];

    /** @param array<int, array{type: string, order: string, label: ?string, amount: int}> $blockers */
    public static function withBlockers(array $blockers): self
    {
        /** @var self $exception */
        $exception = self::withMessages(['shift' => self::MESSAGE]);
        $exception->blockers = $blockers;

        return $exception;
    }
}
