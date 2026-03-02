<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Self-hosted plugin updater backed by GitHub releases.
 */
class AMS_GitHub_Updater {
    /**
     * Plugin basename (for example `assist-my-shop/ams.php`).
     *
     * @var string
     */
    protected $plugin_file;

    /**
     * GitHub repository identifier (`owner/repo`).
     *
     * @var string
     */
    protected $repo;

    /**
     * Optional GitHub token for authenticated API requests.
     *
     * @var string
     */
    protected $token;

    /**
     * Constructor.
     *
     * @param string $plugin_file Absolute plugin main file path.
     * @param string $repo        GitHub repository in `owner/repo` format.
     * @param string $token       Optional GitHub personal access token.
     * @return void Registers updater-related hooks.
     */
    public function __construct( $plugin_file, $repo, $token = '' ) {
        $this->plugin_file = plugin_basename( $plugin_file );
        $this->repo        = $repo;
        $this->token       = $token;

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'http_request_args', [ $this, 'http_request_args' ], 10, 2 );
        add_filter( 'plugin_auto_update_setting_html', [ $this, 'render_auto_update_setting_html' ], 10, 3 );
    }

    /**
     * Inject update information into plugin update transient.
     *
     * @param object $transient Current update transient object.
     * @return object Updated transient object.
     */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $current = $this->get_current_version();
        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $remote_version = ltrim( $release['tag_name'] ?? '', "vV" );
        if ( ! $remote_version ) {
            return $transient;
        }

        $slug = dirname( $this->plugin_file );
        $base = new stdClass();
        $base->id          = 'github.com/' . $this->repo;
        $base->plugin      = $this->plugin_file;
        $base->slug        = $slug;
        $base->url         = 'https://github.com/' . $this->repo;
        $base->new_version = $remote_version;
        $base->package     = '';

        if ( version_compare( $remote_version, $current, '>' ) ) {
            $package = $this->get_download_url( $release );

            $plugin = new stdClass();
            $plugin->id          = 'github.com/' . $this->repo;
            $plugin->plugin      = $this->plugin_file;
            $plugin->slug        = $slug;
            $plugin->url         = 'https://github.com/' . $this->repo;
            $plugin->new_version = $remote_version;
            $plugin->package     = $package;

            $transient->response[ $this->plugin_file ] = $plugin;
            if ( isset( $transient->no_update[ $this->plugin_file ] ) ) {
                unset( $transient->no_update[ $this->plugin_file ] );
            }
        } else {
            $transient->no_update[ $this->plugin_file ] = $base;
            if ( isset( $transient->response[ $this->plugin_file ] ) ) {
                unset( $transient->response[ $this->plugin_file ] );
            }
        }

        return $transient;
    }

    /**
     * Read currently installed plugin version.
     *
     * @return string Installed plugin version.
     */
    protected function get_current_version() {
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->plugin_file );
        return $data['Version'] ?? '0';
    }

    /**
     * Fetch latest release metadata from GitHub API.
     *
     * @return array<string, mixed>|false Release payload or false on error.
     */
    protected function get_latest_release() {
        $url = "https://api.github.com/repos/{$this->repo}/releases/latest";
        $args = [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'url' ),
            ],
            'timeout' => 15,
        ];

        if ( ! empty( $this->token ) ) {
            $args['headers']['Authorization'] = 'token ' . $this->token;
        }

        $resp = wp_remote_get( $url, $args );
        if ( is_wp_error( $resp ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( 200 !== (int) $code ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            return false;
        }

        return $data;
    }

    /**
     * Resolve downloadable package URL from release data.
     *
     * @param array<string, mixed> $release Release metadata payload.
     * @return string Download URL.
     */
    protected function get_download_url( $release ) {
        if ( isset( $release['assets'] ) && is_array( $release['assets'] ) && ! empty( $release['assets'] ) ) {
            return $release['assets'][0]['browser_download_url'];
        }

        if ( isset( $release['zipball_url'] ) ) {
            return $release['zipball_url'];
        }

        return "https://github.com/{$this->repo}/archive/refs/tags/{$release['tag_name']}.zip";
    }

    /**
     * Provide plugin information payload for update details modal.
     *
     * @param mixed  $res    Existing API response.
     * @param string $action Plugins API action.
     * @param object $args   Request arguments object.
     * @return mixed Plugin info object or original response.
     */
    public function plugin_info( $res, $action, $args ) {
        $slug = dirname( $this->plugin_file );
        if ( ( isset( $args->plugin ) && $args->plugin === $this->plugin_file ) || ( isset( $args->slug ) && $args->slug === $slug ) ) {
            $release = $this->get_latest_release();
            if ( ! $release ) {
                return $res;
            }

            $remote_version = ltrim( $release['tag_name'] ?? '', "vV" );
            $info = new stdClass();
            $info->name = $release['name'] ?? $slug;
            $info->slug = $slug;
            $info->version = $remote_version;
            $info->author = $release['author']['login'] ?? '';
            $info->download_link = $this->get_download_url( $release );
            $info->sections = [
                'changelog' => $release['body'] ?? '',
            ];

            return $info;
        }

        return $res;
    }

    /**
     * Add GitHub auth/user-agent headers to outbound requests.
     *
     * @param array<string, mixed> $args HTTP request arguments.
     * @param string               $url  Request URL.
     * @return array<string, mixed> Modified request arguments.
     */
    public function http_request_args( $args, $url ) {
        if ( false !== strpos( $url, 'api.github.com' ) || false !== strpos( $url, 'github.com' ) ) {
            if ( ! empty( $this->token ) ) {
                if ( ! isset( $args['headers'] ) ) {
                    $args['headers'] = [];
                }
                $args['headers']['Authorization'] = 'token ' . $this->token;
            }

            if ( ! isset( $args['headers']['User-Agent'] ) ) {
                if ( ! isset( $args['headers'] ) ) {
                    $args['headers'] = [];
                }
                $args['headers']['User-Agent'] = 'WordPress/' . get_bloginfo( 'url' );
            }
        }

        return $args;
    }

    /**
     * Ensure auto-update toggle is visible for this plugin, even when core
     * marks update-supported as unavailable for non-dotorg sources.
     *
     * @param string               $html        Existing toggle HTML.
     * @param string               $plugin_file Plugin basename for current row.
     * @param array<string, mixed> $plugin_data Plugin metadata.
     * @return string Rendered auto-update toggle HTML.
     */
    public function render_auto_update_setting_html( string $html, string $plugin_file, array $plugin_data ): string {
        if ( $plugin_file !== $this->plugin_file ) {
            return $html;
        }
        // Always render our own toggle for this plugin. Core can return
        // "unavailable" as non-empty but visually blank HTML for non-dotorg plugins.

        $auto_updates = (array) get_site_option( 'auto_update_plugins', [] );
        $enabled = in_array( $this->plugin_file, $auto_updates, true );
        $action  = $enabled ? 'disable-auto-update' : 'enable-auto-update';
        $text    = $enabled ? __( 'Disable auto-updates', 'assist-my-shop' ) : __( 'Enable auto-updates', 'assist-my-shop' );
        $url     = add_query_arg(
            [
                'action' => $action,
                'plugin' => $this->plugin_file,
            ],
            'plugins.php'
        );

        return sprintf(
            '<a href="%s" class="toggle-auto-update aria-button-if-js" data-wp-action="%s"><span class="label">%s</span></a>',
            esc_url( wp_nonce_url( $url, 'updates' ) ),
            esc_attr( $enabled ? 'disable' : 'enable' ),
            esc_html( $text )
        );
    }

}
