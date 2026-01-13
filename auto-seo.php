<?php
/**
 * Plugin Name: SEO Automatique
 * Plugin URI: https://github.com/loris383v/automatisation-seo
 * Description: Automatisation de la génération des méta-descriptions des articles pour Yoast.
 * Version: 1.2.0
 * Author: Loris Lacote
 * Author URI: https://github.com/loris383v
 * Requires Plugins: wordpress-seo
 */

if (!defined('ABSPATH')) exit;

/**
 * Update checker
 */

require 'plugin-update-checker-5.6/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/loris383v/automatisation-seo/',
    __FILE__,
    'auto-yoast-seo'
);

$myUpdateChecker->getVcsApi()->enableReleaseAssets();
//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('master');




// Vérification de la dépendance à l'activation. Sert à rien mais bon rajoute une petite couche de sécurité en vrai.
register_activation_hook(__FILE__, 'auto_seo_check_dependency');

function auto_seo_check_dependency() {
    if (!is_plugin_active('wordpress-seo/wp-seo.php')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die("Ce plugin nécessite l'activation du plugin Yoast SEO pour fonctionner");
    }
}

/**
 * Lien vers les réglages dans la liste des extensions
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="admin.php?page=auto-seo-settings">' . __('Réglages') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

/**
 * Menu et Sous-menus
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

    add_submenu_page(
            'auto-seo',
            'Optimiser',
            'Optimiser',
            'manage_options',
            'auto-seo',
            'auto_seo_render_page'
    );

    add_submenu_page(
            'auto-seo',
            'Réglages',
            'Réglages',
            'manage_options',
            'auto-seo-settings',
            'auto_seo_render_settings_page'
    );
});

/**
 * Page de Réglages
 */
function auto_seo_render_settings_page() {
    if (isset($_POST['auto_seo_save_settings'])) {
        check_admin_referer('auto_seo_settings_action');

        $settings = [
                'enabled'        => isset($_POST['enabled']) ? 1 : 0,
                'post_types'     => isset($_POST['post_types']) ? (array)$_POST['post_types'] : [],
                'fill_desc'      => isset($_POST['fill_desc']) ? 1 : 0,
                'overwrite_desc' => isset($_POST['overwrite_desc']) ? 1 : 0,
                'fill_kw'        => isset($_POST['fill_kw']) ? 1 : 0,
                'overwrite_kw'   => isset($_POST['overwrite_kw']) ? 1 : 0,
                'desc_length'    => intval($_POST['desc_length']) ?: 15,
                'kw_length'      => intval($_POST['kw_length']) ?: 8,
        ];

        update_option('auto_seo_global_settings', $settings);
        echo '<div class="updated"><p>Réglages mis à jour avec succès !</p></div>';
    }

    $options = get_option('auto_seo_global_settings', [
            'enabled' => 1,
            'post_types' => ['post', 'page'],
            'fill_desc' => 1,
            'overwrite_desc' => 0,
            'fill_kw' => 1,
            'overwrite_kw' => 0,
            'desc_length' => 15,
            'kw_length' => 8
    ]);
    ?>
    <div class="wrap">
        <h1>Réglages de l'automatisation</h1>
        <form method="post">
            <?php wp_nonce_field('auto_seo_settings_action'); ?>
            <h2>Automatisation</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Activer l'automatisation</th>
                    <td>
                        <label><input type="checkbox" name="enabled" value="1" <?php checked($options['enabled'], 1); ?>>
                            Générer les données SEO lors de la publication ou de l'enregistrement</label>
                        <p class="description">Active ou désactive la génération automatique de données SEO lors de la sauvegarde des articles et pages.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Types de contenu</th>
                    <td>
                        <label><input type="checkbox" name="post_types[]" value="post" <?php checked(in_array('post', (array)$options['post_types'])); ?>> Articles</label><br>
                        <label><input type="checkbox" name="post_types[]" value="page" <?php checked(in_array('page', (array)$options['post_types'])); ?>> Pages</label>
                        <p class="description">Sélectionnez les types de contenu pour lesquels l'automatisation doit s'appliquer.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Méta description</th>
                    <td>
                        <label><input type="checkbox" name="fill_desc" value="1" <?php checked($options['fill_desc'], 1); ?>> Remplir automatiquement</label>
                        <p class="description">Génère automatiquement la méta description à partir du titre et du contenu de l'article.</p>
                        <br>
                        <label><input type="checkbox" name="overwrite_desc" value="1" <?php checked($options['overwrite_desc'], 1); ?>> Écraser si déjà rempli</label>
                        <p class="description">Remplace la méta description existante même si elle a été remplie manuellement.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Expression clé</th>
                    <td>
                        <label><input type="checkbox" name="fill_kw" value="1" <?php checked($options['fill_kw'], 1); ?>> Remplir automatiquement</label>
                        <p class="description">Génère automatiquement l'expression clé principale à partir du titre de l'article.</p>
                        <br>
                        <label><input type="checkbox" name="overwrite_kw" value="1" <?php checked($options['overwrite_kw'], 1); ?>> Écraser si déjà rempli</label>
                        <p class="description">Remplace l'expression clé existante même si elle a été remplie manuellement.</p>
                    </td>
                </tr>
            </table>

            <h2>Configuration du contenu</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Nombre de mots pour la méta description</th>
                    <td>
                        <input type="number" name="desc_length" value="<?php echo esc_attr($options['desc_length']); ?>" min="1" max="50">
                        <p class="description">Définit le nombre de mots extraits du contenu pour générer la méta description.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Nombre de mots pour l'expression clé</th>
                    <td>
                        <input type="number" name="kw_length" value="<?php echo esc_attr($options['kw_length']); ?>" min="1" max="20">
                        <p class="description">Définit le nombre de mots extraits du titre pour générer l'expression clé.</p>
                    </td>
                </tr>
            </table>
            <p class="submit"><input type="submit" name="auto_seo_save_settings" class="button button-primary" value="Enregistrer les modifications"></p>
        </form>
    </div>
    <?php
}

