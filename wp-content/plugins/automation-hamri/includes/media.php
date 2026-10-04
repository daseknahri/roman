<?php
/**
 * Image upload / WebP conversion / SSRF-guarded remote import
 *
 * Extracted verbatim from wp-automator-pro.php (single-file → modular).
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function wpap_ajax_upload_fallback() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    if ( empty( $_FILES['image'] ) ) wp_send_json_error( 'No file received.' );

    /* Allow all image mime types */
    add_filter( 'upload_mimes', function ( $mimes ) {
        $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
        $mimes['png']          = 'image/png';
        $mimes['gif']          = 'image/gif';
        $mimes['webp']         = 'image/webp';
        $mimes['avif']         = 'image/avif';
        return $mimes;
    } );
    add_filter( 'wp_check_filetype_and_ext', function ( $data, $file, $filename, $mimes ) {
        if ( empty( $data['type'] ) ) {
            $info = wp_check_filetype( $filename, $mimes );
            if ( $info['type'] ) {
                $data['ext']  = $info['ext'];
                $data['type'] = $info['type'];
            }
        }
        return $data;
    }, 10, 4 );

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attach_id = media_handle_upload( 'image', 0 );
    if ( is_wp_error( $attach_id ) ) {
        wp_send_json_error( 'Upload error: ' . $attach_id->get_error_message() );
    }
    wp_send_json_success( array(
        'attach_id' => $attach_id,
        'image_url' => wp_get_attachment_url( $attach_id ),
    ) );
}

/**
 * Convert a freshly-downloaded JPEG/PNG temp file to WebP.
 *
 * WebP is typically 25–50% smaller than the same JPEG/PNG at equal quality, so
 * every imported image (featured + all generated sub-sizes WordPress derives
 * from it) becomes lighter — a direct mobile LCP + bandwidth win, done ONCE at
 * import with zero per-request cost.
 *
 * Design contract — this must NEVER break a publish:
 *   • Fail-safe: on ANY problem (no GD/Imagick, unreadable file, encode error,
 *     WebP not smaller) it returns the ORIGINAL file unchanged.
 *   • Format-only: it re-encodes the downloaded pixels; it does not touch the AI
 *     generation pipeline or any post content.
 *   • Skips GIF (would lose animation) and already-WebP/AVIF sources.
 *   • Disable with option `wpap_webp_enabled` = '0' or filter `wpap_convert_to_webp`.
 *
 * @param string $tmp       Path to the downloaded temp file.
 * @param string $file_name Intended upload file name (with extension).
 * @return array{0:string,1:string,2:string} [ tmp_path, file_name, mime ] — mime
 *         is 'image/webp' when converted, '' when the original was kept.
 */
