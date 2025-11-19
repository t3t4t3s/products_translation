<?php
if ( ! class_exists( 'WP_CLI' ) ) { fwrite(STDERR, "Load via WP-CLI with --require\n"); return; }

class AL_Product_Importer_Command {
    private $post_type       = 'al_product';
    private $language_tax    = 'language';
    private $source_id_key   = '_source_id';
    private $allowed_status  = [ 'publish','draft','pending','private','future' ];
    private $current_row_meta = [];



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

    // Crée/récupère un "label" (parent) par langue (EN/ES) dans la taxo d'attributs
    private function ensure_label_term(string $tax, string $label, string $lang, bool $debug=false): int {
        $label = trim($label);
        if ($label === '') return 0;

        $lang = $this->normalize_lang_code($lang);
        $slug = sanitize_title($label) . '-' . $lang;

        // 1) chercher par slug exact
        $found = get_terms([
            'taxonomy'   => $tax,
            'hide_empty' => false,
            'slug'       => $slug,
            'number'     => 1,
        ]);
        if (!is_wp_error($found) && !empty($found)) {
            $tid = (int)$found[0]->term_id;
            if ($found[0]->name !== $label) wp_update_term($tid, $tax, ['name'=>$label]);
            if (function_exists('pll_set_term_language')) pll_set_term_language($tid, $lang);
            if ($debug) WP_CLI::log("[ATTR-LABEL] reuse {$tax}#{$tid} '{$label}' ({$lang})");
            return $tid;
        }

        // 2) créer si absent
        $ins = wp_insert_term($label, $tax, ['slug'=>$slug]);
        if (is_wp_error($ins)) {
            if ($debug) WP_CLI::warning("[ATTR-LABEL] cannot create '{$label}': ".$ins->get_error_message());
            return 0;
        }
        $tid = (int)$ins['term_id'];
        if (function_exists('pll_set_term_language')) pll_set_term_language($tid, $lang);
        if ($debug) WP_CLI::log("[ATTR-LABEL] created {$tax}#{$tid} '{$label}' ({$lang})");
        return $tid;
    }

    // Crée/récupère une "valeur" enfant sous un label donné
    private function ensure_child_term(string $tax, int $parent_id, string $value, string $lang, bool $debug=false): int {
        $value = trim($value);
        if ($value === '' || $parent_id <= 0) return 0;

        $lang = $this->normalize_lang_code($lang);
        $slug = sanitize_title($value) . '-' . $lang;

        // 1) chercher par slug exact
        $found = get_terms([
            'taxonomy'   => $tax,
            'hide_empty' => false,
            'slug'       => $slug,
            'number'     => 1,
        ]);
        if (!is_wp_error($found) && !empty($found)) {
            $tid = (int)$found[0]->term_id;
            // s'assurer du parent
            if ((int)$found[0]->parent !== $parent_id) {
                wp_update_term($tid, $tax, ['parent'=>$parent_id]);
            }
            if ($found[0]->name !== $value) wp_update_term($tid, $tax, ['name'=>$value]);
            if (function_exists('pll_set_term_language')) pll_set_term_language($tid, $lang);
            if ($debug) WP_CLI::log("[ATTR-VALUE] reuse {$tax}#{$tid} '{$value}' -> parent#{$parent_id} ({$lang})");
            return $tid;
        }

        // 2) créer si absent
        $ins = wp_insert_term($value, $tax, ['slug'=>$slug, 'parent'=>$parent_id]);
        if (is_wp_error($ins)) {
            if ($debug) WP_CLI::warning("[ATTR-VALUE] cannot create '{$value}': ".$ins->get_error_message());
            return 0;
        }
        $tid = (int)$ins['term_id'];
        if (function_exists('pll_set_term_language')) pll_set_term_language($tid, $lang);
        if ($debug) WP_CLI::log("[ATTR-VALUE] created {$tax}#{$tid} '{$value}' -> parent#{$parent_id} ({$lang})");
        return $tid;
    }

