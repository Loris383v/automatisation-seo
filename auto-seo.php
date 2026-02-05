<?php
/**
 * Plugin Name: SEO Automatique
 * Plugin URI: https://github.com/loris383v/automatisation-seo
 * Description: Automatisation de la génération des méta-descriptions et mots-clés pour Yoast, avec IA gratuite. Supporte le traitement en arrière-plan.
 * Version: 2.1.1
 * Author: Loris Lacote
 * Author URI: https://github.com/loris383v
 * Requires Plugins: wordpress-seo
 */

if (!defined('ABSPATH'))
    exit;

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

function auto_seo_check_dependency()
{
    if (!is_plugin_active('wordpress-seo/wp-seo.php')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die("Ce plugin nécessite l'activation du plugin Yoast SEO pour fonctionner");
    }
}

/**
 * Logs
 */
function auto_seo_log($message)
{
    $upload_dir = wp_upload_dir();
    $file = $upload_dir['basedir'] . '/auto-seo-logs.log';
    $date = date('d-m-Y H:i:s');
    file_put_contents($file, "[$date] $message" . PHP_EOL, FILE_APPEND);
}

function auto_seo_get_log_content()
{
    $upload_dir = wp_upload_dir();
    $file = $upload_dir['basedir'] . '/auto-seo-logs.log';
    if (file_exists($file)) {
        // Limite à 20ko pour éviter de crasher si le fichier fait 441220go
        // Si ça fait lagger alors on descendra un peu
        $content = file_get_contents($file);
        if (strlen($content) > 20000)
            return "..." . substr($content, -20000);
        return $content;
    }
    return '';
}

/**
 * Securité pour la clé API
 */
function auto_seo_encrypt($data)
{
    if (empty($data))
        return '';
    $key = defined('AUTH_KEY') ? AUTH_KEY : 'default_secret_salt_fallback';
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    // On stocke l'IV avec le texte chiffré séparé par ::
    return base64_encode($encrypted . '::' . $iv);
}

function auto_seo_decrypt($data)
{
    if (empty($data))
        return '';

    $key = defined('AUTH_KEY') ? AUTH_KEY : 'default_secret_salt_fallback';
    $decoded = base64_decode($data);

    if (strpos($decoded, '::') === false)
        return $data;

    list($encrypted_data, $iv) = explode('::', $decoded, 2);
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
}

/**
 * Lien vers les réglages dans la liste des extensions
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
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
function auto_seo_call_ai($title, $content, $api_key, $site_context = '')
{
    if (empty($api_key)) {
        return new WP_Error('missing_key', 'Clé API Groq manquante.');
    }

    // On limite le contenu envoyé pour éviter de dépasser les tokens ou ralentir inutilement
    $content_sample = mb_substr($content, 0, 3000) . '...';

    // Injection du contexte si présent
    $context_instruction = "";
    if (!empty($site_context)) {
        $context_instruction = "IMPORTANT - Contexte du site : \"$site_context\". Utilise ce contexte pour adapter le ton et la pertinence.";
    }

    $prompt = "Tu es un expert SEO. Analyse le titre et le contenu ci-dessous.
    $context_instruction
    
    Génère un objet JSON strict contenant deux clés :
    1. 'focuskw' : L'expression clé principale la plus pertinente (quelques mots). N'en fournis qu'une seule.
    2. 'metadesc' : Une méta-description accrocheuse, optimisée pour le clic, de moins de 155 caractères.
    
    Titre : $title
    Contenu : $content_sample
    
    Réponds UNIQUEMENT le JSON, rien d'autre.";

    $body = [
        'model' => 'meta-llama/llama-4-scout-17b-16e-instruct', //sinon llama-3.1-8b-instant avec la même limite quotidienne, mais une limite par minute plus faible et moins efficace
        'messages' => [
            ['role' => 'system', 'content' => 'Tu es un assistant SEO. Tu ne parles que JSON.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.5,
        'response_format' => ['type' => 'json_object']
    ];

    // Un seul essai ici, c'est le cron qui gère les trucs
    $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($body),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_code_int = intval($response_code); // Conversion explicite pour éviter les erreurs de type

    // Gestion du Rate Limit (429)
    if ($response_code_int === 429) {
        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
        $wait_time = $retry_after ? intval($retry_after) : 60;
        return new WP_Error('rate_limit', 'Limite de taux atteinte', ['retry_after' => $wait_time]);
    }

    if ($response_code_int !== 200) {
        return new WP_Error('api_error', "Erreur API ($response_code_int)");
    }

    $response_body = wp_remote_retrieve_body($response);
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
 * Traitement regroupé pour ne plus avoir à modifier 2 fonctions différentes à chaque fois. Depuis le temps qu'il fallait que je le fasse ça...
 */
