<?php
/**
 * Export des al_product en FR (Polylang) avec :
 *  - name (== title) pour compatibilité scripts de traduction
 *  - image mise en avant: url, title, alt, caption
 *  - images attachées: tableau d'objets {url, title, alt, caption}
 *  - + liens de catégories traduites: "al_product-cat_ids": {"fr":[...],"en":[...],"es":[...]}
 *
 * Utilisation (depuis la racine WP) :
 *   php ../wp-cli.phar --path=$(pwd) eval-file ./export-al-products-fr-images-name.php > products_fr.json
 */

if (!function_exists('get_posts')) {
  echo json_encode(['error' => 'Ce script doit être exécuté via WP-CLI (eval-file).']);
  exit;
}

$args = [
  'post_type'      => 'al_product',
  'post_status'    => 'any',
  'posts_per_page' => -1,
  'no_found_rows'  => true,
  'lang'           => 'fr',
];

$posts = get_posts($args);
if (!is_array($posts)) $posts = [];
$out = [];

$has_pll = function_exists('pll_get_post_language');

/** Helper: ids de termes pour une liste de termes (objets WP_Term) */
function _alprod_term_ids($terms) {
  if (empty($terms) || is_wp_error($terms)) return [];
  return array_values(array_map(function($t){ return (int)$t->term_id; }, $terms));
}

/** Helper: mappe une liste de termes FR vers leurs traductions en $lang pour une taxonomie */
function _alprod_map_term_ids_to_lang($terms_fr, $taxonomy, $lang) {
  if (empty($terms_fr) || !function_exists('pll_get_term')) return [];
  $ids = [];
  foreach ($terms_fr as $t) {
    $tr_id = pll_get_term((int)$t->term_id, $lang);
    if ($tr_id) {
      $ids[] = (int)$tr_id;
    }
  }
  // unique + filtrage >0
  $ids = array_values(array_filter(array_unique($ids), function($v){ return $v > 0; }));
  return $ids;
}

foreach ($posts as $p) {
  // Taxonomies (termes FR du post)
  $tax_data = [];
  $taxes = get_object_taxonomies($p->post_type, 'objects');
  foreach ($taxes as $tax_name => $tax_obj) {
    $terms = get_the_terms($p->ID, $tax_name);
    if (!empty($terms) && !is_wp_error($terms)) {
      $tax_data[$tax_name] = array_map(function($t) {
        return ['id' => $t->term_id, 'slug' => $t->slug, 'name' => $t->name];
      }, $terms);
    } else {
      $tax_data[$tax_name] = [];
    }
  }

  // --- NOUVEAU : bloc des IDs de catégories traduites pour la taxo "al_product-cat"
  $al_prod_cat_ids = ['fr'=>[], 'en'=>[], 'es'=>[]];
  $taxonomy_cat = 'al_product-cat';
  $terms_fr_for_cat = get_the_terms($p->ID, $taxonomy_cat);
  // FR = ids des termes actuellement liés côté FR
  $al_prod_cat_ids['fr'] = _alprod_term_ids($terms_fr_for_cat);
  // EN/ES = traductions de ces termes
  if ($has_pll) {
    $al_prod_cat_ids['en'] = _alprod_map_term_ids_to_lang($terms_fr_for_cat, $taxonomy_cat, 'en');
    $al_prod_cat_ids['es'] = _alprod_map_term_ids_to_lang($terms_fr_for_cat, $taxonomy_cat, 'es');
  }

  // Métas
  $raw_meta = get_post_meta($p->ID);
  $meta = [];
  foreach ($raw_meta as $k => $v) {
    if (is_array($v)) {
      $meta[$k] = (count($v) === 1) ? $v[0] : $v;
    } else {
      $meta[$k] = $v;
    }
  }

  // Image mise en avant
  $thumb_id = get_post_thumbnail_id($p->ID);
  $thumb = null;
  if ($thumb_id) {
    $thumb = [
      'id'      => $thumb_id,
      'url'     => wp_get_attachment_image_url($thumb_id, 'full'),
      'title'   => get_the_title($thumb_id),
      'alt'     => get_post_meta($thumb_id, '_wp_attachment_image_alt', true),
      'caption' => wp_get_attachment_caption($thumb_id),
    ];
  }

  // Autres images attachées
  $images = [];
  $attached = get_attached_media('image', $p->ID);
  if (!empty($attached)) {
    foreach ($attached as $att) {
      if ($att->ID == $thumb_id) continue;
      $images[] = [
        'id'      => $att->ID,
        'url'     => wp_get_attachment_image_url($att->ID, 'full'),
        'title'   => get_the_title($att->ID),
        'alt'     => get_post_meta($att->ID, '_wp_attachment_image_alt', true),
        'caption' => wp_get_attachment_caption($att->ID),
      ];
    }
  }

  // Liens de traduction (Polylang)
  $translations = null;
  if ($has_pll) {
    $translations = [];
    $langs = pll_languages_list(['fields' => 'slug']);
    foreach ($langs as $lang) {
      $tr_id = pll_get_post($p->ID, $lang);
      if ($tr_id) {
        $translations[$lang] = intval($tr_id);
      }
    }
  }

  $title = get_the_title($p);

  $out[] = [
    'id'            => $p->ID,
    'slug'          => $p->post_name,
    'status'        => $p->post_status,
    'lang'          => $has_pll ? pll_get_post_language($p->ID) : null,
    'date'          => get_post_time('c', true, $p),
    'modified'      => get_post_modified_time('c', true, $p),
    'title'         => $title,
    'name'          => $title, // <--- dupliquer title vers name
    'content_long'  => apply_filters('the_content', $p->post_content),
    'content_short' => wp_strip_all_tags($p->post_excerpt),
    'meta'          => $meta,
    'tax'           => $tax_data,
    'al_product-cat_ids' => $al_prod_cat_ids, // <--- NOUVEAU bloc pour l’import des catégories traduites
    'image'         => $thumb,
    'images'        => $images,
    'permalink'     => get_permalink($p),
    'translations'  => $translations,
  ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
