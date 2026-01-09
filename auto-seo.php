<?php
/**
 * Plugin Name: SEO Automatique
 * Description: Automatisation de la génération des méta-descriptions des articles pour Yoast.
 * Version: 1.1
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
	// nonce pour sécuriser l'AJAX
	$nonce = wp_create_nonce('auto_seo_security_token');
    // On s'assure que les fonctions nécessaires pour la checklist sont chargées
    if ( ! function_exists( 'wp_terms_checklist' ) ) {
        require_once ABSPATH . 'wp-admin/includes/template.php';
    }
    ?>
    <div class="wrap">
        <h1>Auto SEO UwU</h1>

        <div style="margin-bottom: 20px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
            <div style="margin-bottom: 15px;">
                <label style="font-weight: bold; font-size: 1.1em; cursor: pointer;">
                    <input type="checkbox" id="toggle-filter" style="transform: scale(1.2); margin-right: 10px;">
                    Activer le filtrage par catégorie
                </label>
            </div>

            <div id="filter-container" style="display: none; border-top: 1px solid #eee; padding-top: 15px;">
                <div style="margin-bottom: 10px;">
                    <button type="button" id="select-all-cats" class="button button-secondary">Tout sélectionner</button>
                    <button type="button" id="deselect-all-cats" class="button button-secondary">Tout désélectionner</button>
                </div>

                <div style="max-height: 250px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd;">
                    <ul id="cat-checklist">
                        <?php
                        // affiche la liste des catégories
                        wp_terms_checklist( 0, array( 'taxonomy' => 'category' ) );
                        ?>
                    </ul>
                </div>
            </div>
            <p class="description">Si désactivé, tous les articles seront traités. Les champs déjà remplis ne seront jamais écrasés.</p>
        </div>

        <div id="seo-bar-container" style="width:100%; background:#ddd; border-radius:10px; overflow:hidden; margin:20px 0;">
            <div id="seo-bar-fill" style="width:0%; height:30px; background:#2271b1; color:white; text-align:center; line-height:30px; transition: width 0.3s;">0%</div>
        </div>
        <p id="seo-stats">Articles traités : <span id="current">0</span> / <span id="total">0</span></p>
        <button id="start-btn" class="button button-primary button-large">Lancer l'optimisation</button>
    </div>

    <style>
        #cat-checklist ul { margin-left: 20px; margin-top: 5px; }
        #cat-checklist li { margin-bottom: 5px; }
    </style>

    <script>
        jQuery(document).ready(function($) {
            // Gestion du Toggle
            $('#toggle-filter').change(function() {
                $('#filter-container').slideToggle($(this).is(':checked'));
            });

            // Sélection globale
            $('#select-all-cats').click(function() {
                $('#cat-checklist input[type="checkbox"]').prop('checked', true);
            });
            $('#deselect-all-cats').click(function() {
                $('#cat-checklist input[type="checkbox"]').prop('checked', false);
            });

            $('#start-btn').click(function() {
                const $btn = $(this);
                const isFilterActive = $('#toggle-filter').is(':checked');

                // On récupère les IDs des catégories cochées
                let selectedCats = [];
                if (isFilterActive) {
                    $('#cat-checklist input:checked').each(function() {
                        selectedCats.push($(this).val());
                    });

                    if (selectedCats.length === 0) {
                        alert('Choisissez au moins une catégorie ou désactivez le filtre !');
                        return;
                    }
                }

                $btn.prop('disabled', true).text('Traitement...');

                $.post(ajaxurl, {
                    action: 'seo_get_ids',
                    category_ids: selectedCats,
                    filter_active: isFilterActive,
                    security: '<?php echo $nonce; ?>'
                }, function(res) {
                    if(res.success) {
                        let ids = res.data;
                        let total = ids.length;
                        $('#total').text(total);
                        if(total > 0) {
                            processNext(ids, 0, total);
                        } else {
                            $btn.prop('disabled', false).text('Rien à faire ! OwO');
                        }
                    }
                });
            });

            function processNext(ids, index, total) {
                if(index >= total) {
                    $('#start-btn').prop('disabled', false).text('Terminé ! UwU');
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
 * Récup des ID
 */
add_action('wp_ajax_seo_get_ids', function() {
    check_ajax_referer('auto_seo_security_token', 'security');
    if (!current_user_can('manage_options')) wp_die('Interdit !');

    $args = [
            'post_type' => 'post',
            'posts_per_page' => -1,
            'fields' => 'ids'
    ];

    // Si le filtre est actif et qu'on a des catégories
    if ($_POST['filter_active'] === 'true' && !empty($_POST['category_ids'])) {
        $args['category__in'] = array_map('intval', $_POST['category_ids']);
    }

    $ids = get_posts($args);
    wp_send_json_success($ids);
});

/**
 * tratiement de l'article
 */
add_action('wp_ajax_seo_process_item', function() {
    check_ajax_referer('auto_seo_security_token', 'security');
    if (!current_user_can('manage_options')) wp_die('Interdit !');

    $post_id = intval($_POST['post_id']);
    $post = get_post($post_id);

    if ($post) {
        $titre = get_the_title($post_id);

        // Description Yoast
        $current_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        if (empty($current_desc)) {
            $excerpt = wp_trim_words($post->post_content, 15, '...');
            $meta = "$titre | $excerpt";
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta);
        }

        // Expression clé Yoast
        $current_kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        if (empty($current_kw)) {
            $focus_kw = wp_trim_words($titre, 8, ''); // Limite à 8 mots UwU
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_kw);
        }
    }

    wp_send_json_success();
});