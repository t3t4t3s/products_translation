<?php
/**
 * WP-CLI: Supprime les <p> uniquement à l'intérieur des <li> dans post_content des al_product.
 *
 * Usage:
 *   # Dry-run (aucune écriture)
 *   wp alprod fix-li-p
 *
 *   # Appliquer réellement
 *   wp alprod fix-li-p --run
 *
 * Options:
 *   [--run]                Applique réellement les modifications (sinon dry-run).
 *   [--id=<id>]            Limiter au post ID donné.
 *   [--limit=<n>]          Limite le nombre total de posts à traiter (défaut: illimité).
 *   [--status=<status>]    Statut des posts (publish,draft,any…) défaut: any.
 *   [--batch=<n>]          Taille de lot pour le chargement (défaut: 200).
 *   [--verbose]            Affiche les IDs modifiés et le nombre de <p> retirés.
 */

if ( ! defined('WP_CLI') || ! WP_CLI ) {
    return;
}

class ALProd_Fix_LI_Paragraphs_Command {

    public function fix_li_p( $args, $assoc_args ) {
        $do_run   = isset( $assoc_args['run'] );
        $only_id  = isset( $assoc_args['id'] ) ? (int) $assoc_args['id'] : null;
        $limit    = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : null;
        $status   = isset( $assoc_args['status'] ) ? (string) $assoc_args['status'] : 'any';
        $batch    = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 200;
        $verbose  = isset( $assoc_args['verbose'] );

        \WP_CLI::log( '=== al_product • unwrapping <p> inside <li> ===' );
        \WP_CLI::log( 'Mode : ' . ( $do_run ? 'RUN (écriture)' : 'DRY-RUN (aucune écriture)' ) );
        if ( $only_id ) { \WP_CLI::log( 'Filtre ID : ' . $only_id ); }
        if ( $limit )   { \WP_CLI::log( 'Limite    : ' . $limit ); }
        \WP_CLI::log( 'Status   : ' . $status );
        \WP_CLI::log( 'Batch    : ' . $batch );

        $total_scanned      = 0;
        $total_changed      = 0;
        $total_p_unwrapped  = 0;

        if ( $only_id ) {
            $ids = get_posts( [
                'post_type'      => 'al_product',
                'post_status'    => $status,
                'include'        => [ $only_id ],
                'fields'         => 'ids',
                'posts_per_page' => 1,
            ] );
            $this->process_ids( $ids, $do_run, $verbose, $total_scanned, $total_changed, $total_p_unwrapped, $limit );
        } else {
            $paged = 1;
            while ( true ) {
                $per_page = $batch;
                if ( $limit ) {
                    $remaining = $limit - $total_scanned;
                    if ( $remaining <= 0 ) break;
                    $per_page = min( $per_page, $remaining );
                }

                $ids = get_posts( [
                    'post_type'      => 'al_product',
                    'post_status'    => $status,
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                    'posts_per_page' => $per_page,
                    'paged'          => $paged,
                ] );

                if ( empty( $ids ) ) break;

                $this->process_ids( $ids, $do_run, $verbose, $total_scanned, $total_changed, $total_p_unwrapped, $limit );
                $paged++;
            }
        }

        \WP_CLI::log( "\n=== Résumé ===" );
        \WP_CLI::log( "Scannés                          : {$total_scanned}" );
        \WP_CLI::log( "Posts affectés                   : {$total_changed}" );
        \WP_CLI::log( "Balises <p> retirées (dans <li>) : {$total_p_unwrapped}" );
        \WP_CLI::success( $do_run ? 'RUN terminé.' : 'Dry-run terminé.' );
    }

    private function process_ids( $ids, $do_run, $verbose, &$total_scanned, &$total_changed, &$total_p_unwrapped, $limit ) {
        $bar = \WP_CLI\Utils\make_progress_bar( 'Traitement', count( $ids ) );
        foreach ( $ids as $pid ) {
            $total_scanned++;
            $old = get_post_field( 'post_content', $pid );
            if ( $old !== '' && $old !== null ) {
                $res = $this->unwrap_p_inside_li( $old );
                if ( is_array( $res ) ) {
                    list( $new, $removed ) = $res;
                    if ( $removed > 0 && $new !== $old ) {
                        if ( $verbose ) {
                            \WP_CLI::log( "[ID {$pid}] <p> retirés: {$removed}" );
                        }
                        if ( $do_run ) {
                            $r = wp_update_post( [
                                'ID'           => $pid,
                                'post_content' => $new,
                            ], true );
                            if ( is_wp_error( $r ) ) {
                                \WP_CLI::warning( "[ID {$pid}] ERREUR: " . $r->get_error_message() );
                            } else {
                                $total_changed++;
                                $total_p_unwrapped += (int) $removed;
                            }
                        } else {
                            $total_changed++;
                            $total_p_unwrapped += (int) $removed;
                        }
                    }
                }
            }
            $bar->tick();
            if ( $limit && $total_scanned >= $limit ) {
                $bar->finish();
                return;
            }
        }
        $bar->finish();
    }

    /**
     * Déroule (unwrap) les <p> situés à l'intérieur de <li>, en conservant le contenu.
     * Retourne [string $new_html, int $removed] ou $html tel quel si échec.
     */
    private function unwrap_p_inside_li( $html ) {
        if ( $html === '' || $html === null ) return $html;

        $wrapped = '<div id="__wrap__">'.$html.'</div>';

        $prev = libxml_use_internal_errors(true);
        $dom  = new \DOMDocument('1.0', 'UTF-8');

        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        if ( ! $loaded ) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            return $html;
        }

        $xpath   = new \DOMXPath($dom);
        $p_nodes = $xpath->query('//li//p'); // tous les <p> descendants de <li>
        $changed = 0;

        for ( $i = $p_nodes->length - 1; $i >= 0; $i-- ) {
            /** @var \DOMElement $p */
            $p = $p_nodes->item($i);
            $parent = $p->parentNode;
            while ( $p->firstChild ) {
                $parent->insertBefore( $p->firstChild, $p );
            }
            $parent->removeChild( $p );
            $changed++;
        }

        $container = $dom->getElementById('__wrap__');
        $new_html  = '';
        if ( $container ) {
            foreach ( $container->childNodes as $child ) {
                $new_html .= $dom->saveHTML($child);
            }
        } else {
            $new_html = $dom->saveHTML();
        }

        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return [ $new_html, $changed ];
    }
}

\WP_CLI::add_command( 'alprod fix-li-p', [ new ALProd_Fix_LI_Paragraphs_Command(), 'fix_li_p' ] );
