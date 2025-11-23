<?php
if ( ! class_exists('WP_CLI') ) { fwrite(STDERR, "Run via WP-CLI with --require\n"); return; }

class ALProd_Restore_Attrs_Multi_Command {

    private $post_type    = 'al_product';
    private $tax_attr     = 'al_product-attributes';
    private $lang_labels  = [
        'fr' => ['MARQUE','TENSION','TYPE'],
        'en' => ['BRAND','VOLTAGE','CATEGORY'],
        'es' => ['MARCA','TENSIÓN','TIPO'],
    ];

    /**
     * alprod restore-attrs --file=/path/products_xx.json [--lang=fr|en|es] [--dry-run=1]
     */
    public function __invoke( $args, $assoc ) {
        $file    = $assoc['file'] ?? null;
        $langCli = isset($assoc['lang']) ? $this->normalize_lang($assoc['lang']) : '';
        $dry     = !empty($assoc['dry-run']);

        if (!$file || !file_exists($file)) {
            WP_CLI::error("Missing or unreadable --file");
        }

        $rows = json_decode(file_get_contents($file), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($rows)) {
            WP_CLI::error("JSON parse error or root not an array");
        }

        // Déduire la langue si non fournie
        $lang = $langCli;
        if ($lang === '') {
            $lang = $this->guess_lang_from_rows_or_filename($rows, $file);
        }
        if (!in_array($lang, ['fr','en','es'], true)) {
            WP_CLI::error("Unsupported lang. Use --lang=fr|en|es or name your file products_fr.json / _en / _es.");
        }

        // Vérif taxonomie
        if ( ! taxonomy_exists($this->tax_attr) ) {
            WP_CLI::error("Taxonomy '{$this->tax_attr}' does not exist.");
        }

        // Préparer les parents (labels) pour cette langue
        [$label1, $label2, $label3] = $this->lang_labels[$lang];
        $p1 = $this->ensure_parent_label($label1, $lang);
        $p2 = $this->ensure_parent_label($label2, $lang);
        $p3 = $this->ensure_parent_label($label3, $lang);

        $done=0; $skip=0; $err=0;

        foreach ($rows as $i => $row) {
            if (!is_array($row)) { $skip++; continue; }

            $meta = (array)($row['meta'] ?? []);
            $v1   = isset($meta['_attribute1']) ? trim((string)$meta['_attribute1']) : '';
            $v2   = isset($meta['_attribute2']) ? trim((string)$meta['_attribute2']) : '';
            $v3   = isset($meta['_attribute3']) ? trim((string)$meta['_attribute3']) : '';

            // Rien à écrire → skip
            if ($v1==='' && $v2==='' && $v3==='') { $skip++; continue; }

            // Trouver le post cible
            $target_id = $this->resolve_target_post_id($row, $lang);
            if (!$target_id) { $skip++; continue; }

            // Construire la liste d’enfants (valeurs) à assigner
            $child_ids = [];

            if ($v1!=='') $child_ids[] = $this->ensure_value_term($p1, $v1, $lang);
            if ($v2!=='') $child_ids[] = $this->ensure_value_term($p2, $v2, $lang);
            if ($v3!=='') $child_ids[] = $this->ensure_value_term($p3, $v3, $lang);

            $child_ids = array_values(array_filter(array_map('intval',$child_ids), fn($x)=>$x>0));
            if (empty($child_ids)) { $skip++; continue; }

            if ($dry) {
                WP_CLI::log("[DRY] #{$target_id} set {$this->tax_attr} -> ".json_encode($child_ids));
                $done++; continue;
            }

            $res = wp_set_object_terms($target_id, $child_ids, $this->tax_attr, false);
            if (is_wp_error($res)) {
                $err++; WP_CLI::warning("[ERR] set terms on #{$target_id}: ".$res->get_error_message());
                continue;
            }

            // “Finalize” léger pour rafraîchir le front
            $this->touch_and_flush($target_id);

            WP_CLI::log("[OK] #{$target_id} {$this->tax_attr} -> ".json_encode($child_ids));
            $done++;
        }

        WP_CLI::success("Done={$done} | Skipped={$skip} | Errors={$err}");
    }

    /* ================= Helpers ================= */

    private function normalize_lang($s) {
        $s = strtolower(trim((string)$s));
        $map = [
            'fr'=>'fr','fr-fr'=>'fr','french'=>'fr','français'=>'fr',
            'en'=>'en','en-us'=>'en','en-gb'=>'en','english'=>'en','anglais'=>'en',
            'es'=>'es','es-es'=>'es','spanish'=>'es','español'=>'es','espagnol'=>'es',
        ];
        return $map[$s] ?? $s;
    }

    private function guess_lang_from_rows_or_filename(array $rows, string $file) : string {
        // 1) lire lang dans le JSON
        foreach ($rows as $r) {
            if (isset($r['lang']) && is_string($r['lang'])) {
                $c = $this->normalize_lang($r['lang']);
                if (in_array($c,['fr','en','es'],true)) return $c;
            }
        }
        // 2) déduire du nom de fichier
        $fn = strtolower($file);
        if (str_contains($fn, '_fr')) return 'fr';
        if (str_contains($fn, '_en')) return 'en';
        if (str_contains($fn, '_es')) return 'es';
        return '';
    }

