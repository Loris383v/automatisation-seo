<?php
/**
 * Plugin Name: SEO Automatique
 * Description: Automatisation de la génération des méta-descriptions des articles pour Yoast.
 * Version: 1.0
 * Author: Loris Lacote
 * Author URI: https://github.com/loris383v
 */

if (!defined('ABSPATH')) exit; // Sécurité : pas d'accès direct

/**
 * Vérifier si Yoast est bien activé
 */
register_activation_hook(__FILE__, 'auto_seo_check_dependency');

function auto_seo_check_dependency() {
	if (!is_plugin_active('wordpress-seo/wp-seo.php')) {
		// On empêche l'activation ( TODO : chercher comment dire à wordpress de "griser" le bouton, j'ai vu des plugins qui le font
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die("Ce plugin nécessite l'activation du plugin Yoast SEO pour fonctionner");
	}
}

/**
 * Menu dans l'interface admin
 */
add_action('admin_menu', function() {
	add_submenu_page(
		'edit.php',
		'SEO Automatique',
		'Auto SEO',
		'manage_options', // Seuls les admins peuvent le voir
		'auto-seo',
		'auto_seo_render_page'
	);
});

/**
 *Interface page
 */
function auto_seo_render_page() {
	// nonce pour sécuriser l'AJAX
	$nonce = wp_create_nonce('auto_seo_security_token');
	?>
	<div class="wrap">
		<h1>Auto SEO</h1>
		<div id="seo-bar-container" style="width:100%; background:#ddd; border-radius:10px; overflow:hidden; margin:20px 0;">
			<div id="seo-bar-fill" style="width:0%; height:30px; background:#2271b1; color:white; text-align:center; line-height:30px; transition: width 0.3s;">0%</div>
		</div>
		<p id="seo-stats">Articles : <span id="current">0</span> / <span id="total">0</span></p>
		<button id="start-btn" class="button button-primary">Lancer l'optimisation</button>
	</div>

	<script>
        jQuery(document).ready(function($) {
            $('#start-btn').click(function() {
                $(this).prop('disabled', true).text('Traitement...');

                // On récupère les IDs
                $.post(ajaxurl, {
                    action: 'seo_get_ids',
                    security: '<?php echo $nonce; ?>'
                }, function(res) {
                    if(res.success) {
                        let ids = res.data;
                        let total = ids.length;
                        $('#total').text(total);
                        processNext(ids, 0, total);
                    }
                });
            });

            function processNext(ids, index, total) {
                if(index >= total) {
                    $('#start-btn').text('Terminé !');
                    return;
                }

                $.post(ajaxurl, {
                    action: 'seo_process_item',
                    post_id: ids[index],
                    security: '<?php echo $nonce; ?>'
                }, function() {
                    let current = index + 1;
                    let percent = Math.round((current / total) * 100);
                    $('#seo-bar-fill').css('width', percent+'%').text(percent+'%');
                    $('#current').text(current);
                    processNext(ids, current, total);
                });
            }
        });
	</script>
	<?php
}

/**
 * Backend AJAX : Récup des ID
 */
add_action('wp_ajax_seo_get_ids', function() {
	// Vérification du jeton de sécurité
	check_ajax_referer('auto_seo_security_token', 'security');

	// Vérification des droits admin
	if (!current_user_can('manage_options')) wp_die('Vous ne pouvez pas faire ça !');

	$ids = get_posts(['post_type' => 'post', 'posts_per_page' => -1, 'fields' => 'ids']);
	wp_send_json_success($ids);
});

/**
 * Backend : tratiement de l'article
 */
add_action('wp_ajax_seo_process_item', function() {
	check_ajax_referer('auto_seo_security_token', 'security');
	if (!current_user_can('manage_options')) wp_die('Vous ne pouvez pas faire ça !');

	$post_id = intval($_POST['post_id']);
	$post = get_post($post_id);

	if ($post) {
		$titre = get_the_title($post_id);
		$excerpt = wp_trim_words($post->post_content, 15, '...');
		$meta = "$titre | $excerpt";

		// Mise à jour Yoast
		update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta);
	}

	wp_send_json_success();
});