function wpap_maybe_convert_image_to_webp( $tmp, $file_name ) {
    $original = array( $tmp, $file_name, '' );

    /* Master switch: option (default ON) then a filter, so it can be disabled
       without a code change and overridden per-import by developers. */
    if ( '1' !== (string) get_option( 'wpap_webp_enabled', '1' ) ) { return $original; }
    if ( ! apply_filters( 'wpap_convert_to_webp', true, $tmp, $file_name ) ) { return $original; }

    if ( ! is_string( $tmp ) || '' === $tmp || ! is_readable( $tmp ) ) { return $original; }

    /* Identify the real type from the file's bytes, not its extension. */
    $info = @getimagesize( $tmp );
    if ( ! is_array( $info ) || empty( $info[2] ) ) { return $original; }
    $type = (int) $info[2];
    if ( ! in_array( $type, array( IMAGETYPE_JPEG, IMAGETYPE_PNG ), true ) ) {
        return $original; /* leave GIF / WebP / AVIF alone */
    }

    /* Decoding a raster image needs ~width*height*4 bytes REGARDLESS of the small
       compressed file size, so a huge-pixel image can exhaust PHP's memory_limit with
       an UNCATCHABLE fatal — it bypasses the try/catch below AND the callers'
       \Throwable guards, aborting the whole batch/cron. Skip conversion beyond ~40MP
       (WordPress's own memory-raised sideload still handles the original file), and
       raise the image memory limit before we decode. */
    if ( (int) $info[0] < 1 || (int) $info[1] < 1 || ( (int) $info[0] * (int) $info[1] ) > 40000000 ) {
        return $original;
    }
    if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'image' ); }

    $quality = (int) apply_filters( 'wpap_webp_quality', 82 );
    if ( $quality < 1 || $quality > 100 ) { $quality = 82; }

    $webp_tmp = preg_replace( '/\.[A-Za-z0-9]+$/', '', (string) $tmp );
    $webp_tmp = ( '' !== (string) $webp_tmp ? $webp_tmp : $tmp ) . '.webp';
    if ( $webp_tmp === $tmp ) { $webp_tmp = $tmp . '.webp'; }

    $ok = false;

    /* Prefer Imagick, fall back to GD — both are common on shared/VPS PHP. */
    if ( class_exists( 'Imagick' ) ) {
        try {
            $im = new Imagick();
            $im->readImage( $tmp );
            $im->setImageFormat( 'webp' );
            $im->setImageCompressionQuality( $quality );
            $im->stripImage();
            $ok = (bool) $im->writeImage( $webp_tmp );
            $im->clear();
            $im->destroy();
        } catch ( Exception $e ) {
            $ok = false;
            /* Release the decoded Imagick bitmap before the GD fallback decodes the
               SAME image, so the two engines never hold two full copies at once. */
            if ( isset( $im ) && $im instanceof Imagick ) {
                try { $im->clear(); $im->destroy(); } catch ( Exception $e2 ) {}
            }
        }
    }

    if ( ! $ok && function_exists( 'imagewebp' ) ) {
        $src = null;
        if ( IMAGETYPE_JPEG === $type && function_exists( 'imagecreatefromjpeg' ) ) {
            $src = @imagecreatefromjpeg( $tmp );
        } elseif ( IMAGETYPE_PNG === $type && function_exists( 'imagecreatefrompng' ) ) {
            $src = @imagecreatefrompng( $tmp );
            if ( $src ) {
                if ( function_exists( 'imagepalettetotruecolor' ) ) { @imagepalettetotruecolor( $src ); }
                imagealphablending( $src, false );
                imagesavealpha( $src, true );   /* keep PNG transparency */
            }
        }
        if ( $src ) {
            $ok = @imagewebp( $src, $webp_tmp, $quality );
            imagedestroy( $src );
        }
    }

    if ( ! $ok || ! is_readable( $webp_tmp ) ) {
        if ( is_file( $webp_tmp ) ) { @unlink( $webp_tmp ); }
        return $original;
    }

    /* Only adopt WebP when it is actually smaller (it usually is; small/flat PNGs
       occasionally aren't). Otherwise keep the source. */
    $src_size  = @filesize( $tmp );
    $webp_size = @filesize( $webp_tmp );
    if ( $webp_size && $src_size && $webp_size >= $src_size ) {
        @unlink( $webp_tmp );
        return $original;
    }

    @unlink( $tmp ); /* discard the original download; sideload the WebP instead */

    $new_name = preg_replace( '/\.[A-Za-z0-9]+$/', '', (string) $file_name );
    if ( '' === (string) $new_name ) { $new_name = 'image'; }
    $new_name .= '.webp';

    return array( $webp_tmp, $new_name, 'image/webp' );
}

/* ════════════════════════════════════════════
   7. AJAX: PROCESS SINGLE TITLE
   ┌─────────────────────────────────────────┐
   │ IMAGE PRIORITY LOGIC:                   │
   │ 1. Manual fallback selected → USE IT   │
   │    DO NOT call Gemini at all.           │
   │ 2. No manual image → call Gemini/AI    │
   └─────────────────────────────────────────┘
════════════════════════════════════════════ */

