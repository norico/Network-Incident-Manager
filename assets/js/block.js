/* global wp */
/* Network Incident Manager — Gutenberg block (ES5, no build step) */
( function ( blocks, element, blockEditor, components, serverSideRender ) {
    'use strict';

    var el               = element.createElement;
    var __               = wp.i18n.__;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody        = components.PanelBody;
    var SelectControl    = components.SelectControl;
    var RangeControl     = components.RangeControl;
    var ServerSideRender = serverSideRender.default || serverSideRender;

    var SECTION_OPTIONS = [
        { label: __( 'All sections',  'network-incident-manager' ), value: ''          },
        { label: __( 'Active',        'network-incident-manager' ), value: 'active'    },
        { label: __( 'Scheduled',     'network-incident-manager' ), value: 'scheduled' },
        { label: __( 'Resolved',      'network-incident-manager' ), value: 'resolved'  },
    ];

    blocks.registerBlockType( 'nim/incidents', {
        title:       __( 'Network Incidents', 'network-incident-manager' ),
        description: __( 'Display network incidents (active, scheduled, resolved).', 'network-incident-manager' ),
        category:    'widgets',
        icon:        'warning',
        supports:    { html: false },

        attributes: {
            section: { type: 'string',  default: ''  },
            limit:   { type: 'integer', default: 0   },
        },

        edit: function ( props ) {
            var attributes  = props.attributes;
            var setAttributes = props.setAttributes;
            var section     = attributes.section;
            var limit       = attributes.limit;

            // Label shown under the SSR preview.
            var sectionLabel = SECTION_OPTIONS.reduce( function ( found, opt ) {
                return opt.value === section ? opt.label : found;
            }, __( 'All sections', 'network-incident-manager' ) );

            return [
                // Sidebar controls.
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, {
                            title: __( 'Incidents settings', 'network-incident-manager' ),
                            initialOpen: true,
                        },
                        el( SelectControl, {
                            label:    __( 'Section', 'network-incident-manager' ),
                            help:     __( 'Choose which section to display.', 'network-incident-manager' ),
                            value:    section,
                            options:  SECTION_OPTIONS,
                            onChange: function ( val ) { setAttributes( { section: val } ); },
                        } ),
                        el( RangeControl, {
                            label:    __( 'Limit', 'network-incident-manager' ),
                            help:     __( 'Maximum items to show (0 = no limit).', 'network-incident-manager' ),
                            value:    limit,
                            min:      0,
                            max:      50,
                            onChange: function ( val ) { setAttributes( { limit: val || 0 } ); },
                        } )
                    )
                ),
                // Live server-side preview.
                el( ServerSideRender, {
                    key:   'ssr',
                    block: 'nim/incidents',
                    attributes: attributes,
                } ),
            ];
        },

        save: function () {
            // Dynamic block — rendered server-side, nothing saved in post content.
            return null;
        },
    } );

} )(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.serverSideRender
);
