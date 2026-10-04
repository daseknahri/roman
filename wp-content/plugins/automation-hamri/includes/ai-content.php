<?php
/**
 * AI content + image generation — DO NOT EDIT (AI generation pipeline)
 *
 * Extracted verbatim from wp-automator-pro.php (single-file → modular).
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function wpap_generate_content( string $title, string $api_key, string $target_lang = 'auto', int $num_pages = 2 ) {

    /* Build language instruction for Claude */
    $lang_map = array(
        'en'=>'English','fr'=>'French','es'=>'Spanish','de'=>'German',
        'it'=>'Italian','pt'=>'Portuguese','nl'=>'Dutch','pl'=>'Polish',
        'ro'=>'Romanian','hu'=>'Hungarian','bg'=>'Bulgarian','cs'=>'Czech',
        'sk'=>'Slovak','hr'=>'Croatian','sv'=>'Swedish','da'=>'Danish',
        'fi'=>'Finnish','el'=>'Greek','ru'=>'Russian','uk'=>'Ukrainian',
        'tr'=>'Turkish','ar'=>'Arabic','he'=>'Hebrew','fa'=>'Persian',
        'zh'=>'Chinese (Simplified)','ja'=>'Japanese','ko'=>'Korean',
        'hi'=>'Hindi','id'=>'Indonesian','vi'=>'Vietnamese','th'=>'Thai',
    );
    $lang_name = ( $target_lang !== 'auto' && isset( $lang_map[ $target_lang ] ) )
        ? $lang_map[ $target_lang ]
        : '';

    /* Language instruction line — empty string when auto-detect */
    $lang_line = $lang_name
        ? "LANGUAGE INSTRUCTION: Write the ENTIRE article in " . $lang_name . ". Translate the title into " . $lang_name . " as well. Every word — including the FB hook and all navigation text — must be in " . $lang_name . ".\n\n"
        : '';

    /* Build dynamic page tags based on $num_pages */
    $words_per_page = (int) round( 600 / $num_pages );
    $page_tags      = '';
    for ( $pg = 1; $pg <= $num_pages; $pg++ ) {
        if ( $pg === 1 ) {
            $page_tags .= "[PAGE{$pg}]\n"
                       . "First ~{$words_per_page} words in the target language. Introduction + 2-3 rich paragraphs.\n\n";
        } elseif ( $pg === $num_pages ) {
            $page_tags .= "[PAGE{$pg}]\n"
                       . "Final ~{$words_per_page} words. Conclusion + call-to-action.\n\n";
        } else {
            $page_tags .= "[PAGE{$pg}]\n"
                       . "~{$words_per_page} words. Continuation with tips and details.\n\n";
        }
    }
    $total_words = 600 + ( ($num_pages - 2) * 150 );  /* More pages = more content */

    $prompt = $lang_line
            . "Write a professional {$total_words}-word SEO article about: \"" . addslashes( $title ) . "\"\n\n"
            . "Divide the article into EXACTLY {$num_pages} pages using these tags:\n\n"
            . $page_tags
            . "[FB_TEXT]\n"
            . "Write a viral Facebook hook of EXACTLY 2 sentences in the SAME language as the article.\n"
            . "The hook MUST be a creative, engaging teaser drawn from the article CONTENT — NOT the title.\n"
            . "STRICTLY FORBIDDEN: Do NOT copy, echo, or paraphrase the article title.\n"
            . "Write a unique summary that highlights a key insight or benefit from the article body.\n"
            . "Max 40 words total. Engaging and conversational tone. No hashtags. No emojis. No CTA.\n"
            . "STOP after the 2 sentences. Do NOT add any call-to-action or comment mention.\n\n"
            . "[LANG]\n"
            . "Write only the 2-letter ISO language code (e.g. en, fr, ar, hu, es, de, it, pt, tr, nl, ru).\n\n"
            . "CRITICAL OUTPUT RULES — READ CAREFULLY:\n"
            . "Respond with raw HTML only.\n"
            . "If you include any backticks or the word html at the start, the system will FAIL.\n"
            . "PURE HTML ONLY. No markdown. No code fences. No backticks. No html label.\n"
            . "DO NOT start your response with ```html or ``` or any backtick character.\n"
            . "Plain text only inside PAGE tags. No bullet points.\n"
            . "Write all content in the target language.";

    $r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
        'timeout' => 120,
        'headers' => array(
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ),
        'body' => wp_json_encode( array(
            'model'      => 'claude-opus-4-5',
            'max_tokens' => 1400,
            'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
        ) ),
    ) );

    if ( is_wp_error( $r ) ) return $r;

    $code = wp_remote_retrieve_response_code( $r );
    $body = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( $code !== 200 ) {
        return new WP_Error( 'claude', 'Claude API ' . $code . ': ' . ( $body['error']['message'] ?? 'unknown error' ) );
    }

    $text    = $body['content'][0]['text'] ?? '';
    $page1   = '';
    $page2   = '';
    $fb_text = '';
    $lang    = 'en';

    /* Parse N page tags dynamically */
    $pages_arr = array();
    for ( $pg = 1; $pg <= $num_pages; $pg++ ) {
        $next_tag = ( $pg < $num_pages ) ? "\[PAGE" . ( $pg + 1 ) . "\]" : '(?:\[FB_TEXT\]|$)';
        if ( preg_match( '/\[PAGE' . $pg . '\](.*?)' . $next_tag . '/s', $text, $m ) ) {
            $pages_arr[] = wpap_nl2p( wpap_strip_markdown( trim( $m[1] ) ) );
        }
    }
    if ( preg_match( '/\[FB_TEXT\](.*?)(?:\[LANG\]|$)/s',  $text, $m ) ) $fb_text = trim( $m[1] );
    if ( preg_match( '/\[LANG\]\s*([a-z]{2})/i',            $text, $m ) ) $lang    = strtolower( trim( $m[1] ) );

    /* Fallback: if parsing failed, split text evenly */
    if ( empty( $pages_arr ) ) {
        $words    = explode( ' ', strip_tags( $text ) );
        $chunk    = (int) ceil( count( $words ) / $num_pages );
        for ( $pg = 0; $pg < $num_pages; $pg++ ) {
            $pages_arr[] = wpap_nl2p( implode( ' ', array_slice( $words, $pg * $chunk, $chunk ) ) );
        }
        $fb_text  = substr( $text, 0, 300 );
    }

    /* Keep backward-compat vars for page1/page2 */
    $page1 = $pages_arr[0] ?? '';
    $page2 = $pages_arr[1] ?? '';

    /* Language auto-detection from content */
    if ( $lang === 'en' ) {
        $p = $page1;
        if      ( preg_match( '/[\x{0600}-\x{06FF}]/u', $p ) )  $lang = 'ar';
        elseif  ( preg_match( '/[\x{4E00}-\x{9FFF}]/u', $p ) )  $lang = 'zh';
        elseif  ( preg_match( '/[\x{0400}-\x{04FF}]/u', $p ) )  $lang = 'ru';
        elseif  ( preg_match( '/[\x{3040}-\x{30FF}]/u', $p ) )  $lang = 'ja';
        elseif  ( preg_match( '/[\x{AC00}-\x{D7AF}]/u', $p ) )  $lang = 'ko';
        elseif  ( preg_match( '/[őűáéíóöü]/u', $p ) &&
                  preg_match( '/\b(az|egy|van|nem|hogy|és|csak|már)\b/iu', $p ) ) $lang = 'hu';
        elseif  ( preg_match( '/[àâèéêëîïôùûüç]/u', $p ) &&
                  preg_match( '/\b(le|la|les|est|une|avec|pour)\b/i', $p ) )      $lang = 'fr';
        elseif  ( preg_match( '/\b(le|la|les|est|avec|pour|aussi|très)\b/i',$p)) $lang = 'fr';
        elseif  ( preg_match( '/[¿¡ñ]/u', $p ) )                                  $lang = 'es';
        elseif  ( preg_match( '/\b(del|los|las|para|con|pero|más|son)\b/i', $p )) $lang = 'es';
        elseif  ( preg_match( '/[ß]/u', $p ) )                                     $lang = 'de';
        elseif  ( preg_match( '/\b(der|die|das|und|mit|ist|nicht|für)\b/i', $p )) $lang = 'de';
        elseif  ( preg_match( '/\b(della|dello|questo|questa|sono|anche)\b/i',$p)) $lang = 'it';
        elseif  ( preg_match( '/[ãõ]/u', $p ) )                                    $lang = 'pt';
        elseif  ( preg_match( '/\b(não|com|para|uma|dos|mais|seu)\b/i', $p ) )    $lang = 'pt';
        elseif  ( preg_match( '/[şğı]/u', $p ) )                                   $lang = 'tr';
        elseif  ( preg_match( '/\b(van|het|een|zijn|maar|voor|heeft)\b/i', $p ) ) $lang = 'nl';
    }

    return array(
        'page1'   => $page1,        /* already processed by wpap_nl2p */
        'page2'   => $page2,
        'pages'   => $pages_arr,    /* full array of N pages */
        'fb_text' => $fb_text,
        'lang'    => $lang,
    );
}

