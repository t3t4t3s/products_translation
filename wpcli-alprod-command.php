<?php
if ( ! class_exists( 'WP_CLI' ) ) { fwrite(STDERR, "Load via WP-CLI with --require\n"); return; }

class AL_Product_Importer_Command {
    private $post_type       = 'al_product';
    private $language_tax    = 'language';
    private $source_id_key   = '_source_id';
    private $allowed_status  = [ 'publish','draft','pending','private','future' ];


    /**
     * Assigne la taxo des catégories depuis le JSON (clé al_product-cat_ids[lang]),
     * sinon ne fait rien. (Tu l’as peut-être déjà — garde ta version si elle existe.)
     */
    private function assign_categories_from_ids( int $post_id, array $row, string $lang, bool $debug = false ): void {
        $tax = 'al_product-cat';

        if (!taxonomy_exists($tax)) {
            if ($debug) WP_CLI::warning("[TAX] Taxonomy '{$tax}' inexistante, skip.");
            return;
        }

        if (function_exists('normalize_lang_code')) $lang = normalize_lang_code($lang);
        else $lang = strtolower(trim((string)$lang));

        $key = $tax . '_ids'; // al_product-cat_ids
        if (empty($row[$key]) || !is_array($row[$key]) || empty($row[$key][$lang])) {
            if ($debug) WP_CLI::log("[TAX] Pas d'IDs {$key}[{$lang}] dans payload, skip.");
            return;
        }

        $ids = array_values(array_filter(array_map('intval', (array)$row[$key][$lang]), static fn($v)=>$v>0));
        if (empty($ids)) { if ($debug) WP_CLI::log("[TAX] Liste vide pour {$key}[{$lang}]"); return; }

        $valid = [];
        foreach ($ids as $tid) {
            $t = get_term($tid, $tax);
            if ($t && !is_wp_error($t)) $valid[] = $tid;
            elseif ($debug) WP_CLI::log("[TAX] Term inexistant: {$tax}#{$tid}");
        }
        if (empty($valid)) { if ($debug) WP_CLI::log("[TAX] Aucun term valide"); return; }

        $res = wp_set_object_terms($post_id, $valid, $tax, false);
        if (is_wp_error($res)) WP_CLI::warning("[TAX] Erreur assignation {$tax} sur #{$post_id}: ".$res->get_error_message());
        else if ($debug) WP_CLI::log("[TAX] Set {$tax} on #{$post_id} ids=".json_encode($valid));
    }

