<?php
/**
 * Commande WP-CLI : alprod restore-fr-attributes
 *
 * Usage :
 *  php ~/wp-cli.phar --path=/chemin/vers/wp \
 *    --require=/chemin/vers/wpcli-restore-fr-attributes.php \
 *    alprod restore-fr-attributes --file=/chemin/vers/products_fr.json --dry-run=1
 *
 * Puis sans --dry-run pour appliquer réellement.
 */

if ( ! class_exists('WP_CLI') ) {
    fwrite(STDERR, "Ce script doit être lancé via WP-CLI (--require).\n");
    return;
}

class ALPROD_Restore_FR_Attributes_Command {

    private $post_type    = 'al_product';
    private $tax_attrs    = 'al_product-attributes';

    /**
     * alprod restore-fr-attributes --file=/path/products_fr.json [--dry-run=1]
     */
    public function __invoke( $args, $assoc_args ) {
        $file    = isset($assoc_args['file']) ? (string)$assoc_args['file'] : '';
        $dry_run = !empty($assoc_args['dry-run']);

        if ($file === '') {
            WP_CLI::error("Missing --file=/absolute/path/to/products_fr.json");
        }
        if ( ! file_exists($file) ) {
            WP_CLI::error("File not found: {$file}");
        }
        if ( ! taxonomy_exists($this->tax_attrs) ) {
            WP_CLI::error("Taxonomy '{$this->tax_attrs}' inexistante.");
        }

        $json = file_get_contents($file);
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            WP_CLI::error("JSON decode error: ".json_last_error_msg());
        }
        if ( ! is_array($data) ) {
            WP_CLI::error("Le JSON racine doit être un tableau.");
        }

        $n_total=0; $n_ok=0; $n_skip=0; $n_err=0;