/* ==============================================
   STRIP MARKDOWN ARTIFACTS
   Removes ```html, ```, and backtick wrappers
   that AI models sometimes add to responses.
============================================== */
function wpap_strip_markdown( string $text ): string {
    $text = trim( $text );

    /* Pass 1: Remove full fenced blocks ```lang ... ``` (multi-line) */
    $text = preg_replace( '/^```[a-zA-Z]*\r?\n?/m', '', $text );
    $text = preg_replace( '/\r?\n?```\s*$/m',        '', $text );

    /* Pass 2: Remove any remaining ``` or `` sequences */
    $text = str_replace( array( '```', '``' ), '', $text );

    /* Pass 3: Remove single-backtick wrappers around the entire string */
    $text = trim( $text );
    if ( strlen( $text ) > 2 && $text[0] === '`' && $text[ strlen( $text ) - 1 ] === '`' ) {
        $text = trim( $text, '`' );
    }

    /* Pass 4: Remove stray lone backticks */
    $text = str_replace( '`', '', $text );

    /* Pass 5: Remove "html" label left at start after stripping fences */
    $text = preg_replace( '/^html\s*\r?\n?/i', '', $text );

    return trim( $text );
}

/* ==============================================
   NUCLEAR HTML CLEANER
   Called on every page segment before saving.
   Handles two opposite failure modes:
     A) AI returned markdown/plain-text with
        literal <p> showing as escaped text.
     B) AI returned valid HTML that must be
        preserved as-is.
============================================== */
function wpap_nuclear_clean( string $text ): string {

    $text = wpap_strip_markdown( $text );
    $text = trim( $text );

    if ( $text === '' ) return '';

    /* ── Detect which failure mode we are in ──
     *
     * Mode A — AI returned the HTML tags as visible escaped text.
     * This happens when the raw string contains &lt;p&gt; or the
     * literal 4-char sequence "<p>" but *also* contains plain prose
     * mixed in, suggesting the AI double-escaped or mixed formats.
     *
     * Heuristic: if the text contains the literal strings "&lt;" or
     * escaped angle brackets as plain visible text characters, decode
     * them first so we end up with real HTML.
     */
    if ( strpos( $text, '&lt;' ) !== false || strpos( $text, '&amp;lt;' ) !== false ) {
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = trim( $text );
    }

    /* ── Now decide: does the text already contain real HTML tags? ── */
    $has_html = (bool) preg_match( '/<(p|h[1-6]|ul|ol|li|strong|em|br|div|span|a)\b/i', $text );

    if ( $has_html ) {
        /*
         * Text contains real HTML. Preserve it but strip any
         * stray plain-text artifacts that aren't inside tags
         * (e.g. a lone "&lt;p&gt;" that survived entity-decode).
         * wp_kses_post allows all standard post HTML.
         */
        return wp_kses_post( $text );
    }

    /*
     * Pure plain text — wrap paragraphs in <p> tags.
     * Split on double newlines, nl2br single newlines.
     * Do NOT esc_html here because the AI content is
     * trusted article text, not user input, and escaping
     * would make tags like apostrophes show as &#039;.
     */
    $paras = preg_split( '/\n{2,}/', $text );
    $html  = '';
    foreach ( $paras as $para ) {
        $para = trim( $para );
        if ( $para !== '' ) {
            $html .= '<p>' . nl2br( $para ) . '</p>' . "\n";
        }
    }
    return $html ?: '<p>' . $text . '</p>';
}

