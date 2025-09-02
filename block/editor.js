(function (wp) {
    var registerBlockType = wp.blocks.registerBlockType;
    var __ = wp.i18n.__;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;

    var be = wp.blockEditor || wp.editor;
    var InspectorControls = be.InspectorControls;
    var InnerBlocks = be.InnerBlocks;
    var BlockControls = be.BlockControls;

    var components = wp.components;
    var PanelBody = components.PanelBody;
    var DateTimePicker = components.DateTimePicker;
    var ToggleControl = components.ToggleControl;
    var TextareaControl = components.TextareaControl;
    var ToolbarGroup = components.ToolbarGroup;
    var ToolbarButton = components.ToolbarButton;
    var Notice = components.Notice;

    var ALLOWED_BLOCKS = undefined; // allow any
    var TEMPLATE = []; // none

    function toISOZ(val){
        if (!val) return '';
        var d = new Date(val);
        if (isNaN(d.getTime())) return '';
        var iso = d.toISOString();
        return iso.replace(/\.\d{3}Z$/, 'Z');
    }

    registerBlockType('h-b/scheduled-container', {
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var start = attributes.start;
            var end = attributes.end;
            var showForAdmins = attributes.showForAdmins;
            var showPlaceholder = attributes.showPlaceholder;
            var placeholderText = attributes.placeholderText;

            function scheduleLabel() {
                return 'Start: ' + (start || '—') + ' | End: ' + (end || '—');
            }

            return el(
                Fragment,
                null,
                el(
                    BlockControls,
                    null,
                    el(
                        ToolbarGroup,
                        null,
                        el(ToolbarButton, { icon: 'clock', label: __('Schedule Details', 'scheduled-content-block'), disabled: true })
                    )
                ),
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __('Schedule', 'scheduled-content-block'), initialOpen: true },
                        el('p', { className: 'components-help' }, __('Times save as UTC; display uses the site timezone.', 'scheduled-content-block')),
                        el('label', { className: 'components-base-control__label' }, __('Start (optional)', 'scheduled-content-block')),
                        el(DateTimePicker, {
                            currentDate: start || '',
                            onChange: function (val) { setAttributes({ start: toISOZ(val) }); },
                            is12Hour: false
                        }),
                        el('div', { style: { height: '10px' } }),
                        el('label', { className: 'components-base-control__label' }, __('End (optional)', 'scheduled-content-block')),
                        el(DateTimePicker, {
                            currentDate: end || '',
                            onChange: function (val) { setAttributes({ end: toISOZ(val) }); },
                            is12Hour: false
                        })
                    ),
                    el(
                        PanelBody,
                        { title: __('Visibility Options', 'scheduled-content-block'), initialOpen: false },
                        el(ToggleControl, {
                            label: __('Always show to admins', 'scheduled-content-block'),
                            checked: !!showForAdmins,
                            onChange: function (v) { setAttributes({ showForAdmins: !!v }); }
                        }),
                        el(ToggleControl, {
                            label: __('Output placeholder when hidden', 'scheduled-content-block'),
                            checked: !!showPlaceholder,
                            onChange: function (v) { setAttributes({ showPlaceholder: !!v }); }
                        }),
                        showPlaceholder ? el(TextareaControl, {
                            label: __('Placeholder text', 'scheduled-content-block'),
                            value: placeholderText,
                            onChange: function (v) { setAttributes({ placeholderText: v }); }
                        }) : null
                    )
                ),
                el(
                    'div',
                    { className: 'scb-editor-frame' },
                    el(Notice, { status: 'info', isDismissible: false },
                        el('strong', null, __('Scheduled Container', 'scheduled-content-block')),
                        ': ' + scheduleLabel() + ' — ' + __('Editor shows content for authoring. Frontend enforces schedule.', 'scheduled-content-block')
                    ),
                    el(
                        'div',
                        { className: 'scb-editor-inner' },
                        el(InnerBlocks, {
                            allowedBlocks: ALLOWED_BLOCKS,
                            template: TEMPLATE,
                            templateLock: false
                        })
                    )
                )
            );
        },
        save: function () {
            return el(InnerBlocks.Content, null);
        }
    });
})(window.wp);
