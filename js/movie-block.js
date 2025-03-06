const { registerBlockType } = wp.blocks;
const { useBlockProps, InspectorControls } = wp.blockEditor;
const { 
    PanelBody, 
    SelectControl, 
    RangeControl, 
    RadioControl,
    ToggleControl 
} = wp.components;
const { __ } = wp.i18n;
const { useState, Fragment, createElement } = wp.element;
const ServerSideRender = wp.serverSideRender;

// Block configuration
const BLOCK_NAME = 'letterboxd-wordpress/movie-grid';
const DEFAULT_ATTRIBUTES = {
    number: 12,
    showAll: false,
    perPage: 12,
    orderby: 'date',
    order: 'DESC',
    columns: 3,
    displayMode: 'cards',
    showDirector: true,
    showRating: true,
    showStreamingLink: true,
    showExternalLinks: true
};

// Editor control options
const orderOptions = [
    { label: __('Descending', 'letterboxd-wordpress'), value: 'DESC' },
    { label: __('Ascending', 'letterboxd-wordpress'), value: 'ASC' }
];

const orderByOptions = [
    { label: __('Watch Date', 'letterboxd-wordpress'), value: 'watch_date' },
    { label: __('Movie Title', 'letterboxd-wordpress'), value: 'title' },
    { label: __('Release Year', 'letterboxd-wordpress'), value: 'release_year' }
];

const displayModeOptions = [
    { label: __('Cards', 'letterboxd-wordpress'), value: 'cards' },
    { label: __('List', 'letterboxd-wordpress'), value: 'list' }
];


const EditMovieGrid = ({ attributes, setAttributes }) => {
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);
    const blockProps = useBlockProps({
        className: `display-${attributes.displayMode} columns-${attributes.columns}`
    });
    
    // Apply styles based on columns and display mode
    React.useEffect(() => {
        const styleId = 'letterboxd-movie-grid-editor-styles';
        let styleElement = document.getElementById(styleId);
        
        if (!styleElement) {
            styleElement = document.createElement('style');
            styleElement.id = styleId;
            document.head.appendChild(styleElement);
        }
    
        // Apply grid styles conditionally based on display mode
        if (attributes.displayMode === 'cards') {
            styleElement.textContent = `
                .editor-styles-wrapper .movie-grid-preview .movie-grid,
                .editor-styles-wrapper .editor-preview .movie-grid {
                    display: grid;
                    grid-template-columns: repeat(${attributes.columns}, 1fr);
                    gap: 1.5rem;
                }
            `;
        } else {
            // List display mode - always single column
            styleElement.textContent = `
                .editor-styles-wrapper .movie-grid-preview .movie-grid,
                .editor-styles-wrapper .editor-preview .movie-grid {
                    display: flex;
                    flex-direction: column;
                    gap: 1rem;
                }
            `;
        }
    }, [attributes.columns, attributes.displayMode]);

    // Build inspector controls using createElement
    const inspectorControls = createElement(
        InspectorControls,
        null,
        createElement(
            PanelBody,
            { title: __('Movie Grid Settings', 'letterboxd-wordpress') },
            createElement(RadioControl, {
                label: __('Display Mode', 'letterboxd-wordpress'),
                selected: attributes.displayMode,
                options: displayModeOptions,
                onChange: (value) => setAttributes({ displayMode: value }),
                help: __('Choose how to display your movies', 'letterboxd-wordpress')
            }),
            // Only show columns option for cards display mode
            attributes.displayMode === 'cards' && createElement(RangeControl, {
                label: __('Columns', 'letterboxd-wordpress'),
                value: attributes.columns,
                onChange: (value) => setAttributes({ columns: value }),
                min: 1,
                max: 6,
                help: [
                    __('Select number of columns in the grid,', 'letterboxd-wordpress'),
                    createElement('br', { key: 'break' }),
                    __('max of two columns show on mobile', 'letterboxd-wordpress')
                ]
            }),
            createElement(ToggleControl, {
                label: __('Show All Movies', 'letterboxd-wordpress'),
                checked: attributes.showAll,
                onChange: (value) => setAttributes({ showAll: value }),
                help: __('Enable pagination and show all movies', 'letterboxd-wordpress')
            }),
            !attributes.showAll && createElement(RangeControl, {
                label: __('Number of Movies', 'letterboxd-wordpress'),
                value: attributes.number,
                onChange: (value) => setAttributes({ number: value }),
                min: 1,
                max: 24,
                help: __('Select how many movies to display', 'letterboxd-wordpress')
            }),
            attributes.showAll && createElement(RangeControl, {
                label: __('Movies Per Page', 'letterboxd-wordpress'),
                value: attributes.perPage,
                onChange: (value) => setAttributes({ perPage: value }),
                min: 6,
                max: 20,
                help: __('Select how many movies to display per page', 'letterboxd-wordpress')
            }),
            createElement(SelectControl, {
                label: __('Order By', 'letterboxd-wordpress'),
                value: attributes.orderby,
                options: orderByOptions,
                onChange: (value) => setAttributes({ orderby: value })
            }),
            createElement(SelectControl, {
                label: __('Order', 'letterboxd-wordpress'),
                value: attributes.order,
                options: orderOptions,
                onChange: (value) => setAttributes({ order: value })
            })
        ),
        createElement(
            PanelBody,
            { 
                title: __('Display Options', 'letterboxd-wordpress'),
                initialOpen: true
            },
            createElement(ToggleControl, {
                label: __('Show Director', 'letterboxd-wordpress'),
                checked: attributes.showDirector,
                onChange: (value) => setAttributes({ showDirector: value })
            }),
            createElement(ToggleControl, {
                label: __('Show Rating', 'letterboxd-wordpress'),
                checked: attributes.showRating,
                onChange: (value) => setAttributes({ showRating: value })
            }),
            createElement(ToggleControl, {
                label: __('Show Streaming Link', 'letterboxd-wordpress'),
                checked: attributes.showStreamingLink,
                onChange: (value) => setAttributes({ showStreamingLink: value })
            }),
            createElement(ToggleControl, {
                label: __('Show External Links', 'letterboxd-wordpress'),
                checked: attributes.showExternalLinks,
                onChange: (value) => setAttributes({ showExternalLinks: value })
            })
        )
    );

    // Build block content with error handling
    let blockContent;
    if (error) {
        blockContent = createElement(
            'div',
            { className: 'components-placeholder is-error' },
            createElement(
                'div',
                { className: 'components-placeholder__error' },
                error
            )
        );
    } else {
        blockContent = createElement(
            'div', 
            { 
                className: `movie-grid-preview ${isLoading ? 'is-loading' : ''}`,
                'data-columns': attributes.columns,
                'data-display-mode': attributes.displayMode
            },
            createElement(ServerSideRender, {
                block: BLOCK_NAME,
                attributes: attributes,
                onError: (err) => {
                    console.error('Movie Grid Error:', err);
                    setError(err.message || __('Error loading movies', 'letterboxd-wordpress'));
                },
                onBeforeChange: () => {
                    setIsLoading(true);
                    setError(null);
                },
                onAfterChange: () => {
                    setIsLoading(false);
                }
            })
        );
    }

    // Return final element using Fragment and createElement
    return createElement(
        Fragment,
        null,
        inspectorControls,
        createElement(
            'div',
            blockProps,
            blockContent
        )
    );
};