    // Crée (ou retrouve) un terme par NOM en forçant la langue cible. Slug suffixé -{lang}
    private function ensure_term_by_name_lang( string $tax, string $name, string $lang, string $slug_hint = '' ) : int {
        $name = trim((string)$name);
        if ($name === '') return 0;

        $lang = $this->normalize_lang_code($lang);
        $slug_base = $slug_hint !== '' ? sanitize_title($slug_hint) : sanitize_title($name);
        $slug      = $slug_base . '-' . $lang;

        // 1) par slug exact
        $found = get_terms([
            'taxonomy'   => $tax,
            'hide_empty' => false,
            'slug'       => $slug,
            'number'     => 1,
        ]);
        if (!is_wp_error($found) && !empty($found)) {
            $tid = (int)$found[0]->term_id;
            if ($found[0]->name !== $name) wp_update_term($tid, $tax, ['name'=>$name]);
            if (function_exists('pll_set_term_language')) pll_set_term_language($tid, $lang);
            return $tid;
        }

        // 2) sinon crée
        $ins = wp_insert_term($name, $tax, ['slug'=>$slug]);
        if (is_wp_error($ins)) {
            // dernier recours: recherche "par nom"
            $by_name = get_terms([
                'taxonomy'   => $tax,
                'hide_empty' => false,
                'search'     => $name,
                'number'     => 1,
            ]);
            if (!is_wp_error($by_name) && !empty($by_name)) {
                $tid = (int)$by_name[0]->term_id;
                if (function_exists('pll_set_term_language')) pll_set_term_language($tid, $lang);
                return $tid;
            }
            return 0;
        }

        $tid = (int)$ins['term_id'];
        if (function_exists('pll_set_term_language')) pll_set_term_language($tid, $lang);
        return $tid;
    }

