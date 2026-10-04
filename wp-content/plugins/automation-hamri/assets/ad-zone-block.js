/* WP Automator — Ad Zone block (wpap/ad-zone)
 * A dynamic, server-rendered block: it echoes one of the plugin's named ad
 * zones (header / sidebar / footer) wherever it's placed, with no theme edits.
 * The editor shows only a placeholder + a zone picker; the real ad is rendered
 * by PHP (wpap_adzone_block_render) on the front end. No JSX — plain wp.element.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components ) { return; }

	var el = wp.element.createElement;
	var C  = wp.components;
	var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };
	var DOMAIN = 'wp-automator-pro';

	/* useBlockProps lives in wp.blockEditor (newer) or wp.editor (older); fall
	   back to a no-op so the block still mounts on very old block editors. */
	var useBlockProps = ( wp.blockEditor && wp.blockEditor.useBlockProps ) ||
	                    ( wp.editor && wp.editor.useBlockProps ) ||
	                    function () { return {}; };

	wp.blocks.registerBlockType( 'wpap/ad-zone', {
		apiVersion: 2,
		title:      __( 'WP Automator Ad Zone', DOMAIN ),
		icon:       'megaphone',
		category:   'widgets',
		attributes: {
			name: { type: 'string', 'default': 'sidebar' }
		},

		edit: function ( props ) {
			var name = props.attributes.name || 'sidebar';
			var blockProps = useBlockProps( {
				style: {
					border:     '1px dashed #c3c4c7',
					borderRadius: '4px',
					padding:    '16px',
					background: '#f6f7f7'
				}
			} );

			return el(
				'div',
				blockProps,
				el(
					'div',
					{ style: { fontWeight: 600, marginBottom: '8px' } },
					__( 'WP Automator Ad Zone', DOMAIN )
				),
				el( C.SelectControl, {
					label: __( 'Zone', DOMAIN ),
					value: name,
					options: [
						{ label: __( 'Header', DOMAIN ),  value: 'header' },
						{ label: __( 'Sidebar', DOMAIN ), value: 'sidebar' },
						{ label: __( 'Footer', DOMAIN ),  value: 'footer' }
					],
					onChange: function ( value ) {
						props.setAttributes( { name: value } );
					}
				} ),
				el(
					'p',
					{ style: { margin: '8px 0 0', fontSize: '12px', color: '#646970' } },
					__( 'The ad renders on the front end only, using your plugin ad settings.', DOMAIN )
				)
			);
		},

		/* Dynamic block: PHP renders the front-end output. */
		save: function () { return null; }
	} );
} )( window.wp );