function wpap_nl2p( string $text ): string {
    return wpap_nuclear_clean( $text );
}


/* ==============================================
   INTERNAL LINK INJECTION
============================================== */
function wpap_inject_internal_links( string $content, array $pool, string $engine, string $claude_key, string $gemini_key ): string {
    if ( empty( $pool ) ) return $content;
    $list = '';
    foreach ( $pool as $p ) {
        $list .= '  - "' . addslashes( $p['title'] ) . '" -> ' . $p['url'] . "\n";
    }
    $prompt = "INTERNAL LINKING:\nEmbed 2-3 natural HTML anchor links (<a href=\"URL\">keyword</a>) inside the article where contextually relevant.\nPool:\n{$list}Rules: feel natural, no forced links, each URL max once.\n\nBelow is the article. Return ONLY the modified article HTML, no explanation.\n\n" . $content;
    $text = '';
    if ( $engine === 'claude_haiku' && $claude_key ) {
        $r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 60,
            'headers' => array( 'x-api-key' => $claude_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ),
            'body'    => wp_json_encode( array( 'model' => 'claude-haiku-4-5-20251001', 'max_tokens' => 2048,
                'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ) ) ),
        ) );
        if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
            $b = json_decode( wp_remote_retrieve_body( $r ), true );
            $text = $b['content'][0]['text'] ?? '';
        }
    } elseif ( $gemini_key ) {
        $mdl = ( $engine === 'gemini_pro' ) ? 'gemini-1.5-pro' : 'gemini-2.0-flash';
        $r   = wp_remote_post( "https://generativelanguage.googleapis.com/v1beta/models/{$mdl}:generateContent?key={$gemini_key}",
            array( 'timeout' => 60, 'headers' => array( 'Content-Type' => 'application/json' ),
                   'body' => wp_json_encode( array(
                       'contents' => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
                       'generationConfig' => array( 'maxOutputTokens' => 2048 ),
                   ) ) )
        );
        if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
            $b = json_decode( wp_remote_retrieve_body( $r ), true );
            $text = $b['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }
    }
    return ( $text && strpos( $text, '<a href' ) !== false && strlen( $text ) > 200 ) ? $text : $content;
}