// Register the block
registerBlockType(BLOCK_NAME, {
    title: __('Movie Grid', 'letterboxd-wordpress'),
    icon: 'video-alt2',
    category: 'letterboxd-blocks',
    keywords: [
        __('movies', 'letterboxd-wordpress'),
        __('letterboxd', 'letterboxd-wordpress'),
        __('grid', 'letterboxd-wordpress')
    ],
    supports: {
        align: ['wide', 'full'],
        html: false
    },
    attributes: {
        number: {
            type: 'number',
            default: DEFAULT_ATTRIBUTES.number
        },
        showAll: {
            type: 'boolean',
            default: DEFAULT_ATTRIBUTES.showAll
        },
        perPage: {
            type: 'number',
            default: DEFAULT_ATTRIBUTES.perPage
        },
        orderby: {
            type: 'string',
            default: DEFAULT_ATTRIBUTES.orderby
        },
        order: {
            type: 'string',
            default: DEFAULT_ATTRIBUTES.order
        },
        columns: {
            type: 'number',
            default: DEFAULT_ATTRIBUTES.columns
        },
        displayMode: {
            type: 'string',
            default: DEFAULT_ATTRIBUTES.displayMode
        },
        // display options
        showDirector: {
            type: 'boolean',
            default: DEFAULT_ATTRIBUTES.showDirector
        },
        showRating: {
            type: 'boolean',
            default: DEFAULT_ATTRIBUTES.showRating
        },
        showStreamingLink: {
            type: 'boolean',
            default: DEFAULT_ATTRIBUTES.showStreamingLink
        },
        showExternalLinks: {
            type: 'boolean',
            default: DEFAULT_ATTRIBUTES.showExternalLinks
        }
    },
    edit: EditMovieGrid,
    save: () => null // Server-side rendered
});


// Cleanup handler
document.addEventListener('DOMContentLoaded', () => {
    const observer = new MutationObserver((mutations) => {
        const hasRemovedGrid = mutations.some(mutation =>
            Array.from(mutation.removedNodes).some(
                (node) => node.classList && node.classList.contains('wp-block-letterboxd-wordpress-movie-grid')
            )
        );

        if (hasRemovedGrid) {
            const styleElement = document.getElementById('letterboxd-movie-grid-editor-styles');
            if (styleElement && !document.querySelector('.wp-block-letterboxd-wordpress-movie-grid')) {
                styleElement.remove();
            }
        }
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Cleanup observer on page unload
    window.addEventListener('unload', () => observer.disconnect());
});