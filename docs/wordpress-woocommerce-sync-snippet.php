<?php
/**
 * Plugin Name: CodConnect WooCommerce Instant Order Sync
 * Description: Envoie automatiquement et instantanément chaque nouvelle commande vers CodConnect dès qu'elle est validée.
 * Version: 1.0.0
 * Author: CodConnect
 */

if (! defined('ABSPATH')) {
    exit; // Sécurité
}

// ============================================================================
// CONFIGURATION : Remplacer par vos informations CodConnect
// ============================================================================
define('CODCONNECT_API_URL', 'https://votre-domaine.com/api/wc/stores/VOTRE_STORE_ID/webhook');
define('CODCONNECT_SECRET', 'VOTRE_CONSUMER_SECRET_ICI');

/**
 * Envoie la commande à CodConnect dès que le client valide le panier.
 * Hook: woocommerce_checkout_order_processed
 *
 * @param int $order_id ID de la commande WooCommerce
 */
add_action('woocommerce_checkout_order_processed', 'codconnect_send_order_to_api', 20, 1);

/**
 * Fallback : déclenché également si la commande passe au statut "processing".
 * Hook: woocommerce_order_status_processing
 *
 * @param int $order_id ID de la commande WooCommerce
 */
add_action('woocommerce_order_status_processing', 'codconnect_send_order_to_api', 20, 1);

function codconnect_send_order_to_api($order_id) {
    if (! $order_id || ! is_numeric($order_id)) {
        return;
    }

    // Éviter d'envoyer la commande deux fois (déjà notifiée)
    $already_sent = get_post_meta($order_id, '_codconnect_synced', true);
    if ($already_sent) {
        return;
    }

    // Préparation de la requête
    $url = CODCONNECT_API_URL;
    $body = json_encode([
        'wc_order_id' => (int) $order_id,
    ]);

    $args = [
        'method'      => 'POST',
        'timeout'     => 10,
        'blocking'    => false, // Non-bloquant : ne ralentit pas le chargement de la page de remerciement pour le client
        'headers'     => [
            'Content-Type' => 'application/json',
            'X-WC-Secret'  => CODCONNECT_SECRET,
        ],
        'body'        => $body,
        'sslverify'   => false, // Mettre à true en production avec un certificat SSL valide
    ];

    // Marquer la commande comme envoyée pour éviter les doublons
    update_post_meta($order_id, '_codconnect_synced', current_time('mysql'));

    // Envoi de la requête à CodConnect
    wp_remote_post($url, $args);
}
