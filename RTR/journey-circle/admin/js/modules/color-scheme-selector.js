/**
 * Color Scheme Selector Module
 *
 * Provides a modal-based UI for selecting color schemes (presets or custom)
 * for Presentation and Infographic content types in Step 9.
 *
 * Architecture:
 *   - Standalone module, no modifications to existing files
 *   - Stores selected scheme in workflow state (colorScheme key)
 *   - Exposes window.JCColorSchemeSelector for consumption by:
 *       • step9-asset-manager.js  (trigger button rendering)
 *       • content-renderer.js     (getActiveTheme() for rendering)
 *
 * Integration:
 *   ContentRenderer should call:
 *     var theme = window.JCColorSchemeSelector
 *       ? window.JCColorSchemeSelector.getActiveTheme()
 *       : SLIDE_THEME;
 *
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.2.0
 */
(function($) {
    'use strict';

    // =====================================================================
    // DEFAULT / FALLBACK THEME (matches existing SLIDE_THEME in content-renderer)
    // =====================================================================
    var DEFAULT_THEME = {
        id:           'midnight_blue',
        bg:           '#0F2B46',
        titleBg:      '#1A3A5C',
        accentColor:  '#42A5F5',
        accentAlt:    '#66BB6A',
        textColor:    '#FFFFFF',
        subtextColor: '#B0BEC5',
        bulletColor:  '#42A5F5',
        chartColors:  ['#42A5F5','#66BB6A','#EF5350','#FF7043','#AB47BC','#FFA726','#26C6DA','#EC407A'],
        // Infographic-specific
        headerGradientFrom: '#1a237e',
        headerGradientTo:   '#283593',
        headerSubtitle:     '#90caf9',
        footerBg:           '#263238',
        sectionColors: {
            'Title Slide':          { accent: '#42A5F5', icon: 'fas fa-play-circle' },
            'Problem Definition':   { accent: '#EF5350', icon: 'fas fa-exclamation-triangle' },
            'Problem Amplification':{ accent: '#EF5350', icon: 'fas fa-chart-line' },
            'Solution Overview':    { accent: '#66BB6A', icon: 'fas fa-lightbulb' },
            'Solution Details':     { accent: '#66BB6A', icon: 'fas fa-cogs' },
            'Benefits Summary':     { accent: '#42A5F5', icon: 'fas fa-trophy' },
            'Credibility':          { accent: '#AB47BC', icon: 'fas fa-award' },
            'Call to Action':       { accent: '#FF7043', icon: 'fas fa-bullhorn' }
        }
    };

    // =====================================================================
    // PRESET THEMES
    // =====================================================================
    var PRESETS = [
        {
            id: 'midnight_blue',
            name: 'Midnight Blue',
            desc: 'Dark navy — default',
            bg: '#0F2B46', titleBg: '#1A3A5C',
            accentColor: '#42A5F5', accentAlt: '#66BB6A',
            textColor: '#FFFFFF', subtextColor: '#B0BEC5', bulletColor: '#42A5F5',
            chartColors: ['#42A5F5','#66BB6A','#EF5350','#FF7043','#AB47BC','#FFA726','#26C6DA','#EC407A'],
            headerGradientFrom: '#1a237e', headerGradientTo: '#283593',
            headerSubtitle: '#90caf9', footerBg: '#263238'
        },
        {
            id: 'charcoal',
            name: 'Charcoal',
            desc: 'Neutral dark slate',
            bg: '#1E1E2E', titleBg: '#2A2A3C',
            accentColor: '#E0E0E0', accentAlt: '#A0A0B0',
            textColor: '#FFFFFF', subtextColor: '#9CA3AF', bulletColor: '#E0E0E0',
            chartColors: ['#60A5FA','#34D399','#F87171','#FBBF24','#A78BFA','#FB923C','#22D3EE','#F472B6'],
            headerGradientFrom: '#1E1E2E', headerGradientTo: '#2A2A3C',
            headerSubtitle: '#9CA3AF', footerBg: '#111827'
        },
        {
            id: 'ocean_teal',
            name: 'Ocean Teal',
            desc: 'Deep sea tones',
            bg: '#0D2137', titleBg: '#0F3044',
            accentColor: '#2DD4BF', accentAlt: '#38BDF8',
            textColor: '#FFFFFF', subtextColor: '#94A3B8', bulletColor: '#2DD4BF',
            chartColors: ['#2DD4BF','#38BDF8','#FB923C','#F472B6','#A78BFA','#FDE047','#4ADE80','#F87171'],
            headerGradientFrom: '#064E3B', headerGradientTo: '#115E59',
            headerSubtitle: '#6EE7B7', footerBg: '#0F172A'
        },
        {
            id: 'warm_ember',
            name: 'Warm Ember',
            desc: 'Rich burgundy & copper',
            bg: '#2D1B2E', titleBg: '#3D2440',
            accentColor: '#F59E0B', accentAlt: '#EF4444',
            textColor: '#FFFFFF', subtextColor: '#D4B896', bulletColor: '#F59E0B',
            chartColors: ['#F59E0B','#EF4444','#F97316','#EC4899','#8B5CF6','#14B8A6','#F43F5E','#84CC16'],
            headerGradientFrom: '#7F1D1D', headerGradientTo: '#991B1B',
            headerSubtitle: '#FCA5A5', footerBg: '#1C1917'
        },
        {
            id: 'forest_green',
            name: 'Forest',
            desc: 'Earth & evergreen',
            bg: '#14261A', titleBg: '#1B3324',
            accentColor: '#4ADE80', accentAlt: '#A3E635',
            textColor: '#FFFFFF', subtextColor: '#86EFAC', bulletColor: '#4ADE80',
            chartColors: ['#4ADE80','#A3E635','#FACC15','#FB923C','#38BDF8','#E879F9','#F87171','#34D399'],
            headerGradientFrom: '#14532D', headerGradientTo: '#166534',
            headerSubtitle: '#86EFAC', footerBg: '#052E16'
        },
        {
            id: 'clean_light',
            name: 'Clean Light',
            desc: 'Light background, bold accents',
            bg: '#F8FAFC', titleBg: '#EFF3F8',
            accentColor: '#2563EB', accentAlt: '#7C3AED',
            textColor: '#1E293B', subtextColor: '#64748B', bulletColor: '#2563EB',
            chartColors: ['#2563EB','#7C3AED','#DC2626','#EA580C','#0891B2','#16A34A','#CA8A04','#DB2777'],
            headerGradientFrom: '#1E40AF', headerGradientTo: '#1D4ED8',
            headerSubtitle: '#93C5FD', footerBg: '#1E293B'
        },
        {
            id: 'royal_purple',
            name: 'Royal Purple',
            desc: 'Deep violet tones',
            bg: '#1A0F2E', titleBg: '#261447',
            accentColor: '#A78BFA', accentAlt: '#F472B6',
            textColor: '#FFFFFF', subtextColor: '#C4B5FD', bulletColor: '#A78BFA',
            chartColors: ['#A78BFA','#F472B6','#34D399','#FBBF24','#60A5FA','#FB923C','#22D3EE','#F87171'],
            headerGradientFrom: '#4C1D95', headerGradientTo: '#5B21B6',
            headerSubtitle: '#C4B5FD', footerBg: '#0F0720'
        },
        {
            id: 'sunrise_coral',
            name: 'Sunrise Coral',
            desc: 'Warm coral & gold',
            bg: '#1F1218', titleBg: '#2E1A22',
            accentColor: '#FB7185', accentAlt: '#FBBF24',
            textColor: '#FFFFFF', subtextColor: '#FDA4AF', bulletColor: '#FB7185',
            chartColors: ['#FB7185','#FBBF24','#34D399','#60A5FA','#E879F9','#FB923C','#2DD4BF','#A78BFA'],
            headerGradientFrom: '#9F1239', headerGradientTo: '#BE123C',
            headerSubtitle: '#FDA4AF', footerBg: '#1C1917'
        },
        {
            id: 'arctic_frost',
            name: 'Arctic Frost',
            desc: 'Cool ice-blue palette',
            bg: '#0C1929', titleBg: '#112240',
            accentColor: '#7DD3FC', accentAlt: '#E0F2FE',
            textColor: '#FFFFFF', subtextColor: '#BAE6FD', bulletColor: '#7DD3FC',
            chartColors: ['#7DD3FC','#67E8F9','#A5F3FC','#C4B5FD','#FCA5A5','#FDE68A','#86EFAC','#F9A8D4'],
            headerGradientFrom: '#0C4A6E', headerGradientTo: '#075985',
            headerSubtitle: '#BAE6FD', footerBg: '#082F49'
        }
    ];

    // =====================================================================
    // EDITABLE COLOR FIELDS  (label → theme key)
    // =====================================================================
    var EDITABLE_FIELDS = [
        { label: 'Background',       key: 'bg' },
        { label: 'Accent (Primary)', key: 'accentColor' },
        { label: 'Accent (Alt)',     key: 'accentAlt' },
        { label: 'Text',             key: 'textColor' },
        { label: 'Subtext',          key: 'subtextColor' },
        { label: 'Header Grad. Start', key: 'headerGradientFrom' },
        { label: 'Header Grad. End',   key: 'headerGradientTo' },
        { label: 'Footer',           key: 'footerBg' }
    ];


    // =====================================================================
    // MODULE
    // =====================================================================
    var ColorSchemeSelector = {

        _active: null,       // currently applied theme object (full)
        _editing: null,      // working copy in the modal
        _isOpen: false,
        _backdrop: null,
        _customExpanded: false,

        // -----------------------------------------------------------------
        // INIT
        // -----------------------------------------------------------------
        init: function() {
            this._loadFromState();

            var self = this;
            // Restore on jc:restoreState (page load with saved data)
            $(document).on('jc:restoreState', function() { self._loadFromState(); });
            // Clean on full reset
            $(document).on('jc:stateReset', function() { self._active = null; });
        },

        // -----------------------------------------------------------------
        // PUBLIC API
        // -----------------------------------------------------------------

        /**
         * Returns the currently-active theme object.
         * Intended for content-renderer.js to consume instead of its
         * hard-coded SLIDE_THEME.  Falls back to DEFAULT_THEME.
         */
        getActiveTheme: function() {
            var base = this._active || DEFAULT_THEME;
            // Rebuild sectionColors from the active palette (accent-based)
            return this._buildFullTheme(base);
        },

        /**
         * Returns the raw active preset id (or 'custom').
         */
        getActivePresetId: function() {
            return this._active ? (this._active.id || 'custom') : 'midnight_blue';
        },

        /**
         * Open the selector modal.
         * @param {string} [formatHint] - 'presentation' or 'infographic' to bias preview
         */
        open: function(formatHint) {
            this._editing = $.extend(true, {}, this._active || DEFAULT_THEME);
            this._formatHint = formatHint || 'presentation';
            this._render();
            this._isOpen = true;
        },

        /**
         * Programmatically apply a preset by id.
         */
        applyPreset: function(presetId) {
            var p = this._findPreset(presetId);
            if (p) {
                this._active = $.extend(true, {}, p);
                this._persist();
            }
        },

        /**
         * Build an inline trigger button that can be placed anywhere.
         * Returns an HTML string.
         */
        buildTriggerHtml: function() {
            var theme = this._active || DEFAULT_THEME;
            return '<button type="button" class="jc-cs-trigger-btn" title="Change color scheme">'
                + '<i class="fas fa-palette" style="font-size:12px"></i>'
                + '<span class="cs-swatch-mini">'
                + '<span style="background:' + _esc(theme.bg) + '"></span>'
                + '<span style="background:' + _esc(theme.accentColor) + '"></span>'
                + '<span style="background:' + _esc(theme.accentAlt) + '"></span>'
                + '</span>'
                + '<span>Colors</span>'
                + '</button>';
        },

        // -----------------------------------------------------------------
        // PERSISTENCE  (via workflow state)
        // -----------------------------------------------------------------
        _loadFromState: function() {
            if (!window.drJourneyCircle) return;
            var saved = window.drJourneyCircle.getState('colorScheme');
            if (saved && typeof saved === 'object' && saved.bg) {
                this._active = saved;
            }
        },

        _persist: function() {
            if (!window.drJourneyCircle) return;
            window.drJourneyCircle.updateState('colorScheme', this._active ? $.extend(true, {}, this._active) : null);
        },

        // -----------------------------------------------------------------
        // THEME HELPERS
        // -----------------------------------------------------------------
        _findPreset: function(id) {
            for (var i = 0; i < PRESETS.length; i++) {
                if (PRESETS[i].id === id) return PRESETS[i];
            }
            return null;
        },

        /**
         * Build a full theme object (with sectionColors) from a base palette.
         */
        _buildFullTheme: function(base) {
            var t = $.extend(true, {}, base);
            t.titleBg = t.titleBg || _lightenDarken(t.bg, 20);
            t.bulletColor = t.bulletColor || t.accentColor;
            // Derive sectionColors from accent + chart palette
            var cc = t.chartColors || DEFAULT_THEME.chartColors;
            t.sectionColors = {
                'Title Slide':          { accent: t.accentColor, icon: 'fas fa-play-circle' },
                'Problem Definition':   { accent: cc[2] || '#EF5350', icon: 'fas fa-exclamation-triangle' },
                'Problem Amplification':{ accent: cc[2] || '#EF5350', icon: 'fas fa-chart-line' },
                'Solution Overview':    { accent: t.accentAlt || cc[1], icon: 'fas fa-lightbulb' },
                'Solution Details':     { accent: t.accentAlt || cc[1], icon: 'fas fa-cogs' },
                'Benefits Summary':     { accent: t.accentColor, icon: 'fas fa-trophy' },
                'Credibility':          { accent: cc[4] || '#AB47BC', icon: 'fas fa-award' },
                'Call to Action':       { accent: cc[3] || '#FF7043', icon: 'fas fa-bullhorn' }
            };
            // Infographic fields fallback
            t.headerGradientFrom = t.headerGradientFrom || DEFAULT_THEME.headerGradientFrom;
            t.headerGradientTo   = t.headerGradientTo   || DEFAULT_THEME.headerGradientTo;
            t.headerSubtitle     = t.headerSubtitle     || DEFAULT_THEME.headerSubtitle;
            t.footerBg           = t.footerBg           || DEFAULT_THEME.footerBg;
            return t;
        },

        // -----------------------------------------------------------------
        // RENDER MODAL
        // -----------------------------------------------------------------
        _render: function() {
            // Remove old
            if (this._backdrop) { this._backdrop.remove(); this._backdrop = null; }

            var self = this;
            var html = '<div class="jc-cs-backdrop" id="jc-cs-backdrop">'
                + '<div class="jc-cs-modal">'
                +   this._headerHtml()
                +   '<div class="jc-cs-body">'
                +     this._presetsHtml()
                +     this._customToggleHtml()
                +     this._customPanelHtml()
                +     this._previewHtml()
                +   '</div>'
                +   this._footerHtml()
                + '</div></div>';

            var el = $(html).appendTo('body');
            this._backdrop = el;

            // Trigger open animation
            requestAnimationFrame(function() {
                requestAnimationFrame(function() { el.addClass('is-open'); });
            });

            this._bindModal();
        },

        _headerHtml: function() {
            var fmtLabel = this._formatHint === 'infographic' ? 'Infographic' : 'Presentation';
            return '<div class="jc-cs-header">'
                + '<div class="jc-cs-header-left">'
                +   '<div class="jc-cs-header-icon"><i class="fas fa-palette"></i></div>'
                +   '<h3>Color Scheme<span>Applied to presentations &amp; infographics</span></h3>'
                + '</div>'
                + '<button type="button" class="jc-cs-close-btn" id="jc-cs-close"><i class="fas fa-times"></i></button>'
                + '</div>';
        },

        _presetsHtml: function() {
            var activeId = this._editing ? (this._editing.id || '') : '';
            var html = '<p class="jc-cs-section-label">Theme Presets</p><div class="jc-cs-presets">';
            for (var i = 0; i < PRESETS.length; i++) {
                var p = PRESETS[i];
                var sel = (p.id === activeId) ? ' is-selected' : '';
                html += '<div class="jc-cs-preset' + sel + '" data-preset-id="' + p.id + '">'
                    + '<div class="jc-cs-preset-preview" style="background:' + _esc(p.bg) + '">'
                    +   '<div class="jc-cs-swatch-row">';
                // Show accent + accentAlt + first 3 chart colors
                var swatches = [p.accentColor, p.accentAlt, p.chartColors[2], p.chartColors[3], p.chartColors[4]];
                for (var s = 0; s < swatches.length; s++) {
                    html += '<div class="jc-cs-swatch-dot" style="background:' + _esc(swatches[s]) + '"></div>';
                }
                html += '</div></div>'
                    + '<div class="jc-cs-preset-name">' + _esc(p.name) + '</div>'
                    + '<div class="jc-cs-preset-desc">' + _esc(p.desc) + '</div>'
                    + '</div>';
            }
            html += '</div>';
            return html;
        },

        _customToggleHtml: function() {
            var exp = this._customExpanded ? ' is-expanded' : '';
            return '<div class="jc-cs-custom-toggle' + exp + '" id="jc-cs-custom-toggle">'
                + '<span class="toggle-label"><i class="fas fa-sliders-h"></i> Customize Colors</span>'
                + '<span class="toggle-chevron"><i class="fas fa-chevron-down"></i></span>'
                + '</div>';
        },

        _customPanelHtml: function() {
            var vis = this._customExpanded ? ' is-visible' : '';
            var e = this._editing || DEFAULT_THEME;
            var html = '<div class="jc-cs-custom-panel' + vis + '" id="jc-cs-custom-panel">'
                + '<div class="jc-cs-color-grid">';

            for (var i = 0; i < EDITABLE_FIELDS.length; i++) {
                var f = EDITABLE_FIELDS[i];
                var val = e[f.key] || '#000000';
                html += '<div class="jc-cs-color-field">'
                    + '<label>' + _esc(f.label) + '</label>'
                    + '<div class="jc-cs-color-input-wrap">'
                    +   '<input type="color" class="jc-cs-field" data-key="' + f.key + '" value="' + _esc(val) + '">'
                    +   '<input type="text" class="jc-cs-field-hex" data-key="' + f.key + '" value="' + _esc(val) + '" maxlength="7" spellcheck="false">'
                    + '</div></div>';
            }

            // Chart colors row
            var cc = e.chartColors || DEFAULT_THEME.chartColors;
            html += '<div class="jc-cs-chart-colors-label">Chart &amp; Data Colors</div>';
            html += '<div class="jc-cs-chart-row">';
            for (var c = 0; c < cc.length; c++) {
                html += '<div class="jc-cs-chart-swatch">'
                    + '<input type="color" class="jc-cs-chart-input" data-index="' + c + '" value="' + _esc(cc[c]) + '">'
                    + '</div>';
            }
            html += '</div></div></div>';
            return html;
        },

        _previewHtml: function() {
            var e = this._editing || DEFAULT_THEME;
            return '<p class="jc-cs-section-label" style="margin-top:18px">Preview</p>'
                + '<div class="jc-cs-preview-wrap">'
                +   '<div class="jc-cs-live-preview" id="jc-cs-preview">'
                +     this._buildPreviewContent(e)
                +   '</div>'
                + '</div>';
        },

        /**
         * Builds combined Presentation + Infographic mini preview.
         */
        _buildPreviewContent: function(t) {
            var cc = t.chartColors || DEFAULT_THEME.chartColors;
            var isDark = _isDark(t.bg);

            // ── Presentation preview ──
            var pres = '<div class="jc-cs-pres-preview" style="background:' + _esc(t.bg) + '">'
                + '<div class="pres-topbar" style="background:' + _esc(t.accentColor) + '"></div>'
                + '<div class="pres-title" style="color:' + _esc(t.textColor) + '">Presentation Title</div>'
                + '<div class="pres-bullets">';
            var bLabels = ['Key insight with context', 'Strategic data point', 'Call to action item'];
            for (var b = 0; b < bLabels.length; b++) {
                pres += '<div class="pres-bullet">'
                    + '<div class="pres-bullet-dot" style="background:' + _esc(t.accentColor) + '"></div>'
                    + '<span style="color:' + _esc(t.subtextColor) + '">' + bLabels[b] + '</span>'
                    + '</div>';
            }
            pres += '</div>';
            // Mini bar chart
            pres += '<div class="pres-chart-area">';
            var barH = [28, 38, 22, 44];
            for (var bI = 0; bI < barH.length; bI++) {
                pres += '<div class="pres-bar" style="height:' + barH[bI] + 'px;background:' + _esc(cc[bI % cc.length]) + '"></div>';
            }
            pres += '</div></div>';

            // ── Infographic preview ──
            var info = '<div class="jc-cs-info-preview">'
                + '<div class="jc-cs-info-header" style="background:linear-gradient(135deg,' + _esc(t.headerGradientFrom) + ',' + _esc(t.headerGradientTo) + ')">'
                +   '<div class="info-title">Infographic Title</div>'
                +   '<div class="info-subtitle" style="color:' + _esc(t.headerSubtitle) + '">Subtitle text</div>'
                + '</div>'
                + '<div class="jc-cs-info-sections">';
            var secNames = ['Key Finding', 'Analysis', 'Results'];
            for (var si = 0; si < secNames.length; si++) {
                var sc = cc[si % cc.length];
                var bgEven = (si % 2 === 0) ? '#fff' : '#f8f9fa';
                info += '<div class="jc-cs-info-section" style="background:' + bgEven + ';border-left-color:' + _esc(sc) + '">'
                    + '<span class="info-sec-heading">' + secNames[si] + '</span>'
                    + '<span class="info-sec-chip" style="background:' + _esc(sc) + '18;color:' + _esc(sc) + ';border:1px solid ' + _esc(sc) + '40">42%</span>'
                    + '</div>';
            }
            info += '</div>'
                + '<div class="jc-cs-info-footer" style="background:' + _esc(t.footerBg) + '">Call to Action</div>'
                + '</div>';

            return '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0">' + pres + info + '</div>';
        },

        _footerHtml: function() {
            return '<div class="jc-cs-footer">'
                + '<div class="jc-cs-footer-hint"><i class="fas fa-info-circle"></i> Applies to presentations &amp; infographics</div>'
                + '<div class="jc-cs-footer-actions">'
                +   '<button type="button" class="jc-cs-btn jc-cs-btn-secondary" id="jc-cs-reset"><i class="fas fa-undo"></i> Reset Default</button>'
                +   '<button type="button" class="jc-cs-btn jc-cs-btn-primary" id="jc-cs-apply"><i class="fas fa-check"></i> Apply</button>'
                + '</div></div>';
        },

        // -----------------------------------------------------------------
        // BIND EVENTS
        // -----------------------------------------------------------------
        _bindModal: function() {
            var self = this;
            var bd = this._backdrop;

            // Close
            bd.on('click', '#jc-cs-close', function() { self._close(); });
            bd.on('click', function(e) { if ($(e.target).hasClass('jc-cs-backdrop')) self._close(); });
            $(document).on('keydown.jccs', function(e) { if (e.key === 'Escape' && self._isOpen) self._close(); });

            // Preset selection
            bd.on('click', '.jc-cs-preset', function() {
                var id = $(this).data('preset-id');
                var preset = self._findPreset(id);
                if (!preset) return;
                self._editing = $.extend(true, {}, preset);
                // Update UI
                bd.find('.jc-cs-preset').removeClass('is-selected');
                $(this).addClass('is-selected');
                self._refreshCustomPanel();
                self._refreshPreview();
            });

            // Custom toggle
            bd.on('click', '#jc-cs-custom-toggle', function() {
                self._customExpanded = !self._customExpanded;
                $(this).toggleClass('is-expanded', self._customExpanded);
                bd.find('#jc-cs-custom-panel').toggleClass('is-visible', self._customExpanded);
            });

            // Color picker changes
            bd.on('input', '.jc-cs-field', function() {
                var key = $(this).data('key');
                var val = $(this).val();
                self._editing[key] = val;
                self._editing.id = 'custom';
                bd.find('.jc-cs-field-hex[data-key="' + key + '"]').val(val);
                self._markCustom();
                self._refreshPreview();
            });

            // Hex text input
            bd.on('change', '.jc-cs-field-hex', function() {
                var key = $(this).data('key');
                var val = _normalizeHex($(this).val());
                if (val) {
                    $(this).val(val);
                    self._editing[key] = val;
                    self._editing.id = 'custom';
                    bd.find('.jc-cs-field[data-key="' + key + '"]').val(val);
                    self._markCustom();
                    self._refreshPreview();
                }
            });

            // Chart color pickers
            bd.on('input', '.jc-cs-chart-input', function() {
                var idx = parseInt($(this).data('index'), 10);
                if (!self._editing.chartColors) self._editing.chartColors = DEFAULT_THEME.chartColors.slice();
                self._editing.chartColors[idx] = $(this).val();
                self._editing.id = 'custom';
                self._markCustom();
                self._refreshPreview();
            });

            // Reset
            bd.on('click', '#jc-cs-reset', function() {
                self._editing = $.extend(true, {}, DEFAULT_THEME);
                bd.find('.jc-cs-preset').removeClass('is-selected');
                bd.find('.jc-cs-preset[data-preset-id="midnight_blue"]').addClass('is-selected');
                self._refreshCustomPanel();
                self._refreshPreview();
            });

            // Apply
            bd.on('click', '#jc-cs-apply', function() {
                self._active = $.extend(true, {}, self._editing);
                self._persist();
                self._close();
                // Fire event so other modules can react
                $(document).trigger('jc:colorSchemeChanged', [self.getActiveTheme()]);
            });
        },

        _markCustom: function() {
            if (this._editing && this._editing.id !== 'custom') return;
            this._backdrop.find('.jc-cs-preset').removeClass('is-selected');
        },

        _refreshCustomPanel: function() {
            var panel = this._backdrop.find('#jc-cs-custom-panel');
            if (!panel.length) return;
            var e = this._editing || DEFAULT_THEME;
            // Update all color fields
            for (var i = 0; i < EDITABLE_FIELDS.length; i++) {
                var f = EDITABLE_FIELDS[i];
                var val = e[f.key] || '#000000';
                panel.find('.jc-cs-field[data-key="' + f.key + '"]').val(val);
                panel.find('.jc-cs-field-hex[data-key="' + f.key + '"]').val(val);
            }
            // Chart colors
            var cc = e.chartColors || DEFAULT_THEME.chartColors;
            panel.find('.jc-cs-chart-input').each(function() {
                var idx = parseInt($(this).data('index'), 10);
                if (cc[idx]) $(this).val(cc[idx]);
            });
        },

        _refreshPreview: function() {
            var container = this._backdrop.find('#jc-cs-preview');
            if (!container.length) return;
            container.html(this._buildPreviewContent(this._editing || DEFAULT_THEME));
        },

        _close: function() {
            var self = this;
            this._isOpen = false;
            if (this._backdrop) {
                this._backdrop.removeClass('is-open');
                setTimeout(function() {
                    if (self._backdrop) { self._backdrop.remove(); self._backdrop = null; }
                }, 300);
            }
            $(document).off('keydown.jccs');
        }
    };


    // =====================================================================
    // UTILITY HELPERS
    // =====================================================================

    function _esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    /** Normalize a user-typed hex value to #RRGGBB */
    function _normalizeHex(val) {
        if (!val) return null;
        val = val.trim().replace(/^#/, '');
        if (/^[0-9a-fA-F]{3}$/.test(val)) {
            val = val[0]+val[0]+val[1]+val[1]+val[2]+val[2];
        }
        if (/^[0-9a-fA-F]{6}$/.test(val)) {
            return '#' + val.toUpperCase();
        }
        return null;
    }

    /** Rough lightness check for choosing text contrast. */
    function _isDark(hex) {
        if (!hex) return true;
        hex = hex.replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        var r = parseInt(hex.substr(0,2),16), g = parseInt(hex.substr(2,2),16), b = parseInt(hex.substr(4,2),16);
        return (r*0.299 + g*0.587 + b*0.114) < 140;
    }

    /** Lighten or darken a hex color by amount (positive = lighten). */
    function _lightenDarken(hex, amt) {
        if (!hex) return hex;
        hex = hex.replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        var num = parseInt(hex, 16);
        var r = Math.min(255, Math.max(0, (num >> 16) + amt));
        var g = Math.min(255, Math.max(0, ((num >> 8) & 0x00FF) + amt));
        var b = Math.min(255, Math.max(0, (num & 0x0000FF) + amt));
        return '#' + (0x1000000 + (r<<16) + (g<<8) + b).toString(16).slice(1).toUpperCase();
    }


    // =====================================================================
    // BOOTSTRAP
    // =====================================================================

    // Initialize when DOM ready
    $(document).ready(function() {
        ColorSchemeSelector.init();

        // ── Global click handler for trigger buttons ──
        // Trigger buttons can be rendered anywhere via buildTriggerHtml()
        $(document).on('click', '.jc-cs-trigger-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            // Determine format hint from data attribute or nearest context
            var hint = $(this).data('format') || 'presentation';
            ColorSchemeSelector.open(hint);
        });
    });

    // Expose globally
    window.JCColorSchemeSelector = ColorSchemeSelector;

})(jQuery);