function auto_seo_process_single_post($post_id, $opts)
{
    $post = get_post($post_id);
    if (!$post)
        return "Post $post_id introuvable.";

    $titre = get_the_title($post_id);
    $api_key = auto_seo_decrypt($opts['groq_api_key'] ?? '');
    $site_context = $opts['site_context'] ?? '';

    // verifs existantes
    $current_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
    $current_kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);

    $needs_desc = (!empty($opts['overwrite_desc']) || empty($current_desc)) && !empty($opts['fill_desc']);
    $needs_kw = (!empty($opts['overwrite_kw']) || empty($current_kw)) && !empty($opts['fill_kw']);

    if (!$needs_desc && !$needs_kw) {
        return "Ignoré (vide ou déjà rempli)";
    }

    $ai_data = null;
    $method = "Classique";

    // Tentative IA
    if (!empty($opts['use_ai']) && !empty($api_key)) {
        $ai_result = auto_seo_call_ai($titre, $post->post_content, $api_key, $site_context);

        if (is_wp_error($ai_result)) {
            if ($ai_result->get_error_code() === 'rate_limit') {
                return $ai_result; // On remonte l'erreur bloquante au planificateur
            }
            // sinon on log l'erreur et on passe en classique
            if (function_exists('auto_seo_log')) {
                auto_seo_log("Erreur IA sur ID $post_id : " . $ai_result->get_error_message());
            }
        } else {
            $ai_data = $ai_result;
            $method = "IA";
        }
    }

    $res_log = [];

    // Méta description
    if ($needs_desc) {
        if ($ai_data) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $ai_data['metadesc']);
        } else {
            // classqieu
            $content = strip_shortcodes($post->post_content);
            $content = wp_strip_all_tags($content);
            $excerpt = wp_trim_words($content, $opts['desc_length'] ?? 15, '...');
            $categorie_principale = get_the_category($post_id)[0]->name ?? '';

            $meta_val = "$titre";
            if ($categorie_principale)
                $meta_val .= " | $categorie_principale";
            if (!empty($excerpt))
                $meta_val .= " | $excerpt";

            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_val);
        }
        $res_log[] = "Description";
    }

    // Mot clé
    if ($needs_kw) {
        $kw = ($ai_data) ? $ai_data['focuskw'] : wp_trim_words($titre, $opts['kw_length'] ?? 8, '');
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $kw);
        $res_log[] = "Expression clé";
    }

    return "OK ($method) : " . implode(' & ', $res_log);
}


/**
 * Page de Réglages
 */
