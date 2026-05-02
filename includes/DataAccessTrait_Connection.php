<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_ConnectionTrait {

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
            $isConnected = $wpdb->check_connection(false);

            if (!$isConnected) {
                $this->logger->debugMessage("Database connection lost, attempting to reconnect...");

                $wpdb->db_connect();

                if ($wpdb->check_connection(false)) {
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