/** OPT-IN "clean media" — SEO-friendly filenames from the post title + an EXIF/metadata scrub
 *  on imported images. Off by default (Settings → Content options). (ported from build-final) */
function wpap_clean_media_enabled() {
    $c = get_option( 'wpap_content_opts', array() );
    return is_array( $c ) && ! empty( $c['clean_media'] );
}

/** Best-effort EXIF/IPTC scrub: re-encode the JPEG/PNG/WebP in place (drops embedded metadata)
 *  and regenerate its attachment metadata. Never breaks an import over metadata hygiene. GIF/AVIF
 *  are left alone. (ported from build-final) */
function wpap_strip_image_metadata( $attachment_id ) {
    $attachment_id = (int) $attachment_id;
    if ( $attachment_id <= 0 ) { return; }
    $path = get_attached_file( $attachment_id );
    if ( ! $path || ! @file_exists( $path ) ) { return; }
    $type = (string) get_post_mime_type( $attachment_id );
    if ( ! in_array( $type, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return; }
    try {
        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) { return; }
        if ( 'image/jpeg' === $type && method_exists( $editor, 'set_quality' ) ) { $editor->set_quality( 90 ); }
        $saved = $editor->save( $path, $type );   /* overwrite in place; re-encode drops metadata */
        if ( is_wp_error( $saved ) ) { return; }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata( $attachment_id, $path );
        if ( is_array( $meta ) ) { wp_update_attachment_metadata( $attachment_id, $meta ); }
    } catch ( \Throwable $e ) {
        return;   /* never break an import over metadata hygiene */
    }
}

/** Register the allowed-image mime filters ONCE per request (shared by the remote + local
 *  sideloaders) so webp/avif and a mislabelled type both pass wp_check_filetype during
 *  media_handle_sideload(). (ported from build-final 8.49.0) */
function wpap_ensure_image_mime_filters() {
    static $added = false;
    if ( $added ) { return; }
    add_filter( 'upload_mimes', function ( $mimes ) {
        $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
        $mimes['png']          = 'image/png';
        $mimes['gif']          = 'image/gif';
        $mimes['webp']         = 'image/webp';
        $mimes['avif']         = 'image/avif';
        return $mimes;
    } );
    add_filter( 'wp_check_filetype_and_ext', function ( $data, $file, $filename, $mimes ) {
        if ( empty( $data['type'] ) ) {
            $info = wp_check_filetype( $filename, $mimes );
            if ( $info['type'] ) {
                $data['ext']  = $info['ext'];
                $data['type'] = $info['type'];
            }
        }
        return $data;
    }, 10, 4 );
    $added = true;
}

/** After a featured image attachment exists (remote OR local), wire it into the post the SAME
 *  way: set it as the thumbnail, stamp the alt text, and return the attachment URL (the
 *  exported image). Shared so the local branch behaves EXACTLY like Direct Publish.
 *  (ported from build-final 8.49.0; build-v9 carries no FB-card feature, so that block is omitted.) */
function wpap_apply_featured_attachment( int $post_id, int $attach_id, string $title ) {
    set_post_thumbnail( $post_id, $attach_id );
    update_post_meta( $attach_id, '_wp_attachment_image_alt', $title );   /* SEO / Google Images */
    return (string) wp_get_attachment_url( $attach_id );
}

/** Sideload a LOCAL file into the Media Library as an attachment for $post_id (mirrors
 *  wpap_import_remote_image_as_attachment but for a file already on disk — e.g. one extracted
 *  from a Bulk ZIP bundle, so no hosting/public URL is needed). Validates by REAL content
 *  (finfo → getimagesize), not the extension, against the allowed set. Copies the source to a
 *  temp file first so media_handle_sideload can move it without disturbing the original.
 *  Returns an attachment id or WP_Error. (ported from build-final 8.49.0) */