    /**
     * Assigne UNIQUEMENT les valeurs d’attributs depuis les métas _attribute1..3,
     * sans modifier les labels (parents). Les parents existent et sont traduits via Polylang.
     * Conventions parents FR en MAJ: MARQUE / TENSION / TYPE
     * Mapping des labels cibles:
     *  EN: MARQUE->BRAND, TENSION->VOLTAGE, TYPE->CATEGORY
     *  ES: MARQUE->MARCA, TENSION->TENSIÓN, TYPE->TIPO
     */
    private function assign_attribute_values_only_from_meta( int $post_id, string $lang, bool $debug = false ): void {
        $lang = strtolower(trim($lang));
        if ($lang === '' || $lang === 'fr') {
            if ($debug) WP_CLI::log("[ATTR:values] FR/empty -> skip (on ne modifie pas FR).");
            return;
        }

        $tax = 'al_product-attributes';
        if (!taxonomy_exists($tax)) {
            if ($debug) WP_CLI::warning("[ATTR:values] taxonomy {$tax} inexistante");
            return;
        }

        // Parents FR canoniques (MAJ)
        $parents_fr = ['MARQUE','TENSION','TYPE'];

        // Mapping des noms de labels cibles (par nom FR)
        $label_map = [
            'en' => ['MARQUE'=>'BRAND',   'TENSION'=>'VOLTAGE', 'TYPE'=>'CATEGORY'],
            'es' => ['MARQUE'=>'MARCA',   'TENSION'=>'TENSIÓN', 'TYPE'=>'TIPO'],
        ];
        if (!isset($label_map[$lang])) {
            if ($debug) WP_CLI::log("[ATTR:values] Langue non gérée: {$lang}");
            return;
        }
        $target_labels = $label_map[$lang];

        // Valeurs (déjà traduites dans les JSON importés)
        $v1 = trim((string)get_post_meta($post_id, '_attribute1', true)); // MARQUE
        $v2 = trim((string)get_post_meta($post_id, '_attribute2', true)); // TENSION
        $v3 = trim((string)get_post_meta($post_id, '_attribute3', true)); // TYPE
        if ($v1==='' && $v2==='' && $v3==='') {
            if ($debug) WP_CLI::log("[ATTR:values] Aucune valeur _attributeN.");
            return;
        }

        // util: égalité insensible aux accents/casse/espaces
        $eq = function($a,$b) {
            $na = remove_accents(strtolower(trim((string)$a)));
            $nb = remove_accents(strtolower(trim((string)$b)));
            return $na === $nb;
        };

        // Trouver un terme parent par NOM (exact logique, parent=0)
        $find_parent_by_name = function(string $name) use ($tax, $eq) {
            $terms = get_terms([
                'taxonomy'   => $tax,
                'hide_empty' => false,
                'number'     => 400,
                'parent'     => 0,
            ]);
            if (is_wp_error($terms) || empty($terms)) return 0;
            foreach ($terms as $t) {
                if ((int)$t->parent !== 0) continue;
                if ($eq($t->name, $name)) return (int)$t->term_id;
            }
            return 0;
        };

        // Résoudre le parent cible à partir du parent FR (par NOM) puis Polylang, sinon par NOM cible direct
        $resolve_parent = function(string $fr_name, string $target_name, string $lang) use ($tax, $find_parent_by_name, $debug) {
            // 1) trouver parent FR par NOM
            $fr_tid = $find_parent_by_name($fr_name);
            if ($fr_tid && function_exists('pll_get_term')) {
                $mapped = pll_get_term($fr_tid, $lang);
                if ($mapped) return (int)$mapped;
            }
            // 2) sinon trouver parent cible par NOM direct
            $to_tid = $find_parent_by_name($target_name);
            if ($to_tid) return (int)$to_tid;

            // 3) fallback (optionnel) : ne pas créer automatiquement un parent
            return 0;
        };

        // Résoudre les 3 parents
        $parent_ids = [
            'MARQUE'  => $resolve_parent('MARQUE',  $target_labels['MARQUE'],  $lang),
            'TENSION' => $resolve_parent('TENSION', $target_labels['TENSION'], $lang),
            'TYPE'    => $resolve_parent('TYPE',    $target_labels['TYPE'],    $lang),
        ];
        if ($debug) WP_CLI::log("[ATTR:values] parents: ".json_encode($parent_ids));

        // Créer/trouver une valeur sous un parent (sans toucher au label)
        $ensure_value_under_parent = function(string $value, int $parent_id) use ($tax) {
            $value = trim($value);
            if ($value === '' || !$parent_id) return 0;

            $slug = sanitize_title($value);

            // Par slug + parent
            $terms = get_terms([
                'taxonomy'   => $tax,
                'hide_empty' => false,
                'slug'       => $slug,
                'number'     => 20,
            ]);
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $t) {
                    if ((int)$t->parent === $parent_id) return (int)$t->term_id;
                }
            }