    private function ensure_parent_label(string $label, string $lang) : int {
        // On essaie de retrouver par nom exact dans cette taxo
        $found = get_terms([
            'taxonomy'   => $this->tax_attr,
            'hide_empty' => false,
            'name'       => $label,
            'number'     => 1,
            'parent'     => 0,
        ]);
        if (!is_wp_error($found) && !empty($found)) {
            $tid = (int)$found[0]->term_id;
            $this->maybe_set_term_lang($tid, $lang);
            return $tid;
        }
        // Sinon on crée
        $slug = sanitize_title($label).'-'.$lang;
        $ins  = wp_insert_term($label, $this->tax_attr, ['slug'=>$slug]);
        if (is_wp_error($ins)) {
            // dernier recours: prendre un parent existant quelconque
            $any = get_terms(['taxonomy'=>$this->tax_attr,'hide_empty'=>false,'parent'=>0,'number'=>1]);
            return (!is_wp_error($any) && !empty($any)) ? (int)$any[0]->term_id : 0;
        }
        $tid = (int)$ins['term_id'];
        $this->maybe_set_term_lang($tid, $lang);
        return $tid;
    }

    private function ensure_value_term(int $parent_id, string $value, string $lang) : int {
        if ($parent_id <= 0 || $value === '') return 0;

        // Chercher enfant “value” sous ce parent
        $existing = get_terms([
            'taxonomy'   => $this->tax_attr,
            'hide_empty' => false,
            'name'       => $value,
            'parent'     => $parent_id,
            'number'     => 1,
        ]);
        if (!is_wp_error($existing) && !empty($existing)) {
            $tid = (int)$existing[0]->term_id;
            $this->maybe_set_term_lang($tid, $lang);
            return $tid;
        }

        // Créer enfant
        $slug = sanitize_title($value).'-'.$lang;
        $ins  = wp_insert_term($value, $this->tax_attr, ['slug'=>$slug, 'parent'=>$parent_id]);
        if (is_wp_error($ins)) {
            return 0;
        }
        $tid = (int)$ins['term_id'];
        $this->maybe_set_term_lang($tid, $lang);
        return $tid;
    }

    private function maybe_set_term_lang(int $term_id, string $lang) : void {
        if ($term_id<=0 || $lang==='') return;
        if (function_exists('pll_set_term_language')) {
            // Pose la langue si elle n’est pas déjà posée
            $cur = function_exists('pll_get_term_language') ? pll_get_term_language($term_id) : '';
            if (!$cur) {
                @pll_set_term_language($term_id, $lang);
            }
        }
    }

    private function resolve_target_post_id(array $row, string $lang) : int {
        // 1) id direct
        if (!empty($row['id']) && intval($row['id'])>0) {
            $p = get_post((int)$row['id']);
            if ($p && $p->post_type === $this->post_type) return (int)$row['id'];
        }
        // 2) translations[lang]
        if (!empty($row['translations']) && is_array($row['translations'])) {
            $tid = intval($row['translations'][$lang] ?? 0);
            if ($tid>0) {
                $p = get_post($tid);
                if ($p && $p->post_type === $this->post_type) return $tid;
            }
        }
        // 3) source_id + Polylang
        if (!empty($row['source_id']) && function_exists('pll_get_post')) {
            $t = pll_get_post((int)$row['source_id'], $lang);
            if ($t) return (int)$t;
        }
        // 4) slug
        if (!empty($row['slug'])) {
            $post = get_page_by_path(sanitize_title($row['slug']), OBJECT, $this->post_type);
            if ($post) {
                if (function_exists('pll_get_post_language')) {
                    $lc = pll_get_post_language($post->ID);
                    if ($lc && strtolower($lc) !== $lang) {
                        return 0;
                    }
                }
                return (int)$post->ID;
            }
        }
        return 0;
    }

    private function touch_and_flush(int $post_id) : void {
        // petit “touch”
        update_post_meta($post_id, '_alprod_restore_touch', microtime(true));

        // émettre quelques hooks courants
        if ($post = get_post($post_id)) {
            do_action('edit_post',          $post_id, $post);
            do_action('save_post',          $post_id, $post, true);
            do_action("save_post_{$post->post_type}", $post_id, $post, true);
            do_action('wp_insert_post',     $post_id, $post, true);
        }

        // caches
        clean_object_term_cache($post_id, $this->post_type);
        clean_post_cache($post_id);

        // ImpleCode si dispo
        if (function_exists('ic_update_product')) @ic_update_product($post_id);
        if (function_exists('ic_rebuild_product')) @ic_rebuild_product($post_id);
        if (function_exists('ic_clear_product_cache')) @ic_clear_product_cache($post_id);
        if (function_exists('ic_flush_product_cache')) @ic_flush_product_cache($post_id);
    }
}

WP_CLI::add_command('alprod restore-attrs', 'ALProd_Restore_Attrs_Multi_Command');
