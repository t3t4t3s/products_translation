<?php
if ( ! class_exists('WP_CLI') ) { fwrite(STDERR, "Run via WP-CLI with --require\n"); return; }

class ALProd_Normalize_FR_Attr_Parents_Command {
    private string $tax = 'al_product-attributes';

    // Canon FR: label => slug
    private array $canon = [
        'MARQUE'  => 'marque',
        'TENSION' => 'tension',
        'TYPE'    => 'type',
    ];

    // Petites règles de normalisation (synonymes / variantes -> canon)
    private function normalize_label(string $name): ?string {
        $s = trim($name);
        // enlever doubles espaces + casse
        $s = preg_replace('/\s+/u', ' ', $s);
        $u = strtoupper($s);

        // mapping simple
        $map = [
            'MARQUE' => 'MARQUE',
            'BRAND'  => 'MARQUE',   // si jamais un parent FR aurait été “Brand”
            'TENSION'=> 'TENSION',
            'VOLTAGE'=> 'TENSION',
            'TYPE'   => 'TYPE',
            'TIPO'   => 'TYPE',     // sécurité si import mal classé
        ];
        return $map[$u] ?? null;
    }

    /**
     * wp --require=wpcli-normalize-fr-attr-parents.php alprod normalize-fr-parents --dry-run=1
     */
    public function __invoke($args, $assoc) {
        $dry = isset($assoc['dry-run']) ? (int)$assoc['dry-run'] : 1;

        if ( ! taxonomy_exists($this->tax) ) {
            WP_CLI::error("Taxonomy {$this->tax} not found.");
        }

        // 1) S’assurer que les 3 parents canoniques FR existent
        $canon_ids = $this->ensure_canonical_parents($dry);

        // 2) Lister tous les parents FR existants
        $parents = get_terms([
            'taxonomy'   => $this->tax,
            'hide_empty' => false,
            'parent'     => 0,
        ]);
        if (is_wp_error($parents)) WP_CLI::error($parents->get_error_message());

        foreach ($parents as $p) {
            // Ne traiter que les parents “FR” si Polylang présent
            if (function_exists('pll_get_term_language')) {
                $lang = pll_get_term_language($p->term_id);
                if ($lang && strtolower($lang) !== 'fr') {
                    WP_CLI::log("[SKIP] parent #{$p->term_id} '{$p->name}' (lang={$lang})");
                    continue;
                }
            }

            $canon_label = $this->normalize_label($p->name);
            if ($canon_label === null) {
                // Parent hors périmètre: on le laisse tel quel (pas demandé de le supprimer)
                WP_CLI::log("[KEEP] parent #{$p->term_id} '{$p->name}' (non-canon)");
                continue;
            }

            $target_id   = $canon_ids[$canon_label] ?? 0;
            $target_slug = $this->canon[$canon_label];
            $target_name = $canon_label;

            if ($target_id === 0) {
                WP_CLI::warning("[WARN] Canon '{$target_name}' introuvable (devrait exister).");
                continue;
            }

            if ((int)$p->term_id === (int)$target_id) {
                // C’est déjà le parent canonique -> s’assurer du label/slug exact
                $args = [];
                if ($p->name !== $target_name) $args['name'] = $target_name;
                if ($p->slug !== $target_slug) $args['slug'] = $target_slug;
                if (!empty($args)) {
                    if ($dry) {
                        WP_CLI::log("[DRY-RUN] fix canonical parent #{$p->term_id} -> ".json_encode($args, JSON_UNESCAPED_UNICODE));
                    } else {
                        $r = wp_update_term($p->term_id, $this->tax, $args);
                        if (is_wp_error($r)) WP_CLI::warning("[PARENT] update canonical #{$p->term_id} error: ".$r->get_error_message());
                        else WP_CLI::log("[PARENT] fixed canonical #{$p->term_id} -> ".json_encode($args, JSON_UNESCAPED_UNICODE));
                    }
                } else {
                    WP_CLI::log("[OK] canonical parent #{$p->term_id} '{$p->name}'");
                }
                continue;
            }

            // C’est un doublon: on va re-basculer ses enfants vers le canon, puis supprimer le parent doublon
            WP_CLI::log("[MERGE] duplicate parent #{$p->term_id} '{$p->name}' -> '{$target_name}' (#{$target_id})");

            // 3) Rattacher tous ses enfants (valeurs) au parent canonique
            $children = get_terms([
                'taxonomy'   => $this->tax,
                'hide_empty' => false,
                'parent'     => $p->term_id,
            ]);
            if (is_wp_error($children)) {
                WP_CLI::warning("[CHILDREN] read error on parent #{$p->term_id}: ".$children->get_error_message());
                $children = [];
            }

            foreach ($children as $c) {
                // Mise à jour du parent
                $args = ['parent' => $target_id];
                if ($dry) {
                    WP_CLI::log("[DRY-RUN] reparent child #{$c->term_id} '{$c->name}' -> parent {$target_id} ('{$target_name}')");
                } else {
                    $r = wp_update_term($c->term_id, $this->tax, $args);
                    if (is_wp_error($r)) WP_CLI::warning("[CHILD] reparent #{$c->term_id} error: ".$r->get_error_message());
                    else WP_CLI::log("[CHILD] reparent #{$c->term_id} -> {$target_id}");
                }
            }

            // 4) Supprimer le parent doublon
            if ($dry) {
                WP_CLI::log("[DRY-RUN] delete duplicate parent #{$p->term_id}");
            } else {
                $del = wp_delete_term($p->term_id, $this->tax);
                if (is_wp_error($del)) WP_CLI::warning("[DELETE] parent #{$p->term_id} error: ".$del->get_error_message());
                else WP_CLI::log("[DELETE] parent #{$p->term_id} removed");
            }
        }

        // 5) S’assurer du casing/slug final des 3 parents canons (utile si aucun doublon n’existait)
        foreach ($this->canon as $label => $slug) {
            $pid = $canon_ids[$label] ?? 0;
            if ($pid) {
                $t = get_term($pid, $this->tax);
                if ($t && !is_wp_error($t)) {
                    $fix = [];
                    if ($t->name !== $label) $fix['name'] = $label;
                    if ($t->slug !== $slug)  $fix['slug'] = $slug;
                    if (!empty($fix)) {
                        if ($dry) {
                            WP_CLI::log("[DRY-RUN] finalize fix canonical #{$pid} -> ".json_encode($fix, JSON_UNESCAPED_UNICODE));
                        } else {
                            $r = wp_update_term($pid, $this->tax, $fix);
                            if (is_wp_error($r)) WP_CLI::warning("[PARENT] finalize fix #{$pid} error: ".$r->get_error_message());
                            else WP_CLI::log("[PARENT] finalize fix #{$pid} -> ".json_encode($fix, JSON_UNESCAPED_UNICODE));
                        }
                    }
                }
            }
        }

        WP_CLI::success("Done. Canon FR parents unified to MARQUE, TENSION, TYPE (slugs: marque, tension, type).");
    }