/* ════════════════════════════════════════════
   GEMINI CONTENT GENERATOR (Free/Fast alternative)
   Uses gemini-2.0-flash to generate multilingual
   SEO articles. Same page-tag format as Claude.
════════════════════════════════════════════ */
function wpap_generate_content_gemini( string $title, string $api_key, string $target_lang = 'auto', int $num_pages = 2 ) {

    /* Reuse same lang_map as Claude engine */
    $lang_map = array(
        'en'=>'English','fr'=>'French','es'=>'Spanish','de'=>'German',
        'it'=>'Italian','pt'=>'Portuguese','nl'=>'Dutch','pl'=>'Polish',
        'ro'=>'Romanian','hu'=>'Hungarian','bg'=>'Bulgarian','cs'=>'Czech',
        'sk'=>'Slovak','hr'=>'Croatian','sv'=>'Swedish','da'=>'Danish',
        'fi'=>'Finnish','el'=>'Greek','ru'=>'Russian','uk'=>'Ukrainian',
        'tr'=>'Turkish','ar'=>'Arabic','he'=>'Hebrew','fa'=>'Persian',
        'zh'=>'Chinese (Simplified)','ja'=>'Japanese','ko'=>'Korean',
        'hi'=>'Hindi','id'=>'Indonesian','vi'=>'Vietnamese','th'=>'Thai',
    );
    $lang_name = ( $target_lang !== 'auto' && isset( $lang_map[ $target_lang ] ) )
        ? $lang_map[ $target_lang ]
        : '';

    $lang_line = $lang_name
        ? "LANGUAGE INSTRUCTION: Write the ENTIRE article in " . $lang_name . ". Translate the title into " . $lang_name . " as well. Every word must be in " . $lang_name . ".\n\n"
        : '';

    /* Build page tags */
    $words_per_page = (int) round( 600 / $num_pages );
    $page_tags      = '';
    for ( $pg = 1; $pg <= $num_pages; $pg++ ) {
        if ( $pg === 1 ) {
            $page_tags .= "[PAGE{$pg}]\nFirst ~{$words_per_page} words. Introduction + 2-3 rich paragraphs.\n\n";
        } elseif ( $pg === $num_pages ) {
            $page_tags .= "[PAGE{$pg}]\nFinal ~{$words_per_page} words. Conclusion + call-to-action.\n\n";
        } else {
            $page_tags .= "[PAGE{$pg}]\n~{$words_per_page} words. Continuation with tips and details.\n\n";
        }
    }
    $total_words = 600 + ( ( $num_pages - 2 ) * 150 );

    $prompt = $lang_line
            . "Write a professional {$total_words}-word SEO article about: \"" . addslashes( $title ) . "\"\n\n"
            . "Divide the article into EXACTLY {$num_pages} pages using these tags:\n\n"
            . $page_tags
            . "[FB_TEXT]\n"
            . "Write a viral Facebook hook of EXACTLY 2 sentences in the SAME language as the article.\n"
            . "The hook MUST be a creative, engaging teaser drawn from the article CONTENT — NOT the title.\n"
            . "STRICTLY FORBIDDEN: Do NOT copy, echo, or paraphrase the article title.\n"
            . "Write a unique summary that highlights a key insight or benefit from the article body.\n"
            . "Max 40 words total. Engaging and conversational tone. No hashtags. No emojis. No CTA.\n"
            . "STOP after the 2 sentences. Do NOT add any call-to-action or comment mention.\n\n"
            . "[LANG]\n"
            . "Write only the 2-letter ISO language code (e.g. en, fr, ar, es, de, it, pt, tr, nl, ru).\n\n"
            . "CRITICAL OUTPUT RULES — READ CAREFULLY:\n"
            . "Respond with raw HTML only.\n"
            . "If you include any backticks or the word html at the start, the system will FAIL.\n"
            . "PURE HTML ONLY. No markdown. No code fences. No backticks. No html label.\n"
            . "DO NOT start your response with ```html or ``` or any backtick character.\n"
            . "Plain text only inside PAGE tags. No bullet points.";

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $api_key;

    $r = wp_remote_post( $endpoint, array(
        'timeout' => 120,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
            'generationConfig' => array(
                'temperature'     => 0.7,
                'maxOutputTokens' => 2048,
            ),
        ) ),
    ) );

    if ( is_wp_error( $r ) ) return $r;

    $code = wp_remote_retrieve_response_code( $r );
    $body = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( $code !== 200 ) {
        $msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
        return new WP_Error( 'gemini_content', 'Gemini Content API: ' . $msg );
    }

    $text    = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ( ! $text ) {
        return new WP_Error( 'gemini_content', 'Gemini returned empty content.' );
    }

    /* Parse pages — identical logic to Claude parser */
    $pages_arr = array();
    $fb_text   = '';
    $lang      = 'en';

    for ( $pg = 1; $pg <= $num_pages; $pg++ ) {
        $next_tag = ( $pg < $num_pages ) ? "\[PAGE" . ( $pg + 1 ) . "\]" : '(?:\[FB_TEXT\]|$)';
        if ( preg_match( '/\[PAGE' . $pg . '\](.*?)' . $next_tag . '/s', $text, $m ) ) {
            $pages_arr[] = wpap_nl2p( wpap_strip_markdown( trim( $m[1] ) ) );
        }
    }
    if ( preg_match( '/\[FB_TEXT\](.*?)(?:\[LANG\]|$)/s',  $text, $m ) ) $fb_text = trim( $m[1] );
    if ( preg_match( '/\[LANG\]\s*([a-z]{2})/i',            $text, $m ) ) $lang    = strtolower( trim( $m[1] ) );

    /* Fallback split */
    if ( empty( $pages_arr ) ) {
        $words = explode( ' ', strip_tags( $text ) );
        $chunk = (int) ceil( count( $words ) / $num_pages );
        for ( $pg = 0; $pg < $num_pages; $pg++ ) {
            $pages_arr[] = wpap_nl2p( implode( ' ', array_slice( $words, $pg * $chunk, $chunk ) ) );
        }
        $fb_text = substr( $text, 0, 300 );
    }

    return array(
        'page1'   => $pages_arr[0] ?? '',
        'page2'   => $pages_arr[1] ?? '',
        'pages'   => $pages_arr,
        'fb_text' => $fb_text,
        'lang'    => $lang,
    );
}