/**
 * Logique de sauvegarde automatique (save_post / wp_after_insert_post)
 */
add_action('wp_after_insert_post', 'auto_seo_after_save_trigger', 99, 3);
function auto_seo_after_save_trigger($post_id, $post, $update) {
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
    $options = get_option('auto_seo_global_settings');
    if (!$options || empty($options['enabled'])) return;
    if (!in_array($post->post_type, (array)$options['post_types'])) return;
    if (in_array($post->post_status, ['auto-draft', 'inherit'])) return;

    $titre = $post->post_title;
    if (empty($titre)) return;

    if (!empty($options['fill_kw'])) {
        $current_kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        if ($options['overwrite_kw'] || empty($current_kw)) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', wp_trim_words($titre, $options['kw_length'], ''));
        }
    }

    if (!empty($options['fill_desc'])) {
        $current_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        if ($options['overwrite_desc'] || empty($current_desc)) {
            $content = strip_shortcodes($post->post_content);
            $content = wp_strip_all_tags($content);
            $excerpt = wp_trim_words($content, $options['desc_length'], '...');
            if (!empty($excerpt)) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', "$titre | $excerpt");
            }
        }
    }
}

/**
 * Interface page Optimiser (Bulk)
 */
function auto_seo_render_page() {
    $nonce = wp_create_nonce('auto_seo_security_token');
    if (!function_exists('wp_terms_checklist')) {
        require_once ABSPATH . 'wp-admin/includes/template.php';
    }
    ?>
    <div class="wrap">
        <h1>Optimisation de masse</h1>
        <div style="display: flex; gap: 20px; margin-bottom: 20px;">
            <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
                <h3>Filtrage des catégories</h3>
                <div style="margin-bottom: 20px;">
                    <label style="font-weight: bold; cursor: pointer;">
                        <input type="checkbox" id="toggle-whitelist" style="margin-right: 10px;"> Liste blanche
                    </label>
                    <div id="whitelist-container" style="display: none; margin-top: 10px;">
                        <button type="button" class="button button-secondary select-all" data-target="whitelist-checklist">Tout sélectionner</button>
                        <div class="cat-list-wrapper">
                            <ul id="whitelist-checklist"><?php wp_terms_checklist(0, array('taxonomy' => 'category')); ?></ul>
                        </div>
                    </div>
                </div>
                <hr>
                <div style="margin-top: 20px;">
                    <label style="font-weight: bold; cursor: pointer;">
                        <input type="checkbox" id="toggle-blacklist" style="margin-right: 10px;"> Liste noire
                    </label>
                    <div id="blacklist-container" style="display: none; margin-top: 10px;">
                        <button type="button" class="button button-secondary select-all" data-target="blacklist-checklist">Tout sélectionner</button>
                        <div class="cat-list-wrapper">
                            <ul id="blacklist-checklist"><?php wp_terms_checklist(0, array('taxonomy' => 'category')); ?></ul>
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
    </style>
    <script>
        jQuery(document).ready(function($) {
            // Stats détaillées par type
            let stats = {
                post: { checked: 0, updated: 0, desc: 0, kw: 0 },
                page: { checked: 0, updated: 0, desc: 0, kw: 0 }
            };

            $('#toggle-whitelist').change(function() { $('#whitelist-container').slideToggle($(this).is(':checked')); });
            $('#toggle-blacklist').change(function() { $('#blacklist-container').slideToggle($(this).is(':checked')); });
            $('.select-all').click(function() { $('#' + $(this).data('target') + ' input[type="checkbox"]').prop('checked', true); });

            $('#start-btn').click(function() {
                const $btn = $(this);
                const overwriteDesc = $('#overwrite-desc').is(':checked');
                const overwriteKW = $('#overwrite-kw').is(':checked');
                let postTypes = [];
                if ($('#process-posts').is(':checked')) postTypes.push('post');
                if ($('#process-pages').is(':checked')) postTypes.push('page');
                if (postTypes.length === 0) { alert('Sélectionnez au moins un type de contenu !'); return; }

                let whitelist = [];
                if ($('#toggle-whitelist').is(':checked')) { $('#whitelist-checklist input:checked').each(function() { whitelist.push($(this).val()); }); }
                let blacklist = [];
                if ($('#toggle-blacklist').is(':checked')) { $('#blacklist-checklist input:checked').each(function() { blacklist.push($(this).val()); }); }

                $btn.prop('disabled', true).text('Recherche...');
                $('#seo-log').hide();

                // Reset stats
                stats = {
                    post: { checked: 0, updated: 0, desc: 0, kw: 0 },
                    page: { checked: 0, updated: 0, desc: 0, kw: 0 }
                };

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
                        if(total > 0) { $btn.text('Traitement en cours...'); processNext(ids, 0, total, overwriteDesc, overwriteKW); }
                        else { $btn.prop('disabled', false).text('Aucun contenu trouvé'); }
                    }
                });
            });

            function processNext(ids, index, total, overwriteDesc, overwriteKW) {
                if(index >= total) {
                    $('#start-btn').prop('disabled', false).text('Recommencer');

                    let summaryHtml = '<p><strong>' + total + ' éléments vérifiés.</strong></p>';

                    ['post', 'page'].forEach(type => {
                        if (stats[type].checked > 0) {
                            let label = (type === 'post') ? 'Articles' : 'Pages';
                            summaryHtml += '<div style="margin-top:10px;">';
                            summaryHtml += '<strong>' + label + ' : ' + stats[type].updated + '/' + stats[type].checked + ' mis à jour</strong>';
                            summaryHtml += '<ul style="margin: 5px 0 0 20px; list-style: disc;">';
                            summaryHtml += '<li>' + stats[type].desc + ' méta descriptions écrites</li>';
                            summaryHtml += '<li>' + stats[type].kw + ' expression clés écrites</li>';
                            summaryHtml += '</ul></div>';
                        }
                    });

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
                        const type = res.data.post_type;
                        stats[type].checked++;
                        if(res.data.desc_updated) stats[type].desc++;
                        if(res.data.kw_updated) stats[type].kw++;
                        if(res.data.desc_updated || res.data.kw_updated) stats[type].updated++;
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
 * AJAX Callbacks pour l'optimisation de masse
 */
add_action('wp_ajax_seo_get_ids', function() {
    check_ajax_referer('auto_seo_security_token', 'security');
    if (!current_user_can('manage_options')) wp_die();
    $post_types = !empty($_POST['post_types']) ? array_map('sanitize_key', $_POST['post_types']) : ['post'];
    $args = ['post_type' => $post_types, 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any'];
    if (!empty($_POST['whitelist'])) $args['category__in'] = array_map('intval', $_POST['whitelist']);
    if (!empty($_POST['blacklist'])) $args['category__not_in'] = array_map('intval', $_POST['blacklist']);
    wp_send_json_success(get_posts($args));
});

add_action('wp_ajax_seo_process_item', function() {
    check_ajax_referer('auto_seo_security_token', 'security');
    if (!current_user_can('manage_options')) wp_die();
    $post_id = intval($_POST['post_id']);
    $overwrite_desc = $_POST['overwrite_desc'] === 'true';
    $overwrite_kw = $_POST['overwrite_kw'] === 'true';
    $post = get_post($post_id);
    if (!$post) wp_send_json_error();

    $options = get_option('auto_seo_global_settings', [
        'desc_length' => 15,
        'kw_length' => 8
    ]);

    $titre = get_the_title($post_id);
    $categorie_principale = get_the_category()[0]->name;
    $desc_updated = false;
    $kw_updated = false;

    $content = strip_shortcodes($post->post_content);
    $content = wp_strip_all_tags($content);

    // Méta Description
    $current_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
    if ($overwrite_desc || empty($current_desc)) {
        $excerpt = wp_trim_words($content, $options['desc_length'], '...');
        if (!empty($excerpt)) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', "$titre | $categorie_principale | $excerpt");
            $desc_updated = true;
        }
    }

    // Expression clé
    $current_kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
    if ($overwrite_kw || empty($current_kw)) {
        update_post_meta($post_id, '_yoast_wpseo_focuskw', wp_trim_words($titre, $options['kw_length'], ''));
        $kw_updated = true;
    }

    wp_send_json_success([
            'desc_updated' => $desc_updated,
            'kw_updated'   => $kw_updated,
            'post_type'    => $post->post_type
    ]);
});