function wpap_import_local_image_as_attachment( string $file_path, int $post_id, string $title ) {
    if ( '' === $file_path || ! @is_file( $file_path ) || ! @is_readable( $file_path ) ) {
        return new WP_Error( 'wpap_local_image_missing', 'Local image file not found or unreadable.' );
    }

    /* Validate by REAL content (finfo → getimagesize), not the extension, against the allowed set. */
    $real_mime = '';
    if ( function_exists( 'finfo_open' ) ) {
        $f = @finfo_open( FILEINFO_MIME_TYPE );
        if ( $f ) { $real_mime = (string) @finfo_file( $f, $file_path ); finfo_close( $f ); }
    }
    if ( '' === $real_mime ) {
        $gi = @getimagesize( $file_path );
        if ( is_array( $gi ) && ! empty( $gi['mime'] ) ) { $real_mime = (string) $gi['mime']; }
    }
    $ext_by_mime = array(
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif',
    );
    if ( ! isset( $ext_by_mime[ $real_mime ] ) ) {
        return new WP_Error( 'wpap_local_image_type', 'Unsupported image type: ' . ( '' !== $real_mime ? $real_mime : 'unknown' ) . '.' );
    }
    $ext = $ext_by_mime[ $real_mime ];

    wpap_ensure_image_mime_filters();
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    /* Filename: with clean-media on, name it from the title (SEO + drops the source name); else
       keep the zip's own basename. Always force a correct extension for the detected type. */
    if ( wpap_clean_media_enabled() && '' !== trim( (string) $title ) ) {
        $slug = sanitize_title( $title );
        if ( '' === $slug ) { $slug = 'image'; }
        $file_name = sanitize_file_name( substr( $slug, 0, 80 ) . '.' . $ext );
    } else {
        $base      = wp_basename( $file_path );
        $file_name = sanitize_file_name( '' !== $base ? $base : ( 'bulk-zip-image.' . $ext ) );
        if ( strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) ) !== $ext ) {
            $file_name = preg_replace( '/\.[^.]*$/', '', $file_name ) . '.' . $ext;
        }
    }

    /* media_handle_sideload MOVES tmp_name into uploads; stage a copy so the original zip file is untouched. */
    $tmp = wp_tempnam( $file_name );
    if ( ! $tmp || ! @copy( $file_path, $tmp ) ) {
        if ( $tmp ) { @unlink( $tmp ); }
        return new WP_Error( 'wpap_local_image_copy', 'Could not stage the local image for import.' );
    }

    $attach_id = media_handle_sideload( array( 'name' => $file_name, 'tmp_name' => $tmp ), $post_id, $title );
    if ( is_wp_error( $attach_id ) ) { @unlink( $tmp ); return $attach_id; }
    if ( wpap_clean_media_enabled() ) {
        wpap_strip_image_metadata( (int) $attach_id );   /* best-effort EXIF/IPTC scrub */
    }
    return $attach_id;
}