            // Par name + parent
            $terms = get_terms([
                'taxonomy'   => $tax,
                'hide_empty' => false,
                'name'       => $value,
                'number'     => 20,
            ]);
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $t) {
                    if ((int)$t->parent === $parent_id) return (int)$t->term_id;
                }
            }

            // Créer sous le parent
            $ins = wp_insert_term($value, $tax, ['slug'=>$slug, 'parent'=>$parent_id]);
            if (is_wp_error($ins)) return 0;
            return (int)$ins['term_id'];
        };

        // Construire la liste d’IDs valeurs à assigner
        $value_ids = [];
        if ($v1 !== '' && $parent_ids['MARQUE'])  $value_ids[] = $ensure_value_under_parent($v1, $parent_ids['MARQUE']);
        if ($v2 !== '' && $parent_ids['TENSION']) $value_ids[] = $ensure_value_under_parent($v2, $parent_ids['TENSION']);
        if ($v3 !== '' && $parent_ids['TYPE'])    $value_ids[] = $ensure_value_under_parent($v3, $parent_ids['TYPE']);
        $value_ids = array_values(array_filter(array_unique($value_ids)));

        if (empty($value_ids)) {
            if ($debug) WP_CLI::log("[ATTR:values] Rien à assigner (parents introuvables ou valeurs vides).");
            return;
        }

        $res = wp_set_object_terms($post_id, $value_ids, $tax, false);
        if (is_wp_error($res)) {
            WP_CLI::warning("[ATTR:values] Erreur assignation sur #{$post_id}: ".$res->get_error_message());
        } else {
            if ($debug) WP_CLI::log("[ATTR:values] Set {$tax} on #{$post_id} values=".json_encode($value_ids));
        }
    }

    /**
     * Injecte les attributs depuis le bloc meta du JSON :
     *   - _attribute1, _attribute2, _attribute3 (valeurs)
     *   - _attribute1_label, _attribute2_label, _attribute3_label (labels)
     * On ne touche PAS au FR ; on force les labels pour EN/ES uniquement.
     */
    private function assign_attributes_from_meta_simple( int $post_id, array $row_meta, string $lang, bool $debug = false ) : void {
        $lang = $this->normalize_lang_code($lang);

        // Récup des valeurs
        $v1 = isset($row_meta['_attribute1']) ? trim((string)$row_meta['_attribute1']) : '';
        $v2 = isset($row_meta['_attribute2']) ? trim((string)$row_meta['_attribute2']) : '';
        $v3 = isset($row_meta['_attribute3']) ? trim((string)$row_meta['_attribute3']) : '';

        if ($debug) WP_CLI::log("[ATTR-META-SIMPLE] lang={$lang} raw values: '{$v1}', '{$v2}', '{$v3}'");

        // Rien à faire si aucune valeur
        if ($v1 === '' && $v2 === '' && $v3 === '') {
            if ($debug) WP_CLI::log("[ATTR-META-SIMPLE] empty => clearing labels only");
            // On nettoie les labels pour éviter des résidus incohérents
            delete_post_meta($post_id, '_attribute1_label');
            delete_post_meta($post_id, '_attribute2_label');
            delete_post_meta($post_id, '_attribute3_label');
            return;
        }

        // Labels selon la langue (on ne touche pas FR)
        $labels_map = [
            'fr' => ['Marque','Tension','Type'],   // FR inchangé
            'en' => ['Brand','Voltage','Type'],
            'es' => ['Marca','Tensión','Tipo'],
        ];
        $labels = $labels_map[$lang] ?? $labels_map['en'];

        // FR : on conserve ce que tu as déjà ; EN/ES : on force ces labels
        if ($lang === 'fr') {
            // FR : si ton JSON a déjà *_label on les laisse tels quels ; sinon on ne change rien
            if ($debug) WP_CLI::log("[ATTR-META-SIMPLE] FR: no label override (safety).");
        } else {
            // EN/ES : pose/override des labels
            update_post_meta($post_id, '_attribute1_label', $labels[0]);
            update_post_meta($post_id, '_attribute2_label', $labels[1]);
            update_post_meta($post_id, '_attribute3_label', $labels[2]);
            if ($debug) WP_CLI::log("[ATTR-META-SIMPLE] labels set: ".implode(', ', $labels));
        }

        // Pose des valeurs (vides inclus -> on nettoie)
        $pairs = [
            ['_attribute1', $v1],
            ['_attribute2', $v2],
            ['_attribute3', $v3],
        ];
        foreach ($pairs as [$k, $val]) {
            if ($val === '') {
                delete_post_meta($post_id, $k);
            } else {
                update_post_meta($post_id, $k, $val);
            }
        }

        // Important : certains thèmes lisent uniquement les métas ; on peut aussi nettoyer la taxo
        // pour éviter les incohérences (facultatif). Décommente si besoin :
        if (taxonomy_exists('al_product-attributes')) {
            wp_set_object_terms($post_id, [], 'al_product-attributes', false);
        }

        if ($debug) {
            WP_CLI::log("[ATTR-META-SIMPLE] set metas for #{$post_id}: "
                ."_attribute1='{$v1}', _attribute2='{$v2}', _attribute3='{$v3}'");
        }
    }


    // Lit _attribute1..3 dans $row['meta'] (fallback BDD), pose les labels selon la langue,
    // crée/trouve les termes "valeur" en langue cible et les assigne au produit.
    private function assign_attributes_from_meta( int $post_id, string $lang, bool $debug = false ) : void {
        $tax  = 'al_product-attributes';
        if (!taxonomy_exists($tax)) {
            if ($debug) WP_CLI::warning("[ATTR-META] Taxonomy '{$tax}' inexistante");
            return;
        }

        $lang = $this->normalize_lang_code($lang);

        // Lire les metas de la rangée JSON (stashée dans la boucle)
        $row_meta = is_array($this->current_row_meta ?? null) ? $this->current_row_meta : [];

        // Récupérer les 3 valeurs (_attribute1..3)
        $vals = [];
        for ($i=1;$i<=3;$i++) {
            $k = "_attribute{$i}";
            $v = isset($row_meta[$k]) ? trim((string)$row_meta[$k]) : '';
            if ($debug) WP_CLI::log("[ATTR-META] {$k}='{$v}' (lang={$lang})");
            if ($v !== '') $vals[] = $v;
        }

        // Pas de valeurs -> désassigner
        if (empty($vals)) {
            if ($debug) WP_CLI::log("[ATTR-META] aucune valeur -> clear taxonomy");
            wp_set_object_terms($post_id, [], $tax, false);
            return;
        }

        // Poser aussi les labels en meta pour les thèmes qui les lisent
        $labels_map = [
            'fr' => ['Marque','Tension','Type'],
            'en' => ['Brand','Voltage','Type'],
            'es' => ['Marca','Tensión','Tipo'],
        ];
        $labels = $labels_map[$lang] ?? $labels_map['en'];
        for ($i=1;$i<=3;$i++) {
            $labk = "_attribute{$i}_label";
            $valk = "_attribute{$i}";
            if (!empty($row_meta[$valk])) {
                update_post_meta($post_id, $labk, $labels[$i-1]);
                update_post_meta($post_id, $valk, $row_meta[$valk]);
            } else {
                delete_post_meta($post_id, $labk);
            }
        }

        // *** FR : on ne change rien (comportement existant, valeurs "plates") ***
        if ($lang === 'fr') {
            // création/assignation de valeurs "plates" (comme avant)
            $value_ids = [];
            foreach ($vals as $v) {
                // réutilise éventuellement ta ensure_term_by_name_lang existante
                $tid = $this->ensure_term_by_name_lang($tax, $v, $lang, $v);
                if ($tid) $value_ids[] = $tid;
            }
            $value_ids = array_values(array_unique(array_filter($value_ids, static fn($x)=>$x>0)));
            if ($debug) WP_CLI::log("[ATTR-META][FR] ids=".json_encode($value_ids));
            if (!empty($value_ids)) wp_set_object_terms($post_id, $value_ids, $tax, false);
            else wp_set_object_terms($post_id, [], $tax, false);
            return;
        }

        // *** EN/ES : structure Label(parent) -> Valeur(enfant) ***
        $pairing = [
            ['label'=>$labels[0] ?? 'Brand',  'value'=>($row_meta['_attribute1'] ?? '')],
            ['label'=>$labels[1] ?? 'Voltage','value'=>($row_meta['_attribute2'] ?? '')],
            ['label'=>$labels[2] ?? 'Type',   'value'=>($row_meta['_attribute3'] ?? '')],
        ];

        $final_ids = [];
        foreach ($pairing as $idx=>$pair) {
            $label = trim((string)$pair['label']);
            $value = trim((string)$pair['value']);
            if ($value === '') continue;

            $parent = $this->ensure_label_term($tax, $label, $lang, $debug);
            if ($parent <= 0) {
                if ($debug) WP_CLI::warning("[ATTR-META] label term missing for '{$label}' ({$lang})");
                // fallback: valeur "plate"
                $tid = $this->ensure_term_by_name_lang($tax, $value, $lang, $value);
            } else {
                $tid = $this->ensure_child_term($tax, $parent, $value, $lang, $debug);
                if ($tid <= 0) {
                    // fallback "plate"
                    $tid = $this->ensure_term_by_name_lang($tax, $value, $lang, $value);
                }
            }
            if ($tid) $final_ids[] = $tid;
        }

        $final_ids = array_values(array_unique(array_filter($final_ids, static fn($x)=>$x>0)));
        if ($debug) WP_CLI::log("[ATTR-META][{$lang}] set ids=".json_encode($final_ids)." -> #{$post_id}");
        if (!empty($final_ids)) wp_set_object_terms($post_id, $final_ids, $tax, false);
        else wp_set_object_terms($post_id, [], $tax, false);
    }

    /**
     * Assigne les attributs (taxonomy 'al_product-attributes') de façon SIMPLE :
     * - on lit $row['tax']['al_product-attributes'] (déjà traduits par ton JSON EN)
     * - on cherche par slug; sinon on crée le terme avec le name fourni
     * - on pose la langue si Polylang est présent (sans lier aux autres langues)
     * - on assigne les IDs obtenus au produit
     */
    private function assign_attributes_simple( int $post_id, array $row, string $lang, bool $debug = false ): void {
        $tax = 'al_product-attributes';
        if (!taxonomy_exists($tax)) {
            if ($debug) WP_CLI::warning("[ATTR] Taxonomy '{$tax}' inexistante, skip.");
            return;
        }
        // normalise langue pour la pose éventuelle
        $lang = $this->normalize_lang_code($lang);

        // attend un tableau d’objets {id, slug, name} déjà TRADUITS côté JSON EN
        if (empty($row['tax'][$tax]) || !is_array($row['tax'][$tax])) {
            if ($debug) WP_CLI::log("[ATTR] Pas de bloc tax.{$tax} dans le payload, skip.");
            return;
        }

        $target_ids = [];
        foreach ($row['tax'][$tax] as $t) {
            if (!is_array($t)) continue;
            $slug = isset($t['slug']) ? sanitize_title((string)$t['slug']) : '';
            $name = isset($t['name']) ? trim((string)$t['name']) : '';

            if ($slug === '' && $name === '') continue;

            // 1) cherche par slug
            $found = [];
            if ($slug !== '') {
                $found = get_terms([
                    'taxonomy'   => $tax,
                    'hide_empty' => false,
                    'slug'       => $slug,
                    'number'     => 1,
                ]);
            }

            $term_id = 0;
            $must_create = false;

            if (!is_wp_error($found) && !empty($found)) {
                $found_id = (int) $found[0]->term_id;
                $tlang = '';
                if (function_exists('pll_get_term_language')) {
                    $tlang = (string) pll_get_term_language($found_id);
                }

                if (!empty($tlang) && strtolower($tlang) === $lang) {
                    // ✅ bon terme (langue cible). Met à jour le nom si différent
                    $current_name = $found[0]->name;
                    if ($name !== '' && $current_name !== $name) {
                        wp_update_term($found_id, $tax, ['name' => $name]);
                        if ($debug) WP_CLI::log("[ATTR] Updated name for #{$found_id} -> '{$name}'");
                    }
                    $term_id = $found_id;
                    if ($debug) WP_CLI::log("[ATTR] Use existing term #{$term_id} (lang={$lang})");
                } else {
                    // ❌ terme trouvé mais pas dans la langue cible -> on créera un clone EN
                    $must_create = true;
                    if ($debug) WP_CLI::log("[ATTR] Slug '{$slug}' exists in other lang ('{$tlang}') or no lang; will create {$lang} variant.");
                }
            } else {
                // pas trouvé -> création
                $must_create = true;
            }

            if ($must_create) {
                $to_name = $name !== '' ? $name : ($slug !== '' ? $slug : 'attribute');
                // éviter collision avec le FR : suffixer le slug si déjà pris
                $base_slug = ($slug !== '') ? $slug : sanitize_title($to_name);
                $to_slug = $base_slug;

                // si le slug existe déjà, suffixe '-{lang}'
                $exists = get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'slug'=>$to_slug,'number'=>1]);
                if (!is_wp_error($exists) && !empty($exists)) {
                    $to_slug = $base_slug . '-' . $lang;
                }

                $ins = wp_insert_term($to_name, $tax, ['slug' => $to_slug]);
                if (!is_wp_error($ins)) {
                    $term_id = (int) $ins['term_id'];
                    if ($debug) WP_CLI::log("[ATTR] Created term #{$term_id} name='{$to_name}' slug='{$to_slug}'");

                    // Pose la langue si Polylang (PAS de linking interlangue)
                    if ($term_id && $lang && function_exists('pll_set_term_language')) {
                        pll_set_term_language($term_id, $lang);
                    }
                } else {
                    if ($debug) WP_CLI::warning("[ATTR] create failed: ".$ins->get_error_message());
                }
            }

            if ($term_id) $target_ids[] = $term_id;
        }


        $target_ids = array_values(array_unique(array_filter($target_ids)));
        if (empty($target_ids)) {
            if ($debug) WP_CLI::log("[ATTR] Liste d’IDs vide; rien à assigner.");
            return;
        }

        $res = wp_set_object_terms($post_id, $target_ids, $tax, false);
        if (is_wp_error($res)) {
            WP_CLI::warning("[ATTR] Erreur assignation {$tax} sur #{$post_id}: ".$res->get_error_message());
        } else {
            if ($debug) WP_CLI::log("[ATTR] Set {$tax} on #{$post_id} ids=".json_encode($target_ids));
        }
    }


    private function assign_attributes_from_row( int $post_id, array $row, string $lang, bool $debug = false ): void {
        $tax = 'al_product-attributes';
        if (!taxonomy_exists($tax)) { if ($debug) WP_CLI::warning("[ATTR] Taxonomy '{$tax}' inexistante"); return; }

        // Normalise code langue
        $lang = function_exists('normalize_lang_code') ? normalize_lang_code($lang) : strtolower(trim((string)$lang));

        // A) IDs explicites par langue (al_product-attributes_ids[lang])
        $ids_key = $tax . '_ids';
        $ids_to_set = [];
        if (!empty($row[$ids_key]) && !empty($row[$ids_key][$lang]) && is_array($row[$ids_key][$lang])) {
            $ids_to_set = array_values(array_filter(array_map('intval', $row[$ids_key][$lang]), static fn($v)=>$v>0));
            if ($debug) WP_CLI::log("[ATTR] {$ids_key}[{$lang}] => ".json_encode($ids_to_set));
        }

        // B) Sinon, partir des termes FR exportés (tax.al_product-attributes)
        if (empty($ids_to_set) && !empty($row['tax'][$tax]) && is_array($row['tax'][$tax])) {
            $targets = [];
            foreach ($row['tax'][$tax] as $fr) {
                if (!is_array($fr)) continue;
                $fr_id = isset($fr['id']) ? (int)$fr['id'] : 0;
                $slug  = isset($fr['slug']) ? (string)$fr['slug'] : '';
                $name  = isset($fr['name']) ? (string)$fr['name'] : '';

                $tid = 0;
                // 1) mapping direct via Polylang
                if ($fr_id && function_exists('pll_get_term')) {
                    $mapped = (int)pll_get_term($fr_id, $lang);
                    if ($mapped) { $tid = $mapped; if ($debug) WP_CLI::log("[ATTR] pll_get_term FR#{$fr_id} -> {$lang}#{$tid}"); }
                }
                // 2) recherche par slug/name
                if (!$tid) {
                    foreach (array_unique(array_filter([$slug, sanitize_title($name)])) as $cand) {
                        $found = get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'slug'=>$cand,'number'=>1]);
                        if (!is_wp_error($found) && !empty($found)) {
                            $found_id = (int)$found[0]->term_id;
                            $ok = true; $tlang = '';
                            if (function_exists('pll_get_term_language')) {
                                $tlang = (string)pll_get_term_language($found_id);
                                if (!empty($tlang) && strtolower($tlang) !== $lang) { $ok = false; }
                            }
                            if ($ok) {
                                // Pose langue si absente
                                if (empty($tlang) && function_exists('pll_set_term_language')) {
                                    pll_set_term_language($found_id, $lang);
                                    if ($debug) WP_CLI::log("[ATTR] Set term #{$found_id} lang={$lang}");
                                }
                                // Lier au FR si possible
                                if ($fr_id && function_exists('pll_get_term_translations') && function_exists('pll_save_term_translations')) {
                                    $map = (array)pll_get_term_translations($fr_id);
                                    $map[$lang] = $found_id;
                                    pll_save_term_translations($map);
                                }
                                $tid = $found_id;
                                if ($debug) WP_CLI::log("[ATTR] Found by slug/name '{$cand}' -> #{$tid}");
                                break;
                            }
                        }
                    }
                }
                // 3) créer + lier si rien
                if (!$tid) {
                    $to_name = $name ?: ($slug ?: "attr-$fr_id");
                    $to_slug = $slug ? "{$slug}-{$lang}" : sanitize_title($to_name)."-{$lang}";
                    $ins = wp_insert_term($to_name, $tax, ['slug'=>$to_slug]);
                    if (!is_wp_error($ins)) {
                        $tid = (int)$ins['term_id'];
                        if (function_exists('pll_set_term_language')) pll_set_term_language($tid, $lang);
                        if ($fr_id && function_exists('pll_get_term_translations') && function_exists('pll_save_term_translations')) {
                            $map = (array)pll_get_term_translations($fr_id);
                            $map[$lang] = $tid;
                            pll_save_term_translations($map);
                        }
                        if ($debug) WP_CLI::log("[ATTR] Created #{$tid} ({$lang}) from FR#{$fr_id}");
                    } else {
                        if ($debug) WP_CLI::warning("[ATTR] create failed: ".$ins->get_error_message());
                    }
                }

                if ($tid) $targets[] = $tid;
            }
            $ids_to_set = array_values(array_unique(array_filter($targets)));
        }

        // Filtrer pour ne garder QUE des termes dans la langue cible (ou sans langue)
        if (function_exists('pll_get_term_language') && !empty($ids_to_set)) {
            $ids_to_set = array_values(array_filter($ids_to_set, function($tid) use ($lang) {
                $tl = pll_get_term_language($tid);
                return empty($tl) || strtolower($tl) === $lang;
            }));
        }
        if (empty($ids_to_set)) { if ($debug) WP_CLI::log("[ATTR] Aucun ID à assigner"); return; }

        $res = wp_set_object_terms($post_id, $ids_to_set, $tax, false);
        if (is_wp_error($res)) WP_CLI::warning("[ATTR] Erreur assignation {$tax} sur #{$post_id}: ".$res->get_error_message());
        elseif ($debug) WP_CLI::log("[ATTR] Set {$tax} on #{$post_id} ids=".json_encode($ids_to_set));
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

        // Hooks génériques souvent écoutés par ImpleCode / thèmes
        do_action('ic_after_save_product', $post_id);
        do_action('ic_catalog_product_updated', $post_id);
        do_action('catalog_product_updated', $post_id);

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
            $this->current_row_meta = $meta; // <— AJOUT
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

            /* === Langue + linking + catégories + attributs (valeurs uniquement) === */

            // Langue normalisée à partir du JSON
            $__prod_lang = '';
            if (!empty($row['lang']) && is_string($row['lang'])) {
                $__prod_lang = $this->normalize_lang_code($row['lang']);
            }

            // Active les logs détaillés si besoin
            $__debug_any = true; // passe à false en prod si tu veux

            // 1) Langue Polylang
            if ($__prod_lang !== '' && function_exists('pll_set_post_language')) {
                pll_set_post_language($actual_id, $__prod_lang);
                if ($__debug_any) WP_CLI::log("[DEBUG] set lang {$__prod_lang} on ID {$actual_id}");
            }

            // 2) Linking interlangues (optionnel via --link-siblings=1)
            if (!empty($assoc['link-siblings']) && method_exists($this, 'link_with_siblings')) {
                $this->link_with_siblings($actual_id, $row, $__prod_lang, $__debug_any);
            }

            // 3) Catégories traduites depuis al_product-cat_ids
            if (method_exists($this, 'assign_categories_from_ids')) {
                $this->assign_categories_from_ids($actual_id, $row, $__prod_lang, $__debug_any);
            }

            // 4) Valeurs d’attributs depuis métas (_attribute1..3), SANS toucher aux labels (gérés par Polylang)
            $this->assign_attribute_values_only_from_meta($actual_id, $__prod_lang, $__debug_any);

            // 5) FINALIZE : force ImpleCode à recalculer / rafraîchir le front
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
