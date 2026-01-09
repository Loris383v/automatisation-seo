<?php
/**
 * Plugin Name: SEO Automatique
 * Plugin URI: https://github.com/loris383v/automatisation-seo
 * Description: Automatisation de la génération des méta-descriptions des articles pour Yoast.
 * Version: 0.3
 * Author: Loris Lacote
 * Author URI: https://github.com/loris383v
 * Requires Plugins: wordpress-seo
 */

if (!defined('ABSPATH')) exit; // Sécurité : pas d'accès direct

/**
 * Vérifier si Yoast est bien activé
 */
register_activation_hook(__FILE__, 'auto_seo_check_dependency');

function auto_seo_check_dependency() {
	if (!is_plugin_active('wordpress-seo/wp-seo.php')) {
		// On empêche l'activation
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
    $nonce = wp_create_nonce('auto_seo_security_token');
    if ( ! function_exists( 'wp_terms_checklist' ) ) {
        require_once ABSPATH . 'wp-admin/includes/template.php';
    }
    ?>
    <div class="wrap">
        <h1>Auto SEO</h1>

        <div style="display: flex; gap: 20px; margin-bottom: 20px;">
            <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
                <h3>Filtrage des catégories</h3>

                <div style="margin-bottom: 20px;">
                    <label style="font-weight: bold; cursor: pointer;">
                        <input type="checkbox" id="toggle-whitelist" style="margin-right: 10px;">
                        Liste blanche (modifie uniquement les articles des catégories sélectionnées)
                    </label>
                    <div id="whitelist-container" style="display: none; margin-top: 10px;">
                        <button type="button" class="button button-secondary select-all" data-target="whitelist-checklist">Tout sélectionner</button>
                        <button type="button" class="button button-secondary deselect-all" data-target="whitelist-checklist">Tout désélectionner</button>
                        <div class="cat-list-wrapper">
                            <ul id="whitelist-checklist">
                                <?php wp_terms_checklist( 0, array( 'taxonomy' => 'category' ) ); ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <hr>

                <div style="margin-top: 20px;">
                    <label style="font-weight: bold; cursor: pointer;">
                        <input type="checkbox" id="toggle-blacklist" style="margin-right: 10px;">
                        Liste noire (ne va pas modifier les articles des catégories sélectionnées)
                    </label>
                    <div id="blacklist-container" style="display: none; margin-top: 10px;">
                        <button type="button" class="button button-secondary select-all" data-target="blacklist-checklist">Tout sélectionner</button>
                        <button type="button" class="button button-secondary deselect-all" data-target="blacklist-checklist">Tout désélectionner</button>
                        <div class="cat-list-wrapper">
                            <ul id="blacklist-checklist">
                                <?php wp_terms_checklist( 0, array( 'taxonomy' => 'category' ) ); ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div style="width: 300px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px; align-self: flex-start;">
                <h3>Options</h3>
                <p><label><input type="checkbox" id="overwrite-desc"> Écraser la méta description existante</label></p>
                <p><label><input type="checkbox" id="overwrite-kw"> Écraser l'expression clé existante</label></p>
            </div>
        </div>

        <div id="seo-bar-container" style="width:100%; background:#ddd; border-radius:10px; overflow:hidden; margin:20px 0;">
            <div id="seo-bar-fill" style="width:0%; height:30px; background:#2271b1; color:white; text-align:center; line-height:30px; transition: width 0.3s;">0%</div>
        </div>
        <p id="seo-stats">Articles traités : <span id="current">0</span> / <span id="total">0</span></p>
        <button id="start-btn" class="button button-primary button-large">Lancer l'optimisation</button>
    </div>

    <style>
        .cat-list-wrapper { max-height: 200px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd; margin-top: 10px; }
        .cat-list-wrapper ul { margin-left: 20px; }
        .cat-list-wrapper li { margin-bottom: 5px; }
    </style>

    <script>
        jQuery(document).ready(function($) {
            // Gestion des toggles
            $('#toggle-whitelist').change(function() { $('#whitelist-container').slideToggle($(this).is(':checked')); });
            $('#toggle-blacklist').change(function() { $('#blacklist-container').slideToggle($(this).is(':checked')); });

            // Sélection globale
            $('.select-all').click(function() {
                $('#' + $(this).data('target') + ' input[type="checkbox"]').prop('checked', true);
            });
            $('.deselect-all').click(function() {
                $('#' + $(this).data('target') + ' input[type="checkbox"]').prop('checked', false);
            });

            $('#start-btn').click(function() {
                const $btn = $(this);
                const overwriteDesc = $('#overwrite-desc').is(':checked');
                const overwriteKW = $('#overwrite-kw').is(':checked');

                let whitelist = [];
                if ($('#toggle-whitelist').is(':checked')) {
                    $('#whitelist-checklist input:checked').each(function() { whitelist.push($(this).val()); });
                }

                let blacklist = [];
                if ($('#toggle-blacklist').is(':checked')) {
                    $('#blacklist-checklist input:checked').each(function() { blacklist.push($(this).val()); });
                }

                $btn.prop('disabled', true).text('Recherche des articles...');

                $.post(ajaxurl, {
                    action: 'seo_get_ids',
                    whitelist: whitelist,
                    blacklist: blacklist,
                    security: '<?php echo $nonce; ?>'
                }, function(res) {
                    if(res.success) {
                        let ids = res.data;
                        let total = ids.length;
                        $('#total').text(total);
                        if(total > 0) {
                            $btn.text('Traitement en cours...');
                            processNext(ids, 0, total, overwriteDesc, overwriteKW);
                        } else {
                            $btn.prop('disabled', false).text('Aucun article trouvé');
                        }
                    }
                });
            });

            function processNext(ids, index, total, overwriteDesc, overwriteKW) {
                if(index >= total) {
                    $('#start-btn').prop('disabled', false).text('Optimisation terminée !');
                    return;
                }

                $.post(ajaxurl, {
                    action: 'seo_process_item',
                    post_id: ids[index],
                    overwrite_desc: overwriteDesc,
                    overwrite_kw: overwriteKW,
                    security: '<?php echo $nonce; ?>'
                }, function() {
                    let current = index + 1;
                    let percent = Math.round((current / total) * 100);
                    $('#seo-bar-fill').css('width', percent+'%').text(percent+'%');
                    $('#current').text(current);
                    processNext(ids, current, total, overwriteDesc, overwriteKW);
                });
            }
        });
    </script>
    <?php
}

/**
 * Récup des ID
 */
add_action('wp_ajax_seo_get_ids', function() {
    check_ajax_referer('auto_seo_security_token', 'security');
    if (!current_user_can('manage_options')) wp_die();

    $args = [
            'post_type' => 'post',
            'posts_per_page' => -1,
            'fields' => 'ids'
    ];

    // Gestion de la Whitelist
    if (!empty($_POST['whitelist'])) {
        $args['category__in'] = array_map('intval', $_POST['whitelist']);
    }

    // Gestion de la Blacklist
    if (!empty($_POST['blacklist'])) {
        $args['category__not_in'] = array_map('intval', $_POST['blacklist']);
    }

    $ids = get_posts($args);
    wp_send_json_success($ids);
});

/**
 * traitement de chaque article
 */
add_action('wp_ajax_seo_process_item', function() {
    check_ajax_referer('auto_seo_security_token', 'security');
    if (!current_user_can('manage_options')) wp_die();

    $post_id = intval($_POST['post_id']);
    $overwrite_desc = $_POST['overwrite_desc'] === 'true';
    $overwrite_kw = $_POST['overwrite_kw'] === 'true';

    $post = get_post($post_id);
    if (!$post) wp_send_json_error();

    $titre = get_the_title($post_id);

    // Méta Description
    $current_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
    if ($overwrite_desc || empty($current_desc)) {
        $excerpt = wp_trim_words($post->post_content, 15, '...');
        $meta = "$titre | $excerpt";
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta);
    }

    // Expression clé
    $current_kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
    if ($overwrite_kw || empty($current_kw)) {
        $focus_kw = wp_trim_words($titre, 8, '');
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_kw);
    }

    wp_send_json_success();
});