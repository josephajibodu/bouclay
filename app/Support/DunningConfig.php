<?php

namespace App\Support;

use App\Enums\DunningTerminalAction;
use App\Models\Team;
use App\Models\TeamSettings;

/**
 * Team-level dunning settings stored in {@see TeamSettings::$dunning_config}.
 *
 * @phpstan-type DunningConfigArray array{
 *     max_attempts: int,
 *     retry_intervals_days: list<int>,
 *     terminal_action: string,
 *     incomplete_grace_days: int,
 * }
 */
final readonly class DunningConfig
{
    /**
     * @param  list<int>  $retryIntervalsDays
     */
    public function __construct(
        public int $maxAttempts,
        public array $retryIntervalsDays,
        public DunningTerminalAction $terminalAction,
        public int $incompleteGraceDays,
    ) {
        //
    }

    /**
     * Sensible defaults inspired by Paddle's 30-day recovery window.
     */
    public static function defaults(): self
    {
        return new self(
            maxAttempts: 4,
            retryIntervalsDays: [1, 3, 7, 14],
            terminalAction: DunningTerminalAction::Cancel,
            incompleteGraceDays: 7,
        );
    }

    public static function forTeam(Team $team): self
    {
        $team->loadMissing('settings');

        return self::fromArray($team->settings?->dunning_config);
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    public static function fromArray(?array $config): self
    {
        $defaults = self::defaults();

        if ($config === null) {
            return $defaults;
        }

        $intervals = $config['retry_intervals_days'] ?? $defaults->retryIntervalsDays;

        if (! is_array($intervals)) {
            $intervals = $defaults->retryIntervalsDays;
        }

        $intervals = array_values(array_map(
            fn (mixed $days): int => max(0, (int) $days),
            $intervals,
        ));

        if ($intervals === []) {
            $intervals = $defaults->retryIntervalsDays;
        }

        $terminal = DunningTerminalAction::tryFrom((string) ($config['terminal_action'] ?? ''))
            ?? $defaults->terminalAction;

        return new self(
            maxAttempts: max(1, (int) ($config['max_attempts'] ?? $defaults->maxAttempts)),
            retryIntervalsDays: $intervals,
            terminalAction: $terminal,
            incompleteGraceDays: max(1, (int) ($config['incomplete_grace_days'] ?? $defaults->incompleteGraceDays)),
        );
    }

    /**
     * @return DunningConfigArray
     */
    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'retry_intervals_days' => $this->retryIntervalsDays,
            'terminal_action' => $this->terminalAction->value,
            'incomplete_grace_days' => $this->incompleteGraceDays,
        ];
    }

    public function retryIntervalDaysAfterAttempt(int $failedAttemptCount): int
    {
        if ($failedAttemptCount <= 0) {
            return 0;
        }

        $index = min($failedAttemptCount - 1, count($this->retryIntervalsDays) - 1);

        return $this->retryIntervalsDays[$index];
    }
}