function auto_seo_render_settings_page()
{
    if (isset($_POST['auto_seo_save_settings'])) {
        check_admin_referer('auto_seo_settings_action');
        $raw_key = sanitize_text_field($_POST['groq_api_key']);
        $encrypted_key = auto_seo_encrypt($raw_key);

        $settings = [
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
            'post_types' => isset($_POST['post_types']) ? (array) $_POST['post_types'] : [],
            'fill_desc' => isset($_POST['fill_desc']) ? 1 : 0,
            'overwrite_desc' => isset($_POST['overwrite_desc']) ? 1 : 0,
            'fill_kw' => isset($_POST['fill_kw']) ? 1 : 0,
            'overwrite_kw' => isset($_POST['overwrite_kw']) ? 1 : 0,
            'desc_length' => intval($_POST['desc_length']) ?: 15,
            'kw_length' => intval($_POST['kw_length']) ?: 8,
            'groq_api_key' => $encrypted_key,
            'enable_ai' => isset($_POST['enable_ai']) ? 1 : 0,
            'site_context' => isset($_POST['site_context']) ? sanitize_textarea_field($_POST['site_context']) : '',
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
        'enable_ai' => 0,
        'site_context' => ''
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
                            <input type="password" name="groq_api_key" value="<?php echo esc_attr($display_key); ?>"
                                class="regular-text" placeholder="gsk_...">
                            <p class="description">Entrez votre clé API Groq. <a href="https://console.groq.com/keys"
                                    target="_blank">Obtenir une clé ici</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Contexte pour l'IA (Fortement recommandé)</th>
                        <td>
                            <p>Écrivez ici une courte description du site qui sera fournie à l'IA pour générer les descriptions</p>
                            <textarea name="site_context" rows="3" width="100%" class="regular-text"><?php echo esc_textarea($options['site_context']); ?></textarea>
                            <p class="description">Sans contexte, l'IA risque de faire des descriptions très inexactes sur des pages un peu génériques.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Activer l'IA par défaut</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_ai" value="1" <?php checked($options['enable_ai'], 1); ?>>
                                Utiliser l'IA pour générer les champs lors de la sauvegarde automatique/publication.
                            </label>
                            <p class="description">Si désactivé (ou en cas d'erreur), l'algorithme classique sera utilisé.
                            </p>
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
                        <label><input type="checkbox" name="post_types[]" value="post" <?php checked(in_array('post', (array) $options['post_types'])); ?>> Articles</label><br>
                        <label><input type="checkbox" name="post_types[]" value="page" <?php checked(in_array('page', (array) $options['post_types'])); ?>> Pages</label>
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
                        <label><input type="checkbox" name="fill_kw" value="1" <?php checked($options['fill_kw'], 1); ?>>
                            Remplir automatiquement</label><br>
                        <label><input type="checkbox" name="overwrite_kw" value="1" <?php checked($options['overwrite_kw'], 1); ?>> Écraser si déjà rempli</label>
                    </td>
                </tr>
            </table>

            <h2>Configuration du contenu (Mode classique)</h2>
            <p class="description">Ces réglages s'appliquent si l'IA n'est pas utilisée ou si elle échoue.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Longueur méta description (mots)</th>
                    <td>
                        <input type="number" name="desc_length" value="<?php echo esc_attr($options['desc_length']); ?>"
                            min="1" max="50">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Longueur expression clé (mots)</th>
                    <td>
                        <input type="number" name="kw_length" value="<?php echo esc_attr($options['kw_length']); ?>" min="1"
                            max="20">
                    </td>
                </tr>
            </table>
            <p class="submit"><input type="submit" name="auto_seo_save_settings" class="button button-primary"
                    value="Enregistrer les modifications"></p>
        </form>
    </div>
    <?php
}

/**
 * Logique de sauvegarde automatique
 */
add_action('wp_after_insert_post', 'auto_seo_after_save_trigger', 99, 3);
function auto_seo_after_save_trigger($post_id, $post, $update)
{
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id))
        return;

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
        'enable_ai' => 0,
        'site_context' => '',
    ];
    $options = wp_parse_args(get_option('auto_seo_global_settings', []), $defaults);

    if (!$options['enabled'])
        return;
    if (!in_array($post->post_type, (array) ($options['post_types'] ?? [])))
        return;
    if (in_array($post->post_status, ['auto-draft', 'inherit']))
        return;

    // oui
    $run_opts = [
        'fill_desc' => !empty($options['fill_desc']),
        'overwrite_desc' => !empty($options['overwrite_desc']),
        'fill_kw' => !empty($options['fill_kw']),
        'overwrite_kw' => !empty($options['overwrite_kw']),
        'desc_length' => $options['desc_length'] ?? 15,
        'kw_length' => $options['kw_length'] ?? 8,
        'groq_api_key' => $options['groq_api_key'],
        'use_ai' => !empty($options['enable_ai']),
        'site_context' => $options['site_context']
    ];

    // Appel synchrone (pas de cron ici, c'est une sauvegarde manuelle)
    auto_seo_process_single_post($post_id, $run_opts);
}

/**
 * traitement en arrirèe plan
 */