/* ════════════════════════════════════════════
   CLAUDE IMAGE GENERATOR (Premium)
   Uses Anthropic claude-3-5-sonnet to describe → then Pollinations renders
   Falls back to prompt-based generation via DALL-E style description.
   Note: Anthropic API does not natively generate images; we use their
   vision-grade model to craft an optimised prompt, then call Pollinations.
════════════════════════════════════════════ */
function wpap_generate_image_claude( string $title, int $post_id, string $api_key ) {
    /* Step 1: Ask Claude to write the best image-gen prompt for this title */
    $r_prompt = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
        'timeout' => 30,
        'headers' => array(
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ),
        'body' => wp_json_encode( array(
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 120,
            'messages'   => array( array(
                'role'    => 'user',
                'content' => 'Write a single-line image generation prompt (max 80 words) for a professional food photography photo of: "' . addslashes( $title ) . '". Focus on: lighting, composition, style, colors. Output ONLY the prompt, no labels.',
            ) ),
        ) ),
    ) );

    $img_prompt = '';
    if ( ! is_wp_error( $r_prompt ) && 200 === (int) wp_remote_retrieve_response_code( $r_prompt ) ) {
        $bd         = json_decode( wp_remote_retrieve_body( $r_prompt ), true );
        $img_prompt = trim( $bd['content'][0]['text'] ?? '' );
    }

    /* Fallback prompt if Claude call failed */
    if ( ! $img_prompt ) {
        $img_prompt = 'Professional food photography of ' . $title . ', studio lighting, vibrant colors, appetizing, 4K';
    }

    /* Step 2: Generate image via Pollinations with the Claude-crafted prompt */
    $poll_url = 'https://image.pollinations.ai/prompt/' . urlencode( $img_prompt ) . '?width=800&height=600&nologo=true&enhance=true&model=flux';
    $r_img    = wp_remote_get( $poll_url, array( 'timeout' => 90 ) );

    if ( is_wp_error( $r_img ) ) {
        return new WP_Error( 'claude_img', 'Pollinations error: ' . $r_img->get_error_message() );
    }

    $img_data = wp_remote_retrieve_body( $r_img );
    $ct       = wp_remote_retrieve_header( $r_img, 'content-type' );

    if ( ! $img_data || false === strpos( $ct, 'image' ) ) {
        return new WP_Error( 'claude_img', 'No image returned from Pollinations (Claude engine)' );
    }

    /* Save to WP Media Library */
    $ext      = ( false !== strpos( $ct, 'jpeg' ) ) ? 'jpg' : 'png';
    $mime     = ( $ext === 'jpg' ) ? 'image/jpeg' : 'image/png';
    $filename = sanitize_file_name( $post_id . '-claude-' . sanitize_title( $title ) . '.' . $ext );
    $upload   = wp_upload_bits( $filename, null, $img_data );

    if ( $upload['error'] ) {
        return new WP_Error( 'upload', $upload['error'] );
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $aid = wp_insert_attachment( array(
        'guid'           => $upload['url'],
        'post_mime_type' => $mime,
        'post_title'     => $title,
        'post_status'    => 'inherit',
    ), $upload['file'], $post_id );
    wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $upload['file'] ) );
    return $aid;
}

