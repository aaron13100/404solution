<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runtime-only database infrastructure state shared by DatabaseCore helpers.
 */
final class ABJ_404_Solution_DatabaseRuntimeState {

    /** @var int Cooldown when DB query quota is exceeded. */
    const DB_QUOTA_COOLDOWN_SECONDS = 900;

    /** @var int Cooldown when DB is read-only or storage is full. */
    const DB_WRITE_BLOCK_COOLDOWN_SECONDS = 900;

    /** @var bool Whether MariaDB SET STATEMENT wrapper failed and should be skipped. */
    private static $setStatementWrapperUnsupported = false;

    /** @return void */
    public static function setSetStatementWrapperUnsupported(bool $value): void {
        self::$setStatementWrapperUnsupported = $value;
    }

    /** @return bool */
    public static function isSetStatementWrapperUnsupported(): bool {
        return self::$setStatementWrapperUnsupported;
    }
}
