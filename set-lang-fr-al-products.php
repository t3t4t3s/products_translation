<?php
// Affecte la langue "fr" à tous les al_product sans langue.
if(!function_exists('pll_set_post_language')){
  fwrite(STDERR, "Polylang non actif.\n"); exit(1);
}
$posts = get_posts([
  'post_type'      => 'al_product',
  'post_status'    => 'any',
  'posts_per_page' => -1,
  'no_found_rows'  => true,
]);
$set=0;$skip=0;
foreach($posts as $p){
  $lang = pll_get_post_language($p->ID);
  if(!$lang){
    pll_set_post_language($p->ID,'fr'); $set++;
  } else { $skip++; }
}
echo json_encode(['affected'=>$set,'already_had_lang'=>$skip]).PHP_EOL;
