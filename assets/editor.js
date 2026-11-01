/*
 * Marccent Content in the editor, plain JS on WordPress's own packages (no build):
 * - Loop: its query and grid settings; the item is designed once, previewing the query's first post (the blocks
 *   inside get its id and type as context), and repeats for every post on the page (blocks/loop/render.php).
 * - Field: one value of the post, previewed from the post's record.
 * - A panel beside a post for each field group on it (Fields → Field groups); a repeating group is a list of rows to
 *   add, reorder and remove. The field controls are window.marccentContentUI too, for the admin screens (admin.js).
 * - The marccent/field binding source: previews bound blocks and lists the type's values in the Attributes panel.
 * window.marccentContent: { types: [ { name, label, singular, taxonomies: [ { name, label } ], fields: { name: field } } ],
 * groups: { name: { label, repeat, on: [ 'page', 'page:gallery', 'options' ], fields } }, currency }; a type's fields are
 * those of the groups on it that don't repeat.
 */
( function ( wp ) {
	const { createElement: el, Fragment } = wp.element;
	const { registerBlockType, registerBlockBindingsSource } = wp.blocks;
	const { InspectorControls, useBlockProps, useInnerBlocksProps, InnerBlocks, BlockContextProvider, MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { PanelBody, SelectControl, RangeControl, TextControl, ToggleControl, TextareaControl, Button } = wp.components;
	const { useSelect } = wp.data;
	const { useEntityProp } = wp.coreData;
	const data = window.marccentContent || { types: [], groups: {}, currency: '$' };
	data.groups = data.groups || {};

	const opt = ( label, value ) => ( { label, value } );
	// White on Marccent blue, like marccent-blocks' own (its src/shared/register.js).
	const icon = ( src ) => ( { src, foreground: '#fff', background: '#1f64cf' } );
	const typeOf = ( name ) => data.types.find( ( t ) => t.name === name ) || { name, label: name, singular: name, taxonomies: [], fields: {} };

	// The values a type offers: the post's own, its terms and its fields; in a Loop of a group's rows, the row's fields.
	const keysOf = ( type, source ) => {
		if ( source && data.groups[ source ] ) {
			return Object.entries( data.groups[ source ].fields ).map( ( [ name, f ] ) => opt( f.label, name ) );
		}
		const t = typeOf( type );
		return [
			opt( 'Title', 'title' ), opt( 'Excerpt', 'excerpt' ), opt( 'Date', 'date' ), opt( 'Link', 'link' ),
			opt( 'Featured image', 'image' ), opt( 'Author', 'author' ),
			...t.taxonomies.map( ( x ) => opt( x.label, `terms:${ x.name }` ) ),
			...Object.entries( t.fields ).map( ( [ name, f ] ) => opt( `${ f.label } (field)`, name ) ),
		];
	};
	const FORMATS = [ opt( 'As is', '' ), opt( 'Currency', 'currency' ), opt( 'Number', 'number' ), opt( 'Date', 'date' ), opt( 'Upper case', 'upper' ), opt( 'First 20 words', 'words:20' ), opt( 'Without prefix / suffix', 'raw' ) ];

	const text = ( v ) => String( v && typeof v === 'object' ? v.raw ?? v.rendered ?? '' : v ?? '' ).replace( /<[^>]*>/g, '' ).trim();

	// A post's value for a key, as the editor can show it (a close preview of inc/values.php); null when unknown.
	const usePreview = ( postType, postId, key ) => useSelect( ( select ) => {
		if ( ! postId || ! key ) return null;
		const core = select( 'core' );
		const post = core.getEditedEntityRecord( 'postType', postType, postId );
		if ( ! post ) return null;
		const [ name, arg ] = key.split( '|' )[ 0 ].trim().split( ':' );
		const field = typeOf( postType ).fields[ name ];
		const media = ( id ) => ( id ? core.getMedia( id )?.source_url ?? null : null );
		if ( name === 'title' ) return text( post.title );
		if ( name === 'excerpt' ) return text( post.excerpt ) || text( post.content ).split( /\s+/ ).slice( 0, 55 ).join( ' ' );
		if ( name === 'date' ) return post.date ? new Date( post.date ).toLocaleDateString() : null;
		if ( name === 'link' ) return post.link ?? null;
		if ( name === 'image' ) return media( post.featured_media );
		if ( name === 'author' ) return core.getUser( post.author )?.name ?? null;
		if ( name === 'terms' ) {
			const ids = post[ core.getTaxonomy( arg )?.rest_base ?? arg ] || [];
			return ids.map( ( id ) => core.getEntityRecord( 'taxonomy', arg, id )?.name ).filter( Boolean ).join( ', ' ) || null;
		}
		if ( field ) {
			const v = post.meta?.[ name ];
			if ( field.type === 'image' ) return media( v );
			if ( field.type === 'boolean' ) return v ? 'Yes' : 'No';
			return v === '' || v == null ? null : `${ field.prefix ?? '' }${ v }${ field.suffix ?? '' }`;
		}
		return null;
	}, [ postType, postId, key ] );

	/* Loop */

	const ITEM = [ [ 'marccent/card', {}, [
		[ 'marccent/field', { key: 'image' } ],
		[ 'marccent/field', { key: 'title', tag: 'h3', link: true } ],
		[ 'marccent/field', { key: 'excerpt', format: 'words:20' } ],
		[ 'marccent/button', { label: 'Read more', href: '{{link}}', icon: 'arrow-right', color: 'primary' } ],
	] ] ];

	registerBlockType( 'marccent/loop', {
		icon: icon( 'update' ),
		edit( { attributes: a, setAttributes: set } ) {
			const type = typeOf( a.postType );
			const group = a.source ? data.groups[ a.source ] : null;
			const repeating = Object.entries( data.groups ).filter( ( [ , g ] ) => g.repeat );
			const first = useSelect( ( select ) => ( group ? null : select( 'core' ).getEntityRecords( 'postType', a.postType, { per_page: 1, status: 'publish', orderby: 'date' } ) ), [ a.postType, !! group ] );
			const postId = first?.[ 0 ]?.id;
			const blockProps = useBlockProps( { className: 'mcc-loop' } );
			const inner = useInnerBlocksProps( { className: [ 'grid', `cols-${ a.cols }`, a.gap ].filter( Boolean ).join( ' ' ) }, { template: ITEM, orientation: 'horizontal' } );
			const orders = [ opt( 'Date', 'date' ), opt( 'Title', 'title' ), opt( 'Last modified', 'modified' ), opt( 'Menu order', 'menu_order' ), opt( 'Random', 'rand' ),
				...Object.entries( type.fields ).map( ( [ name, f ] ) => opt( `${ f.label } (field)`, `field:${ name }` ) ) ];
			const tokens = keysOf( a.postType, a.source ).map( ( k ) => `{{ ${ k.value } }}` ).join( '  ' );
			const sourcePicker = repeating.length > 0 && el( SelectControl, {
				label: 'Repeat for', value: a.source,
				options: [ opt( 'Posts', '' ), ...repeating.map( ( [ name, g ] ) => opt( `${ g.label } (rows)`, name ) ) ],
				onChange: ( source ) => set( { source } ),
			} );
			const rowsPanel = group && el( PanelBody, { title: 'Rows' },
				sourcePicker,
				el( TextControl, { label: 'From', help: 'A page’s path (gallery, or about/team), or options for the site’s. Empty: the page being viewed.', value: a.from, onChange: ( from ) => set( { from } ) } ),
				el( TextControl, { label: 'Only rows where', help: 'service=flatwork (equal), featured~home (in a comma-separated list); several joined by &.', value: a.where, onChange: ( where ) => set( { where } ) } ),
				el( RangeControl, { label: 'How many', value: a.perPage, min: 1, max: 48, onChange: ( perPage ) => set( { perPage } ) } ),
				el( RangeControl, { label: 'Skip', value: a.offset, min: 0, max: 20, onChange: ( offset ) => set( { offset } ) } ),
				el( TextControl, { label: 'When there are none', value: a.empty, onChange: ( empty ) => set( { empty } ) } )
			);
			return el( Fragment, null,
				el( InspectorControls, null,
					rowsPanel || el( PanelBody, { title: 'Query' },
						sourcePicker,
						el( SelectControl, { label: 'Post type', value: a.postType, options: data.types.map( ( t ) => opt( t.label, t.name ) ), onChange: ( postType ) => set( { postType, taxonomy: '', terms: '' } ) } ),
						el( RangeControl, { label: 'How many', value: a.perPage, min: 1, max: 24, onChange: ( perPage ) => set( { perPage } ) } ),
						el( SelectControl, { label: 'Order by', value: a.orderBy, options: orders, onChange: ( orderBy ) => set( { orderBy } ) } ),
						el( SelectControl, { label: 'Order', value: a.order, options: [ opt( 'Descending', 'desc' ), opt( 'Ascending', 'asc' ) ], onChange: ( order ) => set( { order } ) } ),
						type.taxonomies.length > 0 && el( SelectControl, { label: 'Only from', value: a.taxonomy, options: [ opt( 'Any', '' ), ...type.taxonomies.map( ( x ) => opt( x.label, x.name ) ) ], onChange: ( taxonomy ) => set( { taxonomy } ) } ),
						a.taxonomy && el( TextControl, { label: 'Terms', help: 'Their slugs, comma-separated.', value: a.terms, onChange: ( terms ) => set( { terms } ) } ),
						type.hierarchical && el( TextControl, { label: 'Children of', help: 'A page’s path (services, or about/team): only the pages under it. Empty: all.', value: a.parent, onChange: ( parent ) => set( { parent } ) } ),
						el( RangeControl, { label: 'Skip', value: a.offset, min: 0, max: 20, onChange: ( offset ) => set( { offset } ) } ),
						el( ToggleControl, { label: 'Leave out the post being viewed', checked: a.excludeCurrent, onChange: ( excludeCurrent ) => set( { excludeCurrent } ) } ),
						el( TextControl, { label: 'When there are none', value: a.empty, onChange: ( empty ) => set( { empty } ) } )
					),
					el( PanelBody, { title: 'Grid' },
						el( RangeControl, { label: 'Columns', value: a.cols, min: 1, max: 6, onChange: ( cols ) => set( { cols } ) } ),
						el( SelectControl, { label: 'Gap', value: a.gap, options: [ opt( 'None', 'gap-0' ), opt( 'Small', 'gap-sm' ), opt( 'Normal', '' ), opt( 'Large', 'gap-lg' ) ], onChange: ( gap ) => set( { gap } ) } )
					),
					el( PanelBody, { title: 'Tokens', initialOpen: false },
						el( 'p', null, 'Type these in any block of the item (a heading, a button’s label or link…) to show the post’s value. Add a format after a bar: {{ price | currency }}.' ),
						el( 'code', { style: { wordBreak: 'break-word' } }, tokens )
					)
				),
				el( 'div', blockProps,
					el( 'p', { className: 'mcc-loop-note', style: { margin: '0 0 .5rem', fontSize: '12px', opacity: 0.7 } },
						group
							? `Loop: ${ group.label } rows${ a.from ? ` from ${ a.from }` : '' }${ a.where ? ` where ${ a.where }` : '' }. This item repeats for each one.`
							: `Loop: ${ type.label }. This item repeats for each one${ postId ? `, previewing “${ text( first[ 0 ].title ) }”` : first ? ' (none published yet)' : ' (loading a preview…)' }.` ),
					el( BlockContextProvider, { value: group ? { 'marccent/source': a.source } : { postId, postType: a.postType, 'marccent/source': '' } }, el( 'div', inner ) )
				)
			);
		},
		save: () => el( InnerBlocks.Content ),
	} );

	/* Field */

	registerBlockType( 'marccent/field', {
		icon: icon( 'database' ),
		edit( { attributes: a, setAttributes: set, context } ) {
			const postType = context.postType;
			const source = context[ 'marccent/source' ];
			const keys = keysOf( postType, source );
			const preview = usePreview( postType, context.postId, a.key );
			const value = source ? null : preview; // a row has no preview: its label shows
			const name = a.key.split( ':' )[ 0 ];
			const rowField = source ? data.groups[ source ]?.fields[ name ] : null;
			const isImage = rowField ? rowField.type === 'image' : name === 'image' || typeOf( postType ).fields[ name ]?.type === 'image';
			const Tag = isImage ? 'figure' : a.tag;
			const blockProps = useBlockProps();
			const shown = value == null || value === ''
				? el( 'span', { style: { opacity: 0.5 } }, `[${ keys.find( ( k ) => k.value === a.key )?.label ?? a.key }]` )
				: isImage ? el( 'img', { src: value, alt: '', className: 'w-100 round', style: { display: 'block', aspectRatio: '4 / 3', objectFit: 'cover' } } ) : value;
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: 'Field' },
						el( SelectControl, { label: 'Value', value: keys.some( ( k ) => k.value === a.key ) ? a.key : '', options: [ ...keys, opt( 'Other (type a key)', '' ) ], onChange: ( key ) => key && set( { key } ) } ),
						el( TextControl, { label: 'Key', help: 'title, excerpt, date, link, image:medium, terms:<taxonomy>, a field’s name, or a group’s field (office.phone).', value: a.key, onChange: ( key ) => set( { key } ) } ),
						! isImage && el( SelectControl, { label: 'Format', value: a.format, options: FORMATS, onChange: ( format ) => set( { format } ) } ),
						! isImage && el( SelectControl, { label: 'Tag', value: a.tag, options: [ 'p', 'span', 'div', 'h2', 'h3', 'h4', 'h5', 'h6' ].map( ( t ) => opt( t, t ) ), onChange: ( tag ) => set( { tag } ) } ),
						el( TextControl, { label: 'Label before it', help: 'e.g. “Price:”', value: a.label, onChange: ( label ) => set( { label } ) } ),
						el( ToggleControl, { label: 'Link to the post', checked: a.link, onChange: ( link ) => set( { link } ) } )
					)
				),
				el( Tag, blockProps, a.label && el( 'span', { className: 'fw-bold' }, `${ a.label } ` ), shown )
			);
		},
		save: () => null,
	} );

	/* The Fields panel beside a post */

	const FieldControl = ( { name, f, value, onChange } ) => {
		const common = { label: f.label, help: f.help };
		switch ( f.type ) {
			case 'text': return el( TextareaControl, { ...common, value: value ?? '', onChange } );
			case 'number': return el( TextControl, { ...common, type: 'number', value: value ?? '', onChange: ( v ) => onChange( v === '' ? 0 : Number( v ) ) } );
			case 'boolean': return el( ToggleControl, { ...common, checked: !! value, onChange } );
			case 'date': return el( TextControl, { ...common, type: 'date', value: value ?? '', onChange } );
			case 'url': return el( TextControl, { ...common, type: 'url', value: value ?? '', onChange } );
			case 'email': return el( TextControl, { ...common, type: 'email', value: value ?? '', onChange } );
			case 'select': return el( SelectControl, { ...common, value: value ?? '', options: [ opt( '—', '' ), ...( f.options || [] ).map( ( o ) => opt( o, o ) ) ], onChange } );
			case 'image': return el( ImageControl, { ...common, value, onChange } );
			default: return el( TextControl, { ...common, value: value ?? '', onChange } );
		}
	};

	const ImageControl = ( { label, help, value, onChange } ) => {
		const url = useSelect( ( select ) => ( value ? select( 'core' ).getMedia( value )?.source_url : null ), [ value ] );
		return el( 'div', { className: 'components-base-control', style: { marginBottom: '16px' } },
			el( 'p', { style: { fontWeight: 500, fontSize: '11px', textTransform: 'uppercase', margin: '0 0 8px' } }, label ),
			url && el( 'img', { src: url, alt: '', style: { width: '100%', height: 'auto', marginBottom: '8px' } } ),
			el( MediaUploadCheck, null, el( MediaUpload, {
				onSelect: ( media ) => onChange( media.id ), allowedTypes: [ 'image' ], value,
				render: ( { open } ) => el( Button, { variant: 'secondary', onClick: open }, value ? 'Replace' : 'Choose an image' ),
			} ) ),
			value ? el( Button, { variant: 'link', isDestructive: true, onClick: () => onChange( 0 ), style: { marginLeft: '8px' } }, 'Remove' ) : null,
			help && el( 'p', { className: 'components-base-control__help' }, help )
		);
	};

	// A row's title in the list: its first text value, else its number.
	const rowTitle = ( g, row, i ) => {
		const first = Object.entries( g.fields ).find( ( [ n, f ] ) => [ 'string', 'text', 'select', 'email' ].includes( f.type ) && row?.[ n ] );
		return `${ i + 1 }. ${ first ? String( row[ first[ 0 ] ] ).slice( 0, 40 ) : g.label }`;
	};

	// A group's value: its fields (an object), or its rows (a list) to add, reorder and remove.
	const GroupControl = ( { g, value, onChange } ) => {
		const fieldsOf = ( row, change ) => Object.entries( g.fields ).map( ( [ name, f ] ) =>
			el( FieldControl, { key: name, name, f, value: row?.[ name ], onChange: ( v ) => change( { ...( row || {} ), [ name ]: v } ) } ) );
		if ( ! g.repeat ) {
			return el( Fragment, null, fieldsOf( value && ! Array.isArray( value ) ? value : {}, onChange ) );
		}
		const rows = Array.isArray( value ) ? value : [];
		const put = ( next ) => onChange( next );
		const move = ( i, by ) => {
			const next = rows.slice();
			const [ row ] = next.splice( i, 1 );
			next.splice( i + by, 0, row );
			put( next );
		};
		return el( Fragment, null,
			rows.map( ( row, i ) => el( PanelBody, { key: i, title: rowTitle( g, row, i ), initialOpen: false },
				fieldsOf( row, ( r ) => put( rows.map( ( x, j ) => ( j === i ? r : x ) ) ) ),
				el( 'div', { style: { display: 'flex', gap: '8px' } },
					el( Button, { variant: 'secondary', size: 'small', disabled: i === 0, onClick: () => move( i, -1 ) }, 'Up' ),
					el( Button, { variant: 'secondary', size: 'small', disabled: i === rows.length - 1, onClick: () => move( i, 1 ) }, 'Down' ),
					el( Button, { variant: 'link', isDestructive: true, onClick: () => put( rows.filter( ( x, j ) => j !== i ) ) }, 'Remove' )
				)
			) ),
			el( Button, { variant: 'secondary', onClick: () => put( [ ...rows, {} ] ), style: { marginTop: '8px' } }, `Add ${ g.label.replace( /s$/, '' ).toLowerCase() }` )
		);
	};

	// The groups on a post: on its type, or on it by slug ("page:gallery").
	const groupsOn = ( postType, slug ) => Object.entries( data.groups ).filter( ( [ , g ] ) =>
		g.on.some( ( on ) => on === postType || on === `${ postType }:${ slug }` ) );

	// A panel per group: a repeating group's rows are one value (under its name), the others' fields one value each.
	const FieldsPanel = () => {
		const { postType, slug } = useSelect( ( select ) => ( {
			postType: select( 'core/editor' )?.getCurrentPostType(),
			slug: select( 'core/editor' )?.getEditedPostAttribute( 'slug' ),
		} ), [] );
		const groups = groupsOn( postType, slug );
		const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
		if ( ! groups.length || ! wp.editor?.PluginDocumentSettingPanel ) return null;
		return el( Fragment, null, groups.map( ( [ name, g ] ) => el( wp.editor.PluginDocumentSettingPanel, { key: name, name: `marccent-content-group-${ name }`, title: g.label },
			g.repeat
				? el( GroupControl, { g, value: meta?.[ name ], onChange: ( v ) => setMeta( { ...meta, [ name ]: v } ) } )
				: el( GroupControl, { g, value: Object.fromEntries( Object.keys( g.fields ).map( ( f ) => [ f, meta?.[ f ] ] ) ), onChange: ( v ) => setMeta( { ...meta, ...v } ) } )
		) ) );
	};
	window.marccentContentUI = { FieldControl, GroupControl, opt };
	wp.plugins.registerPlugin( 'marccent-content-fields', { render: FieldsPanel } );

	/* The binding source */

	if ( registerBlockBindingsSource ) {
		registerBlockBindingsSource( {
			name: 'marccent/field',
			label: 'Marccent field',
			usesContext: [ 'postId', 'postType' ],
			getValues( { select, context, bindings } ) {
				const core = select( 'core' );
				const post = context.postId ? core.getEditedEntityRecord( 'postType', context.postType, context.postId ) : null;
				const out = {};
				for ( const [ attribute, { args } ] of Object.entries( bindings ) ) {
					const key = ( args?.key || '' ).split( '|' )[ 0 ].trim();
					const field = typeOf( context.postType ).fields[ key ];
					let v = null;
					if ( post ) {
						if ( key === 'title' ) v = text( post.title );
						else if ( key === 'excerpt' ) v = text( post.excerpt );
						else if ( key === 'link' ) v = post.link;
						else if ( key === 'date' ) v = post.date && new Date( post.date ).toLocaleDateString();
						else if ( key.startsWith( 'image' ) ) v = post.featured_media ? core.getMedia( post.featured_media )?.source_url : null;
						else if ( field ) v = field.type === 'image' ? core.getMedia( post.meta?.[ key ] )?.source_url : post.meta?.[ key ];
					}
					out[ attribute ] = v == null || v === '' ? `{{ ${ args?.key } }}` : String( v );
				}
				return out;
			},
			getFieldsList( { context } ) {
				return keysOf( context?.postType ).map( ( k ) => ( { label: k.label, type: 'string', args: { key: k.value } } ) );
			},
		} );
	}

} )( window.wp );