    /**
     * Assigne la taxo des attributs (al_product-attributes)
     * 1) Priorité aux IDs explicites al_product-attributes_ids[lang]
     * 2) Sinon map via tax.al_product-attributes (ids FR) -> pll_get_term(..., lang)
     */
    private function assign_attributes_from_row( int $post_id, array $row, string $lang, bool $debug = false ): void {
        $tax = 'al_product-attributes';

        if (!taxonomy_exists($tax)) {
            if ($debug) WP_CLI::warning("[ATTR] Taxonomy '{$tax}' inexistante, skip.");
            return;
        }

        if (function_exists('normalize_lang_code')) $lang = normalize_lang_code($lang);
        else $lang = strtolower(trim((string)$lang));

        // ---- 0) Si le JSON fournit déjà des IDs par langue, on les utilise tels quels
        $ids_key = $tax . '_ids'; // ex: al_product-attributes_ids
        if (!empty($row[$ids_key]) && is_array($row[$ids_key]) && !empty($row[$ids_key][$lang])) {
            $ids = array_values(array_filter(array_map('intval', (array)$row[$ids_key][$lang]), static fn($v)=>$v>0));
            $valid = [];
            foreach ($ids as $tid) {
                $t = get_term($tid, $tax);
                if ($t && !is_wp_error($t)) $valid[] = $tid;
            }
            if (!empty($valid)) {
                $res = wp_set_object_terms($post_id, $valid, $tax, false);
                if (is_wp_error($res)) WP_CLI::warning("[ATTR] Erreur assignation {$tax} sur #{$post_id}: ".$res->get_error_message());
                elseif ($debug) WP_CLI::log("[ATTR] Set {$tax} on #{$post_id} ids=".json_encode($valid));
                return;
            }
            if ($debug) WP_CLI::log("[ATTR] {$ids_key}[{$lang}] fourni mais aucun ID valide; on tente les fallbacks.");
        }

        // ---- 1) Récup FR depuis le JSON (tax.al_product-attributes : objets {id,slug,name})
        $fr_terms = [];
        if (!empty($row['tax'][$tax]) && is_array($row['tax'][$tax])) {
            foreach ($row['tax'][$tax] as $t) {
                if (!is_array($t)) continue;
                $fr_terms[] = [
                    'id'   => isset($t['id'])   ? (int)$t['id']   : 0,
                    'slug' => isset($t['slug']) ? (string)$t['slug'] : '',
                    'name' => isset($t['name']) ? (string)$t['name'] : '',
                ];
            }
        }
        if ($debug) WP_CLI::log("[ATTR] Fallback via tax.{$tax} FR terms=".json_encode($fr_terms));

        if (empty($fr_terms)) {
            if ($debug) WP_CLI::log("[ATTR] Aucun terme FR dans le payload; rien à faire.");
            return;
        }

        // ---- 2) Pour chaque terme FR, on recherche sa contrepartie EN/ES :
        //         a) via pll_get_term(fr_id, lang)
        //         b) sinon par slug (ou name) dans la langue cible
        //         c) sinon on CRÉE le terme (lang), on le relie au FR via Polylang, puis on l’utilise
        $target_ids = [];

        foreach ($fr_terms as $fr) {
            $fr_id = $fr['id'] ?? 0;
            $slug  = $fr['slug'] ?? '';
            $name  = $fr['name'] ?? '';

            $target_id = 0;

            // a) mapping Polylang direct
            if ($fr_id && function_exists('pll_get_term')) {
                $mapped = pll_get_term($fr_id, $lang);
                if ($mapped) {
                    $target_id = (int)$mapped;
                    if ($debug) WP_CLI::log("[ATTR] pll_get_term ok: FR#{$fr_id} -> {$lang}#{$target_id}");
                } else {
                    if ($debug) WP_CLI::log("[ATTR] No pll mapping for FR#{$fr_id} -> {$lang}");
                }
            }

            // b) recherche par slug ou name
            if (!$target_id) {
                // liste de candidats : slug puis name (éventuellement slug-en si tu suffixes)
                $cands = array_values(array_unique(array_filter([$slug, sanitize_title($name)])));
                foreach ($cands as $cand) {
                    // restreindre à la langue cible si possible (Polylang stocke la relation sur termmeta)
                    $term = get_terms([
                        'taxonomy'   => $tax,
                        'hide_empty' => false,
                        'slug'       => $cand,
                        'number'     => 1,
                    ]);
                    if (!is_wp_error($term) && !empty($term)) {
                        $tid = (int)$term[0]->term_id;
                        // Vérifier langue du terme si Polylang actif
                        if (function_exists('pll_get_term_language')) {
                            $tl = pll_get_term_language($tid);
                            if ($tl && strtolower($tl) !== $lang) {
                                if ($debug) WP_CLI::log("[ATTR] Found slug '{$cand}' but in lang={$tl}, skip");
                                // continue chercher un autre
                            } else {
                                $target_id = $tid;
                                if ($debug) WP_CLI::log("[ATTR] Found by slug/name '{$cand}' -> term#{$tid}");
                                break;
                            }
                        } else {
                            $target_id = $tid;
                            if ($debug) WP_CLI::log("[ATTR] Found by slug/name '{$cand}' -> term#{$tid} (no pll lang check)");
                            break;
                        }
                    }
                }
            }

            // c) auto-create & link si toujours rien
            if (!$target_id) {
                // crée le terme en langue cible (utilise le nom FR à défaut)
                $to_name = $name ?: $slug ?: ('attr-'.$fr_id);
                $to_slug = $slug ? "{$slug}-{$lang}" : sanitize_title($to_name)."-{$lang}";
                $ins = wp_insert_term($to_name, $tax, ['slug'=>$to_slug]);
                if (!is_wp_error($ins)) {
                    $target_id = (int)$ins['term_id'];
                    if ($debug) WP_CLI::log("[ATTR] Created term {$tax}#{$target_id} ({$lang}) from FR#{$fr_id} '{$to_name}'");

                    // pose langue & lien de traduction si Polylang
                    if (function_exists('pll_set_term_language') && function_exists('pll_save_term_translations')) {
                        pll_set_term_language($target_id, $lang);
                        // récupérer le groupe existant du FR
                        if ($fr_id) {
                            // construire la map existante
                            $map = [];
                            if (function_exists('pll_get_term_translations')) {
                                $map = (array)pll_get_term_translations($fr_id);
                            }
                            // injecter/écraser la langue cible
                            $map[$lang] = $target_id;
                            pll_save_term_translations($map);
                            if ($debug) WP_CLI::log("[ATTR] Linked FR#{$fr_id} <-> {$lang}#{$target_id}");
                        }
                    }
                } else {
                    if ($debug) WP_CLI::warning("[ATTR] wp_insert_term failed for '{$to_name}': ".$ins->get_error_message());
                }
            }

            if ($target_id) $target_ids[] = $target_id;
        }

        $target_ids = array_values(array_unique(array_filter($target_ids, static fn($v)=>$v>0)));
        if (empty($target_ids)) {
            if ($debug) WP_CLI::log("[ATTR] Rien d'assignable in fine.");
            return;
        }

        $res = wp_set_object_terms($post_id, $target_ids, $tax, false);
        if (is_wp_error($res)) WP_CLI::warning("[ATTR] Erreur assignation {$tax} sur #{$post_id}: ".$res->get_error_message());
        else if ($debug) WP_CLI::log("[ATTR] Set {$tax} on #{$post_id} final ids=".json_encode($target_ids));
    }


