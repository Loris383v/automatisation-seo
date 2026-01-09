<?php
/**
 * Plugin Name: SEO Automatique
 * Plugin URI: https://github.com/loris383v/automatisation-seo
 * Description: Automatisation de la génération des méta-descriptions des articles pour Yoast.
 * Version: 0.4
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
    add_menu_page(
            'SEO Automatique',
            'Auto SEO',
            'manage_options',
            'auto-seo',
            'auto_seo_render_page',
            'dashicons-chart-line',
            26
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
                        Liste blanche (uniquement les catégories sélectionnées)
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
                        Liste noire (exclure les catégories sélectionnées)
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
                <h3>Types de contenu</h3>
                <p><label><input type="checkbox" id="process-posts" checked> Articles (posts)</label></p>
                <p><label><input type="checkbox" id="process-pages"> Pages</label></p>
                <hr>
                <h3>Options</h3>
                <p><label><input type="checkbox" id="overwrite-desc"> Écraser la méta description</label></p>
                <p><label><input type="checkbox" id="overwrite-kw"> Écraser l'expression clé</label></p>
            </div>
        </div>

        <div id="seo-bar-container" style="width:100%; background:#ddd; border-radius:10px; overflow:hidden; margin:20px 0;">
            <div id="seo-bar-fill" style="width:0%; height:30px; background:#2271b1; color:white; text-align:center; line-height:30px; transition: width 0.3s;">0%</div>
        </div>

        <p id="seo-stats">Progression : <span id="current">0</span> / <span id="total">0</span></p>

        <div id="seo-log" style="display:none; background: #e7f7ed; border: 1px solid #46b450; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
            <h3 style="margin-top:0;">Résultat de l'optimisation</h3>
            <div id="log-summary"></div>
        </div>

        <button id="start-btn" class="button button-primary button-large">Lancer l'optimisation</button>
    </div>

    <style>
        .cat-list-wrapper { max-height: 200px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd; margin-top: 10px; }
        .cat-list-wrapper ul { margin-left: 20px; }
        .cat-list-wrapper li { margin-bottom: 5px; }
        #log-summary p { margin: 5px 0; }
    </style>

    <script>
        jQuery(document).ready(function($) {
            let stats = {
                post: { updated: 0, skipped: 0 },
                page: { updated: 0, skipped: 0 }
            };

            $('#toggle-whitelist').change(function() { $('#whitelist-container').slideToggle($(this).is(':checked')); });
            $('#toggle-blacklist').change(function() { $('#blacklist-container').slideToggle($(this).is(':checked')); });

            $('.select-all').click(function() { $('#' + $(this).data('target') + ' input[type="checkbox"]').prop('checked', true); });
            $('.deselect-all').click(function() { $('#' + $(this).data('target') + ' input[type="checkbox"]').prop('checked', false); });

            $('#start-btn').click(function() {
                const $btn = $(this);
                const overwriteDesc = $('#overwrite-desc').is(':checked');
                const overwriteKW = $('#overwrite-kw').is(':checked');

                let postTypes = [];
                if ($('#process-posts').is(':checked')) postTypes.push('post');
                if ($('#process-pages').is(':checked')) postTypes.push('page');

                if (postTypes.length === 0) {
                    alert('Sélectionnez au moins un type de contenu !');
                    return;
                }

                let whitelist = [];
                if ($('#toggle-whitelist').is(':checked')) {
                    $('#whitelist-checklist input:checked').each(function() { whitelist.push($(this).val()); });
                }

                let blacklist = [];
                if ($('#toggle-blacklist').is(':checked')) {
                    $('#blacklist-checklist input:checked').each(function() { blacklist.push($(this).val()); });
                }

                $btn.prop('disabled', true).text('Recherche...');
                $('#seo-log').hide();

                // Reset stats
                stats.post.updated = 0; stats.post.skipped = 0;
                stats.page.updated = 0; stats.page.skipped = 0;

                $.post(ajaxurl, {
                    action: 'seo_get_ids',
                    post_types: postTypes,
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
                            $btn.prop('disabled', false).text('Aucun contenu trouvé');
                        }
                    }
                });
            });

            function processNext(ids, index, total, overwriteDesc, overwriteKW) {
                if(index >= total) {
                    $('#start-btn').prop('disabled', false).text('Recommencer');

                    let summaryHtml = '<p><strong>' + total + '</strong> éléments vérifiés au total.</p><hr>';

                    if (stats.post.updated > 0 || stats.post.skipped > 0) {
                        summaryHtml += '<p>📝 <strong>Articles :</strong> ' + stats.post.updated + ' mis à jour, ' + stats.post.skipped + ' ignorés.</p>';
                    }
                    if (stats.page.updated > 0 || stats.page.skipped > 0) {
                        summaryHtml += '<p>📄 <strong>Pages :</strong> ' + stats.page.updated + ' mises à jour, ' + stats.page.skipped + ' ignorées.</p>';
                    }

                    $('#log-summary').html(summaryHtml);
                    $('#seo-log').fadeIn();
                    return;
                }

                $.post(ajaxurl, {
                    action: 'seo_process_item',
                    post_id: ids[index],
                    overwrite_desc: overwriteDesc,
                    overwrite_kw: overwriteKW,
                    security: '<?php echo $nonce; ?>'
                }, function(res) {
                    if(res.success) {
                        const type = res.data.post_type; // 'post' ou 'page'
                        if(res.data.status === 'updated') {
                            stats[type].updated++;
                        } else {
                            stats[type].skipped++;
                        }
                    }

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
 * Récupération des IDs
 */
add_action('wp_ajax_seo_get_ids', function() {
    check_ajax_referer('auto_seo_security_token', 'security');
    if (!current_user_can('manage_options')) wp_die();

    $post_types = !empty($_POST['post_types']) ? array_map('sanitize_key', $_POST['post_types']) : ['post'];

    $args = [
            'post_type' => $post_types,
            'posts_per_page' => -1,
            'fields' => 'ids'
    ];

    if (!empty($_POST['whitelist'])) $args['category__in'] = array_map('intval', $_POST['whitelist']);
    if (!empty($_POST['blacklist'])) $args['category__not_in'] = array_map('intval', $_POST['blacklist']);

    $ids = get_posts($args);
    wp_send_json_success($ids);
});

/**
 * Traitement avec retour de type et de statut
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
    $updated = false;

    // Méta Description
    $current_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
    if ($overwrite_desc || empty($current_desc)) {
        $excerpt = wp_trim_words($post->post_content, 15, '...');
        $meta = "$titre | $excerpt";
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta);
        $updated = true;
    }

    // Expression clé
    $current_kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
    if ($overwrite_kw || empty($current_kw)) {
        $focus_kw = wp_trim_words($titre, 8, '');
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_kw);
        $updated = true;
    }

    // On renvoie le post_type pour le log différencié
    wp_send_json_success([
            'status' => $updated ? 'updated' : 'skipped',
            'post_type' => $post->post_type
    ]);
});