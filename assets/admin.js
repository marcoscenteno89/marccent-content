/*
 * Marccent Content's admin screens, plain JS on WordPress's own packages (no build), drawn into #mcc-admin by
 * inc/admin.php; its data-screen says which:
 * - group: Fields → Field groups, adding or editing one (the list is WordPress's list table, inc/list-table.php): its
 *   name, whether it repeats, where it goes (post types, some of their posts by slug, the site) and its fields, each an
 *   accordion (label, key, type, help, choices, prefix, suffix; a relation's post type and whether it holds several).
 * - types: Fields → Post types. The list, and a type's editor: its names, URL, icon, archive, search, what its editor
 *   has, and its taxonomies.
 * - site: Site content. The groups on the site, with the same controls as beside a post (window.marccentContentUI,
 *   from editor.js).
 * The spec is saved whole (POST marccent-content/v1/spec), checked by the server; its errors show here.
 */
( function ( wp ) {
	const { createElement: el, Fragment, useState, useEffect } = wp.element;

	// A term's or a user's field groups (inc/admin.php, mcc_object_fields): the same controls, kept in a hidden field
	// the term's or profile's form saves.
	document.querySelectorAll( '[data-mcc-object]' ).forEach( ( box ) => {
		const input = box.previousElementSibling;
		const groups = JSON.parse( box.dataset.groups );
		const Object_ = () => {
			const [ values, setValues ] = useState( () => JSON.parse( box.dataset.values ) );
			useEffect( () => {
				input.value = JSON.stringify( values );
			}, [ values ] );
			return el( Fragment, null, Object.entries( groups ).map( ( [ name, g ] ) => el( 'div', { key: name, style: { marginBottom: '16px' } },
				el( 'h3', { style: { margin: '0 0 8px' } }, g.label ),
				el( window.marccentContentUI.GroupControl, { g, value: values[ name ], onChange: ( v ) => setValues( ( all ) => ( { ...all, [ name ]: v } ) ) } )
			) ) );
		};
		wp.element.createRoot( box ).render( el( Object_ ) );
	} );

	const root = document.getElementById( 'mcc-admin' );
	if ( ! root ) return;

	const { Button, TextControl, TextareaControl, SelectControl, ToggleControl, CheckboxControl, Notice, Spinner, Card, CardHeader, CardBody } = wp.components;
	const { GroupControl, opt } = window.marccentContentUI;
	const api = ( path, data ) => wp.apiFetch( { path: `/marccent-content/v1/${ path }`, ...( data ? { method: 'POST', data } : {} ) } );

	const TYPES = [
		opt( 'Text', 'string' ), opt( 'Text, several lines', 'text' ), opt( 'Rich text (HTML)', 'html' ), opt( 'Number', 'number' ), opt( 'Link (URL)', 'url' ),
		opt( 'Email', 'email' ), opt( 'Date', 'date' ), opt( 'Yes / No', 'boolean' ), opt( 'Color', 'color' ), opt( 'Image', 'image' ), opt( 'Gallery (images)', 'gallery' ),
		opt( 'File (PDF…)', 'file' ), opt( 'Location (address, map)', 'location' ), opt( 'Choice from a list', 'select' ),
		opt( 'Relation (another post)', 'relation' ), opt( 'Data (JSON: names and values)', 'json' ), opt( 'Opening hours', 'hours' ),
	];
	const SUPPORTS = [ [ 'title', 'Title' ], [ 'editor', 'Content (blocks)' ], [ 'thumbnail', 'Featured image' ], [ 'excerpt', 'Excerpt' ], [ 'page-attributes', 'Order (and parent)' ], [ 'revisions', 'Revisions' ] ];

	// "Before photo" → before_photo (a key: lowercase, numbers, underscores, starting with a letter).
	const keyOf = ( s, max = 64 ) => ( s || '' ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9]+/g, '_' ).replace( /^[^a-z]+|_+$/g, '' ).slice( 0, max );
	const urlOf = ( s ) => keyOf( s ).replace( /_/g, '-' );
	const move = ( list, i, by ) => {
		const next = list.slice();
		const [ x ] = next.splice( i, 1 );
		next.splice( i + by, 0, x );
		return next;
	};
	const errorsOf = ( e ) => e?.data?.errors || [ e?.message || 'Something went wrong.' ];
	const page = ( title, action, ...body ) => el( Fragment, null,
		el( 'h1', { className: 'wp-heading-inline' }, title ), action, el( 'hr', { className: 'wp-header-end' } ), ...body );
	const Errors = ( { list, onRemove } ) => list.length > 0 && el( Notice, { status: 'error', onRemove }, list.map( ( m, i ) => el( 'div', { key: i }, m ) ) );
	const Row = ( ...children ) => el( 'div', { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '12px', alignItems: 'start' } }, ...children );
	const section = ( title, ...children ) => el( Card, { style: { marginTop: '16px' } }, el( CardHeader, null, el( 'strong', null, title ) ), el( CardBody, null, ...children ) );
	const actions = ( ...children ) => el( 'p', { style: { display: 'flex', gap: '8px', marginTop: '24px' } }, ...children );

	// The spec, from the server, and saving it whole.
	const useSpec = () => {
		const [ state, setState ] = useState( null );
		useEffect( () => {
			api( 'spec' ).then( setState );
		}, [] );
		const save = ( spec ) => api( 'spec', { spec } ).then( setState );
		return [ state, save ];
	};

	/* Field groups */

	// Adding or editing one group (Fields → Field groups' list is WordPress's list table: inc/list-table.php). Saving
	// goes back to the list.
	const GroupScreen = () => {
		const [ state, save ] = useSpec();
		const [ errors, setErrors ] = useState( [] );
		if ( ! state ) return el( Spinner );
		const { spec, postTypes, taxonomies = {}, templates = {} } = state;
		const name = root.dataset.name || '';
		const back = ( notice ) => window.location.assign( notice ? `${ root.dataset.list }&${ notice }` : root.dataset.list );
		if ( name && ! spec.groups[ name ] ) {
			return el( Notice, { status: 'error', isDismissible: false }, `There is no field group “${ name }”. `, el( 'a', { href: root.dataset.list }, 'Back to the field groups' ) );
		}
		return el( GroupEditor, {
			name, group: name ? spec.groups[ name ] : { label: '', on: [], fields: {} }, postTypes, taxonomies, templates, errors, setErrors,
			taken: Object.keys( spec.groups ).filter( ( n ) => n !== name ),
			onCancel: () => back(),
			onSave: ( key, group ) => {
				// Keep the groups' order; a renamed group stays where it was.
				const entries = Object.entries( spec.groups );
				const at = entries.findIndex( ( [ n ] ) => n === name );
				if ( at < 0 ) entries.push( [ key, group ] );
				else entries[ at ] = [ key, group ];
				save( { ...spec, groups: Object.fromEntries( entries ) } ).then( () => back( 'saved=1' ), ( e ) => {
					setErrors( errorsOf( e ) );
					window.scrollTo( 0, 0 );
				} );
			},
		} );
	};

	// The field types as tiles (a Dashicon each), and what a field of each shows in its header.
	const TYPE_ICONS = { string: 'editor-textcolor', text: 'editor-paragraph', number: 'calculator', url: 'admin-links', email: 'email',
		date: 'calendar-alt', boolean: 'yes-alt', image: 'format-image', select: 'list-view', relation: 'networking', html: 'editor-code',
		color: 'art', gallery: 'format-gallery', file: 'media-document', location: 'location', json: 'database', hours: 'clock' };
	const icon = ( name ) => el( 'span', { className: `dashicons dashicons-${ name }`, 'aria-hidden': true } );
	const typeLabel = ( type ) => TYPES.find( ( t ) => t.value === type )?.label || type;

	// The editor's own styles: two columns (the fields, then where it goes and saving), a field per accordion.
	const STYLE = `
		.mcc-editor { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 20px; align-items: start; margin-top: 16px; }
		@media (max-width: 960px) { .mcc-editor { grid-template-columns: 1fr; } .mcc-side { position: static !important; } }
		.mcc-side { position: sticky; top: 46px; display: grid; gap: 16px; }
		.mcc-side .components-card { margin: 0 !important; }
		.mcc-code textarea { font-family: Menlo, Consolas, monospace; font-size: 12px; }
		.mcc-back { display: inline-flex; align-items: center; gap: 2px; text-decoration: none; margin-top: 8px; }
		.mcc-bar { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; }
		.mcc-acc { border: 1px solid #dcdcde; border-radius: 4px; background: #fff; margin-bottom: 8px; }
		.mcc-acc.is-open { border-color: #c3c4c7; box-shadow: 0 1px 3px rgba(0, 0, 0, .06); }
		.mcc-acc.has-error { border-color: #d63638; }
		.mcc-acc__head { display: flex; align-items: center; gap: 4px; padding: 4px 8px 4px 12px; }
		.mcc-acc__toggle { flex: 1; display: flex; align-items: center; gap: 10px; min-width: 0; background: none; border: 0; padding: 8px 0; text-align: left; cursor: pointer; color: inherit; }
		.mcc-acc__toggle:focus-visible { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 2px; }
		.mcc-acc__num { flex: none; width: 24px; height: 24px; border-radius: 50%; background: #f0f0f1; font-size: 11px; display: grid; place-items: center; }
		.mcc-acc__name { display: grid; min-width: 0; }
		.mcc-acc__label { font-weight: 600; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.mcc-acc__key { color: #646970; font-size: 12px; font-family: monospace; }
		.mcc-acc__chevron { transition: transform .15s; color: #646970; }
		.mcc-acc.is-open .mcc-acc__chevron { transform: rotate(180deg); }
		.mcc-acc__head .components-button.is-small.has-icon:not(:hover) { color: #646970; }
		.mcc-acc__body { border-top: 1px solid #f0f0f1; padding: 16px; display: grid; gap: 4px; }
		.mcc-badge { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; background: #f0f6fc; color: #0a4b78; border-radius: 2px; padding: 2px 8px; font-size: 12px; margin-left: auto; }
		.mcc-badge .dashicons { font-size: 16px; width: 16px; height: 16px; }
		.mcc-types { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; margin-bottom: 8px; }
		.mcc-type { display: flex; align-items: center; gap: 8px; border: 1px solid #dcdcde; border-radius: 4px; padding: 8px 10px; background: #fff; cursor: pointer; font-size: 13px; text-align: left; color: inherit; }
		.mcc-type:hover { border-color: #8c8f94; }
		.mcc-type.is-selected { border-color: var(--wp-admin-theme-color, #2271b1); box-shadow: 0 0 0 1px var(--wp-admin-theme-color, #2271b1); background: #f0f6fc; }
		.mcc-sub { font-size: 11px; text-transform: uppercase; color: #646970; font-weight: 600; margin: 12px 0 8px; letter-spacing: .02em; }
		.mcc-tokens { background: #f6f7f7; border-radius: 2px; padding: 8px; font-size: 12px; color: #50575e; }
		.mcc-empty { border: 2px dashed #dcdcde; border-radius: 4px; padding: 24px; text-align: center; color: #646970; margin-bottom: 8px; }
		.mcc-where { display: grid; gap: 2px; }
		.mcc-where .components-base-control { margin: 0; }
		.mcc-chip { display: inline-block; background: #f0f0f1; border-radius: 2px; padding: 1px 6px; margin: 0 4px 4px 0; font-size: 12px; }
	`;

	// A field without a label shows its key as the server does (mcc_normalize_fields): before_photo → Before photo.
	const labelOf = ( k ) => ( k ? k.charAt( 0 ).toUpperCase() + k.slice( 1 ).replace( /_/g, ' ' ) : '' );
	let lastId = 0;
	const fieldOf = ( k, f, open = false ) => ( {
		id: ++lastId, open, key: k, was: k, label: f.label || labelOf( k ), type: f.type || 'string', help: f.help || '',
		options: ( f.options || [] ).join( '\n' ), prefix: f.prefix || '', suffix: f.suffix || '', to: f.to || '', multiple: !! f.multiple, closedDays: f.closed_days || '',
		required: !! f.required, unique: !! f.unique, private: !! f.private, showIf: f.show_if || '',
	} );

	// What a field's settings get wrong, for its accordion and the notice at the top.
	const fieldProblems = ( f, i, keys ) => [
		! f.label.trim() || ! keys[ i ] ? 'Give it a label and a key.' : '',
		keys[ i ] && keys.indexOf( keys[ i ] ) !== i ? `Another field has the key “${ keys[ i ] }”.` : '',
		f.type === 'select' && ! f.options.trim() ? 'List its choices, one per line.' : '',
		f.type === 'relation' && ! f.to ? 'Choose the post type it relates to.' : '',
	].filter( Boolean );

	// One field: its header (number, label, key, type; move, duplicate, remove) and, open, its settings.
	const FieldItem = ( { f, i, count, problems, postTypes, repeat, set, onMove, onDuplicate, onRemove } ) => {
		const k = keyOf( f.key );
		const bodyId = `mcc-field-${ f.id }`;
		const badge = f.type === 'relation' && f.to ? `${ typeLabel( f.type ) } → ${ postTypes[ f.to ] || f.to }${ f.multiple ? ' (several)' : '' }` : typeLabel( f.type );
		return el( 'div', { className: [ 'mcc-acc', f.open && 'is-open', problems.length && 'has-error' ].filter( Boolean ).join( ' ' ) },
			el( 'div', { className: 'mcc-acc__head' },
				el( 'button', { type: 'button', className: 'mcc-acc__toggle', 'aria-expanded': f.open, 'aria-controls': bodyId, onClick: () => set( { open: ! f.open } ) },
					el( 'span', { className: 'mcc-acc__chevron dashicons dashicons-arrow-down-alt2', 'aria-hidden': true } ),
					el( 'span', { className: 'mcc-acc__num' }, i + 1 ),
					el( 'span', { className: 'mcc-acc__name' },
						el( 'span', { className: 'mcc-acc__label' }, f.label || el( 'em', null, 'New field' ) ),
						el( 'span', { className: 'mcc-acc__key' }, k || '—' )
					),
					el( 'span', { className: 'mcc-badge' }, icon( TYPE_ICONS[ f.type ] ), badge )
				),
				el( Button, { size: 'small', icon: 'arrow-up-alt', label: 'Move up', disabled: i === 0, onClick: () => onMove( -1 ) } ),
				el( Button, { size: 'small', icon: 'arrow-down-alt', label: 'Move down', disabled: i === count - 1, onClick: () => onMove( 1 ) } ),
				el( Button, { size: 'small', icon: 'admin-page', label: 'Duplicate', onClick: onDuplicate } ),
				el( Button, { size: 'small', icon: 'trash', label: 'Remove', isDestructive: true, onClick: onRemove } )
			),
			f.open && el( 'div', { className: 'mcc-acc__body', id: bodyId },
				problems.length > 0 && el( Notice, { status: 'error', isDismissible: false }, problems.join( ' ' ) ),
				Row(
					el( TextControl, { label: 'Label', value: f.label, help: 'What editors see beside the post.', onChange: ( v ) => set( { label: v, ...( f.was ? {} : { key: keyOf( v ) } ) } ) } ),
					el( TextControl, { label: 'Key', value: f.key, help: `In blocks: {{ ${ k || 'key' } }}`, onChange: ( v ) => set( { key: keyOf( v ) } ) } )
				),
				f.was && k !== f.was && el( Notice, { status: 'warning', isDismissible: false }, `Renamed from “${ f.was }”: what’s saved under the old key won’t show, and tokens using it stop working.` ),
				el( 'div', { className: 'mcc-sub' }, 'Type' ),
				el( 'div', { className: 'mcc-types', role: 'radiogroup', 'aria-label': 'Type' }, TYPES.map( ( t ) => el( 'button', {
					key: t.value, type: 'button', role: 'radio', 'aria-checked': f.type === t.value,
					className: `mcc-type${ f.type === t.value ? ' is-selected' : '' }`, onClick: () => set( { type: t.value } ),
				}, icon( TYPE_ICONS[ t.value ] ), t.label ) ) ),
				f.type === 'select' && el( TextareaControl, { label: 'Choices, one per line', value: f.options, rows: 5, onChange: ( options ) => set( { options } ) } ),
				f.type === 'relation' && el( Fragment, null,
					Row(
						el( SelectControl, {
							label: 'Related to', value: f.to,
							options: [ opt( 'Choose a post type…', '' ), ...Object.entries( postTypes ).map( ( [ type, l ] ) => opt( l, type ) ) ],
							onChange: ( to ) => set( { to } ),
						} ),
						el( ToggleControl, { label: 'Several (many to many)', checked: f.multiple, help: f.multiple ? 'A list of posts, in an order.' : 'One post (a foreign key).', onChange: ( multiple ) => set( { multiple } ) } )
					),
					el( 'div', { className: 'mcc-tokens' }, `{{ ${ k || 'key' } }} is the title; {{ ${ k || 'key' }.<any of its keys> }} goes through it. A Loop’s “Related” lists the posts related either way.` )
				),
				f.type === 'hours' && el( Fragment, null,
					el( TextControl, { label: 'Days that differ (optional)', help: 'A repeating group on the site with a date, hours and note: holidays, short days. Today’s hours, open now and the schema read it.', value: f.closedDays, onChange: ( closedDays ) => set( { closedDays } ) } ),
					el( 'div', { className: 'mcc-tokens' }, `{{ ${ k || 'key' } }} is the week; {{ ${ k || 'key' }:today }}, {{ ${ k || 'key' }:open_now }}, {{ ${ k || 'key' }:monday }}. In a schema: "openingHoursSpecification": "${ k || 'key' }".` )
				),
				[ 'string', 'number' ].includes( f.type ) && el( Fragment, null,
					el( 'div', { className: 'mcc-sub' }, 'Shown around the value' ),
					Row(
						el( TextControl, { label: 'Before', value: f.prefix, help: 'e.g. $', onChange: ( prefix ) => set( { prefix } ) } ),
						el( TextControl, { label: 'After', value: f.suffix, help: 'e.g.  sq ft', onChange: ( suffix ) => set( { suffix } ) } )
					)
				),
				el( TextControl, { label: 'Help for editors (optional)', value: f.help, onChange: ( help ) => set( { help } ) } ),
				el( 'div', { className: 'mcc-sub' }, 'Rules' ),
				Row(
					el( ToggleControl, { label: 'Private', help: 'Edited here only: never shown on the site (no {{ token }}, Field block, Loop or count), nor in the schema.', checked: f.private, onChange: ( v ) => set( { private: v } ) } ),
					el( ToggleControl, { label: 'Required', help: 'A post isn’t published without it (a draft may be); the site content isn’t saved.', checked: f.required, onChange: ( required ) => set( { required } ) } ),
					! repeat && ! [ 'boolean', 'gallery', 'location', 'html', 'json', 'hours' ].includes( f.type ) && el( ToggleControl, { label: 'Unique', help: 'No two posts with the same value (a SKU, an email).', checked: f.unique, onChange: ( unique ) => set( { unique } ) } )
				),
				el( TextControl, { label: 'Show only when', help: 'The other fields’ values, as a Loop’s where: kind=office, has_parking=1, price>0. Empty: always. Hidden, it isn’t required.', value: f.showIf, onChange: ( showIf ) => set( { showIf } ) } )
			)
		);
	};

	const GroupEditor = ( { name, group, postTypes, taxonomies, templates, taken, errors, setErrors, onSave, onCancel } ) => {
		const isNew = ! name;
		const [ label, setLabel ] = useState( group.label || '' );
		const [ key, setKey ] = useState( name );
		const [ repeat, setRepeat ] = useState( !! group.repeat );
		const [ schema, setSchema ] = useState( group.schema ? JSON.stringify( group.schema, null, 2 ) : '' );
		// Where: per post type, off / all / some (slugs); and the site.
		const initialWhere = () => {
			const w = {};
			( group.on || [] ).forEach( ( o ) => {
				if ( o === 'options' || o === 'user' || o.startsWith( 'term:' ) ) return;
				const [ type, rest ] = o.split( /[:@]/ );
				w[ type ] = w[ type ] || { on: true, slugs: [], templates: [] };
				if ( rest ) w[ type ][ o.includes( '@' ) ? 'templates' : 'slugs' ].push( rest );
			} );
			return w;
		};
		const [ terms, setTerms ] = useState( () => ( group.on || [] ).filter( ( o ) => o.startsWith( 'term:' ) ).map( ( o ) => o.slice( 5 ) ) );
		const [ users, setUsers ] = useState( ( group.on || [] ).includes( 'user' ) );
		const [ where, setWhere ] = useState( initialWhere );
		const [ site, setSite ] = useState( ( group.on || [] ).includes( 'options' ) );
		// A new group's first field starts open; a saved group's fields start closed.
		const [ fields, setFields ] = useState( () => Object.entries( group.fields || {} ).map( ( [ k, f ] ) => fieldOf( k, f ) ) );
		const [ tried, setTried ] = useState( false ); // after a save that failed, each field shows what's wrong
		const setField = ( i, patch ) => setFields( fields.map( ( f, j ) => ( j === i ? { ...f, ...patch } : f ) ) );
		const keys = fields.map( ( f ) => keyOf( f.key ) );
		const problemsOf = fields.map( ( f, i ) => ( tried ? fieldProblems( f, i, keys ) : [] ) );

		const save = () => {
			const problems = [];
			const k = keyOf( key );
			if ( ! label.trim() ) problems.push( 'Give the group a name.' );
			if ( ! k ) problems.push( 'Give the group a key (lowercase letters, numbers, underscores).' );
			if ( taken.includes( k ) ) problems.push( `Another group already has the key “${ k }”.` );
			const bad = fields.map( ( f, i ) => fieldProblems( f, i, keys ) );
			bad.forEach( ( p, i ) => p.length && problems.push( `${ fields[ i ].label || `Field ${ i + 1 }` }: ${ p.join( ' ' ) }` ) );
			const on = [
				...Object.entries( where ).filter( ( [ , w ] ) => w.on ).flatMap( ( [ type, w ] ) => ( w.slugs.length || w.templates?.length
					? [ ...w.slugs.map( ( s ) => `${ type }:${ s }` ), ...( w.templates || [] ).map( ( t ) => `${ type }@${ t }` ) ] : [ type ] ) ),
				...terms.map( ( t ) => `term:${ t }` ),
				...( users ? [ 'user' ] : [] ),
				...( site ? [ 'options' ] : [] ),
			];
			if ( ! on.length ) problems.push( 'Choose where the group goes.' );
			let schemaValue = null;
			if ( schema.trim() ) {
				try {
					schemaValue = JSON.parse( schema );
					if ( ! schemaValue || typeof schemaValue !== 'object' || Array.isArray( schemaValue ) || ! schemaValue.type ) throw new Error();
				} catch {
					problems.push( 'Search engines: the schema isn’t valid JSON with a "type" (see the example under it).' );
				}
			}
			if ( problems.length ) {
				setTried( true );
				setFields( fields.map( ( f, i ) => ( bad[ i ].length ? { ...f, open: true } : f ) ) ); // open the ones to fix
				setErrors( problems );
				window.scrollTo( 0, 0 );
				return;
			}
			const out = { label: label.trim(), on, fields: {} };
			if ( repeat ) out.repeat = true;
			if ( schemaValue ) out.schema = schemaValue;
			fields.forEach( ( f, i ) => {
				const field = { label: f.label.trim(), type: f.type };
				if ( f.help.trim() ) field.help = f.help.trim();
				if ( f.type === 'select' ) field.options = f.options.split( '\n' ).map( ( o ) => o.trim() ).filter( Boolean );
				if ( f.type === 'relation' ) {
					field.to = f.to;
					if ( f.multiple ) field.multiple = true;
				}
				if ( f.type === 'hours' && f.closedDays.trim() ) field.closed_days = f.closedDays.trim();
				if ( [ 'string', 'number' ].includes( f.type ) && f.prefix ) field.prefix = f.prefix;
				if ( [ 'string', 'number' ].includes( f.type ) && f.suffix ) field.suffix = f.suffix;
				if ( f.required ) field.required = true;
				if ( f.unique && ! repeat ) field.unique = true;
				if ( f.private ) field.private = true;
				if ( f.showIf.trim() ) field.show_if = f.showIf.trim();
				out.fields[ keys[ i ] ] = field;
			} );
			onSave( k, out );
		};

		const addField = () => setFields( [ ...fields.map( ( f ) => ( { ...f, open: false } ) ), fieldOf( '', { label: '' }, true ) ] );
		const allOpen = fields.length > 0 && fields.every( ( f ) => f.open );
		const chosen = [ ...Object.entries( where ).filter( ( [ , w ] ) => w.on ), ...terms.map( ( t ) => [ `term:${ t }` ] ), ...( users ? [ [ 'user' ] ] : [] ) ];

		return el( Fragment, null,
			el( 'style', null, STYLE ),
			el( 'a', { href: '#', className: 'mcc-back', onClick: ( e ) => {
				e.preventDefault();
				onCancel();
			} }, icon( 'arrow-left-alt2' ), 'Field groups' ),
			el( 'h1', null, isNew ? 'Add field group' : `Edit “${ group.label || name }”` ),
			el( Errors, { list: errors, onRemove: () => setErrors( [] ) } ),
			el( 'div', { className: 'mcc-editor' },
				el( 'div', null,
					el( Card, null, el( CardBody, null,
						Row(
							el( TextControl, { label: 'Name', value: label, help: 'What editors see: Office, Photos, Team.', onChange: ( v ) => {
								setLabel( v );
								if ( isNew ) setKey( keyOf( v ) );
							} } ),
							el( TextControl, { label: 'Key', value: key, help: repeat ? `A Loop’s “Repeat for”: ${ keyOf( key ) || 'key' }.` : site ? `Anywhere: {{ ${ keyOf( key ) || 'key' }.field }}` : 'Its fields show by their own keys: {{ field }}.', onChange: ( v ) => setKey( keyOf( v ) ) } )
						),
						! isNew && keyOf( key ) !== name && el( Notice, { status: 'warning', isDismissible: false }, `Changing the key from “${ name }” disconnects what’s saved under it, and Loops or tokens that use “${ name }” stop working.` ),
						el( ToggleControl, { label: 'Repeats: a list of rows (a gallery’s photos, offices, team members)', checked: repeat, onChange: setRepeat } )
					) ),
					el( 'div', { className: 'mcc-bar', style: { marginTop: '24px' } },
						el( 'h2', { style: { margin: 0 } }, `Fields (${ fields.length })` ),
						fields.length > 0 && el( Button, { variant: 'tertiary', size: 'small', onClick: () => setFields( fields.map( ( f ) => ( { ...f, open: ! allOpen } ) ) ) }, allOpen ? 'Collapse all' : 'Expand all' )
					),
					fields.length === 0 && el( 'div', { className: 'mcc-empty' }, 'No fields yet. Add the first one: a phone, a price, an image, a relation to another post…' ),
					fields.map( ( f, i ) => el( FieldItem, {
						key: f.id, f, i, count: fields.length, problems: problemsOf[ i ], postTypes, repeat,
						set: ( patch ) => setField( i, patch ),
						onMove: ( by ) => setFields( move( fields, i, by ) ),
						onDuplicate: () => setFields( [ ...fields.slice( 0, i + 1 ).map( ( x ) => ( { ...x, open: false } ) ),
							{ ...f, id: ++lastId, open: true, was: '', key: keyOf( `${ f.key }_copy` ), label: `${ f.label } (copy)` }, ...fields.slice( i + 1 ).map( ( x ) => ( { ...x, open: false } ) ) ] ),
						onRemove: () => {
							// eslint-disable-next-line no-alert
							if ( ! f.was || window.confirm( `Remove “${ f.label || f.key }”? What’s saved under it stays in the database, but nothing shows or edits it any more.` ) ) {
								setFields( fields.filter( ( x, j ) => j !== i ) );
							}
						},
					} ) ),
					el( Button, { variant: 'secondary', icon: 'plus-alt2', onClick: addField, style: { marginTop: '4px' } }, 'Add field' ),
					// What the group is in schema.org's words: the page's JSON-LD (inc/schema.php).
					el( Card, { style: { marginTop: '24px' } },
						el( CardHeader, null, el( 'strong', null, 'Search engines (schema.org)' ) ),
						el( CardBody, null, el( TextareaControl, {
							label: 'Schema (JSON, optional)', value: schema, rows: schema ? 10 : 3, onChange: setSchema,
							className: 'mcc-code',
							help: repeat
								? 'What its rows are, where a Loop shows them. A value is a field’s key, text with {{ tokens }}, or an object with its own "@type". e.g. { "type": "FAQPage", "id": "faq", "each": { "@type": "Question", "name": "question", "acceptedAnswer": { "@type": "Answer", "text": "answer" } } }'
								: 'What a post with this group is: on its page, and wherever a Loop lists it. A value is a field’s key, text with {{ tokens }}, or an object with its own "@type". e.g. { "type": "City", "id": "place", "map": { "name": "{{ title }}, {{ state }}", "geo": { "@type": "GeoCoordinates", "latitude": "latitude", "longitude": "longitude" } } }',
						} ) )
					)
				),
				el( 'div', { className: 'mcc-side' },
					el( Card, null,
						el( CardHeader, null, el( 'strong', null, isNew ? 'Create' : 'Save' ) ),
						el( CardBody, null,
							el( 'p', { style: { marginTop: 0 } }, chosen.length || site
								? [ ...chosen.map( ( [ type ] ) => el( 'span', { key: type, className: 'mcc-chip' }, postTypes[ type ] || taxonomies[ type.slice( 5 ) ] || ( type === 'user' ? 'Users' : type ) ) ), site && el( 'span', { key: 'site', className: 'mcc-chip' }, 'The site' ) ]
								: el( 'em', null, 'Not placed anywhere yet.' ) ),
							el( 'div', { style: { display: 'flex', gap: '8px' } },
								el( Button, { variant: 'primary', onClick: save }, isNew ? 'Create field group' : 'Save field group' ),
								el( Button, { variant: 'tertiary', onClick: onCancel }, 'Cancel' )
							)
						)
					),
					el( Card, null,
						el( CardHeader, null, el( 'strong', null, 'Where it goes' ) ),
						el( CardBody, { className: 'mcc-where' },
							Object.entries( postTypes ).map( ( [ type, typeLabelText ] ) => {
								const w = { templates: [], ...( where[ type ] || { on: false, slugs: [] } ) };
								const setW = ( patch ) => setWhere( { ...where, [ type ]: { ...w, ...patch } } );
								return el( 'div', { key: type },
									el( CheckboxControl, { label: typeLabelText, checked: w.on, onChange: ( on ) => setW( { on } ) } ),
									w.on && el( 'div', { style: { margin: '4px 0 8px 28px' } }, el( TextControl, {
										label: 'Only these (slugs)', help: `Empty: every one of the ${ typeLabelText.toLowerCase() }.`,
										value: w.slugs.join( ', ' ), onChange: ( v ) => setW( { slugs: v.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) } ),
									} ), Object.keys( templates ).length > 0 && el( SelectControl, {
										label: 'Only with the template', value: w.templates[ 0 ] || '',
										options: [ opt( 'Any template', '' ), ...Object.entries( templates ).map( ( [ slug, title ] ) => opt( title, slug ) ) ],
										onChange: ( t ) => setW( { templates: t ? [ t ] : [] } ),
									} ) )
								);
							} ),
							Object.keys( taxonomies ).length > 0 && el( 'div', { className: 'mcc-sub' }, 'Terms (on their edit screen)' ),
							Object.entries( taxonomies ).map( ( [ tax, l ] ) => el( CheckboxControl, { key: tax, label: l, checked: terms.includes( tax ), onChange: ( on ) => setTerms( on ? [ ...terms, tax ] : terms.filter( ( x ) => x !== tax ) ) } ) ),
							el( 'hr', { style: { margin: '8px 0' } } ),
							el( CheckboxControl, { label: 'Users (their profile)', checked: users, onChange: setUsers } ),
							el( CheckboxControl, { label: 'The whole site (Site content)', checked: site, onChange: setSite } )
						)
					)
				)
			)
		);
	};

	/* Post types */

	const TypesScreen = () => {
		const [ state, save ] = useSpec();
		const [ editing, setEditing ] = useState( null );
		const [ errors, setErrors ] = useState( [] );
		if ( ! state ) return el( Spinner );
		const { spec } = state;
		// A saved type shows in the menu from the next load.
		const put = ( types ) => save( { ...spec, types } ).then( () => window.location.reload(), ( e ) => setErrors( errorsOf( e ) ) );

		if ( editing ) {
			return el( TypeEditor, {
				...editing, errors, setErrors,
				taken: [ ...Object.keys( spec.types ).filter( ( n ) => n !== editing.name ), 'post', 'page', 'attachment', 'revision', 'nav_menu_item' ],
				onCancel: () => {
					setEditing( null );
					setErrors( [] );
				},
				onSave: ( name, type ) => {
					const entries = Object.entries( spec.types );
					const at = entries.findIndex( ( [ n ] ) => n === editing.name );
					if ( at < 0 ) entries.push( [ name, type ] );
					else entries[ at ] = [ name, type ];
					put( Object.fromEntries( entries ) );
				},
			} );
		}

		const types = Object.entries( spec.types );
		return page( 'Post types',
			el( Button, { variant: 'secondary', className: 'page-title-action', onClick: () => setEditing( { name: '', type: { supports: [ 'title', 'editor', 'thumbnail', 'excerpt' ] } } ) }, 'Add post type' ),
			el( Errors, { list: errors, onRemove: () => setErrors( [] ) } ),
			el( 'p', null, 'A post type is a kind of content with a page of its own for each item (projects, team members with a bio page, listings). Things that don’t need their own page are better as a repeating field group.' ),
			types.length === 0
				? el( 'p', null, el( 'em', null, 'No post types yet.' ) )
				: el( 'table', { className: 'wp-list-table widefat fixed striped' },
					el( 'thead', null, el( 'tr', null, ...[ 'Post type', 'Address', 'Taxonomies' ].map( ( t ) => el( 'th', { key: t }, t ) ) ) ),
					el( 'tbody', null, types.map( ( [ name, t ] ) => el( 'tr', { key: name },
						el( 'td', null,
							el( 'strong', null, t.label || name ), el( 'div', null, el( 'code', null, name ) ),
							el( 'div', { className: 'row-actions visible' },
								el( Button, { variant: 'link', onClick: () => setEditing( { name, type: t } ) }, 'Edit' ), ' | ',
								el( Button, { variant: 'link', isDestructive: true, onClick: () => {
									// eslint-disable-next-line no-alert
									if ( window.confirm( `Delete the “${ t.label || name }” post type? Its posts stay in the database but disappear from the site and the admin until it’s added again.` ) ) {
										put( Object.fromEntries( types.filter( ( [ n ] ) => n !== name ) ) );
									}
								} }, 'Delete' )
							)
						),
						el( 'td', null, `/${ t.slug || name }/` ),
						el( 'td', null, Object.values( t.taxonomies || {} ).map( ( x ) => x.label ).join( ', ' ) || '—' )
					) ) )
				)
		);
	};

	const TypeEditor = ( { name, type, taken, errors, setErrors, onSave, onCancel } ) => {
		const isNew = ! name;
		const [ t, setT ] = useState( { label: '', singular: '', slug: '', icon: 'dashicons-admin-post', archive: true, noindex: false, supports: [], ...type } );
		const [ key, setKey ] = useState( name );
		const [ taxes, setTaxes ] = useState( () => Object.entries( type.taxonomies || {} ).map( ( [ k, x ] ) => ( { key: k, label: x.label || '', singular: x.singular || '', slug: x.slug || '', hierarchical: x.hierarchical !== false } ) ) );
		const set = ( patch ) => setT( { ...t, ...patch } );
		const setTax = ( i, patch ) => setTaxes( taxes.map( ( x, j ) => ( j === i ? { ...x, ...patch } : x ) ) );

		const save = () => {
			const problems = [];
			const k = keyOf( key, 20 );
			if ( ! t.label.trim() || ! t.singular.trim() ) problems.push( 'Give it a name, plural and singular.' );
			if ( ! k ) problems.push( 'Give it a key (up to 20 lowercase letters, numbers, underscores).' );
			if ( taken.includes( k ) ) problems.push( `“${ k }” is taken.` );
			taxes.forEach( ( x, i ) => {
				if ( ! x.label.trim() || ! keyOf( x.key, 32 ) ) problems.push( `Taxonomy ${ i + 1 }: give it a name and a key.` );
			} );
			if ( problems.length ) {
				setErrors( problems );
				window.scrollTo( 0, 0 );
				return;
			}
			const out = { label: t.label.trim(), singular: t.singular.trim(), slug: urlOf( t.slug ) || urlOf( t.label ), icon: t.icon || 'dashicons-admin-post', archive: !! t.archive, supports: t.supports };
			if ( t.noindex ) out.noindex = true;
			if ( t.private ) out.private = true;
			if ( taxes.length ) {
				out.taxonomies = Object.fromEntries( taxes.map( ( x ) => [ keyOf( x.key, 32 ), { label: x.label.trim(), singular: x.singular.trim() || x.label.trim(), slug: urlOf( x.slug ) || urlOf( x.label ), hierarchical: x.hierarchical } ] ) );
			}
			onSave( k, out );
		};

		return page( isNew ? 'Add post type' : `Edit “${ type.label || name }”`, null,
			el( Errors, { list: errors, onRemove: () => setErrors( [] ) } ),
			section( 'Post type',
				Row(
					el( TextControl, { label: 'Name (plural)', value: t.label, help: 'Projects', onChange: ( label ) => set( { label, ...( isNew ? { slug: urlOf( label ) } : {} ) } ) } ),
					el( TextControl, { label: 'Name (singular)', value: t.singular, help: 'Project', onChange: ( singular ) => {
						set( { singular } );
						if ( isNew ) setKey( keyOf( singular, 20 ) );
					} } ),
					el( TextControl, { label: 'Key', value: key, help: 'Up to 20 characters. Changing it later hides the posts saved under the old one.', onChange: ( v ) => setKey( keyOf( v, 20 ) ) } )
				),
				Row(
					el( TextControl, { label: 'Address', value: t.slug, help: `Its pages: /${ urlOf( t.slug ) || 'address' }/item-name/`, onChange: ( slug ) => set( { slug } ) } ),
					el( TextControl, { label: 'Menu icon', value: t.icon, help: el( 'a', { href: 'https://developer.wordpress.org/resource/dashicons/', target: '_blank', rel: 'noopener' }, 'A Dashicon name' ), onChange: ( icon ) => set( { icon } ) } )
				),
				el( ToggleControl, { label: 'Only in wp-admin (no pages on the site)', help: 'For what the team keeps but visitors never see: a form’s entries, notes. Its posts have no address, archive or place in search and menus.', checked: !! t.private, onChange: ( on ) => set( { private: on } ) } ),
				! t.private && el( ToggleControl, { label: 'A page listing them all (an archive)', checked: !! t.archive, onChange: ( archive ) => set( { archive } ) } ),
				! t.private && el( ToggleControl, { label: 'Keep their pages out of search results', checked: !! t.noindex, onChange: ( noindex ) => set( { noindex } ) } )
			),
			section( 'What its editor has',
				SUPPORTS.map( ( [ s, l ] ) => el( CheckboxControl, { key: s, label: l, checked: t.supports.includes( s ), onChange: ( on ) => set( { supports: on ? [ ...t.supports, s ] : t.supports.filter( ( x ) => x !== s ) } ) } ) ),
				el( 'p', { className: 'description' }, 'Its fields come from field groups put on it (Fields → Field groups).' )
			),
			section( 'Taxonomies (ways to group them: Services, Locations…)',
				taxes.map( ( x, i ) => el( 'div', { key: i, style: { borderTop: i ? '1px solid #ddd' : 0, padding: '12px 0' } },
					Row(
						el( TextControl, { label: 'Name (plural)', value: x.label, onChange: ( label ) => setTax( i, { label, ...( x.key && ! isNew ? {} : { key: keyOf( `${ key }_${ label }`, 32 ), slug: urlOf( label ) } ) } ) } ),
						el( TextControl, { label: 'Name (singular)', value: x.singular, onChange: ( singular ) => setTax( i, { singular } ) } ),
						el( TextControl, { label: 'Key', value: x.key, onChange: ( v ) => setTax( i, { key: keyOf( v, 32 ) } ) } ),
						el( TextControl, { label: 'Address', value: x.slug, onChange: ( slug ) => setTax( i, { slug } ) } )
					),
					el( ToggleControl, { label: 'Nested, like categories (off: flat, like tags)', checked: x.hierarchical, onChange: ( hierarchical ) => setTax( i, { hierarchical } ) } ),
					el( Button, { variant: 'link', isDestructive: true, onClick: () => setTaxes( taxes.filter( ( y, j ) => j !== i ) ) }, 'Remove' )
				) ),
				el( Button, { variant: 'secondary', onClick: () => setTaxes( [ ...taxes, { key: '', label: '', singular: '', slug: '', hierarchical: true } ] ) }, 'Add taxonomy' )
			),
			actions(
				el( Button, { variant: 'primary', onClick: save }, 'Save post type' ),
				el( Button, { variant: 'tertiary', onClick: onCancel }, 'Cancel' )
			)
		);
	};

	/* Site content */

	const SiteScreen = () => {
		const [ state, setState ] = useState( null );
		const [ status, setStatus ] = useState( '' );
		useEffect( () => {
			api( 'site' ).then( setState );
		}, [] );
		if ( ! state ) return el( Spinner );
		const save = () => {
			setStatus( 'saving' );
			api( 'site', { values: state.values } ).then( ( next ) => {
				setState( next );
				setStatus( 'saved' );
			}, ( e ) => setStatus( errorsOf( e ).join( ' ' ) ) );
		};
		return page( 'Site content', null,
			status && status !== 'saving' && el( Notice, { status: status === 'saved' ? 'success' : 'error', onRemove: () => setStatus( '' ) }, status === 'saved' ? 'Saved.' : status ),
			el( 'p', null, 'Details used across the whole site.' ),
			Object.entries( state.groups ).map( ( [ name, g ] ) => section( g.label,
				el( GroupControl, { g, value: state.values[ name ], onChange: ( v ) => setState( { ...state, values: { ...state.values, [ name ]: v } } ) } )
			) ),
			actions( el( Button, { variant: 'primary', isBusy: status === 'saving', disabled: status === 'saving', onClick: save }, 'Save' ) )
		);
	};

	const screens = { group: GroupScreen, types: TypesScreen, site: SiteScreen };
	wp.element.createRoot( root ).render( el( screens[ root.dataset.screen ] || GroupScreen ) );
} )( window.wp );
