
<?php
/**
 * Robust WP-CLI eval-file importer for CPT "al_product".
 *
 * Works with:
 *   wp eval-file wpcli_import_al_product.php -- products_en.json 1 1 1 publish
 *   wp eval-file wpcli_import_al_product.php -- --file=products_en.json --update=1 --dry-run=1 --author=1 --default-status=publish
 *   IMPORT_FILE=products_en.json wp eval-file wpcli_import_al_product.php
 */

if ( ! defined('ABSPATH') ) {
    fwrite(STDERR, "This script must be run via WP-CLI (wp eval-file ...)\n");
    exit(1);
}

// ---------------- Configuration ----------------
$post_type_slug     = 'al_product';
$language_tax_slug  = 'language';
$source_id_meta_key = '_source_id';
$allowed_statuses   = ['publish','draft','pending','private','future'];

// ---------------- Arg parsing ----------------
$input_file     = null;
$do_update      = 0;
$dry_run        = 0;
$author_id      = get_current_user_id();
$default_status = 'draft';

// 1) Prefer WP-CLI globals if available
if ( isset($assoc_args) && is_array($assoc_args) && !empty($assoc_args) ) {
    $input_file      = $assoc_args['file'] ?? $input_file;
    $do_update       = isset($assoc_args['update']) ? (int)$assoc_args['update'] : $do_update;
    $dry_run         = isset($assoc_args['dry-run']) ? (int)$assoc_args['dry-run'] : $dry_run;
    $author_id       = isset($assoc_args['author']) ? (int)$assoc_args['author'] : $author_id;
    $default_status  = $assoc_args['default-status'] ?? $default_status;
}

// 2) Positional $args after -- : [file, update, dry-run, author, default-status]
if ( empty($input_file) && isset($args) && is_array($args) && !empty($args) ) {
    $input_file     = $args[0] ?? $input_file;
    if (isset($args[1])) $do_update = (int)$args[1];
    if (isset($args[2])) $dry_run   = (int)$args[2];
    if (isset($args[3])) $author_id = (int)$args[3];
    if (isset($args[4])) $default_status = $args[4];
}

// 3) Fallback: scan $argv for the last "--" and then take following tokens
if ( empty($input_file) && isset($argv) && is_array($argv) ) {
    $last_sep = -1;
    foreach ($argv as $i => $tok) {
        if ($tok === '--') { $last_sep = $i; }
    }
    if ($last_sep >= 0) {
        $pos = array_slice($argv, $last_sep + 1);
        if (!empty($pos)) {
            $input_file     = $pos[0] ?? $input_file;
            if (isset($pos[1])) $do_update = (int)$pos[1];
            if (isset($pos[2])) $dry_run   = (int)$pos[2];
            if (isset($pos[3])) $author_id = (int)$pos[3];
            if (isset($pos[4])) $default_status = $pos[4];
        }
    } else {
        // If there is no "--", try the last argument as file (best-effort)
        if (count($argv) >= 2) {
            $maybe = end($argv);
            if ($maybe && substr($maybe, -5) === '.json') {
                $input_file = $maybe;
            }
        }
    }
}

// 4) Environment variables
if ( empty($input_file) && getenv('IMPORT_FILE') ) $input_file = getenv('IMPORT_FILE');
if ( getenv('IMPORT_UPDATE') !== false ) $do_update = (int)getenv('IMPORT_UPDATE');
if ( getenv('IMPORT_DRYRUN') !== false ) $dry_run   = (int)getenv('IMPORT_DRYRUN');
if ( getenv('IMPORT_AUTHOR') !== false ) $author_id = (int)getenv('IMPORT_AUTHOR');
if ( getenv('IMPORT_STATUS') !== false ) $default_status = getenv('IMPORT_STATUS');

// Validate
if ( ! $input_file ) {
    fwrite(STDERR, "Missing input file.\nUSAGE (positional): wp eval-file wpcli_import_al_product.php -- products.json 1 1 1 publish\n");
    exit(1);
}
if ( ! in_array($default_status, $allowed_statuses, true) ) {
    fwrite(STDERR, "Invalid default status. Allowed: ".implode(',', $allowed_statuses)."\n");
    exit(1);
}
if ( ! file_exists($input_file) ) {
    fwrite(STDERR, "File not found: {$input_file}\n");
    exit(1);
}

// ---------------- Helpers ----------------
function find_post_by_source_id($meta_key, $source_id, $post_type) {
    $q = new WP_Query([
        'post_type' => $post_type,
        'post_status' => 'any',
        'meta_query' => [[ 'key' => $meta_key, 'value' => $source_id, 'compare' => '=' ]],
        'fields' => 'ids',
        'posts_per_page' => 1,
        'no_found_rows' => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    ]);
    return $q->have_posts() ? (int)$q->posts[0] : 0;
}

