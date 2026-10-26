/**
 * Block editor UI of sw-cloudcalendar/calendar. Plain WordPress globals, no build step: the block uses the same
 * server side render path as the shortcode, the editor only edits attributes and shows a ServerSideRender preview.
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment, useEffect, useState } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, ToggleControl, Placeholder, Spinner, Notice } = wp.components;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;
	const apiFetch = wp.apiFetch;

	function useViews() {
		const [ state, setState ] = useState( { loading: true, views: [], error: false } );
		useEffect( function () {
			let active = true;
			apiFetch( { path: '/sw-cloudcalendar/v1/editor/views' } )
				.then( function ( views ) {
					if ( active ) {
						setState( { loading: false, views: Array.isArray( views ) ? views : [], error: false } );
					}
				} )
				.catch( function () {
					if ( active ) {
						setState( { loading: false, views: [], error: true } );
					}
				} );
			return function () {
				active = false;
			};
		}, [] );
		return state;
	}

	function numberOrUndefined( value ) {
		const parsed = parseInt( value, 10 );
		return isNaN( parsed ) || parsed <= 0 ? undefined : parsed;
	}

	function textOrUndefined( value ) {
		return value === '' ? undefined : value;
	}

	function Edit( props ) {
		const { attributes: a, setAttributes } = props;
		const blockProps = useBlockProps();
		const { loading, views, error } = useViews();

		const viewOptions = [ { label: __( '— Select a calendar view —', 'sw-cloudcalendar' ), value: '0' } ].concat(
			views.map( function ( view ) {
				return { label: view.name + ( view.enabled ? '' : ' (' + __( 'disabled', 'sw-cloudcalendar' ) + ')' ), value: String( view.id ) };
			} )
		);

		const inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Calendar', 'sw-cloudcalendar' ), initialOpen: true },
				loading ? el( Spinner ) : el( SelectControl, {
					label: __( 'Calendar view', 'sw-cloudcalendar' ),
					value: String( a.viewId || 0 ),
					options: viewOptions,
					onChange: function ( value ) {
						setAttributes( { viewId: parseInt( value, 10 ) || 0 } );
					},
				} ),
				el( SelectControl, {
					label: __( 'Layout', 'sw-cloudcalendar' ),
					help: __( 'Only layouts allowed by the view are used.', 'sw-cloudcalendar' ),
					value: a.displayMode || '',
					options: [
						{ label: __( 'View default', 'sw-cloudcalendar' ), value: '' },
						{ label: __( 'Month', 'sw-cloudcalendar' ), value: 'month' },
						{ label: __( 'List', 'sw-cloudcalendar' ), value: 'list' },
						{ label: __( 'Cards', 'sw-cloudcalendar' ), value: 'cards' },
					],
					onChange: function ( value ) {
						setAttributes( { displayMode: value } );
					},
				} )
			),
			el(
				PanelBody,
				{ title: __( 'Filter', 'sw-cloudcalendar' ), initialOpen: false },
				el( 'p', { className: 'swc-block-editor__hint' }, __( 'Filters can only narrow the view; they never grant access to other calendars.', 'sw-cloudcalendar' ) ),
				el( TextControl, { label: __( 'Limit (1–100)', 'sw-cloudcalendar' ), type: 'number', min: 1, max: 100, value: a.limit || '', onChange: function ( v ) { setAttributes( { limit: numberOrUndefined( v ) } ); } } ),
				el( TextControl, { label: __( 'Next days (1–1095)', 'sw-cloudcalendar' ), type: 'number', min: 1, max: 1095, value: a.days || '', onChange: function ( v ) { setAttributes( { days: numberOrUndefined( v ) } ); } } ),
				el( TextControl, { label: __( 'Start date', 'sw-cloudcalendar' ), type: 'date', value: a.start || '', onChange: function ( v ) { setAttributes( { start: textOrUndefined( v ) } ); } } ),
				el( TextControl, { label: __( 'End date', 'sw-cloudcalendar' ), type: 'date', value: a.end || '', onChange: function ( v ) { setAttributes( { end: textOrUndefined( v ) } ); } } ),
				el( ToggleControl, { label: __( 'Include past events', 'sw-cloudcalendar' ), checked: !! a.past, onChange: function ( v ) { setAttributes( { past: v || undefined } ); } } ),
				el( TextControl, { label: __( 'Search text', 'sw-cloudcalendar' ), maxLength: 150, value: a.search || '', onChange: function ( v ) { setAttributes( { search: textOrUndefined( v ) } ); } } ),
				el( TextControl, { label: __( 'Location contains', 'sw-cloudcalendar' ), maxLength: 150, value: a.location || '', onChange: function ( v ) { setAttributes( { location: textOrUndefined( v ) } ); } } ),
				el( TextControl, { label: __( 'Source IDs (comma separated)', 'sw-cloudcalendar' ), value: a.source || '', onChange: function ( v ) { setAttributes( { source: textOrUndefined( v.replace( /[^0-9,]/g, '' ) ) } ); } } ),
				el( SelectControl, {
					label: __( 'Sort order', 'sw-cloudcalendar' ),
					value: a.sort || '',
					options: [
						{ label: __( 'View default', 'sw-cloudcalendar' ), value: '' },
						{ label: __( 'Ascending', 'sw-cloudcalendar' ), value: 'asc' },
						{ label: __( 'Descending', 'sw-cloudcalendar' ), value: 'desc' },
					],
					onChange: function ( v ) { setAttributes( { sort: v } ); },
				} )
			),
			el(
				PanelBody,
				{ title: __( 'Display', 'sw-cloudcalendar' ), initialOpen: false },
				el( TextControl, { label: __( 'Event template ID', 'sw-cloudcalendar' ), help: __( 'Only templates allowed by the view.', 'sw-cloudcalendar' ), type: 'number', min: 1, value: a.template || '', onChange: function ( v ) { setAttributes( { template: numberOrUndefined( v ) } ); } } ),
				el( SelectControl, {
					label: __( 'Navigation', 'sw-cloudcalendar' ),
					help: __( 'Previous/next month in the month view, earlier/more events in lists.', 'sw-cloudcalendar' ),
					value: a.navigation || '',
					options: [
						{ label: __( 'View default', 'sw-cloudcalendar' ), value: '' },
						{ label: __( 'Show', 'sw-cloudcalendar' ), value: 'true' },
						{ label: __( 'Hide', 'sw-cloudcalendar' ), value: 'false' },
					],
					onChange: function ( v ) { setAttributes( { navigation: v } ); },
				} ),
				el( ToggleControl, { label: __( 'Show images', 'sw-cloudcalendar' ), checked: a.showImages !== false, onChange: function ( v ) { setAttributes( { showImages: v ? undefined : false } ); } } ),
				el( ToggleControl, { label: __( 'Show file list', 'sw-cloudcalendar' ), checked: a.showAttachments !== false, onChange: function ( v ) { setAttributes( { showAttachments: v ? undefined : false } ); } } )
			)
		);

		let content;
		if ( ! a.viewId ) {
			content = el(
				Placeholder,
				{ icon: 'calendar-alt', label: __( 'Calendar', 'sw-cloudcalendar' ), className: 'swc-block-editor__placeholder' },
				error ? el( Notice, { status: 'error', isDismissible: false }, __( 'Calendar views could not be loaded.', 'sw-cloudcalendar' ) ) : __( 'Select a calendar view in the block settings.', 'sw-cloudcalendar' )
			);
		} else {
			content = el( ServerSideRender, { block: 'sw-cloudcalendar/calendar', attributes: a } );
		}
		return el( Fragment, null, inspector, el( 'div', blockProps, content ) );
	}

	registerBlockType( 'sw-cloudcalendar/calendar', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