add_action('auto_seo_cron_event', 'auto_seo_run_batch');
function auto_seo_run_batch()
{
    $queue = get_option('auto_seo_queue', []);
    $opts = get_option('auto_seo_batch_options', []);

    if (empty($queue)) {
        update_option('auto_seo_status', 'finished');
        auto_seo_log("--- TERMINÉ ---");
        return;
    }

    // 1 si IA pour gérer les rate limit, 5 sinon
    $batch_size = !empty($opts['use_ai']) ? 1 : 5;
    $current_batch = array_slice($queue, 0, $batch_size);

    foreach ($current_batch as $post_id) {
        $result = auto_seo_process_single_post($post_id, $opts);

        if (is_wp_error($result) && $result->get_error_code() === 'rate_limit') {
            $wait = $result->get_error_data()['retry_after'] + 5; // Marge de sécu
            auto_seo_log("LIMITE IA SUR (ID $post_id). Pause de $wait secondes...");
            wp_schedule_single_event(time() + $wait, 'auto_seo_cron_event');
            return; // STOP complet du script p
        }

        $msg = is_wp_error($result) ? $result->get_error_message() : $result;
        auto_seo_log("ID $post_id : $msg");

        // Retrait de la file d'attente
        $queue = get_option('auto_seo_queue', []);
        if (($key = array_search($post_id, $queue)) !== false) {
            unset($queue[$key]);
            $queue = array_values($queue);
            update_option('auto_seo_queue', $queue);
            update_option('auto_seo_processed', (int) get_option('auto_seo_processed', 0) + 1);
        }
    }

    if (!empty($queue)) {
        // Petite pause si IA, on est pas à ça près et dans tous les cas on finira par se prendre un 429
        $delay = !empty($opts['use_ai']) ? 2 : 0;
        wp_schedule_single_event(time() + $delay, 'auto_seo_cron_event');
    } else {
        update_option('auto_seo_status', 'finished');
        auto_seo_log("--- TERMINÉ ---");
    }
}

/**
 * Interface page Optimiser (Bulk)
 */