        foreach ($data as $row) {
            $n_total++;

            // ID du produit FR exporté
            $post_id = isset($row['id']) ? (int)$row['id'] : 0;
            if ($post_id <= 0) { $n_skip++; continue; }

            $p = get_post($post_id);
            if ( ! $p || $p->post_type !== $this->post_type ) {
                WP_CLI::log("[SKIP] #{$post_id} n’est pas un {$this->post_type}");
                $n_skip++; continue;
            }

            // Vérifier que le produit est bien FR (Polylang)
            $is_fr = true;
            if ( function_exists('pll_get_post_language') ) {
                $code = (string)pll_get_post_language($post_id);
                $is_fr = ( strtolower($code) === 'fr' );
            }
            if ( ! $is_fr ) {
                WP_CLI::log("[SKIP] #{$post_id} non-FR, aucun changement.");
                $n_skip++; continue;
            }

            // Récupération des termes (parents + valeurs) depuis le JSON export FR
            $terms_in = [];
            if (!empty($row['tax'][$this->tax_attrs]) && is_array($row['tax'][$this->tax_attrs])) {
                $terms_in = $row['tax'][$this->tax_attrs];
            } else {
                WP_CLI::log("[SKIP] #{$post_id} : pas de bloc tax.{$this->tax_attrs} dans le JSON.");
                $n_skip++; continue;
            }

            // Liste d'IDs à assigner (on part des IDs du JSON FR)
            $ids_to_assign = [];
            foreach ($terms_in as $trow) {
                $tid  = isset($trow['id']) ? (int)$trow['id'] : 0;
                if ($tid > 0) $ids_to_assign[] = $tid;
            }
            $ids_to_assign = array_values(array_unique(array_filter($ids_to_assign, static function($v){return $v>0;})));

            if (empty($ids_to_assign)) {
                WP_CLI::log("[SKIP] #{$post_id} : aucun ID de terme à réassigner.");
                $n_skip++; continue;
            }

            // Optionnel : restaurer les libellés FR des PARENTS (Marque, Tension, Type, ...)
            // On s'appuie sur le JSON (champ 'name'/'slug' si présents) et uniquement pour FR.
            $rename_errors = 0;
            foreach ($terms_in as $trow) {
                $tid  = isset($trow['id']) ? (int)$trow['id'] : 0;
                $name = isset($trow['name']) ? (string)$trow['name'] : '';
                $slug = isset($trow['slug']) ? (string)$trow['slug'] : '';

                if ($tid <= 0) continue;

                $term = get_term($tid, $this->tax_attrs);
                if ( ! $term || is_wp_error($term) ) continue;

                // On ne renomme que les parents (hierarchie de la taxo ImpleCode)
                if ((int)$term->parent === 0) {
                    $args_update = [];
                    if ($name !== '' && $term->name !== $name) $args_update['name'] = $name;
                    if ($slug !== '' && $term->slug !== $slug) $args_update['slug'] = $slug;

                    if (!empty($args_update)) {
                        if ($dry_run) {
                            WP_CLI::log("[DRY-RUN][PARENT] rename term#{$tid} -> ".json_encode($args_update, JSON_UNESCAPED_UNICODE));
                        } else {
                            $res = wp_update_term($tid, $this->tax_attrs, $args_update);
                            if (is_wp_error($res)) {
                                $rename_errors++;
                                WP_CLI::warning("[PARENT] rename term#{$tid} erreur: ".$res->get_error_message());
                            } else {
                                WP_CLI::log("[PARENT] rename term#{$tid} -> ".json_encode($args_update, JSON_UNESCAPED_UNICODE));
                            }
                        }
                    }
                }

                // --- RENOMMER AUSSI LES VALEURS (terms enfants) D'APRÈS LE JSON (optionnel FR) ---
                if ($tid > 0) {
                    $term_child = get_term($tid, $this->tax_attrs);
                    if ($term_child && !is_wp_error($term_child) && (int)$term_child->parent !== 0) {
                        $args_child = [];
                        // si le JSON fournit un name/slug pour la valeur, on peut le restaurer
                        if ($name !== '' && $term_child->name !== $name) $args_child['name'] = $name;
                        if ($slug !== '' && $term_child->slug !== $slug) $args_child['slug'] = $slug;

                        if (!empty($args_child)) {
                            if ($dry_run) {
                                WP_CLI::log("[DRY-RUN][VALUE] rename term#{$tid} -> ".json_encode($args_child, JSON_UNESCAPED_UNICODE));
                            } else {
                                $res2 = wp_update_term($tid, $this->tax_attrs, $args_child);
                                if (is_wp_error($res2)) {
                                    WP_CLI::warning("[VALUE] rename term#{$tid} erreur: ".$res2->get_error_message());
                                } else {
                                    WP_CLI::log("[VALUE] rename term#{$tid} -> ".json_encode($args_child, JSON_UNESCAPED_UNICODE));
                                }
                            }
                        }
                    }
                }
            }

            // Assigner les termes au produit
            if ($dry_run) {
                WP_CLI::log("[DRY-RUN] set {$this->tax_attrs} on #{$post_id} ids=".json_encode($ids_to_assign));
                $n_ok++; continue;
            }

            $res = wp_set_object_terms($post_id, $ids_to_assign, $this->tax_attrs, false);
            if (is_wp_error($res)) {
                $n_err++;
                WP_CLI::warning("[ERR] Assignation {$this->tax_attrs} sur #{$post_id} : ".$res->get_error_message());
                continue;
            }

            // Touch + hooks + petit cycle pour rafraîchir l’affichage
            update_post_meta($post_id, '_alprod_touch', microtime(true));
            if ($post = get_post($post_id)) {
                do_action('edit_post', $post_id, $post);
                do_action('save_post', $post_id, $post, true);
                do_action("save_post_{$post->post_type}", $post_id, $post, true);
                do_action('wp_insert_post', $post_id, $post, true);
            }
            if (function_exists('ic_update_product'))        @ic_update_product($post_id);
            if (function_exists('ic_rebuild_product'))       @ic_rebuild_product($post_id);
            if (function_exists('ic_clear_product_cache'))   @ic_clear_product_cache($post_id);
            if (function_exists('ic_flush_product_cache'))   @ic_flush_product_cache($post_id);

            clean_object_term_cache($post_id, $this->post_type);
            clean_post_cache($post_id);

            WP_CLI::log("[OK] #{$post_id} : {$this->tax_attrs} réassignés (FR).");
            $n_ok++;
        }

        WP_CLI::success("Done. total={$n_total} ok={$n_ok} skip={$n_skip} err={$n_err}");
    }
}

// Enregistre la commande : alprod restore-fr-attributes
WP_CLI::add_command('alprod restore-fr-attributes', 'ALPROD_Restore_FR_Attributes_Command');
