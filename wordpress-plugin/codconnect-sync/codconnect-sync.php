<?php
/**
 * Plugin Name: CodConnect - Synchronisation Instantan&eacute;e WooCommerce
 * Plugin URI: https://codconnect.com
 * Description: Synchronise instantan&eacute;ment et automatiquement les commandes WooCommerce vers votre compte CodConnect d&egrave;s qu'elles sont pass&eacute;es, avec notification en temps r&eacute;el.
 * Version: 1.0.0
 * Author: CodConnect
 * Author URI: https://codconnect.com
 * Text Domain: codconnect-sync
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if (! defined('ABSPATH')) {
    exit; // S&eacute;curit&eacute;
}

class CodConnectSyncPlugin {
    private const OPTION_KEY = 'codconnect_sync_settings';
    private const LOGS_KEY   = 'codconnect_recent_syncs';

    public function __construct() {
        // Administration
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Requ&ecirc;tes AJAX Admin (d&eacute;couverte et test)
        add_action('wp_ajax_codconnect_discover_stores', [$this, 'ajax_discover_stores']);
        add_action('wp_ajax_codconnect_test_connection', [$this, 'ajax_test_connection']);

        // Hooks Commandes WooCommerce
        add_action('woocommerce_checkout_order_processed', [$this, 'on_checkout_order_processed'], 20, 1);
        add_action('woocommerce_order_status_processing', [$this, 'on_order_status_processing'], 20, 1);

        // Lien de r&eacute;glages rapide sur la page Plugins
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
    }

    public static function get_options(): array {
        $defaults = [
            'api_url'      => '',
            'secret_key'   => '',
            'store_id'     => '',
            'store_name'   => '',
            'is_enabled'   => 1,
            'sync_trigger' => 'checkout', // 'checkout', 'processing', 'both'
        ];
        return wp_parse_args(get_option(self::OPTION_KEY, []), $defaults);
    }

    public function add_action_links(array $links): array {
        $settings_link = '<a href="' . admin_url('admin.php?page=codconnect-sync') . '">' . __('R&eacute;glages', 'codconnect-sync') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function add_admin_menu(): void {
        // Sous WooCommerce si actif, sinon sous R&eacute;glages
        $parent = class_exists('WooCommerce') ? 'woocommerce' : 'options-general.php';

        add_submenu_page(
            $parent,
            'CodConnect Sync',
            'CodConnect Sync',
            'manage_woocommerce',
            'codconnect-sync',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings(array $input): array {
        return [
            'api_url'      => esc_url_raw(rtrim($input['api_url'] ?? '', '/')),
            'secret_key'   => sanitize_text_field($input['secret_key'] ?? ''),
            'store_id'     => intval($input['store_id'] ?? 0),
            'store_name'   => sanitize_text_field($input['store_name'] ?? ''),
            'is_enabled'   => ! empty($input['is_enabled']) ? 1 : 0,
            'sync_trigger' => sanitize_text_field($input['sync_trigger'] ?? 'checkout'),
        ];
    }

    public function enqueue_admin_assets($hook): void {
        if (strpos($hook, 'codconnect-sync') === false) {
            return;
        }

        wp_enqueue_style('wp-color-picker');
    }

    // =========================================================================
    // AJAX ADMIN : D&eacute;couverte des Stores et Test de Connexion
    // =========================================================================

    public function ajax_discover_stores(): void {
        check_ajax_referer('codconnect_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Acc&egrave;s refus&eacute;.']);
        }

        $api_url = esc_url_raw(rtrim($_POST['api_url'] ?? '', '/'));
        $secret  = sanitize_text_field($_POST['secret'] ?? '');

        if (empty($api_url) || empty($secret)) {
            wp_send_json_error(['message' => 'Veuillez renseigner l\'URL CodConnect et la cl&eacute; secr&egrave;te.']);
        }

        $endpoint = $api_url . '/api/wc/stores/discover';
        $response = wp_remote_post($endpoint, [
            'timeout'   => 15,
            'sslverify' => false,
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-WC-Secret'  => $secret,
            ],
            'body'      => json_encode([
                'site_url' => get_site_url(),
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Erreur de connexion : ' . $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['statut'])) {
            $msg = $body['message'] ?? 'Cl&eacute; secr&egrave;te invalide ou aucune boutique correspondante.';
            wp_send_json_error(['message' => $msg]);
        }

        $stores = $body['data'] ?? [];
        if (empty($stores)) {
            wp_send_json_error(['message' => 'Aucune boutique active trouv&eacute;e pour cette cl&eacute; secr&egrave;te dans CodConnect.']);
        }

        wp_send_json_success(['stores' => $stores]);
    }

    public function ajax_test_connection(): void {
        check_ajax_referer('codconnect_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Acc&egrave;s refus&eacute;.']);
        }

        $api_url  = esc_url_raw(rtrim($_POST['api_url'] ?? '', '/'));
        $secret   = sanitize_text_field($_POST['secret'] ?? '');
        $store_id = intval($_POST['store_id'] ?? 0);

        if (empty($api_url) || empty($secret) || empty($store_id)) {
            wp_send_json_error(['message' => 'Veuillez remplir l\'URL, le Secret et choisir une Boutique.']);
        }

        $endpoint = $api_url . '/api/wc/stores/' . $store_id . '/webhook';
        $response = wp_remote_post($endpoint, [
            'timeout'   => 15,
            'sslverify' => false,
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-WC-Secret'  => $secret,
            ],
            'body'      => json_encode([
                'test' => true,
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Erreur r&eacute;seau : ' . $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && ! empty($body['statut'])) {
            wp_send_json_success([
                'message' => $body['message'] ?? 'Connexion &eacute;tablie avec succ&egrave;s !',
                'store'   => $body['store'] ?? null,
            ]);
        }

        $error_msg = $body['message'] ?? ('Erreur HTTP ' . $code);
        wp_send_json_error(['message' => $error_msg]);
    }

    // =========================================================================
    // SYNCHRONISATION DES COMMANDES WOOCOMMERCE
    // =========================================================================

    public function on_checkout_order_processed($order_id): void {
        $options = self::get_options();
        if (empty($options['is_enabled']) || ! in_array($options['sync_trigger'], ['checkout', 'both'])) {
            return;
        }

        $this->send_order_to_codconnect((int) $order_id, 'checkout');
    }

    public function on_order_status_processing($order_id): void {
        $options = self::get_options();
        if (empty($options['is_enabled']) || ! in_array($options['sync_trigger'], ['processing', 'both'])) {
            return;
        }

        $this->send_order_to_codconnect((int) $order_id, 'status_processing');
    }

    private function send_order_to_codconnect(int $order_id, string $trigger_event): void {
        if (! $order_id) {
            return;
        }

        $options = self::get_options();
        $api_url  = $options['api_url'];
        $secret   = $options['secret_key'];
        $store_id = (int) $options['store_id'];

        if (empty($api_url) || empty($secret) || empty($store_id)) {
            return;
        }

        // V&eacute;rifier si d&eacute;j&agrave; synchronis&eacute;
        if (get_post_meta($order_id, '_codconnect_synced_at', true)) {
            return;
        }

        $endpoint = $api_url . '/api/wc/stores/' . $store_id . '/webhook';

        $payload = [
            'wc_order_id'   => $order_id,
            'trigger_event' => $trigger_event,
        ];

        // Marquer la commande imm&eacute;diatement
        update_post_meta($order_id, '_codconnect_synced_at', current_time('mysql'));

        // Envoi asynchrone non-bloquant pour prot&eacute;ger l'exp&eacute;rience client
        $response = wp_remote_post($endpoint, [
            'method'    => 'POST',
            'timeout'   => 10,
            'blocking'  => false, // Ne ralentit JAMAIS la page client
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-WC-Secret'  => $secret,
            ],
            'body'      => json_encode($payload),
            'sslverify' => false,
        ]);

        // Enregistrer dans le journal r&eacute;cent
        $this->record_log($order_id, $trigger_event);
    }

    private function record_log(int $order_id, string $event): void {
        $logs = get_option(self::LOGS_KEY, []);
        if (! is_array($logs)) {
            $logs = [];
        }

        array_unshift($logs, [
            'order_id' => $order_id,
            'date'     => current_time('d/m/Y H:i:s'),
            'event'    => $event,
        ]);

        // Garder maximum 10 entr&eacute;es
        $logs = array_slice($logs, 0, 10);
        update_option(self::LOGS_KEY, $logs, false);
    }

    // =========================================================================
    // PAGE DE CONFIGURATION DANS L'ADMINISTRATION
    // =========================================================================

    public function render_settings_page(): void {
        $options = self::get_options();
        $nonce   = wp_create_nonce('codconnect_admin_nonce');
        $logs    = get_option(self::LOGS_KEY, []);
        ?>
        <div class="wrap" style="max-width: 900px; margin-top: 20px;">
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
                <h1 style="margin: 0; font-size: 26px; font-weight: 700; color: #1e293b;">
                    &#128640; CodConnect &mdash; Synchronisation Instantan&eacute;e
                </h1>
                <span style="background: #10b981; color: white; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                    v1.0.0 Zero-Code
                </span>
            </div>

            <p style="color: #64748b; font-size: 15px; line-height: 1.5;">
                Configurez l'interconnexion entre votre boutique WooCommerce et CodConnect en 3 clics.
                Chaque commande pass&eacute;e sera instantan&eacute;ment transmise &agrave; CodConnect avec notification sonore et popup dans votre tableau de bord.
            </p>

            <div id="codconnect-notice" style="display: none; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 500;"></div>

            <form method="post" action="options.php" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 30px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
                <?php settings_fields(self::OPTION_KEY); ?>

                <table class="form-table" role="presentation" style="margin-top: 0;">
                    <!-- Activer la synchronisation -->
                    <tr>
                        <th scope="row" style="font-weight: 600; width: 220px;">Activer la synchronisation</th>
                        <td>
                            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[is_enabled]" value="1" <?php checked($options['is_enabled'], 1); ?> style="width: 20px; height: 20px;">
                                <span style="font-size: 14px; font-weight: 500; color: #334155;">Activer la transmission instantan&eacute;e des commandes</span>
                            </label>
                        </td>
                    </tr>

                    <!-- URL CodConnect -->
                    <tr>
                        <th scope="row" style="font-weight: 600;">URL Instance CodConnect</th>
                        <td>
                            <input type="url" id="codconnect_api_url" name="<?php echo self::OPTION_KEY; ?>[api_url]" value="<?php echo esc_attr($options['api_url']); ?>" placeholder="https://app.codconnect.ma ou http://localhost:8000" class="regular-text" style="width: 100%; max-width: 500px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1;" required>
                            <p class="description" style="color: #64748b; margin-top: 5px;">
                                L'adresse de base de votre serveur CodConnect (sans slash &agrave; la fin).
                            </p>
                        </td>
                    </tr>

                    <!-- Clé Secrète -->
                    <tr>
                        <th scope="row" style="font-weight: 600;">Cl&eacute; Secr&egrave;te / Consumer Secret</th>
                        <td>
                            <input type="password" id="codconnect_secret_key" name="<?php echo self::OPTION_KEY; ?>[secret_key]" value="<?php echo esc_attr($options['secret_key']); ?>" placeholder="cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxx" class="regular-text" style="width: 100%; max-width: 500px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1;" required>
                            <p class="description" style="color: #64748b; margin-top: 5px;">
                                Le Consumer Secret de votre boutique dans CodConnect (utilis&eacute; pour authentifier les webhooks).
                            </p>
                        </td>
                    </tr>

                    <!-- Boutique CodConnect -->
                    <tr>
                        <th scope="row" style="font-weight: 600;">Boutique CodConnect (Store)</th>
                        <td>
                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <select id="codconnect_store_select" style="min-width: 280px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                    <?php if (! empty($options['store_id'])) : ?>
                                        <option value="<?php echo esc_attr($options['store_id']); ?>" selected>
                                            #<?php echo esc_html($options['store_id']); ?> - <?php echo esc_html($options['store_name'] ?: 'Boutique s&eacute;lectionn&eacute;e'); ?>
                                        </option>
                                    <?php else : ?>
                                        <option value="">&mdash; Cliquez sur &laquo; Charger les boutiques &raquo; &mdash;</option>
                                    <?php endif; ?>
                                </select>

                                <input type="hidden" id="codconnect_store_id" name="<?php echo self::OPTION_KEY; ?>[store_id]" value="<?php echo esc_attr($options['store_id']); ?>">
                                <input type="hidden" id="codconnect_store_name" name="<?php echo self::OPTION_KEY; ?>[store_name]" value="<?php echo esc_attr($options['store_name']); ?>">

                                <button type="button" id="btn-discover-stores" class="button" style="padding: 6px 14px; border-radius: 6px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                    <span>&#128269;</span> Charger les boutiques
                                </button>

                                <button type="button" id="btn-test-connection" class="button button-secondary" style="padding: 6px 14px; border-radius: 6px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                    <span>&#9889;</span> Tester la connexion
                                </button>
                            </div>
                            <p class="description" style="color: #64748b; margin-top: 5px;">
                                La boutique sera d&eacute;tect&eacute;e automatiquement depuis votre compte CodConnect.
                            </p>
                        </td>
                    </tr>

                    <!-- Déclencheur -->
                    <tr>
                        <th scope="row" style="font-weight: 600;">D&eacute;clencheur d'envoi</th>
                        <td>
                            <select name="<?php echo self::OPTION_KEY; ?>[sync_trigger]" style="padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                <option value="checkout" <?php selected($options['sync_trigger'], 'checkout'); ?>>D&egrave;s validation du panier (Checkout)</option>
                                <option value="processing" <?php selected($options['sync_trigger'], 'processing'); ?>>Lorsque la commande passe &agrave; &laquo; En cours &raquo; (Processing)</option>
                                <option value="both" <?php selected($options['sync_trigger'], 'both'); ?>>Les deux (Checkout + Processing)</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #f1f5f9;">
                    <?php submit_button('Enregistrer les modifications', 'primary', 'submit', false, ['style' => 'padding: 8px 24px; font-size: 15px; border-radius: 8px; font-weight: 600;']); ?>
                </div>
            </form>

            <!-- Journal des dernières synchronisations -->
            <div style="margin-top: 30px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
                <h3 style="margin-top: 0; font-size: 18px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                    <span>&#128220;</span> Derni&egrave;res commandes envoy&eacute;es &agrave; CodConnect
                </h3>

                <?php if (empty($logs)) : ?>
                    <p style="color: #94a3b8; font-style: italic; margin-bottom: 0;">Aucune commande synchronis&eacute;e pour le moment.</p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 10px; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0;">
                        <thead>
                            <tr>
                                <th style="font-weight: 600;">Commande #</th>
                                <th style="font-weight: 600;">Date d'envoi</th>
                                <th style="font-weight: 600;">&Eacute;v&eacute;nement</th>
                                <th style="font-weight: 600;">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log) : ?>
                                <tr>
                                    <td><strong>#<?php echo esc_html($log['order_id']); ?></strong></td>
                                    <td><?php echo esc_html($log['date']); ?></td>
                                    <td><code><?php echo esc_html($log['event']); ?></code></td>
                                    <td><span style="color: #10b981; font-weight: 600;">&#10003; Transmise</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            const nonce = '<?php echo esc_js($nonce); ?>';

            function showNotice(text, isError) {
                const notice = $('#codconnect-notice');
                notice.css({
                    'display': 'block',
                    'background': isError ? '#fee2e2' : '#dcfce7',
                    'color': isError ? '#991b1b' : '#166534',
                    'border': isError ? '1px solid #f87171' : '1px solid #86efac'
                }).html((isError ? '&#9888; ' : '&#10004; ') + text);
            }

            // Bouton Charger les Boutiques
            $('#btn-discover-stores').on('click', function(e) {
                e.preventDefault();
                const btn = $(this);
                const apiUrl = $('#codconnect_api_url').val().trim();
                const secret = $('#codconnect_secret_key').val().trim();

                if (!apiUrl || !secret) {
                    showNotice('Veuillez d\'abord saisir l\'URL CodConnect et le Consumer Secret.', true);
                    return;
                }

                btn.prop('disabled', true).text('Recherche en cours...');

                $.post(ajaxurl, {
                    action: 'codconnect_discover_stores',
                    nonce: nonce,
                    api_url: apiUrl,
                    secret: secret
                }, function(res) {
                    btn.prop('disabled', false).html('<span>&#128269;</span> Charger les boutiques');

                    if (res.success && res.data && res.data.stores) {
                        const select = $('#codconnect_store_select');
                        select.empty();

                        res.data.stores.forEach(function(store, idx) {
                            select.append($('<option>', {
                                value: store.id,
                                text: '#' + store.id + ' - ' + store.name,
                                'data-name': store.name
                            }));
                        });

                        const first = res.data.stores[0];
                        $('#codconnect_store_id').val(first.id);
                        $('#codconnect_store_name').val(first.name);

                        showNotice('Succ&egrave;s : ' + res.data.stores.length + ' boutique(s) trouv&eacute;e(s) dans CodConnect !', false);
                    } else {
                        showNotice(res.data ? res.data.message : 'Erreur lors de la r&eacute;cup&eacute;ration des boutiques.', true);
                    }
                }).fail(function() {
                    btn.prop('disabled', false).html('<span>&#128269;</span> Charger les boutiques');
                    showNotice('Erreur r&eacute;seau lors de l\'appel &agrave; CodConnect.', true);
                });
            });

            // Changement dans le select de boutique
            $('#codconnect_store_select').on('change', function() {
                const val = $(this).val();
                const name = $(this).find('option:selected').data('name') || $(this).find('option:selected').text();
                $('#codconnect_store_id').val(val);
                $('#codconnect_store_name').val(name);
            });

            // Bouton Tester la Connexion
            $('#btn-test-connection').on('click', function(e) {
                e.preventDefault();
                const btn = $(this);
                const apiUrl = $('#codconnect_api_url').val().trim();
                const secret = $('#codconnect_secret_key').val().trim();
                const storeId = $('#codconnect_store_id').val().trim();

                if (!apiUrl || !secret || !storeId) {
                    showNotice('Veuillez renseigner l\'URL, la cl&eacute; secr&egrave;te et s&eacute;lectionner une boutique avant de tester.', true);
                    return;
                }

                btn.prop('disabled', true).text('Test en cours...');

                $.post(ajaxurl, {
                    action: 'codconnect_test_connection',
                    nonce: nonce,
                    api_url: apiUrl,
                    secret: secret,
                    store_id: storeId
                }, function(res) {
                    btn.prop('disabled', false).html('<span>&#9889;</span> Tester la connexion');

                    if (res.success && res.data) {
                        showNotice('&#9989; ' + (res.data.message || 'Connexion r&eacute;ussie avec CodConnect !'), false);
                    } else {
                        showNotice(res.data ? res.data.message : 'Erreur de connexion au Webhook.', true);
                    }
                }).fail(function() {
                    btn.prop('disabled', false).html('<span>&#9889;</span> Tester la connexion');
                    showNotice('Impossible de joindre le serveur CodConnect.', true);
                });
            });
        });
        </script>
        <?php
    }
}

// Initialisation du plugin
new CodConnectSyncPlugin();
