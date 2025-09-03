(function (wp) {
    var registerBlockType = wp.blocks.registerBlockType;
    var __ = wp.i18n.__;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;

    var be = wp.blockEditor || wp.editor;
    var InspectorControls = be.InspectorControls;
    var InnerBlocks = be.InnerBlocks;
    var BlockControls = be.BlockControls;
    var useBlockProps = be.useBlockProps;
    var useInnerBlocksProps = be.useInnerBlocksProps;

    var components = wp.components;
    var PanelBody = components.PanelBody;
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

    function isoToLocalParts(iso) {
        if (!iso) return { date: '', time: '' };
        var tz = getSiteTimeZone();
        try {
            if (wp.date && wp.date.moment) {
                var m = wp.date.moment(iso).tz(tz);
                return { date: m.format('YYYY-MM-DD'), time: m.format('HH:mm') };
            }
        } catch (e) {}
        var d = new Date(iso);
        if (isNaN(d.getTime())) return { date: '', time: '' };
        var pad = function (n) { return ('0' + n).slice(-2); };
        return {
            date: d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()),
            time: pad(d.getHours()) + ':' + pad(d.getMinutes())
        };
    }

    function combineLocal(dateStr, timeStr) {
        if (!dateStr && !timeStr) return '';
        timeStr = timeStr || '00:00';
        var tz = getSiteTimeZone();
        try {
            if (wp.date && wp.date.moment) {
                var m;
                if (dateStr) {
                    m = wp.date.moment.tz(dateStr + 'T' + timeStr, tz);
                } else {
                    var parts = timeStr.split(':');
                    m = wp.date.moment.tz(tz);
                    m.set({ hour: parseInt(parts[0], 10), minute: parseInt(parts[1], 10), second: 0, millisecond: 0 });
                }
                return toISOZ(m.toDate());
            }
        } catch (e) {}
        if (!dateStr) {
            var now = new Date();
            dateStr = now.getFullYear() + '-' + ('0' + (now.getMonth() + 1)).slice(-2) + '-' + ('0' + now.getDate()).slice(-2);
        }
        return toISOZ(dateStr + 'T' + timeStr);
    }

    function getSiteTimeZone() {
        try {
            if (wp.date && (wp.date.__experimentalGetSettings || wp.date.getSettings)) {
                var settings = wp.date.__experimentalGetSettings ? wp.date.__experimentalGetSettings() : wp.date.getSettings();
                if (settings && settings.timezone && settings.timezone.string) {
                    return settings.timezone.string;
                }
            }
        } catch (e) {}
        // Fallback: browser TZ or UTC
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        } catch (e) {
            return 'UTC';
        }
    }

    function formatReadable(iso) {
        if (!iso) return '—';
        var tz = getSiteTimeZone();
        try {
            var d = new Date(iso);
            var datePart = new Intl.DateTimeFormat(undefined, { timeZone: tz, month: 'long', day: 'numeric', year: 'numeric' }).format(d);
            var timePart = new Intl.DateTimeFormat(undefined, { timeZone: tz, hour: 'numeric', minute: '2-digit', hour12: true }).format(d);
            timePart = timePart.toLowerCase().replace(' ', '');
            return datePart + ' at ' + timePart;
        } catch (e) {
            return iso;
        }
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
                return 'Start: ' + formatReadable(start) + ' | End: ' + formatReadable(end);
            }

            var blockProps = useBlockProps({ className: 'scb-editor-frame' });
            var innerBlocksProps = useInnerBlocksProps(
                { className: 'scb-editor-inner' },
                { allowedBlocks: ALLOWED_BLOCKS, template: TEMPLATE, templateLock: false }
            );

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
                        { title: __('Display Schedule', 'scheduled-content-block'), initialOpen: true },
                        el('p', { className: 'components-help' }, __('Choose your start and end date for content to be displayed. Dates and times use the site timezone.', 'scheduled-content-block')),
                        (function(){
                            var sp = isoToLocalParts(start);
                            return el('div', { className: 'scb-datetime-group' },
                                el('label', { className: 'components-base-control__label' }, __('Start', 'scheduled-content-block')),
                                el('div', { className: 'scb-datetime-row' },
                                    el('input', {
                                        type: 'date',
                                        className: 'components-text-control__input',
                                        value: sp.date,
                                        onChange: function(e){ setAttributes({ start: combineLocal(e.target.value, sp.time) }); }
                                    }),
                                    el('input', {
                                        type: 'time',
                                        className: 'components-text-control__input',
                                        value: sp.time,
                                        onChange: function(e){ setAttributes({ start: combineLocal(sp.date, e.target.value) }); }
                                    })
                                )
                            );
                        })(),
                        el('div', { style: { height: '10px' } }),
                        (function(){
                            var ep = isoToLocalParts(end);
                            return el('div', { className: 'scb-datetime-group' },
                                el('label', { className: 'components-base-control__label' }, __('End', 'scheduled-content-block')),
                                el('div', { className: 'scb-datetime-row' },
                                    el('input', {
                                        type: 'date',
                                        className: 'components-text-control__input',
                                        value: ep.date,
                                        onChange: function(e){ setAttributes({ end: combineLocal(e.target.value, ep.time) }); }
                                    }),
                                    el('input', {
                                        type: 'time',
                                        className: 'components-text-control__input',
                                        value: ep.time,
                                        onChange: function(e){ setAttributes({ end: combineLocal(ep.date, e.target.value) }); }
                                    })
                                )
                            );
                        })()
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
                    blockProps,
                    el(Notice, { status: 'info', isDismissible: false },
                        el('strong', null, __('Scheduled Container', 'scheduled-content-block')),
                        ': ' + scheduleLabel()
                    ),
                    el('div', innerBlocksProps)
                )
            );
        },
        save: function () {
            return el(InnerBlocks.Content, null);
        }
    });
})(window.wp);
