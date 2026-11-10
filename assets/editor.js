/*
 * Marccent Content in the editor, plain JS on WordPress's own packages (no build):
 * - Loop: its query and grid settings; the item is designed once, previewing the query's first post (the blocks
 *   inside get its id and type as context), and repeats for every post on the page (blocks/loop/render.php).
 * - Field: one value of the post, previewed from the post's record.
 * - A panel beside a post for each field group on it (Fields → Field groups); a repeating group is a list of rows to
 *   add, reorder and remove. The field controls are window.marccentContentUI too, for the admin screens (admin.js).
 * - The marccent/field binding source: previews bound blocks and lists the type's values in the Attributes panel.
 * - Relations (a field of type relation, to a post type): picked from that type's posts beside a post; their keys
 *   ("account", "account.phone") in the Field block's list; a Loop's "Related" setting lists the posts related to the
 *   current one either way.
 * - Show if: on every block, a where on the current post's (or row's, or the site's) values; the block shows only when
 *   it passes (inc/blocks.php).
 * window.marccentContent: { types: [ { name, label, singular, taxonomies: [ { name, label } ], fields: { name: field } } ],
 * groups: { name: { label, repeat, on: [ 'page', 'page:gallery', 'options' ], fields } }, currency }; a type's fields are
 * those of the groups on it that don't repeat.
 */
