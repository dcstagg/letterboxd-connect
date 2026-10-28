/**
 * Movie Grid block, editor side. Settings are in the block's sidebar; the
 * block itself shows the server-rendered grid (render.php).
 */
(function (wp) {
	'use strict';

	const el = wp.element.createElement;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { Disabled, PanelBody, RangeControl, SelectControl, ToggleControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	const common = { __nextHasNoMarginBottom: true };
	const select = (label, value, options, onChange) =>
		el(SelectControl, { ...common, __next40pxDefaultSize: true, label, value, options, onChange });
	const range = (label, value, min, max, onChange) =>
		el(RangeControl, { ...common, __next40pxDefaultSize: true, label, value, min, max, onChange });
	const toggle = (label, checked, onChange) =>
		el(ToggleControl, { ...common, label, checked, onChange });

	wp.blocks.registerBlockType('letterboxd-connect/movie-grid', {
		edit({ attributes: a, setAttributes: set }) {
			const blockProps = useBlockProps();

			return el(wp.element.Fragment, null,
				el(InspectorControls, null,
					el(PanelBody, { title: 'Layout' },
						select('Display', a.displayMode, [
							{ label: 'Cards', value: 'cards' },
							{ label: 'List', value: 'list' },
						], (displayMode) => set({ displayMode })),
						a.displayMode === 'cards' && range('Columns', a.columns, 1, 6, (columns) => set({ columns }))
					),
					el(PanelBody, { title: 'Movies' },
						toggle('Show all movies, a page at a time', a.showAll, (showAll) => set({ showAll })),
						a.showAll
							? range('Movies per page', a.perPage, 1, 100, (perPage) => set({ perPage }))
							: range('Number of movies', a.number, 1, 100, (number) => set({ number })),
						select('Order by', a.orderby, [
							{ label: 'Date watched', value: 'watch_date' },
							{ label: 'Title', value: 'title' },
							{ label: 'Release year', value: 'release_year' },
						// A to Z is what a title order usually means.
						], (orderby) => set(orderby === 'title' ? { orderby, order: 'ASC' } : { orderby })),
						select('Order', a.order, [
							{ label: 'Newest / Z first', value: 'DESC' },
							{ label: 'Oldest / A first', value: 'ASC' },
						], (order) => set({ order }))
					),
					el(PanelBody, { title: 'Show' },
						toggle('Director', a.showDirector, (showDirector) => set({ showDirector })),
						toggle('Rating', a.showRating, (showRating) => set({ showRating })),
						toggle('Where to watch link', a.showStreamingLink, (showStreamingLink) => set({ showStreamingLink })),
						toggle('IMDb and Rotten Tomatoes links', a.showExternalLinks, (showExternalLinks) => set({ showExternalLinks }))
					)
				),
				el('div', blockProps,
					// Disabled: the preview's links shouldn't open in the editor.
					el(Disabled, null, el(ServerSideRender, { block: 'letterboxd-connect/movie-grid', attributes: a }))
				)
			);
		},
		save: () => null,
	});
})(window.wp);
