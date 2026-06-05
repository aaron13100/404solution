<?php

if (!defined('ABSPATH')) {
    exit;
}

interface ABJ_404_Solution_DatabaseUpgradeCoordinator {

    /**
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    public function invokeDatabaseUpgradeMethod(string $method, array $args = []);

    /** @return string|null */
    public function getUpgradeRuntimeId();

    public function isLogsv2CanonicalBackfillScheduled(): bool;

    public function setLogsv2CanonicalBackfillScheduled(bool $scheduled): void;

    public function getCanonicalUrlBackfillChunkSize(): int;

    public function getCanonicalUrlBackfillTimeBudgetSec(): float;

    public function getLogsv2CanonicalUrlBackfillTimeBudgetSec(): float;

    public function getLogsv2CanonicalUrlBackfillCompleteOption(): string;

    /** @return array<int, string> */
    public function getPluginTableSuffixes(): array;
}
