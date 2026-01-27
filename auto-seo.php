<?php
/**
 * Plugin Name: SEO Automatique
 * Plugin URI: https://github.com/loris383v/automatisation-seo
 * Description: Automatisation de la génération des méta-descriptions et mots-clés pour Yoast, avec IA gratuite.
 * Version: 2.0
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
 * Securité pour la clé API
 */
function auto_seo_encrypt($data) {
    if (empty($data)) return '';
    $key = defined('AUTH_KEY') ? AUTH_KEY : 'default_secret_salt_fallback';
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    // On stocke l'IV avec le texte chiffré séparé par ::
    return base64_encode($encrypted . '::' . $iv);
}

function auto_seo_decrypt($data) {
    if (empty($data)) return '';

    $key = defined('AUTH_KEY') ? AUTH_KEY : 'default_secret_salt_fallback';
    $decoded = base64_decode($data);
    
    if (strpos($decoded, '::') === false) return $data; // Fallback sécu

    list($encrypted_data, $iv) = explode('::', $decoded, 2);
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
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
 * Fonction d'appel à l'API Groq
 */
function auto_seo_call_ai($title, $content, $api_key) {
    if (empty($api_key)) {
        return new WP_Error('missing_key', 'Clé API Groq manquante.');
    }

    // On limite le contenu envoyé pour éviter de dépasser les tokens ou ralentir inutilement
    $content_sample = mb_substr($content, 0, 3000) . '...';

    $prompt = "Tu es un expert SEO. Analyse le titre et le contenu ci-dessous.
    Génère un objet JSON strict contenant deux clés :
    1. 'focuskw' : L'expression clé principale la plus pertinente (quelques mots). N'en fournis qu'une seule.
    2. 'metadesc' : Une méta-description accrocheuse, optimisée pour le clic, de moins de 155 caractères.
    
    Titre : $title
    Contenu : $content_sample
    
    Réponds UNIQUEMENT le JSON, rien d'autre.";

    $body = [
        'model' => 'llama-3.1-8b-instant', // rapide et limite api élevée
        'messages' => [
            ['role' => 'system', 'content' => 'Tu es un assistant SEO. Tu ne parles que JSON.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.5,
        'response_format' => ['type' => 'json_object']
    ];

    $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => json_encode($body),
        'timeout' => 20
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if ($response_code !== 200) {
        $error_msg = "Erreur API ($response_code)";
        if ($response_code === 401) $error_msg = "Clé API invalide.";
        if ($response_code === 429) $error_msg = "Limite de taux (Rate limit) atteinte.";
        
        // Tentative de lire le message d'erreur
        $json_error = json_decode($response_body, true);
        if (isset($json_error['error']['message'])) {
            $error_msg .= " : " . $json_error['error']['message'];
        }
        
        return new WP_Error('api_error', $error_msg);
    }

    $data = json_decode($response_body, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        return new WP_Error('format_error', 'La réponse de l\'IA est malformée');
    }

    $ai_content = json_decode($data['choices'][0]['message']['content'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !isset($ai_content['focuskw']) || !isset($ai_content['metadesc'])) {
        return new WP_Error('json_parse_error', 'L\'IA n\'a pas renvoyé un JSON valide.');
    }

    return $ai_content;
}

/**
 * Page de Réglages
 */
function auto_seo_render_settings_page() {
    if (isset($_POST['auto_seo_save_settings'])) {
        check_admin_referer('auto_seo_settings_action');

        // Récupération de la clé brute
        $raw_key = sanitize_text_field($_POST['groq_api_key']);
        // Chiffrement avant stockage
        $encrypted_key = auto_seo_encrypt($raw_key);

        $settings = [
                'enabled'        => isset($_POST['enabled']) ? 1 : 0,
                'post_types'     => isset($_POST['post_types']) ? (array)$_POST['post_types'] : [],
                'fill_desc'      => isset($_POST['fill_desc']) ? 1 : 0,
                'overwrite_desc' => isset($_POST['overwrite_desc']) ? 1 : 0,
                'fill_kw'        => isset($_POST['fill_kw']) ? 1 : 0,
                'overwrite_kw'   => isset($_POST['overwrite_kw']) ? 1 : 0,
                'desc_length'    => intval($_POST['desc_length']) ?: 15,
                'kw_length'      => intval($_POST['kw_length']) ?: 8,
                'groq_api_key'   => $encrypted_key,
                'enable_ai'      => isset($_POST['enable_ai']) ? 1 : 0,
        ];

        update_option('auto_seo_global_settings', $settings);
        echo '<div class="updated"><p>Réglages mis à jour avec succès !</p></div>';
    }

    $defaults = [
        'enabled' => 1,
        'post_types' => ['post', 'page'],
        'fill_desc' => 1,
        'overwrite_desc' => 0,
        'fill_kw' => 1,
        'overwrite_kw' => 0,
        'desc_length' => 15,
        'kw_length' => 8,
        'groq_api_key' => '',
        'enable_ai' => 0
    ];
    $options = wp_parse_args(get_option('auto_seo_global_settings', []), $defaults);
    
    // Déchiffrement pour affichage dans l'input
    $display_key = auto_seo_decrypt($options['groq_api_key']);
    ?>
    <div class="wrap">
        <h1>Réglages de l'automatisation</h1>
        <form method="post">
            <?php wp_nonce_field('auto_seo_settings_action'); ?>
            
            <h2>Intelligence Artificielle</h2>
            <div style="background: #f0f6fc; padding: 15px; border: 1px solid #c5d9ed; border-radius: 5px;">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Clé API Groq</th>
                        <td>
                            <input type="password" name="groq_api_key" value="<?php echo esc_attr($display_key); ?>" class="regular-text" placeholder="gsk_...">
                            <p class="description">Entrez votre clé API Groq.<a href="https://console.groq.com/keys" target="_blank">Obtenir une clé ici</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Activer l'IA par défaut</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_ai" value="1" <?php checked($options['enable_ai'], 1); ?>>
                                Utiliser l'IA pour générer les champs lors de la sauvegarde automatique/publication.
                            </label>
                            <p class="description">Si désactivé (ou en cas d'erreur), l'algorithme classique (découpage de texte) sera utilisé.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <h2>Automatisation classique</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Activer l'automatisation</th>
                    <td>
                        <label><input type="checkbox" name="enabled" value="1" <?php checked($options['enabled'], 1); ?>>
                            Générer les données SEO lors de la publication ou de l'enregistrement</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Types de contenu</th>
                    <td>
                        <label><input type="checkbox" name="post_types[]" value="post" <?php checked(in_array('post', (array)$options['post_types'])); ?>> Articles</label><br>
                        <label><input type="checkbox" name="post_types[]" value="page" <?php checked(in_array('page', (array)$options['post_types'])); ?>> Pages</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Méta description</th>
                    <td>
                        <label><input type="checkbox" name="fill_desc" value="1" <?php checked($options['fill_desc'], 1); ?>> Remplir automatiquement</label><br>
                        <label><input type="checkbox" name="overwrite_desc" value="1" <?php checked($options['overwrite_desc'], 1); ?>> Écraser si déjà rempli</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Expression clé</th>
                    <td>
                        <label><input type="checkbox" name="fill_kw" value="1" <?php checked($options['fill_kw'], 1); ?>> Remplir automatiquement</label><br>
                        <label><input type="checkbox" name="overwrite_kw" value="1" <?php checked($options['overwrite_kw'], 1); ?>> Écraser si déjà rempli</label>
                    </td>
                </tr>
            </table>

            <h2>Configuration du contenu (Mode classique / Fallback)</h2>
            <p class="description">Ces réglages s'appliquent si l'IA n'est pas utilisée ou si elle échoue.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Longueur méta description (mots)</th>
                    <td>
                        <input type="number" name="desc_length" value="<?php echo esc_attr($options['desc_length']); ?>" min="1" max="50">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Longueur expression clé (mots)</th>
                    <td>
                        <input type="number" name="kw_length" value="<?php echo esc_attr($options['kw_length']); ?>" min="1" max="20">
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
    
    $defaults = [
        'enabled' => 1,
        'post_types' => ['post', 'page'],
        'fill_desc' => 1,
        'overwrite_desc' => 0,
        'fill_kw' => 1,
        'overwrite_kw' => 0,
        'desc_length' => 15,
        'kw_length' => 8,
        'groq_api_key' => '',
        'enable_ai' => 0
    ];
    $options = wp_parse_args(get_option('auto_seo_global_settings', []), $defaults);
    
    if (!$options || empty($options['enabled'])) return;
    if (!in_array($post->post_type, (array)$options['post_types'])) return;
    if (in_array($post->post_status, ['auto-draft', 'inherit'])) return;

    $titre = $post->post_title;
    if (empty($titre)) return;

    // Déchiffrement de la clé api
    $api_key = auto_seo_decrypt($options['groq_api_key']);

    // est-ce qu'on doit utiliser l'IA
    $use_ai = (!empty($options['enable_ai']) && !empty($api_key));
    $ai_data = null;

    // Pré-récupération IA si activé
    if ($use_ai && (!empty($options['fill_kw']) || !empty($options['fill_desc']))) {
        $ai_result = auto_seo_call_ai($titre, $post->post_content, $api_key);
        if (!is_wp_error($ai_result)) {
            $ai_data = $ai_result;
        }
        // si erreur IA, ai_data reste null
    }

    // Traitement expression cle
    if (!empty($options['fill_kw'])) {
        $current_kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        if ($options['overwrite_kw'] || empty($current_kw)) {
            $new_kw = ($ai_data) ? $ai_data['focuskw'] : wp_trim_words($titre, $options['kw_length'], '');
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $new_kw);
        }
    }

    // Traitement meta desc
    if (!empty($options['fill_desc'])) {
        $current_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        if ($options['overwrite_desc'] || empty($current_desc)) {
            if ($ai_data) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $ai_data['metadesc']);
            } else {
                //classique
                $content = strip_shortcodes($post->post_content);
                $content = wp_strip_all_tags($content);
                $excerpt = wp_trim_words($content, $options['desc_length'], '...');
                $categorie_principale = get_the_category($post_id)[0]->name ?? '';
                if (!empty($excerpt)) {
                    $meta_val = "$titre";
                    if ($categorie_principale) $meta_val .= " | $categorie_principale";
                    $meta_val .= " | $excerpt";
                    update_post_meta($post_id, '_yoast_wpseo_metadesc', "$meta_val");
                }
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
    
    // Récupérer la clé api pour vérifier
    $options = get_option('auto_seo_global_settings', []);
    $has_api_key = !empty($options['groq_api_key']); // même chiffrée, juste savoir si elle est là
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
                <hr>
                <h3>Intelligence Artificielle</h3>
                <p>
                    <label style="<?php echo $has_api_key ? '' : 'color: #888; cursor: not-allowed;'; ?>" title="<?php echo $has_api_key ? '' : 'Veuillez configurer la clé API Groq dans les réglages.'; ?>">
                        <input type="checkbox" id="use-ai" <?php echo $has_api_key ? '' : 'disabled'; ?>> 
                        <strong>Générer avec l'IA</strong>
                    </label>
                </p>
                <?php if(!$has_api_key): ?>
                    <p class="description" style="color: #d63638;">Clé API manquante. <a href="admin.php?page=auto-seo-settings">Configurer</a></p>
                <?php endif; ?>
            </div>
        </div>

        <div id="seo-bar-container" style="width:100%; background:#ddd; border-radius:10px; overflow:hidden; margin:20px 0;">
            <div id="seo-bar-fill" style="width:0%; height:30px; background:#2271b1; color:white; text-align:center; line-height:30px; transition: width 0.3s;">0%</div>
        </div>
        <p id="seo-stats">Progression : <span id="current">0</span> / <span id="total">0</span></p>
        <div id="seo-log" style="display:none; background: #e7f7ed; border: 1px solid #46b450; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
            <h3 style="margin-top:0;">Résultat de l'optimisation</h3>
            <div id="log-summary"></div>
            <div id="error-log" style="margin-top:10px; color:#d63638; display:none;"></div>
        </div>
        <button id="start-btn" class="button button-primary button-large">Lancer l'optimisation</button>
    </div>
    <style>
        .cat-list-wrapper { max-height: 200px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd; margin-top: 10px; }
        .cat-list-wrapper ul { margin-left: 20px; }
    </style>
    <script>
        jQuery(document).ready(function($) {
            let stats = {
                post: { checked: 0, updated: 0, desc: 0, kw: 0 },
                page: { checked: 0, updated: 0, desc: 0, kw: 0 },
                errors: []
            };

            $('#toggle-whitelist').change(function() { $('#whitelist-container').slideToggle($(this).is(':checked')); });
            $('#toggle-blacklist').change(function() { $('#blacklist-container').slideToggle($(this).is(':checked')); });
            $('.select-all').click(function() { $('#' + $(this).data('target') + ' input[type="checkbox"]').prop('checked', true); });

            $('#start-btn').click(function() {
                const $btn = $(this);
                const overwriteDesc = $('#overwrite-desc').is(':checked');
                const overwriteKW = $('#overwrite-kw').is(':checked');
                const useAI = $('#use-ai').is(':checked');
                
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
                $('#error-log').empty().hide();

                stats = {
                    post: { checked: 0, updated: 0, desc: 0, kw: 0 },
                    page: { checked: 0, updated: 0, desc: 0, kw: 0 },
                    errors: []
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
                        if(total > 0) { 
                            $btn.text('Traitement en cours...'); 
                            processNext(ids, 0, total, overwriteDesc, overwriteKW, useAI); 
                        }
                        else { $btn.prop('disabled', false).text('Aucun contenu trouvé'); }
                    }
                });
            });

            function processNext(ids, index, total, overwriteDesc, overwriteKW, useAI) {
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
                    
                    if (stats.errors.length > 0) {
                        let errorHtml = '<strong>Alertes et Erreurs :</strong><ul>';
                        stats.errors.forEach(err => {
                            errorHtml += '<li>ID ' + err.id + ' : ' + err.msg + '</li>';
                        });
                        errorHtml += '</ul>';
                        $('#error-log').html(errorHtml).show();
                    }

                    $('#seo-log').fadeIn();
                    return;
                }
                $.post(ajaxurl, {
                    action: 'seo_process_item',
                    post_id: ids[index],
                    overwrite_desc: overwriteDesc,
                    overwrite_kw: overwriteKW,
                    use_ai: useAI,
                    security: '<?php echo $nonce; ?>'
                }, function(res) {
                    if(res.success) {
                        const type = res.data.post_type;
                        stats[type].checked++;
                        if(res.data.desc_updated) stats[type].desc++;
                        if(res.data.kw_updated) stats[type].kw++;
                        if(res.data.desc_updated || res.data.kw_updated) stats[type].updated++;
                        
                        // Gestion erreurs/warnings (non bloquantes car fallback)
                        if(res.data.error) {
                            stats.errors.push({id: ids[index], msg: '<span style="color:#e67e22;">' + res.data.error + '</span> (Fait en mode classique)'});
                        }
                    } else {
                        // Erreur bloquante ou retour false
                        stats.errors.push({id: ids[index], msg: res.data ? res.data : 'Erreur inconnue'});
                    }
                    
                    let current = index + 1;
                    let percent = Math.round((current / total) * 100);
                    $('#seo-bar-fill').css('width', percent+'%').text(percent+'%');
                    $('#current').text(current);
                    processNext(ids, current, total, overwriteDesc, overwriteKW, useAI);
                }).fail(function() {
                    stats.errors.push({id: ids[index], msg: 'Erreur serveur/réseau'});
                    processNext(ids, index + 1, total, overwriteDesc, overwriteKW, useAI);
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
    $use_ai = $_POST['use_ai'] === 'true';
    
    $post = get_post($post_id);
    if (!$post) wp_send_json_error('Post introuvable');

    $defaults = [
        'desc_length' => 15,
        'kw_length' => 8,
        'groq_api_key' => ''
    ];
    $options = wp_parse_args(get_option('auto_seo_global_settings', []), $defaults);

    $titre = get_the_title($post_id);
    $desc_updated = false;
    $kw_updated = false;
    $error_msg = null;

    // Vérifier si on a besoin de mettre à jour quelque chose avant de faire des calculs
    $current_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
    $current_kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
    
    $needs_desc = ($overwrite_desc || empty($current_desc));
    $needs_kw = ($overwrite_kw || empty($current_kw));

    if (!$needs_desc && !$needs_kw) {
        wp_send_json_success([
            'desc_updated' => false,
            'kw_updated'   => false,
            'post_type'    => $post->post_type
        ]);
    }

    $ai_data = null;
    $api_key = auto_seo_decrypt($options['groq_api_key']);

    // Logique IA
    if ($use_ai && !empty($api_key)) {
        $ai_response = auto_seo_call_ai($titre, $post->post_content, $api_key);
        
        if (is_wp_error($ai_response)) {
            // Enregistrement de l'erreur pour le rapport, mais on continue
            $error_msg = $ai_response->get_error_message();
            $ai_data = null; 
        } else {
            $ai_data = $ai_response;
        }
    }

    // Mise à jour (Soit IA, soit classique)
    
    // Méta Description
    if ($needs_desc) {
        if ($ai_data) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $ai_data['metadesc']);
            $desc_updated = true;
        } else {
            //classique
            $content = strip_shortcodes($post->post_content);
            $content = wp_strip_all_tags($content);
            $excerpt = wp_trim_words($content, $options['desc_length'], '...');
            $categorie_principale = get_the_category($post_id)[0]->name ?? '';
            
            if (!empty($excerpt)) {
                $meta_val = "$titre";
                if ($categorie_principale) $meta_val .= " | $categorie_principale";
                $meta_val .= " | $excerpt";
                
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_val);
                $desc_updated = true;
            }
        }
    }

    // Expression clé
    if ($needs_kw) {
        if ($ai_data) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $ai_data['focuskw']);
            $kw_updated = true;
        } else {
            // Classique
            update_post_meta($post_id, '_yoast_wpseo_focuskw', wp_trim_words($titre, $options['kw_length'], ''));
            $kw_updated = true;
        }
    }

    wp_send_json_success([
            'desc_updated' => $desc_updated,
            'kw_updated'   => $kw_updated,
            'post_type'    => $post->post_type,
            'error'        => $error_msg
    ]);
});