/* ==============================================
   GEMINI IMAGE ENGINE (Flash+30s retry / Pro)
   mode=flash → gemini-2.0-flash-preview-image-generation
   mode=pro   → imagen-3.0-generate-002
   Fallback: Pollinations
============================================== */
function wpap_generate_image_gemini( string $title, int $post_id, string $api_key, string $mode = 'flash' ) {
    $prompt = 'Professional food photography of "' . $title . '". Studio lighting, vibrant colors, 4K, no text.';
    $b64 = ''; $mime = 'image/jpeg';
    if ( $mode === 'pro' ) {
        $r = wp_remote_post(
            'https://generativelanguage.googleapis.com/v1beta/models/imagen-3.0-generate-002:predict?key=' . $api_key,
            array( 'timeout'=>90,'headers'=>array('Content-Type'=>'application/json'),
                   'body'=>wp_json_encode(array('instances'=>array(array('prompt'=>$prompt)),'parameters'=>array('sampleCount'=>1,'aspectRatio'=>'4:3'))) )
        );
        if ( !is_wp_error($r) && 200===(int)wp_remote_retrieve_response_code($r) ) {
            $d=$json=json_decode(wp_remote_retrieve_body($r),true);
            $b64=$d['predictions'][0]['bytesBase64Encoded']??''; $mime=$d['predictions'][0]['mimeType']??'image/jpeg';
        }
    } else {
        $ep   = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent?key=' . $api_key;
        $body = wp_json_encode(array('contents'=>array(array('parts'=>array(array('text'=>$prompt)))),'generationConfig'=>array('responseModalities'=>array('TEXT','IMAGE'))));
        for ( $try=1; $try<=2; $try++ ) {
            $r    = wp_remote_post($ep,array('timeout'=>90,'sslverify'=>false,'headers'=>array('Content-Type'=>'application/json'),'body'=>$body));
            $code = is_wp_error($r)?0:(int)wp_remote_retrieve_response_code($r);
            if ( $code===429 && $try===1 ) { sleep(30); continue; }
            if ( !is_wp_error($r) && $code===200 ) {
                $d=json_decode(wp_remote_retrieve_body($r),true);
                foreach($d['candidates'][0]['content']['parts']??array() as $part) {
                    if(isset($part['inlineData'])){$b64=$part['inlineData']['data'];$mime=$part['inlineData']['mimeType']??'image/jpeg';break 2;}
                }
            }
            break;
        }
    }
    if ( !$b64 ) {
        $pr=wp_remote_get('https://image.pollinations.ai/prompt/'.urlencode($prompt).'?width=800&height=600&nologo=true',array('timeout'=>90));
        if(!is_wp_error($pr)&&200===(int)wp_remote_retrieve_response_code($pr)){
            $img=wp_remote_retrieve_body($pr); $ct=wp_remote_retrieve_header($pr,'content-type');
            if($img&&false!==strpos($ct,'image'))return wpap_save_image_to_library($img,$ct,$post_id,$title,'poll');
        }
        return new WP_Error('gemini_img','All image attempts failed.');
    }
    return wpap_save_image_to_library(base64_decode($b64),$mime,$post_id,$title,$mode);
}

