<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Ledger entry types. */
final class LedgerType
{
    public const DEPOSIT = 'DEPOSIT';
    public const HOLD = 'HOLD';
    public const RELEASE = 'RELEASE';
    public const CAPTURE = 'CAPTURE';
    public const WITHDRAWAL = 'WITHDRAWAL';
    public const ADJUSTMENT = 'ADJUSTMENT';
}

/**
 * Money service for the OpokuAuctions corridor.
 *
 * Every mutation follows the same three rules:
 *
 *  1. Lock the wallet row FOR UPDATE before reading a balance. Without this,
 *     two concurrent bids can both read the same balance and both succeed,
 *     which is a double-spend.
 *  2. Refuse to move money the buyer does not have. No negative balances, ever.
 *  3. Write the ledger row in the same transaction as the balance change. A
 *     balance without a matching ledger row is a bug, not a state.
 *
 * Compare with the Medusa spike: there, `baseRepository_.transaction()` had to
 * be replaced with knex's own transaction because it silently opened no
 * transaction at all. Here `DB::transaction()` is the transaction, and
 * `lockForUpdate()` is the row lock. There is no way to run this by accident.
 */
class WalletService
{
    public function ensureWallet(string $userId, string $currencyCode = 'usd'): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $userId, 'currency_code' => $currencyCode],
            [
                'id' => 'wallet_'.Str::random(20),
                'balance_available' => 0,
                'balance_held' => 0,
                'version' => 1,
            ],
        );
    }

    /**
     * Add funds. The only operation that creates value, and only ever called
     * from a verified payment provider callback.
     */
    public function deposit(
        string $userId,
        int|float $amount,
        string $currencyCode = 'usd',
        array $reference = []
    ): array {
        $this->assertPositiveInteger($amount, 'Deposit');

        return DB::transaction(function () use ($userId, $amount, $currencyCode, $reference) {
            $wallet = $this->lockWallet($userId, $currencyCode);
            $next = $wallet->balance_available + $amount;

            $wallet->balance_available = $next;
            $wallet->version = $wallet->version + 1;
            $wallet->save();

            $this->writeLedger($wallet, LedgerType::DEPOSIT, $amount, $next + $wallet->balance_held, $reference);

            return ['available' => $next, 'held' => $wallet->balance_held];
        });
    }

    /**
     * Commit funds to a bid: available -> held.
     * Throws if the buyer cannot cover the amount. This is the double-spend
     * gate, and it only works because the row is locked first.
     */
    public function hold(
        string $userId,
        int|float $amount,
        string $currencyCode = 'usd',
        array $reference = []
    ): array {
        $this->assertPositiveInteger($amount, 'Hold');

        return DB::transaction(function () use ($userId, $amount, $currencyCode, $reference) {
            $wallet = $this->lockWallet($userId, $currencyCode);

            if ($wallet->balance_available < $amount) {
                throw new RuntimeException(sprintf(
                    'Insufficient funds: need %d, available %d',
                    $amount,
                    $wallet->balance_available
                ));
            }

            $nextAvailable = $wallet->balance_available - $amount;
            $nextHeld = $wallet->balance_held + $amount;

            $wallet->balance_available = $nextAvailable;
            $wallet->balance_held = $nextHeld;
            $wallet->version = $wallet->version + 1;
            $wallet->save();

            // A HOLD increases the TOTAL balance (funds move bucket, not out of
            // the account), so the ledger amount is positive.
            $this->writeLedger($wallet, LedgerType::HOLD, $amount, $nextAvailable + $nextHeld, $reference);

            return ['available' => $nextAvailable, 'held' => $nextHeld];
        });
    }

    /** Release a hold back to available: held -> available. Losing bidder path. */
    public function release(
        string $userId,
        int|float $amount,
        string $currencyCode = 'usd',
        array $reference = []
    ): array {
        $this->assertPositiveInteger($amount, 'Release');

        return DB::transaction(function () use ($userId, $amount, $currencyCode, $reference) {
            $wallet = $this->lockWallet($userId, $currencyCode);

            if ($wallet->balance_held < $amount) {
                throw new RuntimeException(sprintf(
                    'Cannot release %d: only %d held',
                    $amount,
                    $wallet->balance_held
                ));
            }

            $nextAvailable = $wallet->balance_available + $amount;
            $nextHeld = $wallet->balance_held - $amount;

            $wallet->balance_available = $nextAvailable;
            $wallet->balance_held = $nextHeld;
            $wallet->version = $wallet->version + 1;
            $wallet->save();

            $this->writeLedger($wallet, LedgerType::RELEASE, -$amount, $nextAvailable + $nextHeld, $reference);

            return ['available' => $nextAvailable, 'held' => $nextHeld];
        });
    }

    /** Take held funds permanently. Winning bidder pays path. */
    public function capture(
        string $userId,
        int|float $amount,
        string $currencyCode = 'usd',
        array $reference = []
    ): array {
        $this->assertPositiveInteger($amount, 'Capture');

        return DB::transaction(function () use ($userId, $amount, $currencyCode, $reference) {
            $wallet = $this->lockWallet($userId, $currencyCode);

            if ($wallet->balance_held < $amount) {
                throw new RuntimeException(sprintf(
                    'Cannot capture %d: only %d held',
                    $amount,
                    $wallet->balance_held
                ));
            }

            $nextHeld = $wallet->balance_held - $amount;

            $wallet->balance_held = $nextHeld;
            $wallet->version = $wallet->version + 1;
            $wallet->save();

            $this->writeLedger($wallet, LedgerType::CAPTURE, -$amount, $wallet->balance_available + $nextHeld, $reference);

            return ['available' => $wallet->balance_available, 'held' => $nextHeld];
        });
    }

    /**
     * Verify a wallet against its own ledger. Catches any drift between the
     * cached balance columns and the immutable record of what happened.
     *
     * Two independent invariants, because money moves two ways:
     *   held  - bucket movement only: HOLD adds, RELEASE/CAPTURE remove
     *   total - ownership only: DEPOSIT adds, CAPTURE subtracts;
     *           HOLD/RELEASE are neutral
     */
    public function reconcile(string $userId, string $currencyCode = 'usd'): array
    {
        $wallet = Wallet::where('user_id', $userId)
            ->where('currency_code', $currencyCode)
            ->firstOrFail();

        $entries = LedgerEntry::where('wallet_id', $wallet->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $held = 0;
        $total = 0;

        foreach ($entries as $entry) {
            switch ($entry->type) {
                case LedgerType::HOLD:
                    // Money moves into the held bucket. Total unchanged.
                    $held += abs($entry->amount);
                    break;

                case LedgerType::RELEASE:
                    // Money moves back to available. Total unchanged.
                    $held -= abs($entry->amount);
                    break;

                case LedgerType::CAPTURE:
                    // Money leaves held AND leaves the account entirely.
                    $held -= abs($entry->amount);
                    $total -= abs($entry->amount);
                    break;

                case LedgerType::DEPOSIT:
                    $total += abs($entry->amount);
                    break;

                case LedgerType::WITHDRAWAL:
                    $total -= abs($entry->amount);
                    break;

                case LedgerType::ADJUSTMENT:
                    $total += $entry->amount;
                    break;
            }
        }

        $walletTotal = $wallet->balance_available + $wallet->balance_held;

        return [
            'wallet' => [
                'available' => $wallet->balance_available,
                'held' => $wallet->balance_held,
            ],
            'ledger' => ['available' => $total - $held, 'held' => $held],
            'ok' => $total === $walletTotal && $held === $wallet->balance_held,
        ];
    }

    /** Lock the wallet row for the rest of the current transaction. */
    private function lockWallet(string $userId, string $currencyCode): Wallet
    {
        $wallet = Wallet::where('user_id', $userId)
            ->where('currency_code', $currencyCode)
            ->lockForUpdate()
            ->first();

        if (! $wallet) {
            throw new RuntimeException(
                sprintf('No %s wallet for user %s', strtoupper($currencyCode), $userId)
            );
        }

        return $wallet;
    }

    private function writeLedger(
        Wallet $wallet,
        string $type,
        int|float $amount,
        int $balanceAfter,
        array $reference = []
    ): void {
        // Append-only: no update path exists on this table by design.
        LedgerEntry::create([
            'id' => 'led_'.Str::random(16),
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'currency_code' => $wallet->currency_code,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_type' => $reference['type'] ?? null,
            'reference_id' => $reference['id'] ?? null,
            'note' => $reference['note'] ?? null,
        ]);
    }

    /**
     * Guard every money input.
     *
     * This exists because PHP will quietly turn 10.5 into int(10) when it is
     * passed to an `int` parameter, which would let a fractional cent through
     * without complaint. strict_types stops the silent coercion; this check
     * stops the float at the domain boundary with a message that says why.
     */
    private function assertPositiveInteger(int|float $amount, string $verb): int
    {
        if (! is_int($amount) || $amount <= 0) {
            throw new RuntimeException("{$verb} amount must be a positive integer");
        }

        return $amount;
    }
}