function auto_seo_render_page()
{
    $nonce = wp_create_nonce('auto_seo_security_token');
    if (!function_exists('wp_terms_checklist')) {
        require_once ABSPATH . 'wp-admin/includes/template.php';
    }

    $options = get_option('auto_seo_global_settings', []);
    $has_api_key = !empty($options['groq_api_key']); // même chiffrée, juste savoir si elle est là
    ?>
    <div class="wrap">
        <h1>Optimisation de masse</h1>
        
        <!-- Filtrage et options -->
        <div style="display: flex; gap: 20px; margin-bottom: 20px;">
            <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
                <h3>Filtrage des catégories</h3>
                <div style="margin-bottom: 20px;">
                    <label style="font-weight: bold; cursor: pointer;">
                        <input type="checkbox" id="toggle-whitelist" style="margin-right: 10px;"> Liste blanche
                    </label>
                    <div id="whitelist-container" style="display: none; margin-top: 10px;">
                        <button type="button" class="button button-secondary select-all"
                            data-target="whitelist-checklist">Tout sélectionner</button>
                        <div class="cat-list-wrapper">
                            <ul id="whitelist-checklist"><?php wp_terms_checklist(0, array('taxonomy' => 'category')); ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <hr>
                <div style="margin-top: 20px;">
                    <label style="font-weight: bold; cursor: pointer;">
                        <input type="checkbox" id="toggle-blacklist" style="margin-right: 10px;"> Liste noire
                    </label>
                    <div id="blacklist-container" style="display: none; margin-top: 10px;">
                        <button type="button" class="button button-secondary select-all"
                            data-target="blacklist-checklist">Tout sélectionner</button>
                        <div class="cat-list-wrapper">
                            <ul id="blacklist-checklist"><?php wp_terms_checklist(0, array('taxonomy' => 'category')); ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div
                style="width: 300px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px; align-self: flex-start;">
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
                    <label style="<?php echo $has_api_key ? '' : 'color: #888; cursor: not-allowed;'; ?>"
                        title="<?php echo $has_api_key ? '' : 'Veuillez configurer la clé API Groq dans les réglages.'; ?>">
                        <input type="checkbox" id="use-ai" <?php echo $has_api_key ? '' : 'disabled'; ?>>
                        <strong>Générer avec l'IA</strong>
                    </label>
                </p>
                <?php if (!$has_api_key): ?>
                    <p class="description" style="color: #d63638;">Clé API manquante. <a
                            href="admin.php?page=auto-seo-settings">Configurer</a></p>
                <?php endif; ?>
                
                <div style="margin-top:20px;">
                    <button id="start-btn" class="button button-primary button-large" style="width:100%">Lancer l'optimisation</button>
                    <button id="stop-btn" class="button button-secondary" style="width:100%; margin-top:10px; color:#d63638;">Arrêt</button>
                </div>
            </div>
        </div>

        <!-- Logs et Status Serveur -->
        <div id="server-monitor" style="margin-top:20px;">
            <h3>État du serveur</h3>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <div style="font-weight:bold; font-size:1.1em;">
                    Progression : <span id="current">0</span> / <span id="total">0</span>
                    <span id="status-badge" style="margin-left:10px; padding:3px 8px; border-radius:3px; font-size:12px; background:#eee;">EN ATTENTE</span>
                </div>
            </div>

            <div id="seo-bar-container" style="width:100%; background:#ddd; border-radius:10px; overflow:hidden; margin-bottom:10px;">
                <div id="seo-bar-fill" style="width:0%; height:30px; background:#2271b1; color:white; text-align:center; line-height:30px; transition: width 0.3s;">0%</div>
            </div>

            <div id="live-log-container"
                style="background: #23282d; color: #0f0; border: 1px solid #ccc; padding: 10px; height: 300px; overflow-y: scroll; font-family: monospace;">
                <div id="live-log-list">En attente de démarrage...</div>
            </div>
        </div>
    </div>
    
    <style>
        .cat-list-wrapper { max-height: 200px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd; margin-top: 10px; }
        .cat-list-wrapper ul { margin-left: 20px; }
    </style>

    <script>
        jQuery(document).ready(function ($) {
            // -- Gestion UI --
            $('#toggle-whitelist').change(function () { $('#whitelist-container').slideToggle($(this).is(':checked')); });
            $('#toggle-blacklist').change(function () { $('#blacklist-container').slideToggle($(this).is(':checked')); });
            $('.select-all').click(function () { $('#' + $(this).data('target') + ' input[type="checkbox"]').prop('checked', true); });

            // -- Polling Serveur --
            function refreshStatus() {
                $.post(ajaxurl, {action: 'seo_get_status'}, function(res) {
                    if(res.success) {
                        let d = res.data;
                        // Logs
                        $('#live-log-list').html('<pre style="white-space:pre-wrap; margin:0">'+d.logs+'</pre>');
                        let logContainer = document.getElementById('live-log-container');
                        logContainer.scrollTop = logContainer.scrollHeight;

                        // Bar
                        let pct = d.total > 0 ? Math.round((d.processed / d.total) * 100) : 0;
                        $('#seo-bar-fill').css('width', pct + '%').text(pct + '%');
                        $('#current').text(d.processed);
                        $('#total').text(d.total);

                        // Badge et Boutons
                        let statusLabel = d.status === 'running' ? 'EN COURS (SERVEUR)' : (d.status === 'finished' ? 'TERMINÉ' : 'EN ATTENTE');
                        let color = d.status === 'running' ? '#e5f5fa' : (d.status === 'finished' ? '#dff0d8' : '#eee');
                        $('#status-badge').text(statusLabel).css('background', color);

                        if (d.status === 'running') {
                            $('#start-btn').prop('disabled', true).text('Traitement en cours...');
                        } else {
                            $('#start-btn').prop('disabled', false).text('Lancer l\'optimisation');
                        }
                    }
                });
            }
            setInterval(refreshStatus, 3000);
            refreshStatus();

            // -- Actions --
            $('#start-btn').click(function () {
                if(!confirm("Lancer le traitement en arrière-plan ? Cela peut peut prendre plusieurs jours sur un gros site si l'IA est activée.")) return;

                const $btn = $(this);
                let postTypes = [];
                if ($('#process-posts').is(':checked')) postTypes.push('post');
                if ($('#process-pages').is(':checked')) postTypes.push('page');
                if (postTypes.length === 0) { alert('Sélectionnez au moins un type de contenu !'); return; }

                // Récupération Whitelist / Blacklist depuis les inputs wp
                let whitelist = [];
                if ($('#toggle-whitelist').is(':checked')) { $('#whitelist-checklist input:checked').each(function () { whitelist.push($(this).val()); }); }
                let blacklist = [];
                if ($('#toggle-blacklist').is(':checked')) { $('#blacklist-checklist input:checked').each(function () { blacklist.push($(this).val()); }); }

                $btn.prop('disabled', true).text('Lancement...');

                $.post(ajaxurl, {
                    action: 'seo_start_background',
                    post_types: postTypes,
                    whitelist: whitelist,
                    blacklist: blacklist,
                    overwrite_desc: $('#overwrite-desc').is(':checked'),
                    overwrite_kw: $('#overwrite-kw').is(':checked'),
                    use_ai: $('#use-ai').is(':checked'),
                    security: '<?php echo $nonce; ?>'
                }, function (res) {
                    if (res.success) {
                        alert(res.data.message);
                        refreshStatus();
                    } else {
                        alert('Erreur : ' + res.data);
                        $btn.prop('disabled', false).text('Lancer l\'optimisation');
                    }
                });
            });

            $('#stop-btn').click(function() {
                if(confirm("Arrêter l'optimisation ?")) {
                    $.post(ajaxurl, {action: 'seo_stop_process'}, function() { refreshStatus(); });
                }
            });
        });
    </script>
    <?php
}