function wpap_import_remote_image_as_attachment( string $image_url, int $post_id, string $title ) {
    $image_url = esc_url_raw( trim( $image_url ) );
    if ( ! $image_url || ! wp_http_validate_url( $image_url ) ) {
        return new WP_Error( 'wpap_invalid_image_url', 'Invalid image URL.' );
    }

    /* Deduplicate: if this exact source URL was already imported, REUSE that
       attachment instead of downloading a second copy. Saves disk + bandwidth,
       speeds up re-uploads, and avoids hammering the source host. Each imported
       image is tagged with _wpap_source_image_url below. Filterable off. */
    if ( apply_filters( 'wpap_dedupe_images', true ) ) {
        /* Look up by a fixed-length md5 of the URL, not the raw URL. Source image URLs
           (signed S3/CDN links) routinely exceed 191 bytes, so a meta_value(191) prefix
           index could not resolve a raw-URL lookup — it would scan every same-prefix row.
           The 32-char hash is fully covered by the composite postmeta index, turning this
           per-publish dedup into an index seek. The raw URL is still stored (below) and
           read back here to rule out the astronomically-rare hash collision. */
        $hash = md5( $image_url );
        $dupe = get_posts( array(
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'meta_key'         => '_wpap_source_image_hash',
            'meta_value'       => $hash,
            'fields'           => 'ids',
            'posts_per_page'   => 1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ) );
        if ( ! empty( $dupe ) && (int) $dupe[0] > 0 && 'attachment' === get_post_type( (int) $dupe[0] )
            && get_post_meta( (int) $dupe[0], '_wpap_source_image_url', true ) === $image_url ) {
            return (int) $dupe[0];   /* reuse the existing local copy */
        }
    }

    /* SSRF guard (fast early reject, FAIL CLOSED): block hosts that are unresolvable
       or resolve to loopback / link-local / private ranges (notably 169.254.169.254
       cloud-metadata) which wp_http_validate_url misses. The actual fetch
       (wpap_download_remote_image_safely) also resolves-and-pins, so this is only a
       cheap pre-check. */
    $host = wp_parse_url( $image_url, PHP_URL_HOST );
    if ( '' === wpap_resolve_public_ip( (string) $host ) ) {
        return new WP_Error( 'wpap_blocked_host', 'Image host is unresolvable or resolves to a private or reserved address, and was blocked.' );
    }

    static $mime_filters_added = false;
    if ( ! $mime_filters_added ) {
        add_filter( 'upload_mimes', function ( $mimes ) {
            $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
            $mimes['png']          = 'image/png';
            $mimes['gif']          = 'image/gif';
            $mimes['webp']         = 'image/webp';
            $mimes['avif']         = 'image/avif';
            return $mimes;
        } );
        add_filter( 'wp_check_filetype_and_ext', function ( $data, $file, $filename, $mimes ) {
            if ( empty( $data['type'] ) ) {
                $info = wp_check_filetype( $filename, $mimes );
                if ( $info['type'] ) {
                    $data['ext']  = $info['ext'];
                    $data['type'] = $info['type'];
                }
            }
            return $data;
        }, 10, 4 );
        $mime_filters_added = true;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    /* Download the image into the local Media Library. Retry once on a transient
       failure (a slow/blocked fetch from the image host) so the copy lands here
       and the post gets a proper LOCAL featured image instead of no image. */
    $tmp = wpap_download_remote_image_safely( $image_url, 60 );
    if ( is_wp_error( $tmp ) ) {
        sleep( 2 );
        $tmp = wpap_download_remote_image_safely( $image_url, 90 );   /* second attempt, longer timeout */
    }
    if ( is_wp_error( $tmp ) ) {
        return $tmp;
    }

    $file_name = wp_basename( (string) wp_parse_url( $image_url, PHP_URL_PATH ) );
    if ( ! $file_name ) {
        $file_name = sanitize_file_name( sanitize_title( $title ?: 'bulk-import-image' ) . '.jpg' );
    }

    /* Re-encode JPEG/PNG downloads to WebP (~25–50% smaller → faster mobile LCP).
       Fail-safe: keeps the original file on any problem, so this can never block
       a publish. Records the source URL below for de-duplication either way. */
    $webp      = wpap_maybe_convert_image_to_webp( $tmp, $file_name );
    $tmp       = $webp[0];
    $file_name = $webp[1];

    $file_array = array(
        'name'     => $file_name,
        'tmp_name' => $tmp,
    );
    if ( '' !== $webp[2] ) {
        $file_array['type'] = $webp[2];   /* image/webp */
    }

    $attach_id = media_handle_sideload( $file_array, $post_id, $title );
    if ( is_wp_error( $attach_id ) ) {
        @unlink( $tmp );
        return $attach_id;
    }

    update_post_meta( $attach_id, '_wpap_source_image_url', $image_url );
    update_post_meta( $attach_id, '_wpap_source_image_hash', md5( $image_url ) );   /* index-covered dedup key */

    if ( wpap_clean_media_enabled() ) {
        wpap_strip_image_metadata( (int) $attach_id );   /* best-effort EXIF/IPTC scrub (mirrors the local sideloader) */
    }

    return $attach_id;
}

/* Download a remote image to a temp file WITH per-hop SSRF protection.
   download_url() follows up to 5 redirects without re-checking each target, so a
   public URL that clears an initial guard could 30x-bounce to an internal address,
   and a re-resolving transport could DNS-rebind between check and connect. We
   follow redirects manually (redirection=0) and use wpap_safe_remote_get() on EVERY
   hop, which resolves-once-and-PINS the connection IP (the same defence the Sheet
   fetch uses). Returns a temp-file path or WP_Error. */
function wpap_download_remote_image_safely( $url, $timeout = 60 ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $current  = trim( (string) $url );
    $response = null;
    $followed = 0;
    while ( true ) {
        $response = wpap_safe_remote_get( $current, array(
            'timeout'             => (int) $timeout,
            'redirection'         => 0,            /* follow manually, re-resolving + re-pinning each hop */
            'sslverify'           => true,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => 25 * 1024 * 1024,
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $rcode = (int) wp_remote_retrieve_response_code( $response );
        if ( $rcode < 300 || $rcode >= 400 ) {
            break;   /* not a redirect — done */
        }
        if ( ++$followed > 5 ) {
            return new WP_Error( 'wpap_redirects', 'Too many redirects fetching the image.' );
        }
        $loc = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );
        if ( '' === $loc ) {
            return new WP_Error( 'wpap_redirect', 'Image redirect had no destination.' );
        }
        if ( preg_match( '#^https?://#i', $loc ) ) {
            $current = $loc;
        } elseif ( 0 === strpos( $loc, '/' ) ) {
            $p = wp_parse_url( $current );
            if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
                return new WP_Error( 'wpap_redirect', 'Could not resolve the image redirect.' );
            }
            $current = $p['scheme'] . '://' . $p['host'] . $loc;
        } else {
            return new WP_Error( 'wpap_redirect', 'Unsupported image redirect target.' );
        }
    }
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( 200 !== $code ) {
        return new WP_Error( 'wpap_http_' . $code, sprintf( 'Image fetch failed (HTTP %d).', $code ) );
    }
    $body = wp_remote_retrieve_body( $response );
    if ( '' === $body ) {
        return new WP_Error( 'wpap_empty_image', 'Image response was empty.' );
    }
    $tmp = wp_tempnam( $current );
    if ( ! $tmp ) {
        return new WP_Error( 'wpap_tmp', 'Could not create a temp file for the image.' );
    }
    if ( false === file_put_contents( $tmp, $body ) ) {
        @unlink( $tmp );
        return new WP_Error( 'wpap_tmp_write', 'Could not write the downloaded image to disk.' );
    }
    return $tmp;
}

add_action( 'wp_ajax_wpap_bulk_import_remote_images', 'wpap_ajax_bulk_import_remote_images' );
function wpap_ajax_bulk_import_remote_images() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    @set_time_limit( 300 );
    @ignore_user_abort( true );
    @ini_set( 'max_execution_time', '300' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $raw_items = trim( (string) wp_unslash( $_POST['items'] ?? '' ) );
    if ( '' === $raw_items ) {
        wp_send_json_error( 'Paste rows or URLs first.' );
    }

    if ( strlen( $raw_items ) > wpap_bulk_max_bytes() ) {
        wp_send_json_error( sprintf(
            'Payload too large (%d KB). Maximum is %d KB — split it into smaller batches.',
            (int) round( strlen( $raw_items ) / 1024 ),
            (int) round( wpap_bulk_max_bytes() / 1024 )
        ) );
    }

    $payload = json_decode( $raw_items, true );
    if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
        wp_send_json_error( 'Invalid input. Expected a JSON array of rows.' );
    }

    if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
        $payload = $payload['items'];
    } elseif ( isset( $payload['title'] ) || isset( $payload['caption'] ) || isset( $payload['imageUrl'] ) || isset( $payload['image_url'] ) || isset( $payload['image'] ) ) {
        $payload = array( $payload );
    }

    $items = array_values( array_filter( $payload, function ( $item ) {
        return is_array( $item ) || is_object( $item ) || is_scalar( $item );
    } ) );
    if ( empty( $items ) ) {
        wp_send_json_error( 'No valid rows found.' );
    }

    $created  = array();
    $messages = array();

    /* Cap batch size to bound worker time on a single request. */
    $wpap_max_items = wpap_bulk_max_items();
    if ( count( $items ) > $wpap_max_items ) {
        $messages[] = sprintf(
            '%d row(s) ignored: this batch is capped at %d items per request.',
            count( $items ) - $wpap_max_items,
            $wpap_max_items
        );
        $items = array_slice( $items, 0, $wpap_max_items );
    }

    foreach ( $items as $index => $raw_item ) {
        $row_number = $index + 1;

        $item = is_array( $raw_item ) ? $raw_item : ( is_object( $raw_item ) ? get_object_vars( $raw_item ) : array() );
        if ( is_scalar( $raw_item ) ) {
            $scalar_value = trim( (string) $raw_item );
            if ( wp_http_validate_url( $scalar_value ) ) {
                $item['imageUrl'] = $scalar_value;
            } else {
                $item['title'] = $scalar_value;
            }
        }

        $title_raw = $item['title'] ?? $item['caption'] ?? $item['name'] ?? '';
        $title     = sanitize_text_field( wp_unslash( is_scalar( $title_raw ) ? (string) $title_raw : '' ) );

        $image_raw = $item['imageUrl'] ?? $item['image_url'] ?? $item['image'] ?? '';
        $image_raw = is_scalar( $image_raw ) ? trim( (string) $image_raw ) : '';
        $image_url = $image_raw ? esc_url_raw( $image_raw ) : '';

        if ( $image_raw && ! wp_http_validate_url( $image_raw ) ) {
            $messages[] = sprintf( 'Row %d skipped: invalid image URL.', $row_number );
            continue;
        }

        if ( ! $title && $image_url ) {
            $title = sprintf( 'Imported Image %d', $row_number );
        } elseif ( ! $title ) {
            $title = sprintf( 'Imported Item %d', $row_number );
        }

        $attach_id = 0;
        if ( $image_url ) {
            /* Isolate each import — a fatal on one image (OOM in thumbnailing, a
               hostile file) must not abort the whole batch. */
            try {
                $attach_id = wpap_import_remote_image_as_attachment( $image_url, 0, $title );
            } catch ( \Throwable $e ) {
                error_log( '[Automation Hamri] Image import crashed on row ' . $row_number . ': ' . $e->getMessage() );
                $messages[] = sprintf( 'Row %d skipped: image import failed (%s).', $row_number, $e->getMessage() );
                continue;
            }
            if ( is_wp_error( $attach_id ) || ! $attach_id ) {
                $messages[] = sprintf(
                    'Row %d skipped: %s',
                    $row_number,
                    is_wp_error( $attach_id ) ? $attach_id->get_error_message() : 'image download failed.'
                );
                continue;
            }
        }

        $created[] = array(
            'attach_id' => (int) $attach_id,
            'image_url' => $attach_id ? (string) wp_get_attachment_url( $attach_id ) : '',
            'source_url' => $image_url,
            'title'     => $title,
            'label'     => $attach_id ? ( wp_basename( (string) wp_parse_url( $image_url, PHP_URL_PATH ) ) ?: $title ) : 'No image',
        );
    }

    if ( empty( $created ) ) {
        wp_send_json_error( array(
            'message'  => 'No images were imported.',
            'messages' => $messages,
        ) );
    }

    wp_send_json_success( array(
        'created'  => count( $created ),
        'skipped'  => count( $items ) - count( $created ),
        'total'    => count( $items ),
        'messages' => $messages,
        'items'    => $created,
    ) );
}

add_action( 'wp_ajax_wpap_bulk_import_distribution', 'wpap_ajax_bulk_import_distribution' );