    /**
     * Force l’application (ImpleCode reconstruit ses données) + logs explicites
     */
    private function finalize_product_apply_attributes( int $post_id, bool $debug = false ): void {
        global $wpdb;

        $ptype = get_post_type($post_id);
        if ($debug) WP_CLI::log("[FINALIZE] start for #{$post_id}, type={$ptype}");

        // 0) "Touch" méta (au cas où des hooks s'en servent)
        update_post_meta($post_id, '_alprod_touch', microtime(true));

        // 1) Snapshot des dates pour ne pas polluer 'post_modified'
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE ID=%d",
            $post_id
        ), ARRAY_A );
        $orig_modified     = $row ? $row['post_modified']     : null;
        $orig_modified_gmt = $row ? $row['post_modified_gmt'] : null;

        // 2) Forcer un VRAI cycle de sauvegarde en modifiant puis restaurant un champ
        //    (ici 'post_content') pour que WordPress exécute toute la chaîne de hooks.
        $orig_content = get_post_field('post_content', $post_id);
        $step1 = wp_update_post(['ID'=>$post_id, 'post_content'=>$orig_content . ' '], true);
        if (is_wp_error($step1)) {
            WP_CLI::warning("[FINALIZE] step1 error on #{$post_id}: ".$step1->get_error_message());
        }
        $step2 = wp_update_post(['ID'=>$post_id, 'post_content'=>$orig_content], true);
        if (is_wp_error($step2)) {
            WP_CLI::warning("[FINALIZE] step2 error on #{$post_id}: ".$step2->get_error_message());
        }
        if ($debug) WP_CLI::log("[FINALIZE] two-stage wp_update_post done");

        // 3) Restaurer les dates modifiées pour ne pas “sale” l’historique
        if ($orig_modified !== null && $orig_modified_gmt !== null) {
            $wpdb->update(
                $wpdb->posts,
                ['post_modified'=>$orig_modified, 'post_modified_gmt'=>$orig_modified_gmt],
                ['ID'=>$post_id],
                ['%s','%s'],
                ['%d']
            );
            clean_post_cache($post_id);
            if ($debug) WP_CLI::log("[FINALIZE] restored post_modified on #{$post_id}");
        }

        // 4) Purges caches plugin (si présentes) + caches WP
        if (function_exists('ic_update_product')) {
            @ic_update_product($post_id);
            if ($debug) WP_CLI::log("[FINALIZE] ic_update_product({$post_id})");
        }
        if (function_exists('ic_rebuild_product')) {
            @ic_rebuild_product($post_id);
            if ($debug) WP_CLI::log("[FINALIZE] ic_rebuild_product({$post_id})");
        }
        if (function_exists('ic_clear_product_cache')) {
            @ic_clear_product_cache($post_id);
            if ($debug) WP_CLI::log("[FINALIZE] ic_clear_product_cache({$post_id})");
        }
        if (function_exists('ic_flush_product_cache')) {
            @ic_flush_product_cache($post_id);
            if ($debug) WP_CLI::log("[FINALIZE] ic_flush_product_cache({$post_id})");
        }

        // Transients ImpleCode potentiels (purge large mais sûre)
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ic\_%' OR option_name LIKE '\_transient\_timeout\_ic\_%' OR option_name LIKE '\_transient\_epc\_%' OR option_name LIKE '\_transient\_timeout\_epc\_%'" );

        if ($ptype) clean_object_term_cache($post_id, $ptype);
        clean_post_cache($post_id);
        if ($debug) WP_CLI::log("[FINALIZE] caches cleaned");
    }



    /** =======================
     *  Image helpers
     *  ======================= */
    private function ensure_attachment_from_url( $url, $post_id = 0, $title = '', $alt = '', $caption = '', $lang = '' ) {
        if ( ! $url ) return 0;
        // Tente de retrouver un attachment existant par URL
        if ( function_exists('attachment_url_to_postid') ) {
            $aid = attachment_url_to_postid( $url );
            if ($aid) {
                $is_en = (strtolower((string)$lang) === 'en');
                if ( $is_en && $title !== '' )   wp_update_post(['ID'=>$aid, 'post_title'=>$title]);
                if ( $is_en && $caption !== '' ) wp_update_post(['ID'=>$aid, 'post_excerpt'=>$caption]);
                if ( $is_en && $alt !== '' )     update_post_meta($aid, '_wp_attachment_image_alt', $alt);
                if ( $post_id )                  wp_update_post(['ID'=>$aid, 'post_parent'=>$post_id]);
                return (int)$aid;
            }
        }
        // Sinon, on sideload
        if ( ! function_exists('media_sideload_image') ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $att_id = media_sideload_image( $url, $post_id, $title, 'id' );
        if ( is_wp_error($att_id) ) return 0;
        $att_id = (int)$att_id;
        if ( $alt !== '' )     update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
        if ( $caption !== '' ) wp_update_post(['ID'=>$att_id, 'post_excerpt'=>$caption]);
        return $att_id;
        // Assure aussi le title après sideload
        if ( $title !== '' ) wp_update_post(['ID'=>$att_id, 'post_title'=>$title]);

    }

    private function import_images_for_row( $post_id, $row, $lang = '' ) {
        if ( !is_array($row) ) return;
        // Image mise en avant
        if ( isset($row['image']) && is_array($row['image']) ) {
            $img = $row['image'];
            $url = isset($img['url']) ? (string)$img['url'] : '';
            $title = isset($img['title']) ? (string)$img['title'] : '';
            $alt = isset($img['alt']) ? (string)$img['alt'] : '';
            $caption = isset($img['caption']) ? (string)$img['caption'] : '';
            if ( $url !== '' ) {
                $aid = $this->ensure_attachment_from_url( $url, $post_id, $title, $alt, $caption, $lang );
                if ( $aid ) {
                    set_post_thumbnail( $post_id, $aid );
                }
            }
        }
        // Galerie
        $gallery_ids = [];
        if ( isset($row['images']) && is_array($row['images']) ) {
            foreach ( $row['images'] as $g ) {
                if ( !is_array($g) ) continue;
                $url = isset($g['url']) ? (string)$g['url'] : '';
                if ( $url === '' ) continue;
                $title   = isset($g['title']) ? (string)$g['title'] : '';
                $alt     = isset($g['alt']) ? (string)$g['alt'] : '';
                $caption = isset($g['caption']) ? (string)$g['caption'] : '';
                $aid = $this->ensure_attachment_from_url( $url, $post_id, $title, $alt, $caption, $lang );
                if ( $aid ) $gallery_ids[] = (int)$aid;
            }
        }
        if ( !empty($gallery_ids) ) {
            // Stocke en meta pour usage thème/plugin (clé générique)
            update_post_meta( $post_id, '_gallery_ids', $gallery_ids );
        }
    }
public function import( $args, $assoc ) {
        $file              = $assoc['file'] ?? null;
        $do_update         = isset($assoc['update']) ? (int)$assoc['update'] : 0;
        $update_if_changed = isset($assoc['update-if-changed']) ? (int)$assoc['update-if-changed'] : 0;
        $id_only           = isset($assoc['id-only']) ? (int)$assoc['id-only'] : 0;
        $prefer_id         = isset($assoc['prefer-id']) ? (int)$assoc['prefer-id'] : 1;
        $pres_slug         = isset($assoc['preserve-slug']) ? (int)$assoc['preserve-slug'] : 0;
        $skip_empty        = isset($assoc['skip-empty']) ? (int)$assoc['skip-empty'] : 0;
        $dry               = isset($assoc['dry-run']) ? (int)$assoc['dry-run'] : 0;
        $author            = isset($assoc['author']) ? (int)$assoc['author'] : get_current_user_id();
        $status            = $assoc['status'] ?? 'draft';
        $tax_override      = $assoc['tax-language'] ?? '';
        $link_sib          = isset($assoc['link-siblings']) ? (int)$assoc['link-siblings'] : 0;
        $debug_link        = isset($assoc['debug-linking']) ? (int)$assoc['debug-linking'] : 0;
        $create_suffix     = $assoc['create-slug-suffix'] ?? '';

        if ( ! $file )               WP_CLI::error("Missing --file=<path>");
        if ( ! file_exists($file) )  WP_CLI::error("File not found: {$file}");
        if ( ! in_array($status, $this->allowed_status, true) ) WP_CLI::error("Invalid --status: {$status}");

        $data = json_decode(file_get_contents($file), true);
        if (json_last_error() !== JSON_ERROR_NONE) WP_CLI::error("JSON decode error: ".json_last_error_msg());
        if ( ! is_array($data) ) WP_CLI::error("JSON root must be array");

        $link_groups = [];
        $touched_src = [];
        $imported=0; $updated=0; $skipped=0; $errors=0;

        foreach ($data as $idx => $row) {
            if ( ! is_array($row) ) { $skipped++; continue; }

            $row_id    = isset($row['id']) ? (int)$row['id'] : 0;
            $name      = (string)($row['name'] ?? '');
            $slug_in   = isset($row['slug']) ? sanitize_title($row['slug']) : '';
            $pst_stat  = (string)($row['status'] ?? $status);
            $content   = (string)($row['content_long'] ?? '');
            $excerpt   = (string)($row['content_short'] ?? '');
            $meta      = (array)($row['meta'] ?? []);
            $tax       = (array)($row['tax'] ?? []);
            $source_id = $row['source_id'] ?? null;
            $json_lang = isset($row['lang']) ? strtolower(trim($row['lang'])) : '';

            if ($name === '') { $skipped++; continue; }

            $target_code = '';
            if ($json_lang) {
                $target_code = $this->normalize_lang_code($json_lang);
            } elseif ($tax_override !== '') {
                $parts = array_map('trim', explode(',', $tax_override));
                if (!empty($parts)) $target_code = $this->lang_name_to_code($parts[0]) ?: $this->normalize_lang_code($parts[0]);
            } elseif (isset($tax['language']) && !empty($tax['language'])) {
                $target_code = $this->lang_name_to_code((string)$tax['language'][0]);
            }

            $existing_id = 0;
            if ($prefer_id && $row_id > 0) {
                $p = get_post($row_id);
                if ($p && $p->post_type === $this->post_type) $existing_id = $row_id;
            }
            if (!$existing_id && $source_id) {
                $siblings = get_posts([
                    'post_type'      => $this->post_type,
                    'post_status'    => 'any',
                    'posts_per_page' => -1,
                    'meta_key'       => $this->source_id_key,
                    'meta_value'     => $source_id,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ]);
                if (!empty($siblings)) {
                    foreach ($siblings as $sid) {
                        $code = $this->get_post_lang_code($sid);
                        if ($target_code && $code && $this->codes_match($code, $target_code)) {
                            $existing_id = (int)$sid;
                            break;
                        }
                    }
                }
            }
            if (!$existing_id && $slug_in) {
                $post = get_page_by_path($slug_in, OBJECT, $this->post_type);
                if ($post) {
                    $match_id = (int)$post->ID;
                    $match_code = $this->get_post_lang_code($match_id);
                    if ($target_code && $match_code && $this->codes_match($match_code, $target_code)) {
                        $existing_id = $match_id;
                    }
                }
            }

            if (!$existing_id && $id_only) { WP_CLI::log("[SKIP] No target found for row #{$idx} (id-only=1)."); $skipped++; continue; }

            $postarr = [
                'post_type'   => $this->post_type,
                'post_title'  => $name,
                'post_status' => in_array($pst_stat, $this->allowed_status, true) ? $pst_stat : $status,
                'post_author' => $author,
            ];
            if ($existing_id) {
                $postarr['ID'] = $existing_id;
                if (!$pres_slug) $postarr['post_name'] = $slug_in ?: sanitize_title($name);
            } else {
                $candidate = $slug_in ?: sanitize_title($name);
                if ($create_suffix !== '') $candidate = $this->append_suffix($candidate, $create_suffix);
                $postarr['post_name'] = sanitize_title($candidate);
            }
            if (!($skip_empty && $content === '')) $postarr['post_content'] = $content;
            if (!($skip_empty && $excerpt === '')) $postarr['post_excerpt'] = $excerpt;

            // ----- CREATE / UPDATE -----
            $post_id = 0; $action='create'; $actual_id = 0;
            if ($existing_id) {
                if ($do_update) {
                    if (!empty($update_if_changed) && $this->is_unchanged($existing_id, $postarr, $meta, $pres_slug)) {
                        WP_CLI::log("[SKIP] Unchanged ID {$existing_id}");
                        $skipped++; continue; // no AFTER_WRITE
                    }
                    if ($dry) {
                        WP_CLI::log("[DRY-RUN][UPDATE] #{$existing_id} {$postarr['post_title']} (slug: ".($postarr['post_name'] ?? '(preserve)').")");
                        $updated++; continue; // no AFTER_WRITE
                    }
                    $post_id = wp_update_post($postarr, true); $action='update';
                    if (is_wp_error($post_id)) { $errors++; WP_CLI::warning(strtoupper($action)." ERROR: ".$post_id->get_error_message()); continue; }
                    $actual_id = (int)$post_id;

                    // assigner les catégories traduites (al_product-cat_ids[lang]) aussi en UPDATE
                    // Langue depuis $row + normalisation
                    $__prod_lang = !empty($row['lang']) && is_string($row['lang']) ? $row['lang'] : '';
                    $__prod_lang = function_exists('normalize_lang_code') ? normalize_lang_code($__prod_lang) : strtolower(trim($__prod_lang));

                    // Debug commun (réutilise ton flag)
                    $__debug_tax = !empty($assoc_args['debug-linking']) || !empty($assoc_args['debug-tax']);

                } else {
                    WP_CLI::log("[SKIP] Existing {$existing_id} and --update=0"); $skipped++; continue;
                }
            } else {
                if ($dry) {
                    WP_CLI::log("[DRY-RUN][CREATE] {$postarr['post_title']} (slug: {$postarr['post_name']})");
                    $imported++; continue; // no AFTER_WRITE
                }
                $post_id = wp_insert_post($postarr, true); $action='create';
                if (is_wp_error($post_id)) { $errors++; WP_CLI::warning(strtoupper($action)." ERROR: ".$post_id->get_error_message()); continue; }
                $actual_id = (int)$post_id;

                // assigner les catégories traduites (al_product-cat_ids[lang]) en CREATE
                // Langue depuis $row + normalisation
                $__prod_lang = !empty($row['lang']) && is_string($row['lang']) ? $row['lang'] : '';
                $__prod_lang = function_exists('normalize_lang_code') ? normalize_lang_code($__prod_lang) : strtolower(trim($__prod_lang));

                // Debug commun
                $__debug_tax = !empty($assoc_args['debug-linking']) || !empty($assoc_args['debug-tax']);

            }

            // ----- Post-write steps (only when we really wrote) -----
            if ($source_id) {
                update_post_meta($actual_id, $this->source_id_key, $source_id);
                $touched_src[$source_id] = true;
            }
            foreach ($meta as $k=>$v) {
                if ($k==='') continue;
                if ($skip_empty && ($v==='' || $v===null)) continue;
                if (is_array($v) || is_object($v)) $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
                update_post_meta($actual_id, $k, $v);
            }

            // >>> Ajouts : langue / linking / taxos / FINALIZE par item
            $__prod_lang = !empty($row['lang']) && is_string($row['lang']) ? $row['lang'] : '';
            $__prod_lang = function_exists('normalize_lang_code') ? normalize_lang_code($__prod_lang) : strtolower(trim($__prod_lang));
            $__debug_any = true; // force logs pendant les tests; mets false ensuite si tu veux

            // Pose de la langue
            if ( !empty($__prod_lang) && function_exists('pll_set_post_language') ) {
                pll_set_post_language($actual_id, $__prod_lang);
                if ($__debug_any) WP_CLI::log("[DEBUG] set lang {$__prod_lang} on ID {$actual_id}");
            }

            // Linking interlangues (si option)
            if ( !empty($assoc_args['link-siblings']) && method_exists($this, 'link_with_siblings') ) {
                $this->link_with_siblings($actual_id, $row, $__prod_lang, $__debug_any);
            }

            // Catégories traduites (si export les fournit)
            if (method_exists($this, 'assign_categories_from_ids')) {
                $this->assign_categories_from_ids($actual_id, $row, $__prod_lang, $__debug_any);
            }

            // Attributs traduits
            if (method_exists($this, 'assign_attributes_from_row')) {
                $this->assign_attributes_from_row($actual_id, $row, $__prod_lang, $__debug_any);
            }

            // FINALIZE: appliquer immédiatement côté ImpleCode
            $this->finalize_product_apply_attributes($actual_id, $__debug_any);

            
            // Images (mise en avant + galerie)
            $this->import_images_for_row( $actual_id, $row, $json_lang );
            $lang_terms = [];
            if ($tax_override!=='') $lang_terms = array_map('trim', explode(',', $tax_override));
            elseif (isset($tax['language'])) $lang_terms = (array)$tax['language'];
            if (!empty($lang_terms)) {
                $term_ids=[];
                foreach ($lang_terms as $nm) {
                    $nm = trim((string)$nm); if ($nm==='') continue;
                    $term = term_exists($nm, $this->language_tax);
                    if (0===$term || null===$term){
                        $ins = wp_insert_term($nm, $this->language_tax);
                        if (is_wp_error($ins)){
                            $term = term_exists($nm, $this->language_tax);
                            if ($term && !is_wp_error($term)) $term_ids[]=(int)$term['term_id'];
                            continue;
                        }
                        $term_ids[]=(int)$ins['term_id'];
                    } else { $term_ids[]=(int)$term['term_id']; }
                }
                if (!empty($term_ids)) wp_set_object_terms($actual_id, $term_ids, $this->language_tax, false);
            }

            if ($target_code && function_exists('pll_set_post_language')) {
                $pll_code = $this->resolve_pll_code($target_code);
                if ($pll_code) pll_set_post_language($actual_id, $pll_code);
            }

            // collect for linking
            $pll_hint  = '';
            if ($json_lang) { $pll_hint = $this->normalize_lang_code($json_lang); }
            elseif (!empty($lang_terms)) { $pll_hint = $this->lang_name_to_code($lang_terms[0]); }

            if ($link_sib && $source_id && $pll_hint && $actual_id) {
                if (!isset($link_groups[$source_id])) $link_groups[$source_id] = [];
                $link_groups[$source_id][$pll_hint] = (int)$actual_id;
            }

            WP_CLI::log("[".strtoupper($action)."] ID {$actual_id} - {$postarr['post_title']} (status: {$postarr['post_status']})");
            if     ($action==='create') $imported++;
            elseif ($action==='update') $updated++;
        }

        // Linking phase
        if ($link_sib) {
            if ( ! function_exists('pll_set_post_language') || ! function_exists('pll_save_post_translations') || ! function_exists('pll_get_post_language') ) {
                WP_CLI::warning("Polylang not available; skipping --link-siblings.");
            } else {
                $existing_codes = function_exists('pll_languages_list') ? (array)pll_languages_list(['fields'=>'slug']) : [];
                $all_src_ids = array_unique(array_merge(array_keys($link_groups), array_keys($touched_src)));
                foreach ($all_src_ids as $src_id) {
                    $siblings = get_posts([
                        'post_type'      => $this->post_type,
                        'post_status'    => 'any',
                        'posts_per_page' => -1,
                        'meta_key'       => $this->source_id_key,
                        'meta_value'     => $src_id,
                        'fields'         => 'ids',
                        'no_found_rows'  => true,
                    ]);
                    if (is_numeric($src_id)) {
                        $src_post = get_post((int)$src_id);
                        if ($src_post && $src_post->post_type === $this->post_type && !in_array((int)$src_post->ID, $siblings, true)) {
                            $siblings[] = (int)$src_post->ID;
                        }
                    }
                    if (empty($siblings)) { continue; }

                    $map = [];
                    foreach ($siblings as $pid) {
                        $code = $this->get_post_lang_code($pid);
                        if (!$code) {
                            $terms = wp_get_object_terms($pid, $this->language_tax, ['fields'=>'names']);
                            if (!empty($terms)) {
                                $guess = $this->resolve_pll_code($this->lang_name_to_code($terms[0]));
                                if ($guess) { pll_set_post_language($pid, $guess); $code = $guess; }
                            }
                        }
                        if ($code) $map[$code] = (int)$pid;
                    }
                    if (count($map) < 2) continue;
                    pll_save_post_translations($map);
                    WP_CLI::log("[LINKED] source_id={$src_id} -> ". json_encode($map));
                }
            }
        }

        WP_CLI::success("Imported: {$imported} | Updated: {$updated} | Skipped: {$skipped} | Errors: {$errors}");
    }

    private function get_post_lang_code( $post_id ) {
        $code = '';
        if (function_exists('pll_get_post_language')) {
            $code = (string)pll_get_post_language($post_id);
        }
        return $code;
    }
    private function codes_match( $site_code, $hint ) {
        $site_code = strtolower(trim($site_code));
        $hint      = strtolower(trim($hint));
        return ($site_code === $hint) || (strpos($site_code, $hint) === 0) || (strpos($hint, $site_code) === 0);
    }
    private function resolve_pll_code( $hint ) {
        if (!function_exists('pll_languages_list')) return $hint;
        $hint = strtolower(trim($hint));
        $codes = (array)pll_languages_list(['fields'=>'slug']);
        if (in_array($hint, $codes, true)) return $hint;
        foreach ($codes as $c) {
            if (strpos($c, $hint) === 0 || strpos($hint, $c) === 0) return $c;
        }
        return '';
    }
    private function normalize_lang_code($code) {
        $c = strtolower(trim($code));
        $lut = [
            'fr'=>'fr','fr-fr'=>'fr','french'=>'fr','français'=>'fr',
            'en'=>'en','en-us'=>'en','en-gb'=>'en','english'=>'en','anglais'=>'en',
            'es'=>'es','es-es'=>'es','spanish'=>'es','español'=>'es','espagnol'=>'es',
        ];
        return $lut[$c] ?? $c;
    }
    private function lang_name_to_code($name) {
        $n = strtolower(trim($name));
        $lut = [
            'fr'=>'fr','français'=>'fr','french'=>'fr',
            'en'=>'en','english'=>'en','anglais'=>'en',
            'es'=>'es','español'=>'es','spanish'=>'es','espagnol'=>'es',
        ];
        return $lut[$n] ?? '';
    }
    private function append_suffix( $slug, $suffix ) {
        $slug   = sanitize_title( (string)$slug );
        $suffix = trim( (string)$suffix );
        if ($suffix === '') return $slug;
        if ($suffix[0] !== '-') $suffix = '-' . $suffix;
        $suffix = preg_replace('/-+/', '-', $suffix);
        if (substr($slug, -strlen($suffix)) === $suffix) return $slug;
        return $slug . $suffix;
    }

    private function same_text($a, $b) {
        $a = (string)$a; $b = (string)$b;
        $a = trim(str_replace("\r\n", "\n", $a));
        $b = trim(str_replace("\r\n", "\n", $b));
        return $a === $b;
    }
    private function is_unchanged($existing_id, $postarr, $meta, $pres_slug) {
        $p = get_post($existing_id);
        if (!$p) return false;

        if (isset($postarr['post_title']) && !$this->same_text($p->post_title, $postarr['post_title'])) return false;
        if (isset($postarr['post_status']) && (string)$p->post_status !== (string)$postarr['post_status']) return false;
        if (isset($postarr['post_content']) && !$this->same_text($p->post_content, $postarr['post_content'])) return false;
        if (isset($postarr['post_excerpt']) && !$this->same_text($p->post_excerpt, $postarr['post_excerpt'])) return false;
        if (!$pres_slug && isset($postarr['post_name'])) {
            if ((string)$p->post_name !== (string)$postarr['post_name']) return false;
        }
        foreach ((array)$meta as $k => $v) {
            if ($k === '') continue;
            $existing = get_post_meta($existing_id, $k, true);
            if (is_array($v) || is_object($v)) $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
            if (!$this->same_text((string)$existing, (string)$v)) return false;
        }
        return true;
    }
}

WP_CLI::add_command('alprod', ['AL_Product_Importer_Command', 'import']);