/* ==============================================
   PEXELS IMAGE ENGINE
============================================== */
function wpap_generate_image_pexels( string $title, int $post_id, string $api_key ) {
    /* Try up to 2 search queries — exact title then generic "food" fallback */
    $queries = array( $title, 'delicious food dish meal' );
    $url     = '';

    foreach ( $queries as $q ) {
        $r = wp_remote_get(
            'https://api.pexels.com/v1/search?query=' . urlencode( $q ) . '&per_page=5&orientation=landscape',
            array(
                'timeout'    => 25,
                'sslverify'  => false,
                'headers'    => array( 'Authorization' => $api_key ),
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            )
        );
        if ( is_wp_error( $r ) ) continue;
        if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
            /* Rate-limited? Wait 3s and retry once */
            sleep( 3 );
            $r = wp_remote_get(
                'https://api.pexels.com/v1/search?query=' . urlencode( $q ) . '&per_page=5&orientation=landscape',
                array( 'timeout'=>25,'sslverify'=>false,'headers'=>array('Authorization'=>$api_key) )
            );
            if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) continue;
        }
        $d   = json_decode( wp_remote_retrieve_body( $r ), true );
        $url = $d['photos'][0]['src']['large2x'] ?? $d['photos'][0]['src']['large'] ?? '';
        if ( $url ) break; /* Found a photo — stop trying queries */
    }

    if ( ! $url ) {
        /* Final fallback: Pollinations free image */
        return wpap_generate_image_pollinations( $title, $post_id );
    }

    /* Download the photo with retry */
    $img = ''; $ct = '';
    for ( $try = 1; $try <= 2; $try++ ) {
        $r2  = wp_remote_get( $url, array( 'timeout'=>60,'sslverify'=>false ) );
        if ( is_wp_error( $r2 ) ) { sleep(2); continue; }
        $img = wp_remote_retrieve_body( $r2 );
        $ct  = wp_remote_retrieve_header( $r2, 'content-type' );
        if ( $img && false !== strpos( $ct, 'image' ) ) break;
        sleep(2);
    }

    if ( ! $img || false === strpos( $ct, 'image' ) ) {
        return wpap_generate_image_pollinations( $title, $post_id );
    }

    return wpap_save_image_to_library( $img, $ct, $post_id, $title, 'pexels' );
}

