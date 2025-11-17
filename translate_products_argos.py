#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
translate_products_argos.py
 - Traduit un JSON de produits (liste d'objets) avec Argos Translate
 - Préserve les balises HTML et les emojis (option --emoji-mode keep)
 - Génère un slug traduit depuis le nom (option --slug-from-name)
 - Copie l'ID source dans "source_id" (option --set-source-id)
 - Glossaire de traductions forcées respectant la casse (tokens sûrs __GLS0__)
 - Barre de progression simple (--progress auto)
 - Option --strip-strong pour retirer <strong>...</strong> avant traduction
 - ✅ Traduit aussi les attributs (meta & tax al_product-attributes)

Entrée:  JSON (array) avec des objets type:
{
  "id": 263,
  "lang": "fr",
  "name": "Climatiseur ...",
  "slug": "climatiseur-...",
  "content_short": "<b>Puissant</b> 🙂 ...",
  "content_long": "...",
  "meta": { "_yoast_wpseo_title": "...", "_sku": "ABC123", "_attribute1": "LAVE LINGE" },
  "tax": { "al_product-attributes": [ { "id": 101, "slug": "tension", "name": "TENSION" } ] }
}

Sortie: même structure, avec champs traduits et options appliquées.
"""

import argparse, json, io, re, sys, unicodedata
from typing import Any, Dict, List, Callable

# ---------- Argos Translate ----------
def build_translator(src_code: str, tgt_code: str) -> Callable[[str], str]:
    try:
        import argostranslate.package as argos_package  # noqa: F401
        import argostranslate.translate as argos_translate
    except Exception as e:
        raise RuntimeError("Argos Translate n'est pas installé: pip install argostranslate") from e

    installed_languages = argos_translate.get_installed_languages()
    src = next((l for l in installed_languages if l.code.lower().startswith(src_code.lower())), None)
    tgt = next((l for l in installed_languages if l.code.lower().startswith(tgt_code.lower())), None)
    if not src or not tgt:
        raise RuntimeError(f"Aucun package Argos pour {src_code}->{tgt_code}. Installez le paquet correspondant.")

    # Compat : certaines versions ont des API différentes
    try:
        tr = src.get_translation(tgt)
    except Exception:
        cand = None
        try:
            cands = getattr(src, "translations", [])
            for t in cands:
                if getattr(t, "to_code", "") == tgt.code:
                    cand = t; break
        except Exception:
            pass
        if not cand:
            raise
        tr = cand

    def _translate(text: str) -> str:
        if not text:
            return text
        return tr.translate(text)
    return _translate

# ---------- Emoji / HTML helpers ----------
EMOJI_RE = re.compile(
    "["                   
    "\U0001F300-\U0001F5FF"
    "\U0001F600-\U0001F64F"
    "\U0001F680-\U0001F6FF"
    "\U0001F700-\U0001F77F"
    "\U0001F780-\U0001F7FF"
    "\U0001F800-\U0001F8FF"
    "\U0001F900-\U0001F9FF"
    "\U0001FA00-\U0001FA6F"
    "\U0001FA70-\U0001FAFF"
    "\U00002700-\U000027BF"
    "\U00002600-\U000026FF"
    "\U00002B00-\U00002BFF"
    "]+",
    flags=re.UNICODE
)

TAG_RE = re.compile(r"(<[^>]+>)")
NBSP_TOKEN = "\uF000NBSP\uF000"

def split_html_preserve_tags(s: str) -> List[str]:
    parts = []
    last = 0
    for m in TAG_RE.finditer(s):
        if m.start() > last:
            parts.append(s[last:m.start()])
        parts.append(m.group(1))
        last = m.end()
    if last < len(s):
        parts.append(s[last:])
    return parts

def tag_name(tag: str) -> str:
    m = re.match(r"</?\s*([a-zA-Z0-9]+)", tag)
    return m.group(1).lower() if m else ""

TRANSLATABLE_PARENTS = {
    "p","div","span","li","ul","ol","h1","h2","h3","h4","h5","h6","em","i","b","strong","small","sup","sub","blockquote","section","article","td","th","label","a"
}

def should_translate_text(open_stack: List[str]) -> bool:
    if any(t in ("script","style","code","pre") for t in open_stack):
        return False
    return True

def translate_preserving_emojis(text: str, translate_fn: Callable[[str], str], nbsp_token: str) -> str:
    out = []
    buf = []
    i = 0
    L = len(text)

    def flush_buf():
        if buf:
            seg = "".join(buf).replace("&nbsp;", nbsp_token)
            out.append(translate_fn(seg).replace(nbsp_token, "&nbsp;"))
            buf.clear()

    while i < L:
        if text.startswith("&nbsp;", i):
            buf.append("&nbsp;")
            i += 6
            continue
        ch = text[i]
        if EMOJI_RE.match(ch):
            flush_buf()
            out.append(ch)
            i += 1
            while i < L:
                if text.startswith("&nbsp;", i):
                    out.append("&nbsp;")
                    i += 6
                elif text[i].isspace():
                    out.append(text[i]); i += 1
                else:
                    break
            continue
        buf.append(ch); i += 1

    flush_buf()
    return "".join(out)

_WS_HTML_RE = re.compile(r'^((?:\s|&nbsp;)+)?(.*?)(?:((?:\s|&nbsp;)+))?$', re.DOTALL)
def _split_leading_trailing_html_spaces(s: str):
    if not s:
        return "", "", ""
    m = _WS_HTML_RE.match(s)
    if not m:
        return "", s, ""
    return (m.group(1) or ""), (m.group(2) or ""), (m.group(3) or "")

STRONG_OPEN_RE  = re.compile(r"<\s*strong\b[^>]*>", flags=re.IGNORECASE)
STRONG_CLOSE_RE = re.compile(r"<\s*/\s*strong\s*>", flags=re.IGNORECASE)
def _strip_strong_tags(html: str) -> str:
    if not html:
        return html
    html = STRONG_OPEN_RE.sub("", html)
    html = STRONG_CLOSE_RE.sub("", html)
    return html

def translate_html_string(s: str, translate_fn: Callable[[str], str],
                          emoji_mode: str = "keep", strip_strong: bool = False) -> str:
    if strip_strong and s:
        s = _strip_strong_tags(s)
    if not s or ("<" not in s and ">" not in s):
        if not s:
            return s
        return translate_preserving_emojis(s, translate_fn, NBSP_TOKEN) if emoji_mode == "keep" else translate_fn(s)
    segs = split_html_preserve_tags(s)
    out, stack = [], []
    for seg in segs:
        if seg.startswith("<"):
            name = tag_name(seg)
            if seg.startswith("</"):
                if stack and stack[-1] == name:
                    stack.pop()
                else:
                    if name in stack:
                        while stack and stack[-1] != name:
                            stack.pop()
                        if stack and stack[-1] == name:
                            stack.pop()
                out.append(seg)
            else:
                low = seg.lower()
                if seg.endswith("/>") or low.startswith("<!--") or seg.startswith("<!"):
                    out.append(seg)
                else:
                    stack.append(name)
                    out.append(seg)
        else:
            if seg.strip() == "":
                out.append(seg)
            else:
                if should_translate_text(stack):
                    leading, core, trailing = _split_leading_trailing_html_spaces(seg)
                    if core:
                        translated_core = (
                            translate_preserving_emojis(core, translate_fn, NBSP_TOKEN)
                            if emoji_mode == "keep" else translate_fn(core)
                        )
                    else:
                        translated_core = core
                    out.append(leading + translated_core + trailing)
                else:
                    out.append(seg)
    return "".join(out)

# ---------- Slugify ----------
def slugify(text: str) -> str:
    text = unicodedata.normalize("NFKD", text)
    text = text.encode("ascii", "ignore").decode("ascii")
    text = re.sub(r"[^\w\s-]", "", text).strip().lower()
    text = re.sub(r"[-\s]+", "-", text)
    return text

# ---------- Glossary (forced translations with case preservation) ----------
def _detect_case(sample: str) -> str:
    if sample.isupper(): return "upper"
    if sample.islower(): return "lower"
    if sample[:1].isupper() and sample[1:].islower() and " " not in sample: return "capital"
    parts = sample.split()
    if len(parts) > 1 and all(p[:1].isupper() and p[1:].islower() for p in parts if p): return "title"
    return "mixed"

def _apply_case(target: str, mode: str) -> str:
    if mode == "upper": return target.upper()
    if mode == "lower": return target.lower()
    if mode == "capital": return target[:1].upper() + target[1:].lower() if target else target
    if mode == "title": return " ".join([w[:1].upper() + w[1:].lower() if w else w for w in target.split(" ")])
    return target

def _load_glossary_from_file(path: str) -> Dict[str, str]:
    g = {}
    if not path: return g
    try:
        if path.lower().endswith(".json"):
            with io.open(path, "r", encoding="utf-8") as f:
                data = json.load(f)
            if isinstance(data, dict):
                for k, v in data.items():
                    if k and v:
                        g[str(k)] = str(v)
        else:
            with io.open(path, "r", encoding="utf-8") as f:
                for line in f:
                    line = line.strip()
                    if not line or line.startswith("#"): continue
                    if "=" in line:
                        src, tgt = line.split("=", 1)
                        src, tgt = src.strip(), tgt.strip()
                        if src and tgt:
                            g[src] = tgt
    except Exception as e:
        sys.stderr.write(f"[WARN] Impossible de lire le glossaire '{path}': {e}\n")
    return g

def _merge_glossary(g_file: Dict[str,str], g_pairs: List[str]) -> Dict[str,str]:
    g = dict(g_file)
    for pair in g_pairs or []:
        if "=" in pair:
            src, tgt = pair.split("=", 1)
            src, tgt = src.strip(), tgt.strip()
            if src and tgt:
                g[src] = tgt
    return g

def make_glossary_translate_fn(base_translate_fn, glossary: Dict[str, str], mode: str):
    if not glossary:
        return base_translate_fn
    items_raw = [(str(k), str(v)) for k, v in glossary.items()]
    items_raw = [(k, v) for (k, v) in items_raw if v != ""]
    if not items_raw:
        return base_translate_fn
    items = sorted(items_raw, key=lambda kv: len(kv[0]), reverse=True)
    lut_lower = {k.lower(): v for k, v in items}
    escaped = [re.escape(src) for src, _ in items if src]
    if not escaped:
        return base_translate_fn
    if mode == "word":
        pattern = re.compile(
            r"(?<![\w])(" + "|".join(escaped) + r")(?![\w])",
            flags=re.IGNORECASE | re.UNICODE
        )
    else:
        pattern = re.compile("(" + "|".join(escaped) + ")", flags=re.IGNORECASE | re.UNICODE)

    def _make_token(i: int) -> str:
        return f"__GLS{i}__"

    restore_pat = re.compile(
        r"""
        _{1,2}\s*GLS\s*(\d+)\s*_{1,2}
        """,
        re.IGNORECASE | re.UNICODE | re.VERBOSE
    )

    def translate_with_glossary(text: str) -> str:
        id2tgt: Dict[int, str] = {}
        idx = 0

        def _repl(m: re.Match) -> str:
            nonlocal idx
            src_found = m.group(0)
            tgt_base = lut_lower.get(src_found.lower())
            if tgt_base is None:
                return src_found
            case_mode = _detect_case(src_found)
            tgt_final = _apply_case(tgt_base, case_mode)
            token = _make_token(idx)
            id2tgt[idx] = tgt_final
            idx += 1
            return token

        protected = pattern.sub(_repl, text)
        translated = base_translate_fn(protected)

        if id2tgt:
            def _restore(m: re.Match) -> str:
                num = m.group(1)
                try:
                    i = int(num)
                    return id2tgt.get(i, m.group(0))
                except Exception:
                    return m.group(0)
            translated = restore_pat.sub(_restore, translated)

        return translated

    return translate_with_glossary

# ---------- Progress bar ----------
def _print_progress(i: int, n: int, width: int = 30):
    if n <= 0: return
    i = min(i, n)
    ratio = i / n
    filled = int(ratio * width)
    bar = "#" * filled + "-" * (width - filled)
    sys.stderr.write(f"\r[{bar}] {i}/{n} ({int(ratio*100)}%)")
    sys.stderr.flush()

def _end_progress():
    sys.stderr.write("\n"); sys.stderr.flush()

# ---------- Helpers attributs ----------
# Prefixes meta considérés comme "attributs"
ATTRIBUTE_META_PREFIXES = ("_attribute", "alpc_attr_", "ic_attr_", "attribute_")

_NUM_UNIT_RE = re.compile(
    r'^\s*([+-]?\d+(?:[.,]\d+)?)\s*([%°A-Za-zµΩohmHzVWAJNs\/\-\^″]+)?\s*$',
    re.UNICODE
)

def _looks_numeric_with_unit(val: str) -> bool:
    """True si la valeur ressemble à un nombre éventuellement suivi d'une unité (ex: 110 V, 55″, 60 Hz, 20 %, 12.5 kg)."""
    if not isinstance(val, str):
        return False
    return _NUM_UNIT_RE.match(val.strip()) is not None

def _is_attribute_meta_key(key: str) -> bool:
    if not isinstance(key, str): return False
    return key.startswith(ATTRIBUTE_META_PREFIXES)

# ---------- Translation of one product ----------
def translate_product(prod: Dict[str, Any], translate_fn, options) -> Dict[str, Any]:
    out = json.loads(json.dumps(prod))  # deep copy
    emoji_mode   = getattr(options, "emoji_mode", "keep")
    strip_strong = bool(getattr(options, "strip_strong", False))

    # Text/HTML fields
    if "content_short" in out and out["content_short"] is not None:
        out["content_short"] = translate_html_string(out["content_short"], translate_fn, emoji_mode, strip_strong)
    if "content_long" in out and out["content_long"] is not None:
        out["content_long"]  = translate_html_string(out["content_long"],  translate_fn, emoji_mode, strip_strong)

    # Name (usually plain text)
    if "name" in out and out["name"] is not None:
        out["name"] = translate_fn(out["name"])

    # Slug from translated name
    if getattr(options, "slug_from_name", False):
        if "name" in out and out["name"]:
            out["slug"] = slugify(out["name"])

    # Yoast / metas (exemples courants)
    meta = out.get("meta") or {}
    if "_yoast_wpseo_metadesc" in meta and meta["_yoast_wpseo_metadesc"] is not None:
        meta["_yoast_wpseo_metadesc"] = translate_html_string(meta["_yoast_wpseo_metadesc"], translate_fn, emoji_mode, strip_strong)
    if "_yoast_wpseo_title" in meta and meta["_yoast_wpseo_title"] is not None:
        meta["_yoast_wpseo_title"] = translate_fn(meta["_yoast_wpseo_title"])
    if "_yoast_wpseo_focuskw" in meta and meta["_yoast_wpseo_focuskw"] is not None:
        meta["_yoast_wpseo_focuskw"] = translate_fn(meta["_yoast_wpseo_focuskw"])
    if "_yoast_wpseo_keywordsynonyms" in meta and meta["_yoast_wpseo_keywordsynonyms"] is not None:
        meta["_yoast_wpseo_keywordsynonyms"] = translate_fn(meta["_yoast_wpseo_keywordsynonyms"])

    # ✅ Traduction des attributs en meta
    for k, v in list(meta.items()):
        if not isinstance(v, str):
            continue
        if _is_attribute_meta_key(k):
            # Ne pas traduire si valeur purement numérique / numérique+unité
            if not _looks_numeric_with_unit(v):
                meta[k] = translate_fn(v)

    out["meta"] = meta

    # Language & taxonomy
    out["lang"] = getattr(options, "target", out.get("lang"))
    tax = out.get("tax") or {}
    target_name = getattr(options, "target_name", None) or getattr(options, "target", None)
    if target_name:
        tax["language"] = [target_name]

    # ✅ Traduction des noms côté taxonomie des attributs (ex: al_product-attributes)
    for tax_name, terms in list(tax.items()):
        try:
            if isinstance(tax_name, str) and "attributes" in tax_name and isinstance(terms, list):
                for t in terms:
                    if isinstance(t, dict) and "name" in t and isinstance(t["name"], str) and t["name"]:
                        # Traduit le "name" du terme ; on laisse id/slug intacts (mapping côté import)
                        t["name"] = translate_fn(t["name"])
        except Exception:
            pass

    out["tax"] = tax

    # IDs
    if getattr(options, "set_source_id", False):
        orig_id = prod.get("id")
        if orig_id not in (None, ""):
            out["source_id"] = orig_id
    if getattr(options, "null_id", False):
        out["id"] = None

    return out

# ---------- Main ----------
def main():
    p = argparse.ArgumentParser()
    p.add_argument("--source", required=True, help="Code langue source (ex: fr)")
    p.add_argument("--target", required=True, help="Code langue cible (ex: en)")
    p.add_argument("--target-name", default="", help="Nom lisible de la langue (ex: English)")

    p.add_argument("--input", required=True, help="JSON d'entrée (array d'objets)")
    p.add_argument("--output", required=True, help="JSON de sortie")

    p.add_argument("--null-id", action="store_true", help="Met 'id' à null dans la sortie (création).")
    p.add_argument("--set-source-id", action="store_true", help="Copie 'id' d'origine dans 'source_id'.")
    p.add_argument("--slug-from-name", action="store_true", help="Génère le slug depuis le nom traduit.")
    p.add_argument("--emoji-mode", choices=["keep", "translate"], default="keep", help="Préserver les emojis (keep) ou les laisser passer au traducteur (translate).")
    p.add_argument("--strip-strong", action="store_true", help="Supprime les balises <strong> et </strong> avant la traduction.")

    # Glossaire & progress
    p.add_argument("--glossary-file", default="", help="Fichier glossaire (JSON {src: tgt} ou lignes 'src=tgt').")
    p.add_argument("--glossary-pair", action="append", default=[], help="Paire 'src=tgt' (répétable).")
    p.add_argument("--glossary-mode", choices=["word", "substring"], default="word", help="Correspondance 'word' (délimitée) ou 'substring'.")
    p.add_argument("--progress", choices=["auto", "none"], default="auto", help="Barre de progression sur stderr.")

    args = p.parse_args()

    # Build translator
    translate_fn = build_translator(args.source, args.target)

    # Glossary
    g_file = _load_glossary_from_file(args.glossary_file)
    glossary = _merge_glossary(g_file, args.glossary_pair)
    translate_fn = make_glossary_translate_fn(translate_fn, glossary, args.glossary_mode)

    # Load JSON
    with io.open(args.input, "r", encoding="utf-8") as f:
        data = json.load(f)
    if not isinstance(data, list):
        raise RuntimeError("Le JSON d'entrée doit être une liste d'objets (produits).")

    # Translate
    out = []
    n = len(data)
    show_progress = (args.progress == "auto")
    for i, prod in enumerate(data, 1):
        out.append(translate_product(prod, translate_fn, args))
        if show_progress:
            _print_progress(i, n)
    if show_progress:
        _end_progress()

    # Write JSON
    with io.open(args.output, "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=2)

if __name__ == "__main__":
    main()
