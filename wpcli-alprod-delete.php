<?php
/**
 * WP-CLI: supprimer des al_product par langue (Polylang).
 *
 * Exemples :
 *  Dry-run (aperçu) :
 *    wp --require=wpcli-alprod-delete.php alprod delete-lang --lang=en --dry-run=1
 *
 *  Mettre à la corbeille :
 *    wp --require=wpcli-alprod-delete.php alprod delete-lang --lang=en --trash=1
 *
 *  Suppression définitive :
 *    wp --require=wpcli-alprod-delete.php alprod delete-lang --lang=en --force=1
 *
 *  Filtrer par statut (facultatif, csv) :
 *    wp --require=wpcli-alprod-delete.php alprod delete-lang --lang=en --status=publish,draft
 */

if ( ! defined('WP_CLI') || ! WP_CLI ) {
    return;
}

class AL_Product_Delete_Command {

    private $post_type = 'al_product';
    private $pll_tax   = 'language'; // Taxonomie Polylang

    /**
     * Supprime tous les al_product d'une langue.
     *
     * ## OPTIONS
     *
     * --lang=<code>
     * : Code langue (ex: fr, en, es).
     *
     * [--status=<csv>]
     * : Statuts à cibler (csv). Par défaut : any (tous).
     *
     * [--dry-run=<0|1>]
     * : Affiche ce qui serait supprimé, sans toucher à la base.
     *
     * [--trash=<0|1>]
     * : Mettre à la corbeille au lieu de suppression définitive.
     *
     * [--force=<0|1>]
     * : Supprime définitivement (wp_delete_post(..., true)). Ignoré si --trash=1.
     *
     * ## EXAMPLES
     *
     * wp --require=wpcli-alprod-delete.php alprod delete-lang --lang=en --dry-run=1
     * wp --require=wpcli-alprod-delete.php alprod delete-lang --lang=en --trash=1
     * wp --require=wpcli-alprod-delete.php alprod delete-lang --lang=en --force=1
     */
    public function delete_lang( $args, $assoc_args ) {
        $lang   = isset($assoc_args['lang']) ? strtolower(trim($assoc_args['lang'])) : '';
        $status = isset($assoc_args['status']) ? trim($assoc_args['status']) : 'any';
        $dry    = !empty($assoc_args['dry-run']) ? (int)$assoc_args['dry-run'] : 0;
        $trash  = !empty($assoc_args['trash']) ? (int)$assoc_args['trash'] : 0;
        $force  = !empty($assoc_args['force']) ? (int)$assoc_args['force'] : 0;

        if ($lang === '') {
            WP_CLI::error("Précisez --lang=<code> (ex: fr, en, es).");
        }

        // Vérifier que la langue existe côté Polylang si possible
        if ( function_exists('pll_languages_list') ) {
            $codes = (array) pll_languages_list(['fields' => 'slug']);
            if ( ! in_array($lang, $codes, true) ) {
                WP_CLI::warning("Langue '{$lang}' non trouvée dans Polylang (codes connus: ".implode(',', $codes)."). On tente quand même via la taxonomie '{$this->pll_tax}'.");
            }
        }

        // Récupérer la term id de la langue (taxonomie Polylang = 'language')
        $term = get_term_by('slug', $lang, $this->pll_tax);
        if ( ! $term || is_wp_error($term) ) {
            WP_CLI::error("Impossible de trouver le terme '{$lang}' dans la taxonomie '{$this->pll_tax}'.");
        }

        // Construire la requête par lot (éviter d'épuiser la mémoire)
        $per_page = 500;
        $paged    = 1;
        $total_deleted = 0;

        $status_param = $status === 'any'
            ? 'any'
            : array_map('trim', explode(',', $status));

        do {
            $q = new WP_Query([
                'post_type'      => $this->post_type,
                'post_status'    => $status_param,
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'tax_query'      => [
                    [
                        'taxonomy' => $this->pll_tax,
                        'field'    => 'term_id',
                        'terms'    => [(int)$term->term_id],
                    ],
                ],
            ]);

            $ids = $q->posts;
            if ( empty($ids) ) {
                break;
            }

            foreach ( $ids as $pid ) {
                $title = get_the_title($pid);
                if ( $dry ) {
                    WP_CLI::log("[DRY-RUN] supprimer ID {$pid} - {$title}");
                    continue;
                }

                if ( $trash ) {
                    $res = wp_trash_post($pid);
                    if ( ! $res ) {
                        WP_CLI::warning("Échec mise à la corbeille ID {$pid} - {$title}");
                    } else {
                        WP_CLI::log("[TRASH] ID {$pid} - {$title}");
                        $total_deleted++;
                    }
                } else {
                    // Suppression définitive
                    $res = wp_delete_post($pid, (bool)$force);
                    if ( ! $res ) {
                        WP_CLI::warning("Échec suppression ID {$pid} - {$title}");
                    } else {
                        WP_CLI::log("[DELETE] ID {$pid} - {$title}");
                        $total_deleted++;
                    }
                }
            }

            $paged++;
        } while ( true );

        if ( $dry ) {
            WP_CLI::success("Dry-run terminé (aucune suppression effectuée).");
        } else {
            WP_CLI::success("Terminée. Total supprimés: {$total_deleted} (langue: {$lang}).");
        }
    }
}

WP_CLI::add_command( 'alprod delete-lang', [ new AL_Product_Delete_Command(), 'delete_lang' ] );