( function ( wp ) {
	const { createElement: el, Fragment } = wp.element;
	const { registerBlockType, registerBlockBindingsSource } = wp.blocks;
	const { InspectorControls, useBlockProps, useInnerBlocksProps, InnerBlocks, BlockContextProvider, MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { PanelBody, SelectControl, RangeControl, TextControl, ToggleControl, TextareaControl, Button, ComboboxControl, ColorPalette } = wp.components;
	const { useSelect } = wp.data;
	const { useState } = wp.element;
	const { useEntityProp } = wp.coreData;
	const data = window.marccentContent || { types: [], groups: {}, currency: '$' };
	data.groups = data.groups || {};

	const opt = ( label, value ) => ( { label, value } );
	// White on Marccent blue, like marccent-blocks' own (its src/shared/register.js).
	const icon = ( src ) => ( { src, foreground: '#fff', background: '#1f64cf' } );
	const typeOf = ( name ) => data.types.find( ( t ) => t.name === name ) || { name, label: name, singular: name, taxonomies: [], fields: {} };

	// A relation's keys, one step in: the related post's title, link and fields ("account.phone").
	const relatedKeys = ( name, f ) => {
		const to = typeOf( f.to );
		return [ opt( `${ f.label } → link`, `${ name }.link` ),
			...Object.entries( to.fields ).map( ( [ n, x ] ) => opt( `${ f.label } → ${ x.label }`, `${ name }.${ n }` ) ) ];
	};
	const fieldKeys = ( fields ) => Object.entries( fields ).flatMap( ( [ name, f ] ) => [
		opt( `${ f.label } (${ f.type === 'relation' ? 'relation' : 'field' })`, name ),
		...( f.type === 'relation' ? relatedKeys( name, f ) : [] ),
	] );

	// Counts and sums over what's related to a type's posts (inc/values.php, mcc_aggregate): the posts that point to one,
	// its own related posts, its repeating groups' rows; and the sum of each one's numbers.
	const numbers = ( fields ) => Object.entries( fields ).filter( ( [ , f ] ) => f.type === 'number' );
	const aggregateKeys = ( type ) => {
		const t = typeOf( type );
		return [
			...data.types.flatMap( ( o ) => Object.entries( o.fields ).filter( ( [ , f ] ) => f.type === 'relation' && f.to === type ).flatMap( ( [ by, f ] ) => [
				opt( `Number of ${ o.label.toLowerCase() } (by ${ f.label })`, `count:${ o.name }.${ by }` ),
				...numbers( o.fields ).map( ( [ n, x ] ) => opt( `Sum of ${ x.label } (${ o.label.toLowerCase() } by ${ f.label })`, `sum:${ o.name }.${ by }.${ n }` ) ),
			] ) ),
			...Object.entries( t.fields ).filter( ( [ , f ] ) => f.type === 'relation' && f.multiple ).flatMap( ( [ name, f ] ) => [
				opt( `Number of ${ f.label }`, `count:this.${ name }` ),
				...numbers( typeOf( f.to ).fields ).map( ( [ n, x ] ) => opt( `Sum of ${ x.label } (${ f.label })`, `sum:this.${ name }.${ n }` ) ),
			] ),
			...Object.entries( data.groups ).filter( ( [ , g ] ) => g.repeat && g.on.some( ( on ) => on.split( ':' )[ 0 ] === type ) ).flatMap( ( [ name, g ] ) => [
				opt( `Number of ${ g.label }`, `count:this.${ name }` ),
				...numbers( g.fields ).map( ( [ n, x ] ) => opt( `Sum of ${ x.label } (${ g.label })`, `sum:this.${ name }.${ n }` ) ),
			] ),
		];
	};

	// The values a type offers: the post's own, its terms and its fields; in a Loop of a group's rows, the row's fields.
	const keysOf = ( type, source ) => {
		if ( source && data.groups[ source ] ) {
			return fieldKeys( data.groups[ source ].fields );
		}
		const t = typeOf( type );
		return [
			opt( 'Title', 'title' ), opt( 'Excerpt', 'excerpt' ), opt( 'Date', 'date' ), opt( 'Link', 'link' ),
			opt( 'Featured image', 'image' ), opt( 'Author', 'author' ),
			...t.taxonomies.map( ( x ) => opt( x.label, `terms:${ x.name }` ) ),
			...fieldKeys( t.fields ),
			...aggregateKeys( type ),
		];
	};
	const FORMATS = [ opt( 'As is', '' ), opt( 'Currency', 'currency' ), opt( 'Number', 'number' ), opt( 'Date', 'date' ), opt( 'Upper case', 'upper' ), opt( 'First 20 words', 'words:20' ), opt( 'Without prefix / suffix', 'raw' ) ];

	// A where (inc/blocks.php, mcc_where) on a set of values: the tests joined by &; = one of, != none of, ~ in a list,
	// *= contains, < > <= >=; nothing after = or != is empty or not.
	const passes = ( values, where ) => ( where || '' ).split( '&' ).map( ( t ) => t.trim() ).filter( Boolean ).every( ( t ) => {
		const m = t.match( /^([a-z0-9_\-.:]+)\s*(!=|<=|>=|\*=|=|~|<|>)\s*(.*)$/i );
		if ( ! m ) return true;
		const [ , name, op, want ] = m;
		const raw = values?.[ name ];
		const have = ( Array.isArray( raw ) ? raw : [ raw === true ? '1' : raw === false || raw == null ? '' : raw ] ).map( String );
		const wants = want.split( ',' ).map( ( w ) => w.trim() );
		if ( op === '=' ) return have.some( ( h ) => wants.includes( h ) );
		if ( op === '!=' ) return ! have.some( ( h ) => wants.includes( h ) );
		if ( op === '~' ) return ( Array.isArray( raw ) ? have : String( raw ?? '' ).split( ',' ).map( ( x ) => x.trim() ) ).includes( want );
		if ( op === '*=' ) return have.join( ' ' ).toLowerCase().includes( want.toLowerCase() );
		const h = have[ 0 ];
		if ( h === '' ) return false;
		const c = ! isNaN( h ) && ! isNaN( want ) ? Number( h ) - Number( want ) : h.localeCompare( want );
		return { '<': c < 0, '>': c > 0, '<=': c <= 0, '>=': c >= 0 }[ op ];
	} );

	const text = ( v ) => String( v && typeof v === 'object' ? v.raw ?? v.rendered ?? '' : v ?? '' ).replace( /<[^>]*>/g, '' ).trim();

	// A relation's value as a list of ids.
	const idsOf = ( v ) => ( Array.isArray( v ) ? v : [ v ] ).map( Number ).filter( Boolean );

	// A post's value for a key, as the editor can show it (a close preview of inc/values.php); null when unknown.
	const usePreview = ( postType, postId, key ) => useSelect( ( select ) => ( postId && key ? previewOf( select( 'core' ), postType, postId, key.split( '|' )[ 0 ].trim() ) : null ), [ postType, postId, key ] );

	const previewOf = ( core, postType, postId, key ) => {
		const post = core.getEditedEntityRecord( 'postType', postType, postId );
		if ( ! post ) return null;
		const [ name, arg ] = key.split( ':' );
		// "account.phone": the related posts' value.
		const dot = name.indexOf( '.' );
		if ( dot > 0 ) {
			const rel = typeOf( postType ).fields[ name.slice( 0, dot ) ];
			if ( rel?.type !== 'relation' ) return null;
			const rest = name.slice( dot + 1 ) + ( arg ? `:${ arg }` : '' );
			const values = idsOf( post.meta?.[ name.slice( 0, dot ) ] ).map( ( id ) => previewOf( core, rel.to, id, rest ) ).filter( Boolean );
			return values.length ? values.join( ', ' ) : null;
		}
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
			if ( field.type === 'relation' ) {
				const titles = idsOf( v ).map( ( id ) => text( core.getEntityRecord( 'postType', field.to, id )?.title ) ).filter( Boolean );
				return titles.length ? titles.join( ', ' ) : null;
			}
			if ( field.type === 'image' ) return media( v );
			if ( field.type === 'boolean' ) return v ? 'Yes' : 'No';
			return v === '' || v == null ? null : `${ field.prefix ?? '' }${ v }${ field.suffix ?? '' }`;
		}
		return null;
	};

	/* Loop */

	const ITEM = [ [ 'marccent/card', {}, [
		[ 'marccent/field', { key: 'image' } ],
		[ 'marccent/field', { key: 'title', tag: 'h3', link: true } ],
		[ 'marccent/field', { key: 'excerpt', format: 'words:20' } ],
		[ 'marccent/button', { label: 'Read more', href: '{{link}}', icon: 'arrow-right', color: 'primary' } ],
	] ] ];

	registerBlockType( 'marccent/loop', {
		icon: icon( 'update' ),
		edit( { attributes: a, setAttributes: set, context } ) {
			const type = typeOf( a.postType );
			const forward = a.relation.startsWith( 'this.' );
			// Related either way: the posts of this type whose relation is the current post, or the current post's own.
			const relations = [
				opt( 'Any post (no relation)', '' ),
				...Object.entries( type.fields ).filter( ( [ , f ] ) => f.type === 'relation' )
					.map( ( [ name, f ] ) => opt( `Those whose ${ f.label } is the current ${ typeOf( f.to ).singular }`, name ) ),
				// The current post's type first (the outer Loop's), then the others (a template for one of them).
				...[ ...data.types ].sort( ( x, y ) => ( y.name === context.postType ) - ( x.name === context.postType ) ).flatMap( ( t ) => Object.entries( t.fields )
					.filter( ( [ , f ] ) => f.type === 'relation' )
					.map( ( [ name, f ] ) => opt( `The current ${ t.singular }’s ${ f.label } (${ typeOf( f.to ).label })`, `this.${ name }` ) ) ),
			];
			const group = a.source ? data.groups[ a.source ] : null;
			const repeating = Object.entries( data.groups ).filter( ( [ , g ] ) => g.repeat );
			const first = useSelect( ( select ) => ( group ? null : select( 'core' ).getEntityRecords( 'postType', a.postType, { per_page: 1, status: 'publish', orderby: 'date' } ) ), [ a.postType, !! group ] );
			const postId = first?.[ 0 ]?.id;
			const blockProps = useBlockProps( { className: 'mcc-loop' } );
			const inner = useInnerBlocksProps( { className: [ ( { row: 'grid auto-cols', wrap: 'row-wrap' } )[ a.layout ] || `grid cols-${ a.cols }`, a.gap ].filter( Boolean ).join( ' ' ) }, { template: ITEM, orientation: 'horizontal' } );
			const orders = [ ...( forward ? [ opt( 'Their order in the relation', 'post__in' ) ] : [] ),
				opt( 'Date', 'date' ), opt( 'Title', 'title' ), opt( 'Last modified', 'modified' ), opt( 'Menu order', 'menu_order' ), opt( 'Random', 'rand' ),
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
				el( TextControl, { label: 'Only rows where', help: 'service=flatwork,curb (one of), service!=curb, featured~home (in a comma-separated list), quantity>2; several joined by &.', value: a.where, onChange: ( where ) => set( { where } ) } ),
				el( RangeControl, { label: 'How many', value: a.perPage, min: 1, max: 48, onChange: ( perPage ) => set( { perPage } ) } ),
				el( RangeControl, { label: 'Skip', value: a.offset, min: 0, max: 20, onChange: ( offset ) => set( { offset } ) } ),
				el( TextControl, { label: 'When there are none', value: a.empty, onChange: ( empty ) => set( { empty } ) } )
			);
			return el( Fragment, null,
				el( InspectorControls, null,
					rowsPanel || el( PanelBody, { title: 'Query' },
						sourcePicker,
						! forward && el( SelectControl, { label: 'Post type', value: a.postType, options: data.types.map( ( t ) => opt( t.label, t.name ) ), onChange: ( postType ) => set( { postType, taxonomy: '', terms: '', relation: '' } ) } ),
						el( SelectControl, {
							label: 'Related', value: a.relation, options: relations,
							help: 'The current post: the outer Loop’s item, else the page’s. Loops nest as deep as you like, each related to the one around it.',
							onChange: ( relation ) => {
								// The current post's relation: its posts' type.
								const name = relation.startsWith( 'this.' ) ? relation.slice( 5 ) : '';
								const f = name && data.types.map( ( x ) => x.fields[ name ] ).find( ( x ) => x?.type === 'relation' );
								set( { relation, ...( f ? { postType: f.to, orderBy: 'post__in', taxonomy: '', terms: '' } : {} ) } );
							},
						} ),
						el( RangeControl, { label: 'How many', value: a.perPage, min: 1, max: 24, onChange: ( perPage ) => set( { perPage } ) } ),
						el( TextControl, { label: 'Only posts where', help: 'Their fields: status=New,Scheduled (one of), status!=Cancelled, total>=1000, scheduled<today, products~12; several joined by &. Inside another Loop, its item\'s values: region={{ key }}.', value: a.where, onChange: ( where ) => set( { where } ) } ),
						el( SelectControl, { label: 'Order by', value: a.orderBy, options: orders, onChange: ( orderBy ) => set( { orderBy } ) } ),
						el( SelectControl, { label: 'Order', value: a.order, options: [ opt( 'Descending', 'desc' ), opt( 'Ascending', 'asc' ) ], onChange: ( order ) => set( { order } ) } ),
						type.taxonomies.length > 0 && el( SelectControl, { label: 'Only from', value: a.taxonomy, options: [ opt( 'Any', '' ), ...type.taxonomies.map( ( x ) => opt( x.label, x.name ) ) ], onChange: ( taxonomy ) => set( { taxonomy } ) } ),
						a.taxonomy && el( TextControl, { label: 'Terms', help: 'Their slugs, comma-separated.', value: a.terms, onChange: ( terms ) => set( { terms } ) } ),
						type.hierarchical && el( TextControl, { label: 'Children of', help: 'A page’s path (services, or about/team): only the pages under it. Empty: all.', value: a.parent, onChange: ( parent ) => set( { parent } ) } ),
						el( RangeControl, { label: 'Skip', value: a.offset, min: 0, max: 20, onChange: ( offset ) => set( { offset } ) } ),
						el( ToggleControl, { label: 'Leave out the post being viewed', checked: a.excludeCurrent, onChange: ( excludeCurrent ) => set( { excludeCurrent } ) } ),
						el( TextControl, { label: 'When there are none', value: a.empty, onChange: ( empty ) => set( { empty } ) } )
					),
					el( PanelBody, { title: 'For visitors', initialOpen: !! ( a.paginate || a.filters || a.searchBox ) },
						el( ToggleControl, { label: 'Pages (previous / next)', help: a.paginate ? `${ a.perPage } per page.` : 'Off: only the first ones.', checked: a.paginate, onChange: ( paginate ) => set( { paginate } ) } ),
						el( TextControl, { label: 'Filters', help: `Fields visitors can narrow it by, comma-separated: ${ ( group ? Object.keys( group.fields ) : [ ...Object.keys( type.fields ), ...type.taxonomies.map( ( x ) => `terms:${ x.name }` ) ] ).join( ', ' ) || 'none yet' }.`, value: a.filters, onChange: ( filters ) => set( { filters } ) } ),
						el( ToggleControl, { label: 'A search box', checked: a.searchBox, onChange: ( searchBox ) => set( { searchBox } ) } )
					),
					el( PanelBody, { title: 'Grid' },
						el( SelectControl, { label: 'Layout', value: a.layout || '', options: [ { label: 'Equal columns', value: '' }, { label: 'A row, each item its own width', value: 'row' }, { label: 'A row that wraps', value: 'wrap' } ], onChange: ( layout ) => set( { layout } ) } ),
						! a.layout && el( RangeControl, { label: 'Columns', value: a.cols, min: 1, max: 6, onChange: ( cols ) => set( { cols } ) } ),
						el( SelectControl, { label: 'Gap', value: a.gap, options: [ opt( 'None', 'gap-0' ), opt( 'Small', 'gap-sm' ), opt( 'Normal', '' ), opt( 'Large', 'gap-lg' ) ], onChange: ( gap ) => set( { gap } ) } )
					),
					el( PanelBody, { title: 'Tokens', initialOpen: false },
						el( 'p', null, 'Type these in any block of the item (a heading, a button’s label or link…) to show the post’s value. Add a format after a bar: {{ price | currency }}.' ),
						el( 'code', { style: { wordBreak: 'break-word' } }, tokens )
					)
				),
				el( 'div', blockProps,
					( a.filters || a.searchBox ) && el( 'div', { style: { display: 'flex', gap: '8px', marginBottom: '8px', opacity: 0.6, fontSize: '12px' } },
						[ ...( a.searchBox ? [ 'Search' ] : [] ), ...a.filters.split( ',' ).map( ( x ) => x.trim() ).filter( Boolean ) ].map( ( x ) => el( 'span', { key: x, style: { border: '1px solid currentColor', borderRadius: '4px', padding: '2px 8px' } }, `${ x } ▾` ) ) ),
					el( 'p', { className: 'mcc-loop-note', style: { margin: '0 0 .5rem', fontSize: '12px', opacity: 0.7 } },
						group
							? `Loop: ${ group.label } rows${ a.from ? ` from ${ a.from }` : '' }${ a.where ? ` where ${ a.where }` : '' }. This item repeats for each one.`
							: `Loop: ${ type.label }${ a.relation ? ` (${ relations.find( ( r ) => r.value === a.relation )?.label ?? a.relation })` : '' }. This item repeats for each one${ postId ? `, previewing “${ text( first[ 0 ].title ) }”` : first ? ' (none published yet)' : ' (loading a preview…)' }.` ),
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
						el( TextControl, { label: 'Key', help: 'title, excerpt, date, link, image:medium, terms:<taxonomy>, a field’s name, a group’s field (office.phone), through a relation (account.phone), or a count or sum (count:work_order.account?status=New).', value: a.key, onChange: ( key ) => set( { key } ) } ),
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
		const common = { label: f.required ? `${ f.label } *` : f.label, help: f.help };
		switch ( f.type ) {
			case 'text': return el( TextareaControl, { ...common, value: value ?? '', onChange } );
			case 'html': return el( TextareaControl, { ...common, help: f.help || 'HTML: <strong>, <em>, <a href>, <ul><li>…', rows: 6, value: value ?? '', onChange } );
			case 'color': return el( 'div', { className: 'components-base-control', style: { marginBottom: '16px' } },
				el( 'p', { style: { fontWeight: 500, fontSize: '11px', textTransform: 'uppercase', margin: '0 0 8px' } }, common.label ),
				el( ColorPalette, { value: value || undefined, onChange: ( v ) => onChange( v || '' ), colors: wp.data.select( 'core/block-editor' )?.getSettings?.()?.colors || [] } ),
				f.help && el( 'p', { className: 'components-base-control__help' }, f.help ) );
			case 'file': return el( MediaControl, { ...common, value, onChange, kind: 'file' } );
			case 'gallery': return el( GalleryControl, { ...common, value, onChange } );
			case 'location': return el( LocationControl, { ...common, value, onChange } );
			case 'json': return el( JsonControl, { ...common, value, onChange } );
			case 'hours': return el( HoursControl, { ...common, value, onChange } );
			case 'number': return el( TextControl, { ...common, type: 'number', value: value ?? '', onChange: ( v ) => onChange( v === '' ? 0 : Number( v ) ) } );
			case 'boolean': return el( ToggleControl, { ...common, checked: !! value, onChange } );
			case 'date': return el( TextControl, { ...common, type: 'date', value: value ?? '', onChange } );
			case 'url': return el( TextControl, { ...common, type: 'url', value: value ?? '', onChange } );
			case 'email': return el( TextControl, { ...common, type: 'email', value: value ?? '', onChange } );
			case 'select': return el( SelectControl, { ...common, value: value ?? '', options: [ opt( '—', '' ), ...( f.options || [] ).map( ( o ) => opt( o, o ) ) ], onChange } );
			case 'image': return el( MediaControl, { ...common, value, onChange, kind: 'image' } );
			case 'relation': return el( RelationControl, { ...common, f, value, onChange } );
			default: return el( TextControl, { ...common, value: value ?? '', onChange } );
		}
	};

	// Opening hours: a box per day ("08:00-17:00", or two spans "08:00-12:00, 13:00-17:00"; empty: closed).
	const DAYS = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
	const HoursControl = ( { label, help, value, onChange } ) => el( 'div', { className: 'components-base-control', style: { marginBottom: '16px' } },
		caption( label ),
		DAYS.map( ( day ) => el( TextControl, {
			key: day, label: day.charAt( 0 ).toUpperCase() + day.slice( 1 ), placeholder: 'Closed', value: ( value || {} )[ day ] ?? '',
			onChange: ( v ) => onChange( { ...Object.fromEntries( DAYS.map( ( d ) => [ d, ( value || {} )[ d ] ?? '' ] ) ), [ day ]: v } ),
		} ) ),
		el( 'p', { className: 'components-base-control__help' }, help || '08:00-17:00, or two spans: 08:00-12:00, 13:00-17:00. Empty: closed.' )
	);

	// Data as names and values (a form's answers, an API's item): typed as JSON, checked as it's typed, and shown as a
	// table of its names and values. Saved only when it's JSON.
	const JsonControl = ( { label, help, value, onChange } ) => {
		const pretty = ( v ) => {
			try {
				return v ? JSON.stringify( JSON.parse( v ), null, 2 ) : '';
			} catch ( e ) {
				return v;
			}
		};
		const [ text, setText ] = useState( () => pretty( value ) );
		const [ error, setError ] = useState( '' );
		let data = null;
		try {
			data = value ? JSON.parse( value ) : null;
		} catch ( e ) {}
		const show = ( v ) => ( v && typeof v === 'object' ? ( Array.isArray( v ) ? v.map( show ).join( ', ' ) : JSON.stringify( v ) ) : String( v ?? '' ) );
		return el( 'div', { className: 'components-base-control', style: { marginBottom: '16px' } },
			el( TextareaControl, {
				label, help: error || help || 'JSON: { "name": "Ana", "email": "ana@example.com" }', rows: 6, value: text, className: 'code', spellCheck: false,
				onChange: ( v ) => {
					setText( v );
					try {
						onChange( v.trim() ? JSON.stringify( JSON.parse( v ) ) : '' );
						setError( '' );
					} catch ( e ) {
						setError( 'Not JSON yet: it’s saved once it is.' );
					}
				},
			} ),
			data && typeof data === 'object' && ! Array.isArray( data ) && el( 'table', { className: 'widefat striped', style: { marginTop: '4px' } },
				el( 'tbody', null, Object.entries( data ).map( ( [ k, v ] ) => el( 'tr', { key: k },
					el( 'th', { scope: 'row', style: { width: '40%' } }, k ),
					el( 'td', null, show( v ) ) ) ) ) )
		);
	};

	const caption = ( label ) => el( 'p', { style: { fontWeight: 500, fontSize: '11px', textTransform: 'uppercase', margin: '0 0 8px' } }, label );

	// An image, or any file (a PDF, a price list…): an attachment from the media library, by its id.
	const MediaControl = ( { label, help, value, onChange, kind } ) => {
		const media = useSelect( ( select ) => ( value ? select( 'core' ).getMedia( value ) : null ), [ value ] );
		const url = media?.source_url;
		return el( 'div', { className: 'components-base-control', style: { marginBottom: '16px' } },
			caption( label ),
			url && ( kind === 'image'
				? el( 'img', { src: url, alt: '', style: { width: '100%', height: 'auto', marginBottom: '8px' } } )
				: el( 'p', { style: { margin: '0 0 8px', wordBreak: 'break-all' } }, el( 'a', { href: url, target: '_blank', rel: 'noreferrer' }, url.split( '/' ).pop() ) ) ),
			el( MediaUploadCheck, null, el( MediaUpload, {
				onSelect: ( m ) => onChange( m.id ), allowedTypes: kind === 'image' ? [ 'image' ] : undefined, value,
				render: ( { open } ) => el( Button, { variant: 'secondary', onClick: open }, value ? 'Replace' : kind === 'image' ? 'Choose an image' : 'Choose a file' ),
			} ) ),
			value ? el( Button, { variant: 'link', isDestructive: true, onClick: () => onChange( 0 ), style: { marginLeft: '8px' } }, 'Remove' ) : null,
			help && el( 'p', { className: 'components-base-control__help' }, help )
		);
	};

	// Several images in an order: picked (and reordered) in the media library's gallery view.
	const GalleryControl = ( { label, help, value, onChange } ) => {
		const ids = idsOf( value );
		const urls = useSelect( ( select ) => ids.map( ( id ) => select( 'core' ).getMedia( id )?.media_details?.sizes?.thumbnail?.source_url ?? select( 'core' ).getMedia( id )?.source_url ), [ ids.join( ',' ) ] );
		return el( 'div', { className: 'components-base-control', style: { marginBottom: '16px' } },
			caption( label ),
			ids.length > 0 && el( 'div', { style: { display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '4px', marginBottom: '8px' } },
				urls.map( ( u, i ) => el( 'img', { key: ids[ i ], src: u, alt: '', style: { width: '100%', aspectRatio: '1', objectFit: 'cover' } } ) ) ),
			el( MediaUploadCheck, null, el( MediaUpload, {
				multiple: 'add', gallery: true, allowedTypes: [ 'image' ], value: ids,
				onSelect: ( list ) => onChange( list.map( ( m ) => m.id ) ),
				render: ( { open } ) => el( Button, { variant: 'secondary', onClick: open }, ids.length ? `Edit the ${ ids.length } images` : 'Choose images' ),
			} ) ),
			ids.length > 0 && el( Button, { variant: 'link', isDestructive: true, onClick: () => onChange( [] ), style: { marginLeft: '8px' } }, 'Clear' ),
			help && el( 'p', { className: 'components-base-control__help' }, help )
		);
	};

	// A place: its address, and its latitude and longitude, found from the address (OpenStreetMap's search) or typed.
	const LocationControl = ( { label, help, value, onChange } ) => {
		const saved = value && typeof value === 'object' && ! Array.isArray( value ) ? value : {};
		// Older saves have lat and lng.
		const v = { address: saved.address ?? '', latitude: saved.latitude ?? saved.lat ?? '', longitude: saved.longitude ?? saved.lng ?? '' };
		const [ busy, setBusy ] = useState( '' );
		const put = ( patch ) => onChange( { ...v, ...patch } );
		const find = () => {
			setBusy( 'Looking…' );
			window.fetch( `https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${ encodeURIComponent( v.address || '' ) }` )
				.then( ( r ) => r.json() )
				.then( ( found ) => {
					if ( ! found[ 0 ] ) return setBusy( 'No place found for that address.' );
					put( { latitude: Number( Number( found[ 0 ].lat ).toFixed( 6 ) ), longitude: Number( Number( found[ 0 ].lon ).toFixed( 6 ) ) } );
					setBusy( '' );
				}, () => setBusy( 'Couldn’t reach the map search.' ) );
		};
		const has = v.latitude !== '' && v.latitude != null && v.longitude !== '' && v.longitude != null;
		return el( 'div', { className: 'components-base-control', style: { marginBottom: '16px' } },
			caption( label ),
			el( TextControl, { label: 'Address', value: v.address ?? '', onChange: ( address ) => put( { address } ) } ),
			el( 'div', { style: { display: 'flex', gap: '8px' } },
				el( TextControl, { label: 'Latitude', type: 'number', value: v.latitude, onChange: ( latitude ) => put( { latitude: latitude === '' ? '' : Number( latitude ) } ) } ),
				el( TextControl, { label: 'Longitude', type: 'number', value: v.longitude, onChange: ( longitude ) => put( { longitude: longitude === '' ? '' : Number( longitude ) } ) } )
			),
			el( Button, { variant: 'secondary', size: 'small', disabled: ! v.address, onClick: find }, 'Find it from the address' ),
			busy && el( 'p', { className: 'components-base-control__help' }, busy ),
			has && el( 'iframe', { title: 'Map', loading: 'lazy', style: { width: '100%', height: '180px', border: 0, marginTop: '8px' },
				src: `https://www.openstreetmap.org/export/embed.html?bbox=${ v.longitude - 0.01 },${ v.latitude - 0.006 },${ v.longitude + 0.01 },${ v.latitude + 0.006 }&marker=${ v.latitude },${ v.longitude }` } ),
			help && el( 'p', { className: 'components-base-control__help' }, help )
		);
	};

	// A relation: one post of its type, or several in an order, picked by searching their titles.
	const RelationControl = ( { label, help, f, value, onChange } ) => {
		const [ search, setSearch ] = useState( '' );
		const many = !! f.multiple;
		const ids = idsOf( value );
		const { found, chosen } = useSelect( ( select ) => {
			const core = select( 'core' );
			return {
				found: core.getEntityRecords( 'postType', f.to, { per_page: 20, search, status: 'publish', orderby: search ? 'relevance' : 'title', order: 'asc' } ) || [],
				// Each one by its id: kept from any query that loaded it, so a new search doesn't blank its title.
				chosen: ids.map( ( id ) => core.getEntityRecord( 'postType', f.to, id ) ).filter( Boolean ),
			};
		}, [ f.to, search, ids.join( ',' ) ] );
		const titleOf = ( id ) => text( [ ...chosen, ...found ].find( ( p ) => p.id === id )?.title ) || `#${ id }`;
		const options = [ ...chosen, ...found ].filter( ( p, i, all ) => all.findIndex( ( x ) => x.id === p.id ) === i )
			.filter( ( p ) => ! many || ! ids.includes( p.id ) ).map( ( p ) => opt( text( p.title ) || `#${ p.id }`, String( p.id ) ) );
		const put = ( next ) => onChange( many ? next : next[ 0 ] || 0 );
		return el( 'div', { style: { marginBottom: '16px' } },
			el( ComboboxControl, {
				label, help: help || `${ typeOf( f.to ).label }: type to search.`, options, onFilterValueChange: setSearch,
				value: many ? null : ( ids[ 0 ] ? String( ids[ 0 ] ) : null ),
				onChange: ( v ) => put( many ? ( v ? [ ...ids, Number( v ) ] : ids ) : ( v ? [ Number( v ) ] : [] ) ),
			} ),
			many && ids.map( ( id, i ) => el( 'div', { key: id, style: { display: 'flex', gap: '4px', alignItems: 'center' } },
				el( 'span', { style: { flex: 1 } }, titleOf( id ) ),
				el( Button, { size: 'small', icon: 'arrow-up-alt2', label: 'Up', disabled: i === 0, onClick: () => put( ids.map( ( x, j ) => ( j === i - 1 ? id : j === i ? ids[ i - 1 ] : x ) ) ) } ),
				el( Button, { size: 'small', icon: 'no-alt', label: 'Remove', isDestructive: true, onClick: () => put( ids.filter( ( x ) => x !== id ) ) } )
			) )
		);
	};

	// A row's title in the list: its first text value, else its number.
	const rowTitle = ( g, row, i ) => {
		const first = Object.entries( g.fields ).find( ( [ n, f ] ) => [ 'string', 'text', 'select', 'email' ].includes( f.type ) && row?.[ n ] );
		return `${ i + 1 }. ${ first ? String( row[ first[ 0 ] ] ).slice( 0, 40 ) : g.label }`;
	};

	// A group's value: its fields (an object), or its rows (a list) to add, reorder and remove.
	const GroupControl = ( { g, value, onChange } ) => {
		// A field with show_if shows while the row's (or the post's) other values pass it.
		const fieldsOf = ( row, change ) => Object.entries( g.fields ).filter( ( [ , f ] ) => ! f.show_if || passes( row || {}, f.show_if ) ).map( ( [ name, f ] ) =>
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

	// The groups on a post: on its type, on it by slug ("page:gallery") or by its template ("page@landing").
	const groupsOn = ( postType, slug, template ) => Object.entries( data.groups ).filter( ( [ , g ] ) =>
		g.on.some( ( on ) => on === postType || on === `${ postType }:${ slug }` || ( template && on === `${ postType }@${ template }` ) ) );

	// A panel per group: a repeating group's rows are one value (under its name), the others' fields one value each.
	const FieldsPanel = () => {
		const { postType, slug, template } = useSelect( ( select ) => ( {
			postType: select( 'core/editor' )?.getCurrentPostType(),
			slug: select( 'core/editor' )?.getEditedPostAttribute( 'slug' ),
			template: select( 'core/editor' )?.getEditedPostAttribute( 'template' ),
		} ), [] );
		const groups = groupsOn( postType, slug, template );
		const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
		if ( ! groups.length || ! wp.editor?.PluginDocumentSettingPanel ) return null;
		return el( Fragment, null, groups.map( ( [ name, g ] ) => el( wp.editor.PluginDocumentSettingPanel, { key: name, name: `marccent-content-group-${ name }`, title: g.label },
			g.repeat
				? el( GroupControl, { g, value: meta?.[ name ], onChange: ( v ) => setMeta( { ...meta, [ name ]: v } ) } )
				: el( GroupControl, { g, value: Object.fromEntries( Object.keys( g.fields ).map( ( f ) => [ f, meta?.[ f ] ] ) ), onChange: ( v ) => setMeta( { ...meta, ...v } ) } )
		) ) );
	};
	window.marccentContentUI = { FieldControl, GroupControl, opt, passes };
	wp.plugins.registerPlugin( 'marccent-content-fields', { render: FieldsPanel } );

	// A page's type for search engines (the page's JSON-LD, inc/schema.php): what kind of page it is.
	const PAGE_TYPES = [ [ 'Web page (the default)', '' ], [ 'About: the business, who we are', 'AboutPage' ], [ 'Contact: how to reach us', 'ContactPage' ], [ 'Collection: a list of things (services, areas)', 'CollectionPage' ], [ 'Questions and answers', 'FAQPage' ], [ 'Checkout', 'CheckoutPage' ], [ 'One item (a product, a listing)', 'ItemPage' ] ];
	const PageTypePanel = () => {
		const postType = useSelect( ( select ) => select( 'core/editor' )?.getCurrentPostType(), [] );
		const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
		if ( postType !== 'page' || ! wp.editor?.PluginDocumentSettingPanel ) return null;
		return el( wp.editor.PluginDocumentSettingPanel, { name: 'marccent-content-page-type', title: 'Page type' },
			el( SelectControl, {
				label: 'For search engines', value: meta?.page_type || '', __nextHasNoMarginBottom: true,
				options: PAGE_TYPES.map( ( [ label, value ] ) => ( { label, value } ) ),
				help: 'What kind of page this is, in the page’s structured data. Questions shown with a Loop are added either way.',
				onChange: ( page_type ) => setMeta( { ...meta, page_type } ),
			} )
		);
	};
	wp.plugins.registerPlugin( 'marccent-content-page-type', { render: PageTypePanel } );

	/* Show if, on every block */

	wp.hooks.addFilter( 'blocks.registerBlockType', 'marccent-content/show-if', ( settings ) => ( {
		...settings,
		attributes: { ...settings.attributes, mccShowIf: { type: 'string', default: '' } },
	} ) );
	wp.hooks.addFilter( 'editor.BlockEdit', 'marccent-content/show-if', wp.compose.createHigherOrderComponent( ( BlockEdit ) => ( props ) => el( Fragment, null,
		el( BlockEdit, props ),
		props.isSelected && el( InspectorControls, null, el( PanelBody, { title: 'Show if', initialOpen: !! props.attributes.mccShowIf },
			el( TextControl, {
				label: 'Only when', value: props.attributes.mccShowIf || '',
				help: 'A where on the post’s (or the Loop row’s) values: price>0, phone!= (has one), featured=yes, office.phone!=, status=New,Scheduled. Empty: always.',
				onChange: ( mccShowIf ) => props.setAttributes( { mccShowIf } ),
			} )
		) )
	), 'withShowIf' ) );

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
						else if ( key.includes( '.' ) || field?.type === 'relation' ) v = previewOf( core, context.postType, context.postId, key );
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
