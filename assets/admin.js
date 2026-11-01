/*
 * Marccent Content's admin screens, plain JS on WordPress's own packages (no build), drawn into #mcc-admin by
 * inc/admin.php; its data-screen says which:
 * - groups: Fields → Field groups. The list, and a group's editor: its name, whether it repeats, where it goes (post
 *   types, some of their posts by slug, the site) and its fields (label, name, type, help, options, prefix, suffix).
 * - types: Fields → Post types. The list, and a type's editor: its names, URL, icon, archive, search, what its editor
 *   has, and its taxonomies.
 * - site: Site content. The groups on the site, with the same controls as beside a post (window.marccentContentUI,
 *   from editor.js).
 * The spec is saved whole (POST marccent-content/v1/spec), checked by the server; its errors show here.
 */
( function ( wp ) {
	const root = document.getElementById( 'mcc-admin' );
	if ( ! root ) return;

	const { createElement: el, Fragment, useState, useEffect } = wp.element;
	const { Button, TextControl, TextareaControl, SelectControl, ToggleControl, CheckboxControl, Notice, Spinner, Card, CardHeader, CardBody } = wp.components;
	const { GroupControl, opt } = window.marccentContentUI;
	const api = ( path, data ) => wp.apiFetch( { path: `/marccent-content/v1/${ path }`, ...( data ? { method: 'POST', data } : {} ) } );

	const TYPES = [
		opt( 'Text', 'string' ), opt( 'Text, several lines', 'text' ), opt( 'Number', 'number' ), opt( 'Link (URL)', 'url' ),
		opt( 'Email', 'email' ), opt( 'Date', 'date' ), opt( 'Yes / No', 'boolean' ), opt( 'Image', 'image' ), opt( 'Choice from a list', 'select' ),
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

	// Where a group goes, in words: "Pages: gallery · Posts · The site".
	const whereOf = ( on, postTypes ) => {
		const byType = {};
		on.forEach( ( o ) => {
			if ( o === 'options' ) return;
			const [ type, slug ] = o.split( ':' );
			byType[ type ] = byType[ type ] || [];
			if ( slug ) byType[ type ].push( slug );
		} );
		return [
			...Object.entries( byType ).map( ( [ type, slugs ] ) => `${ postTypes[ type ] || type }${ slugs.length ? `: ${ slugs.join( ', ' ) }` : '' }` ),
			...( on.includes( 'options' ) ? [ 'The site (Site content)' ] : [] ),
		].join( ' · ' ) || '—';
	};

	const GroupsScreen = () => {
		const [ state, save ] = useSpec();
		const [ editing, setEditing ] = useState( null ); // { name: original name or '', group }
		const [ errors, setErrors ] = useState( [] );
		const [ notice, setNotice ] = useState( '' );
		if ( ! state ) return el( Spinner );
		const { spec, postTypes } = state;
		const put = ( groups, message ) => save( { ...spec, groups } ).then( () => {
			setEditing( null );
			setErrors( [] );
			setNotice( message );
		}, ( e ) => setErrors( errorsOf( e ) ) );

		if ( editing ) {
			return el( GroupEditor, {
				...editing, postTypes, errors, setErrors,
				taken: Object.keys( spec.groups ).filter( ( n ) => n !== editing.name ),
				onCancel: () => {
					setEditing( null );
					setErrors( [] );
				},
				onSave: ( name, group ) => {
					// Keep the groups' order; a renamed group stays where it was.
					const entries = Object.entries( spec.groups );
					const at = entries.findIndex( ( [ n ] ) => n === editing.name );
					if ( at < 0 ) entries.push( [ name, group ] );
					else entries[ at ] = [ name, group ];
					put( Object.fromEntries( entries ), `Saved “${ group.label }”.` );
				},
			} );
		}

		const groups = Object.entries( spec.groups );
		return page( 'Field groups',
			el( Button, { variant: 'secondary', className: 'page-title-action', onClick: () => setEditing( { name: '', group: { label: '', on: [], fields: {} } } ) }, 'Add field group' ),
			notice && el( Notice, { status: 'success', onRemove: () => setNotice( '' ) }, notice ),
			el( Errors, { list: errors, onRemove: () => setErrors( [] ) } ),
			el( 'p', null, 'A field group is a set of fields (an office: address, phone, latitude, longitude) put on a kind of post, on some pages, or on the whole site. One that repeats is a list of rows, like a gallery’s photos, that a Loop block can show.' ),
			groups.length === 0
				? el( 'p', null, el( 'em', null, 'No field groups yet.' ) )
				: el( 'table', { className: 'wp-list-table widefat fixed striped' },
					el( 'thead', null, el( 'tr', null, ...[ 'Group', 'Where', 'Fields', 'Repeats' ].map( ( t ) => el( 'th', { key: t }, t ) ) ) ),
					el( 'tbody', null, groups.map( ( [ name, g ] ) => el( 'tr', { key: name },
						el( 'td', null,
							el( 'strong', null, el( 'a', { href: '#', onClick: ( e ) => {
								e.preventDefault();
								setEditing( { name, group: g } );
							} }, g.label || name ) ),
							el( 'div', null, el( 'code', null, name ) ),
							el( 'div', { className: 'row-actions visible' },
								el( Button, { variant: 'link', onClick: () => setEditing( { name, group: g } ) }, 'Edit' ), ' | ',
								el( Button, { variant: 'link', isDestructive: true, onClick: () => {
									// eslint-disable-next-line no-alert
									if ( window.confirm( `Delete “${ g.label || name }”? The values already saved stay in the database, but nothing shows or edits them any more.` ) ) {
										put( Object.fromEntries( groups.filter( ( [ n ] ) => n !== name ) ), `Deleted “${ g.label || name }”.` );
									}
								} }, 'Delete' )
							)
						),
						el( 'td', null, whereOf( g.on || [], postTypes ) ),
						el( 'td', null, Object.entries( g.fields || {} ).map( ( [ k, f ] ) => f.label || k ).join( ', ' ) || '—' ),
						el( 'td', null, g.repeat ? 'Yes' : 'No' )
					) ) )
				)
		);
	};

	const GroupEditor = ( { name, group, postTypes, taken, errors, setErrors, onSave, onCancel } ) => {
		const isNew = ! name;
		const [ label, setLabel ] = useState( group.label || '' );
		const [ key, setKey ] = useState( name );
		const [ repeat, setRepeat ] = useState( !! group.repeat );
		// Where: per post type, off / all / some (slugs); and the site.
		const initialWhere = () => {
			const w = {};
			( group.on || [] ).forEach( ( o ) => {
				if ( o === 'options' ) return;
				const [ type, slug ] = o.split( ':' );
				w[ type ] = w[ type ] || { on: true, slugs: [] };
				if ( slug ) w[ type ].slugs.push( slug );
			} );
			return w;
		};
		const [ where, setWhere ] = useState( initialWhere );
		const [ site, setSite ] = useState( ( group.on || [] ).includes( 'options' ) );
		const [ fields, setFields ] = useState( () => Object.entries( group.fields || {} ).map( ( [ k, f ] ) => ( {
			key: k, was: k, label: f.label || k, type: f.type || 'string', help: f.help || '',
			options: ( f.options || [] ).join( '\n' ), prefix: f.prefix || '', suffix: f.suffix || '',
		} ) ) );
		const setField = ( i, patch ) => setFields( fields.map( ( f, j ) => ( j === i ? { ...f, ...patch } : f ) ) );

		const save = () => {
			const problems = [];
			const k = keyOf( key );
			if ( ! label.trim() ) problems.push( 'Give the group a name.' );
			if ( ! k ) problems.push( 'Give the group a key (lowercase letters, numbers, underscores).' );
			if ( taken.includes( k ) ) problems.push( `Another group already has the key “${ k }”.` );
			const keys = fields.map( ( f ) => keyOf( f.key ) );
			fields.forEach( ( f, i ) => {
				if ( ! f.label.trim() || ! keys[ i ] ) problems.push( `Field ${ i + 1 }: give it a label and a key.` );
				if ( keys.indexOf( keys[ i ] ) !== i ) problems.push( `Two fields have the key “${ keys[ i ] }”.` );
				if ( f.type === 'select' && ! f.options.trim() ) problems.push( `${ f.label || `Field ${ i + 1 }` }: list its choices, one per line.` );
			} );
			const on = [
				...Object.entries( where ).filter( ( [ , w ] ) => w.on ).flatMap( ( [ type, w ] ) => ( w.slugs.length ? w.slugs.map( ( s ) => `${ type }:${ s }` ) : [ type ] ) ),
				...( site ? [ 'options' ] : [] ),
			];
			if ( ! on.length ) problems.push( 'Choose where the group goes.' );
			if ( problems.length ) {
				setErrors( problems );
				window.scrollTo( 0, 0 );
				return;
			}
			const out = { label: label.trim(), on, fields: {} };
			if ( repeat ) out.repeat = true;
			fields.forEach( ( f, i ) => {
				const field = { label: f.label.trim(), type: f.type };
				if ( f.help.trim() ) field.help = f.help.trim();
				if ( f.type === 'select' ) field.options = f.options.split( '\n' ).map( ( o ) => o.trim() ).filter( Boolean );
				if ( [ 'string', 'number' ].includes( f.type ) && f.prefix ) field.prefix = f.prefix;
				if ( [ 'string', 'number' ].includes( f.type ) && f.suffix ) field.suffix = f.suffix;
				out.fields[ keys[ i ] ] = field;
			} );
			onSave( k, out );
		};

		const renamed = fields.filter( ( f ) => f.was && keyOf( f.key ) !== f.was );
		return page( isNew ? 'Add field group' : `Edit “${ group.label || name }”`, null,
			el( Errors, { list: errors, onRemove: () => setErrors( [] ) } ),
			section( 'Group',
				Row(
					el( TextControl, { label: 'Name', value: label, help: 'What editors see: Office, Photos, Team.', onChange: ( v ) => {
						setLabel( v );
						if ( isNew ) setKey( keyOf( v ) );
					} } ),
					el( TextControl, { label: 'Key', value: key, help: repeat ? `A Loop’s “Repeat for” (${ keyOf( key ) || 'key' }); in its item, each field: {{ field }}.` : site ? `Anywhere: {{ ${ keyOf( key ) || 'key' }.field }}` : 'Its fields show in Loops and tokens by their own keys: {{ field }}.', onChange: ( v ) => setKey( keyOf( v ) ) } )
				),
				! isNew && keyOf( key ) !== name && el( Notice, { status: 'warning', isDismissible: false }, `Changing the key from “${ name }” disconnects what’s saved under it, and Loops or tokens that use “${ name }” stop working.` ),
				el( ToggleControl, { label: 'Repeats: a list of rows (a gallery’s photos, offices, team members)', checked: repeat, onChange: setRepeat } )
			),
			section( 'Where it goes',
				Object.entries( postTypes ).map( ( [ type, typeLabel ] ) => {
					const w = where[ type ] || { on: false, slugs: [] };
					const setW = ( patch ) => setWhere( { ...where, [ type ]: { ...w, ...patch } } );
					return el( 'div', { key: type, style: { marginBottom: '8px' } },
						el( CheckboxControl, { label: typeLabel, checked: w.on, onChange: ( on ) => setW( { on } ) } ),
						w.on && el( 'div', { style: { marginLeft: '28px', maxWidth: '28rem' } }, el( TextControl, {
							label: 'Only these (their slugs, comma-separated)', help: `Empty: every one of the ${ typeLabel.toLowerCase() }.`,
							value: w.slugs.join( ', ' ), onChange: ( v ) => setW( { slugs: v.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) } ),
						} ) )
					);
				} ),
				el( CheckboxControl, { label: 'The whole site (edited under Site content)', checked: site, onChange: setSite } )
			),
			section( 'Fields',
				renamed.length > 0 && el( Notice, { status: 'warning', isDismissible: false }, `Renamed: ${ renamed.map( ( f ) => `“${ f.was }” → “${ keyOf( f.key ) }”` ).join( ', ' ) }. What’s saved under the old key won’t show, and tokens using it stop working.` ),
				fields.length === 0 && el( 'p', null, el( 'em', null, 'No fields yet.' ) ),
				fields.map( ( f, i ) => el( 'div', { key: i, style: { borderTop: i ? '1px solid #ddd' : 0, padding: '12px 0' } },
					Row(
						el( TextControl, { label: 'Label', value: f.label, onChange: ( v ) => setField( i, { label: v, ...( f.was ? {} : { key: keyOf( v ) } ) } ) } ),
						el( TextControl, { label: 'Key', value: f.key, onChange: ( v ) => setField( i, { key: keyOf( v ) } ) } ),
						el( SelectControl, { label: 'Type', value: f.type, options: TYPES, onChange: ( type ) => setField( i, { type } ) } )
					),
					Row(
						el( TextControl, { label: 'Help (optional)', value: f.help, onChange: ( help ) => setField( i, { help } ) } ),
						[ 'string', 'number' ].includes( f.type ) && el( TextControl, { label: 'Before the value', value: f.prefix, help: 'e.g. $', onChange: ( prefix ) => setField( i, { prefix } ) } ),
						[ 'string', 'number' ].includes( f.type ) && el( TextControl, { label: 'After the value', value: f.suffix, help: 'e.g.  sq ft', onChange: ( suffix ) => setField( i, { suffix } ) } )
					),
					f.type === 'select' && el( TextareaControl, { label: 'Choices, one per line', value: f.options, rows: 4, onChange: ( options ) => setField( i, { options } ) } ),
					el( 'div', { style: { display: 'flex', gap: '8px' } },
						el( Button, { variant: 'secondary', size: 'small', disabled: i === 0, onClick: () => setFields( move( fields, i, -1 ) ) }, 'Up' ),
						el( Button, { variant: 'secondary', size: 'small', disabled: i === fields.length - 1, onClick: () => setFields( move( fields, i, 1 ) ) }, 'Down' ),
						el( Button, { variant: 'link', isDestructive: true, onClick: () => setFields( fields.filter( ( x, j ) => j !== i ) ) }, 'Remove' )
					)
				) ),
				el( Button, { variant: 'secondary', onClick: () => setFields( [ ...fields, { key: '', was: '', label: '', type: 'string', help: '', options: '', prefix: '', suffix: '' } ] ) }, 'Add field' )
			),
			actions(
				el( Button, { variant: 'primary', onClick: save }, 'Save field group' ),
				el( Button, { variant: 'tertiary', onClick: onCancel }, 'Cancel' )
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
				el( ToggleControl, { label: 'A page listing them all (an archive)', checked: !! t.archive, onChange: ( archive ) => set( { archive } ) } ),
				el( ToggleControl, { label: 'Keep their pages out of search results', checked: !! t.noindex, onChange: ( noindex ) => set( { noindex } ) } )
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

	const screens = { groups: GroupsScreen, types: TypesScreen, site: SiteScreen };
	wp.element.createRoot( root ).render( el( screens[ root.dataset.screen ] || GroupsScreen ) );
} )( window.wp );