function find_post_by_slug($slug, $post_type) {
    $post = get_page_by_path($slug, OBJECT, $post_type);
    return $post ? (int)$post->ID : 0;
}

function ensure_terms($taxonomy, $names) {
    $term_ids = [];
    foreach ((array)$names as $name) {
        $name = trim((string)$name);
        if ($name === '') continue;
        $term = term_exists($name, $taxonomy);
        if (0 === $term || null === $term) {
            $ins = wp_insert_term($name, $taxonomy);
            if (is_wp_error($ins)) {
                $term = term_exists($name, $taxonomy);
                if ( $term && ! is_wp_error($term) ) $term_ids[] = (int)$term['term_id'];
                continue;
            }
            $term_ids[] = (int)$ins['term_id'];
        } else {
            $term_ids[] = (int)$term['term_id'];
        }
    }
    return $term_ids;
}

function put_meta_bulk($post_id, $meta) {
    foreach ($meta as $k => $v) {
        if ($k === '' || $v === null) continue;
        if (is_array($v) || is_object($v)) $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
        update_post_meta($post_id, $k, $v);
    }
}

// ---------------- Load JSON ----------------
$json = file_get_contents($input_file);
$data = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "JSON decode error: ".json_last_error_msg()."\n");
    exit(1);
}
if ( ! is_array($data) ) {
    fwrite(STDERR, "JSON root must be an array of products.\n");
    exit(1);
}

$imported = 0; $updated = 0; $skipped = 0; $errors = 0;

foreach ($data as $idx => $row) {
    if ( ! is_array($row) ) { $skipped++; continue; }

    $name     = (string)($row['name'] ?? '');
    $slug     = isset($row['slug']) ? sanitize_title($row['slug']) : '';
    $status   = (string)($row['status'] ?? $default_status);
    $content  = (string)($row['content_long'] ?? '');
    $excerpt  = (string)($row['content_short'] ?? '');
    $meta     = (array)($row['meta'] ?? []);
    $tax      = (array)($row['tax'] ?? []);
    $source_id= $row['source_id'] ?? null;

    if ($name === '') { $skipped++; continue; }

    // create vs update
    $existing_id = 0;
    if ($source_id) $existing_id = find_post_by_source_id($source_id_meta_key, $source_id, $post_type_slug);
    if (!$existing_id && $slug) $existing_id = find_post_by_slug($slug, $post_type_slug);

    $postarr = [
        'post_type'    => $post_type_slug,
        'post_title'   => $name,
        'post_name'    => $slug ?: sanitize_title($name),
        'post_status'  => in_array($status, $allowed_statuses, true) ? $status : $default_status,
        'post_content' => $content,
        'post_excerpt' => $excerpt,
        'post_author'  => (int)$author_id,
    ];

    $post_id = 0; $action = 'create';
    if ($existing_id) {
        if ($do_update) {
            $postarr['ID'] = $existing_id;
            if ($dry_run) { echo "[DRY-RUN][UPDATE] #{$existing_id} {$postarr['post_title']} ({$postarr['post_name']})\n"; $updated++; continue; }
            $post_id = wp_update_post($postarr, true);
            $action  = 'update';
        } else {
            echo "[SKIP] Existing post found (ID {$existing_id}) and update=0.\n"; $skipped++; continue;
        }
    } else {
        if ($dry_run) { echo "[DRY-RUN][CREATE] {$postarr['post_title']} ({$postarr['post_name']})\n"; $imported++; continue; }
        $post_id = wp_insert_post($postarr, true);
        $action  = 'create';
    }

    if (is_wp_error($post_id)) { $errors++; fwrite(STDERR, strtoupper($action)." ERROR: ".$post_id->get_error_message()."\n"); continue; }

    // Meta
    if ($source_id) update_post_meta($post_id, $source_id_meta_key, $source_id);
    if (!empty($meta)) put_meta_bulk($post_id, $meta);

    // Taxonomy: language
    if (isset($tax['language'])) {
        $term_ids = ensure_terms($language_tax_slug, $tax['language']);
        if (!empty($term_ids)) wp_set_object_terms($post_id, $term_ids, $language_tax_slug, false);
    }

    echo "[".strtoupper($action)."] ID {$post_id} - {$postarr['post_title']} (slug: {$postarr['post_name']}, status: {$postarr['post_status']})\n";
    if ($action === 'create') $imported++; else $updated++;
}

echo "----\nImported: {$imported}\nUpdated: {$updated}\nSkipped: {$skipped}\nErrors: {$errors}\n";
