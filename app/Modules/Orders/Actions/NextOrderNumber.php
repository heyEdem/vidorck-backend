<?php

namespace App\Modules\Orders\Actions;

use Illuminate\Support\Facades\DB;

final class NextOrderNumber
{
    public function execute(int $year): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Order numbers must be allocated inside a transaction.');
        }

        DB::statement(<<<'SQL'
            INSERT INTO order_number_sequences (year, last_value, created_at, updated_at)
            VALUES (?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT(year) DO UPDATE SET last_value = last_value + 1, updated_at = CURRENT_TIMESTAMP
            SQL, [$year]);

        $sequence = (int) DB::table('order_number_sequences')->where('year', $year)->value('last_value');

        return sprintf('VHI-%04d-%06d', $year, $sequence);
    }
}
