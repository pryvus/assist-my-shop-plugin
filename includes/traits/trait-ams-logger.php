<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait AMS_Logger {

    /**
     * Whether logging is enabled (option or WP_DEBUG)
     */
    public static function enabled(): bool {
        return ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || get_option( 'ams_debug', '0' ) === '1';
    }

    /**
     * Sanitize data for logging (remove sensitive keys)
     */
    public static function sanitize_for_log( $data ) {
        if ( is_array( $data ) ) {
            $clean = $data;
            if ( isset( $clean['api_key'] ) ) {
                $clean['api_key'] = '***REDACTED***';
            }
            return $clean;
        }

        return $data;
    }

    /**
     * Log a message to the PHP error log when enabled.
     *
     * @param string $message
     * @param mixed  $context
     * @param string $level
     */
    public static function log( string $message, $context = null, string $level = 'info' ): void {
        if ( ! self::enabled() ) {
            return;
        }

        $prefix = "AMS [" . strtoupper( $level ) . "]: ";

        $entry = [
            'time'    => date_i18n( 'Y-m-d H:i:s' ),
            'level'   => strtoupper( $level ),
            'message' => $message,
        ];

        if ( ! is_null( $context ) ) {
            $entry['context'] = self::sanitize_for_log( $context );
        }

        $line = sprintf( "%s %s: %s", $entry['time'], $entry['level'], $entry['message'] );
        if ( isset( $entry['context'] ) ) {
            $line .= ' -- ' . trim( print_r( $entry['context'], true ) );
        }

        // Attempt to write to plugin-specific log file in uploads
        $written = false;
        $log_path = self::get_log_file_path();
        if ( $log_path ) {
            $dir = dirname( $log_path );
            if ( ! file_exists( $dir ) ) {
                // Use WP helper to create dir if possible
                if ( function_exists( 'wp_mkdir_p' ) ) {
                    wp_mkdir_p( $dir );
                } else {
                    @mkdir( $dir, 0755, true );
                }
            }

            if ( is_writable( $dir ) || ( file_exists( $log_path ) && is_writable( $log_path ) ) ) {
                $line = $line . PHP_EOL;
                $written_bytes = @file_put_contents( $log_path, $line, FILE_APPEND | LOCK_EX );
                if ( $written_bytes !== false ) {
                    $written = true;
                }
            }
        }

        // Fallback to error_log if file couldn't be written
        if ( ! $written ) {
            error_log( $prefix . $message . ( isset( $entry['context'] ) ? ' -- ' . print_r( $entry['context'], true ) : '' ) );
        }
    }

    /**
     * Return path to the plugin log file in uploads directory.
     */
    private static function get_log_file_path(): ?string {
        if ( ! function_exists( 'wp_upload_dir' ) ) {
            return null;
        }
        $uploads = wp_upload_dir();
        if ( empty( $uploads['basedir'] ) ) {
            return null;
        }
        $dir = trailingslashit( $uploads['basedir'] ) . 'ams-logs';
        return $dir . DIRECTORY_SEPARATOR . 'ams.log';
    }

}
