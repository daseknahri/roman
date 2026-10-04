/* WP Automator — Author Tools (Gutenberg / block editor panel)
 * A PluginDocumentSettingPanel that reproduces the Classic meta box: every field
 * binds to post meta through the core/editor store, plus a live readiness
 * checklist and a client-side "auto-fill empty fields" helper. Server-side save
 * (wpap_ed_apply_derived on rest_after_insert_post) remains the source of truth;
 * this only mirrors that behaviour in the browser. No JSX — plain wp.element.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.components ) { return; }

	var el          = wp.element.createElement;
	var useSelect   = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var registerPlugin = wp.plugins.registerPlugin;
	var C  = wp.components;
	var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };
	var DOMAIN = 'wp-automator-pro';
	var MIN_WORDS = 150;

	/* Resolve PluginDocumentSettingPanel from whichever package exposes it. */
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
	            ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel || ! registerPlugin ) { return; }

	/* ── Helpers ported from editor-tools.js ── */

	function clip( s, max ) {
		s = ( s || '' ).replace( /\s+/g, ' ' ).trim();
		if ( s.length <= max ) { return s; }
		var c = s.slice( 0, max );
		var i = c.lastIndexOf( ' ' );
		if ( i > max * 0.5 ) { c = c.slice( 0, i ); }
		return c.replace( /[\s,.;:—-]+$/, '' );
	}

	function words( t ) {
		t = ( t || '' ).trim();
		return t ? t.split( /\s+/ ).length : 0;
	}

	/* Parse INERTLY with DOMParser (a detached document with no browsing context):
	   unlike a live element's innerHTML, this never loads resources or fires inline
	   handlers, so an <img onerror=…> smuggled into post content (e.g. via a bulk
	   import that bypassed kses) can't execute in the editor. */
	function textFromHtml( html ) {
		try {
			var doc = new DOMParser().parseFromString( html || '', 'text/html' );
			return ( doc && doc.body && ( doc.body.textContent || '' ) ) || '';
		} catch ( e ) { return ''; }
	}

	var ING_RE  = /ingredient/i;
	var STEP_RE = /instruction|direction|step|method|preparation|how to make|how to prepare/i;

	function cleanItem( s ) {
		s = ( s || '' ).replace( /\s+/g, ' ' ).trim();
		s = s.replace( /^\s*(?:\d+\s*[.)\-:]|[\-*•‣◦⁃])\s*/, '' );
		return s.trim();
	}

	function labelOf( elem ) {
		var tag = ( elem.tagName || '' ).toLowerCase();
		if ( /^h[2-4]$/.test( tag ) ) { return ( elem.textContent || '' ).trim(); }
		if ( tag === 'p' ) {
			var b = elem.querySelector( 'strong, b' );
			if ( b ) {
				var bt = ( b.textContent || '' ).trim();
				var pt = ( elem.textContent || '' ).trim();
				if ( bt && pt.length <= bt.length + 2 ) { return bt; }
			}
		}
		return null;
	}

	function itemsFrom( elem ) {
		var out = [];
		var tag = ( elem.tagName || '' ).toLowerCase();
		if ( tag === 'ul' || tag === 'ol' ) {
			Array.prototype.forEach.call( elem.querySelectorAll( 'li' ), function ( li ) {
				var t = cleanItem( li.textContent );
				if ( t ) { out.push( t ); }
			} );
		} else if ( tag === 'p' ) {
			var t = cleanItem( elem.textContent );
			if ( t ) { out.push( t ); }
		}
		return out;
	}

	function extractRecipe( html ) {
		var res = { ingredients: [], steps: [] };
		if ( ! html ) { return res; }
		var root;
		try { root = new DOMParser().parseFromString( html, 'text/html' ).body; }   /* inert parse — no resource loads / handler execution */
		catch ( e ) { return res; }
		if ( ! root ) { return res; }
		var kids = Array.prototype.filter.call( root.childNodes, function ( n ) { return n.nodeType === 1; } );
		var target = null;
		for ( var i = 0; i < kids.length; i++ ) {
			var elem  = kids[ i ];
			var label = labelOf( elem );
			if ( label !== null ) {
				if ( ING_RE.test( label ) && ! res.ingredients.length ) { target = 'ingredients'; }
				else if ( STEP_RE.test( label ) && ! res.steps.length ) { target = 'steps'; }
				else { target = null; }
				continue;
			}
			if ( target ) {
				var items = itemsFrom( elem );
				if ( items.length ) {
					res[ target ] = res[ target ].concat( items );
					var t = ( elem.tagName || '' ).toLowerCase();
					if ( t === 'ul' || t === 'ol' ) { target = null; }
				}
			}
		}
		return res;
	}

	/* ── Meta accessors ── */

	function metaStr( meta, key ) {
		var v = meta && meta[ key ];
		return ( v === undefined || v === null ) ? '' : String( v );
	}

	/* ── The panel component ── */

	function AuthorToolsPanel() {
		var meta = useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			return ( ed && ed.getEditedPostAttribute( 'meta' ) ) || {};
		}, [] );

		var title = useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			return ( ed && ed.getEditedPostAttribute( 'title' ) ) || '';
		}, [] );

		var content = useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			if ( ! ed ) { return ''; }
			var c = ed.getEditedPostAttribute( 'content' );
			if ( typeof c === 'function' ) { c = c(); }
			if ( ( ! c || typeof c !== 'string' ) && ed.getEditedPostContent ) { c = ed.getEditedPostContent(); }
			return ( typeof c === 'string' ) ? c : '';
		}, [] );

		var featured = useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			return ( ed && ed.getEditedPostAttribute( 'featured_media' ) ) || 0;
		}, [] );

		var categories = useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			return ( ed && ed.getEditedPostAttribute( 'categories' ) ) || [];
		}, [] );

		var tags = useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			return ( ed && ed.getEditedPostAttribute( 'tags' ) ) || [];
		}, [] );

		var isNewPost = useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			return !! ( ed && ed.isCleanNewPost && ed.isCleanNewPost() );
		}, [] );

		var editPost = useDispatch( 'core/editor' ).editPost;

		function setMeta( obj ) { editPost( { meta: obj } ); }

		function onMeta( key ) {
			return function ( value ) {
				var o = {}; o[ key ] = value; setMeta( o );
			};
		}
		function onToggle( key ) {
			return function ( checked ) {
				var o = {}; o[ key ] = checked ? '1' : ''; setMeta( o );
			};
		}
		function onNumber( key ) {
			return function ( value ) {
				var n = parseInt( value, 10 );
				if ( isNaN( n ) ) { n = 0; }
				n = Math.max( 0, Math.min( 2880, n ) );
				var o = {}; o[ key ] = n; setMeta( o );
			};
		}

		/* Seed the opt-in flags for a genuinely NEW post so it's managed by default
		   (mirrors the Classic meta box), while an EXISTING post left untouched stays
		   opt-OUT — the server's apply_derived only acts on an explicitly stored '1'.
		   Runs once: seeding makes the post dirty so isCleanNewPost flips to false. */
		wp.element.useEffect( function () {
			if ( ! isNewPost ) { return; }
			var seed = {};
			if ( meta._wpap_ed_manage === undefined )   { seed._wpap_ed_manage = '1'; }
			if ( meta._wpap_ed_autofill === undefined ) { seed._wpap_ed_autofill = '1'; }
			if ( Object.keys( seed ).length ) { setMeta( seed ); }
		}, [ isNewPost ] );

		function autofill() {
			var changed = {};
			var t    = title || '';
			var html = content || '';
			var body = textFromHtml( html );
			if ( ! metaStr( meta, '_wpap_ed_seo_title' ) && t )   { changed._wpap_ed_seo_title = clip( t, 60 ); }
			if ( ! metaStr( meta, '_wpap_ed_meta_desc' ) && body ) { changed._wpap_ed_meta_desc = clip( body, 155 ); }
			if ( ! metaStr( meta, '_wpap_ed_img_alt' ) && t )     { changed._wpap_ed_img_alt = t; }
			if ( ! metaStr( meta, '_wpap_ed_img_title' ) && t )   { changed._wpap_ed_img_title = t; }

			if ( metaStr( meta, '_wpap_recipe_on' ) === '1' ) {
				var needIng  = ! metaStr( meta, '_wpap_recipe_ingredients' );
				var needStep = ! metaStr( meta, '_wpap_recipe_steps' );
				if ( needIng || needStep ) {
					var rx = extractRecipe( html );
					if ( needIng && rx.ingredients.length ) { changed._wpap_recipe_ingredients = rx.ingredients.join( '\n' ); }
					if ( needStep && rx.steps.length )      { changed._wpap_recipe_steps = rx.steps.join( '\n' ); }
				}
			}
			if ( Object.keys( changed ).length ) { setMeta( changed ); }
		}

		/* Live checklist (mirrors editor-tools.js render()). */
		var checks = [
			[ !! ( title && title.trim() ),                      __( 'Title', DOMAIN ) ],
			[ words( textFromHtml( content ) ) >= MIN_WORDS,     __( 'Enough content', DOMAIN ) ],
			[ !! metaStr( meta, '_wpap_ed_meta_desc' ),          __( 'Meta description', DOMAIN ) ],
			[ ( categories && categories.length ) > 0,           __( 'Category', DOMAIN ) ],
			[ ( tags && tags.length ) > 0,                       __( 'Tags', DOMAIN ) ],
			[ ( featured || 0 ) > 0,                             __( 'Featured image', DOMAIN ) ],
			[ !! metaStr( meta, '_wpap_ed_img_alt' ),            __( 'Image alt text', DOMAIN ) ]
		];
		var done = 0;
		checks.forEach( function ( c ) { if ( c[ 0 ] ) { done++; } } );

		var checklistChildren = [
			el( 'div', { key: 'score', className: 'wpap-ed-score' },
				done + ' / ' + checks.length + ' ' + __( 'ready', DOMAIN ) )
		];
		checks.forEach( function ( c, idx ) {
			checklistChildren.push(
				el( 'span', { key: 'chk' + idx, className: 'wpap-ed-chk ' + ( c[ 0 ] ? 'ok' : 'no' ) },
					( c[ 0 ] ? '✓ ' : '○ ' ) + c[ 1 ] )
			);
		} );

		/* ── Build controls ── */

		var mainChildren = [
			el( 'div', { key: 'actions', className: 'wpap-ed-actions' }, [
				el( C.Button, {
					key: 'af', variant: 'primary', isPrimary: true, onClick: autofill
				}, __( 'Auto-fill empty fields', DOMAIN ) ),
				el( C.Button, {
					key: 'prep', variant: 'secondary', isSecondary: true, onClick: autofill,
					style: { marginLeft: '8px' }
				}, __( 'Prepare for publishing', DOMAIN ) )
			] ),
			el( 'p', { key: 'note', className: 'wpap-ed-note' },
				__( 'Auto-fill only touches EMPTY fields — it never overwrites what you typed.', DOMAIN ) ),
			el( 'div', { key: 'checklist', className: 'wpap-ed-checklist', 'aria-live': 'polite' }, checklistChildren ),
			el( C.TextControl, {
				key: 'seo_title',
				label: __( 'SEO title', DOMAIN ),
				value: metaStr( meta, '_wpap_ed_seo_title' ),
				maxLength: 120,
				onChange: onMeta( '_wpap_ed_seo_title' )
			} ),
			el( C.TextareaControl, {
				key: 'meta_desc',
				label: __( 'Meta description', DOMAIN ),
				value: metaStr( meta, '_wpap_ed_meta_desc' ),
				rows: 3,
				onChange: onMeta( '_wpap_ed_meta_desc' )
			} )
		];

		var imageChildren = [
			el( C.TextControl, {
				key: 'img_alt', label: __( 'Alt text', DOMAIN ),
				value: metaStr( meta, '_wpap_ed_img_alt' ), onChange: onMeta( '_wpap_ed_img_alt' )
			} ),
			el( C.TextControl, {
				key: 'img_title', label: __( 'Title', DOMAIN ),
				value: metaStr( meta, '_wpap_ed_img_title' ), onChange: onMeta( '_wpap_ed_img_title' )
			} ),
			el( C.TextareaControl, {
				key: 'img_caption', label: __( 'Caption', DOMAIN ), rows: 2,
				value: metaStr( meta, '_wpap_ed_img_caption' ), onChange: onMeta( '_wpap_ed_img_caption' )
			} ),
			el( C.TextareaControl, {
				key: 'img_desc', label: __( 'Description', DOMAIN ), rows: 2,
				value: metaStr( meta, '_wpap_ed_img_desc' ), onChange: onMeta( '_wpap_ed_img_desc' )
			} )
		];

		var recipeChildren = [
			el( C.ToggleControl, {
				key: 'recipe_on', label: __( 'This post is a recipe', DOMAIN ),
				checked: metaStr( meta, '_wpap_recipe_on' ) === '1', onChange: onToggle( '_wpap_recipe_on' )
			} ),
			el( C.TextControl, {
				key: 'servings', label: __( 'Servings', DOMAIN ),
				value: metaStr( meta, '_wpap_recipe_servings' ), onChange: onMeta( '_wpap_recipe_servings' )
			} ),
			el( C.TextControl, {
				key: 'prep', type: 'number', min: 0, max: 1440, label: __( 'Prep (min)', DOMAIN ),
				value: metaStr( meta, '_wpap_recipe_prep' ), onChange: onNumber( '_wpap_recipe_prep' )
			} ),
			el( C.TextControl, {
				key: 'cook', type: 'number', min: 0, max: 1440, label: __( 'Cook (min)', DOMAIN ),
				value: metaStr( meta, '_wpap_recipe_cook' ), onChange: onNumber( '_wpap_recipe_cook' )
			} ),
			el( C.TextControl, {
				key: 'total', type: 'number', min: 0, max: 2880, label: __( 'Total (min)', DOMAIN ),
				value: metaStr( meta, '_wpap_recipe_total' ), onChange: onNumber( '_wpap_recipe_total' )
			} ),
			el( C.TextareaControl, {
				key: 'ingredients', label: __( 'Ingredients (one per line)', DOMAIN ), rows: 5,
				value: metaStr( meta, '_wpap_recipe_ingredients' ), onChange: onMeta( '_wpap_recipe_ingredients' )
			} ),
			el( C.TextareaControl, {
				key: 'steps', label: __( 'Steps (one per line)', DOMAIN ), rows: 6,
				value: metaStr( meta, '_wpap_recipe_steps' ), onChange: onMeta( '_wpap_recipe_steps' )
			} ),
			el( 'p', { key: 'recipe_note', className: 'wpap-ed-note' },
				__( 'The Viral Reader theme shows a recipe card and emits matching Recipe schema (JSON-LD). Total auto-fills from prep + cook if left blank.', DOMAIN ) )
		];

		var controlsChildren = [
			el( C.SelectControl, {
				key: 'split', label: __( 'Split into pages', DOMAIN ),
				value: metaStr( meta, '_wpap_ed_split' ) || '0',
				options: [
					{ label: __( 'Off — single page', DOMAIN ), value: '0' },
					{ label: __( 'Smart (long posts only)', DOMAIN ), value: 'smart' },
					{ label: __( '2 pages', DOMAIN ), value: '2' },
					{ label: __( '3 pages', DOMAIN ), value: '3' }
				],
				onChange: onMeta( '_wpap_ed_split' )
			} ),
			el( C.ToggleControl, {
				key: 'autofill', label: __( 'Auto-fill empty fields when I save', DOMAIN ),
				checked: metaStr( meta, '_wpap_ed_autofill' ) === '1', onChange: onToggle( '_wpap_ed_autofill' )
			} ),
			el( C.ToggleControl, {
				key: 'manage', label: __( 'Let WP Automator manage this post (SEO schema, related links, ad zones)', DOMAIN ),
				checked: metaStr( meta, '_wpap_ed_manage' ) === '1', onChange: onToggle( '_wpap_ed_manage' )
			} )
		];

		return el( Panel, {
			name: 'wpap-author-tools',
			title: __( 'WP Automator — Author Tools', DOMAIN ),
			className: 'wpap-ed wpap-ed-gutenberg'
		}, [
			el( 'div', { key: 'main' }, mainChildren ),
			el( C.PanelBody, {
				key: 'image', title: __( 'Featured-image metadata', DOMAIN ), initialOpen: false
			}, imageChildren ),
			el( C.PanelBody, {
				key: 'recipe', title: __( 'Recipe details (adds Recipe rich-result schema)', DOMAIN ), initialOpen: false
			}, recipeChildren ),
			el( 'div', { key: 'controls', className: 'wpap-ed-controls' }, controlsChildren )
		] );
	}

	registerPlugin( 'wpap-author-tools', { render: AuthorToolsPanel } );

}( window.wp ) );