/* Pollinations free fallback (no API key needed) */
function wpap_generate_image_pollinations( string $title, int $post_id ) {
    $prompt = 'Professional food photography of ' . $title . ', studio lighting, vibrant colors, 4K';
    $url    = 'https://image.pollinations.ai/prompt/' . urlencode( $prompt ) . '?width=800&height=600&nologo=true';
    $r      = wp_remote_get( $url, array( 'timeout'=>90,'sslverify'=>false ) );
    if ( is_wp_error( $r ) ) return $r;
    $img = wp_remote_retrieve_body( $r );
    $ct  = wp_remote_retrieve_header( $r, 'content-type' );
    if ( ! $img || false === strpos( $ct, 'image' ) ) {
        return new WP_Error( 'poll', 'Pollinations returned no image.' );
    }
    return wpap_save_image_to_library( $img, $ct, $post_id, $title, 'poll' );
}

/* Save image bytes to Media Library */
function wpap_save_image_to_library( string $data, string $mime, int $post_id, string $title, string $prefix='' ) {
    require_once ABSPATH.'wp-admin/includes/image.php';
    $ext=(strpos($mime,'jpeg')!==false||strpos($mime,'jpg')!==false)?'jpg':'png';
    $m=($ext==='jpg')?'image/jpeg':'image/png';
    $fn=sanitize_file_name($post_id.($prefix?'-'.$prefix:'').'-'.sanitize_title($title).'.'.$ext);
    $up=wp_upload_bits($fn,null,$data);
    if($up['error'])return new WP_Error('upload',$up['error']);
    $aid=wp_insert_attachment(array('guid'=>$up['url'],'post_mime_type'=>$m,'post_title'=>$title,'post_status'=>'inherit'),$up['file'],$post_id);
    wp_update_attachment_metadata($aid,wp_generate_attachment_metadata($aid,$up['file']));
    return $aid;
}





/* ════════════════════════════════════════════
   13. FRONT-END: NEXT-PAGE BUTTON + STYLES
════════════════════════════════════════════ */
add_action( 'wp_head', 'wpap_frontend' );