    private function ensure_canonical_parents(int $dry): array {
        $ids = ['MARQUE'=>0,'TENSION'=>0,'TYPE'=>0];

        // Tenter de retrouver par slug d’abord
        foreach ($this->canon as $label=>$slug) {
            $term = get_terms([
                'taxonomy'   => $this->tax,
                'hide_empty' => false,
                'slug'       => $slug,
                'parent'     => 0,
                'number'     => 1,
            ]);
            if (!is_wp_error($term) && !empty($term)) {
                $ids[$label] = (int)$term[0]->term_id;
                continue;
            }
            // Sinon par name exact (au cas où)
            $t2 = get_terms([
                'taxonomy'   => $this->tax,
                'hide_empty' => false,
                'name'       => $label,
                'parent'     => 0,
                'number'     => 1,
            ]);
            if (!is_wp_error($t2) && !empty($t2)) {
                $ids[$label] = (int)$t2[0]->term_id;
            }
        }

        // Créer les manquants
        foreach ($this->canon as $label=>$slug) {
            if ($ids[$label] > 0) continue;
            if ($dry) {
                WP_CLI::log("[DRY-RUN] create canonical parent '{$label}' (slug '{$slug}')");
                // on simule un id factice pour la suite
                continue;
            }
            $r = wp_insert_term($label, $this->tax, ['slug'=>$slug, 'parent'=>0]);
            if (is_wp_error($r)) {
                WP_CLI::warning("[CREATE] parent '{$label}' error: ".$r->get_error_message());
            } else {
                $ids[$label] = (int)$r['term_id'];
                WP_CLI::log("[CREATE] canonical parent '{$label}' #{$ids[$label]}");
                // Poser langue FR si Polylang
                if (function_exists('pll_set_term_language')) {
                    pll_set_term_language($ids[$label], 'fr');
                }
            }
        }
        return $ids;
    }
}

WP_CLI::add_command('alprod normalize-fr-parents', 'ALProd_Normalize_FR_Attr_Parents_Command');
