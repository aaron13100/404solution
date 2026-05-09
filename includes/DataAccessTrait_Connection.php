<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_ConnectionTrait {

    /**
     * Probe wpdb's check_connection method defensively for custom wpdb
     * drop-ins (HyperDB, LudicrousDB, mu-cluster proxies) that may not
     * implement it. WordPress core has shipped this method since 4.1,
     * but a `wp-content/db.php` drop-in can replace `$wpdb` with a
     * subclass that omits it. Calling an undefined method on such a
     * subclass throws a fatal Error before we'd hit a try/catch.
     *
     * Returns true (assume connected) when the method is missing -- the
     * absence of a probe is not a connection failure, and the standard
     * wpdb default is also "no probe == connected".
     *
     * @param object $wpdb The current $wpdb instance (or subclass).
     * @param bool   $allowReconnect Passed through to check_connection().
     * @return bool True if connected (or unable to probe); false if probed and disconnected.
     */
    private function safeCheckConnection($wpdb, $allowReconnect = false) {
        if (!is_object($wpdb)) {
            return true;
        }
        if (!method_exists($wpdb, 'check_connection') && !is_callable(array($wpdb, 'check_connection'))) {
            return true;
        }
        return (bool) $wpdb->check_connection($allowReconnect);
    }

    /**
     * Ensure database connection is active and reconnect if necessary.
     *
     * @return bool True if connection is active, false otherwise
     */
    private function ensureConnection() {
        global $wpdb;

        if (!isset($wpdb)) {
            return true;
        }

        try {
            $isConnected = $this->safeCheckConnection($wpdb, false);

            if (!$isConnected) {
                $this->logger->debugMessage("Database connection lost, attempting to reconnect...");

                if (is_object($wpdb) && method_exists($wpdb, 'db_connect')) {
                    $wpdb->db_connect();
                }

                if ($this->safeCheckConnection($wpdb, false)) {
                    $this->logger->debugMessage("Database reconnection successful");
                    return true;
                }

                $this->logger->errorMessage("Failed to reconnect to database");
                return false;
            }
        } catch (Exception $e) {
            $this->logger->debugMessage("Connection check failed: " . $e->getMessage());
            return true;
        } catch (Error $e) {
            $this->logger->debugMessage("Connection check not available: " . $e->getMessage());
            return true;
        }

        return true;
    }
}