/**
 * AJAX
 */
add_action('wp_ajax_seo_start_background', function() {
    check_ajax_referer('auto_seo_security_token', 'security');
    if (!current_user_can('manage_options')) wp_die();

    // Reset Logs
    $upload_dir = wp_upload_dir();
    file_put_contents($upload_dir['basedir'] . '/auto-seo-logs.log', "--- DÉMARRAGE DU TRAITEMENT ---\n");

    // Fetch IDs avec filtrage complet
    $args = [
        'post_type' => !empty($_POST['post_types']) ? $_POST['post_types'] : ['post'],
        'fields' => 'ids',
        'posts_per_page' => -1,
        'post_status' => 'any'
    ];
    // Gestion Whitelist et Blacklist
    if (!empty($_POST['whitelist'])) $args['category__in'] = array_map('intval', $_POST['whitelist']);
    if (!empty($_POST['blacklist'])) $args['category__not_in'] = array_map('intval', $_POST['blacklist']);
    
    $ids = get_posts($args);
    if (empty($ids)) wp_send_json_error("Aucun contenu trouvé avec ces critères.");

    // Options Batch
    $global = get_option('auto_seo_global_settings', []);
    $opts = [
        'groq_api_key' => $global['groq_api_key'] ?? '',
        'use_ai' => $_POST['use_ai'] === 'true',
        'overwrite_desc' => $_POST['overwrite_desc'] === 'true',
        'overwrite_kw' => $_POST['overwrite_kw'] === 'true',
        'fill_desc' => true, 
        'fill_kw' => true,
        'desc_length' => $global['desc_length'] ?? 15,
        'kw_length' => $global['kw_length'] ?? 8,
        'site_context' => $global['site_context'] ?? '',
    ];

    update_option('auto_seo_queue', $ids);
    update_option('auto_seo_batch_options', $opts);
    update_option('auto_seo_total', count($ids));
    update_option('auto_seo_processed', 0);
    update_option('auto_seo_status', 'running');

    wp_schedule_single_event(time(), 'auto_seo_cron_event');
    wp_send_json_success(['message' => "Lancé sur " . count($ids) . " éléments."]);
});

add_action('wp_ajax_seo_get_status', function () {
    if (!current_user_can('manage_options'))
        wp_die();
    wp_send_json_success([
        'status' => get_option('auto_seo_status', 'idle'),
        'total' => (int) get_option('auto_seo_total', 0),
        'processed' => (int) get_option('auto_seo_processed', 0),
        'logs' => auto_seo_get_log_content()
    ]);
});

add_action('wp_ajax_seo_stop_process', function () {
    if (!current_user_can('manage_options'))
        wp_die();
    update_option('auto_seo_queue', []);
    update_option('auto_seo_status', 'finished');
    auto_seo_log("--- ARRÊT FORCÉ ---");
    wp_send_json_success();
});