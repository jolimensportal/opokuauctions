<?php

namespace App\Spike;

use Symfony\Component\Process\Process;

/**
 * Runs N operations in N real OS processes, released together by a shared
 * start barrier, so the row locks are exercised against genuine concurrency
 * rather than against sequential calls that merely look concurrent.
 */
class ProcessRace
{
    /**
     * Fire `count` artisan commands at the same instant.
     *
     * @param  array<int,string>  $argumentSets  one arg set per process
     * @return array<int,string>  one result word per process, in launch order
     */
    public function run(array $argumentSets, int $count): array
    {
        $startAt = microtime(true) + 1.2; // small runway so every process is up first

        $processes = [];
        foreach ($argumentSets as $i => $args) {
            $process = new Process(
                array_merge([PHP_BINARY, base_path('artisan'), 'spike:race'], $args),
                base_path(),
                null,
                null,
                60
            );
            $process->setTimeout(60);
            $process->start();
            $processes[$i] = $process;
        }

        // Release the barrier only once every process is actually started.
        while (microtime(true) < $startAt) {
            usleep(1000);
        }

        $results = [];
        foreach ($processes as $i => $process) {
            $process->wait();
            $out = trim($process->getOutput());
            $results[$i] = $out !== '' ? $out : 'error';
        }

        return $results;
    }

    /** Build the arg sets for a wallet hold race. */
    public static function holdArgs(string $user, int $amount, int $count): array
    {
        $sets = [];
        for ($i = 0; $i < $count; $i++) {
            $sets[] = [
                'type' => 'hold',
                '--user='.$user,
                '--amount='.$amount,
                '--currency=usd',
            ];
        }

        return $sets;
    }

    /** Build the arg sets for a bid race on one lot. */
    public static function bidArgs(string $lotId, int $baseAmount, int $step, int $count): array
    {
        $sets = [];
        for ($i = 0; $i < $count; $i++) {
            $sets[] = [
                'type' => 'bid',
                '--lot='.$lotId,
                '--bidder='.$lotId.'-hammer-'.$i,
                '--amount='.($baseAmount + $i * $step),
                '--currency=usd',
            ];
        }

        return $sets;
    }
}