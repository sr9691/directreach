/**
 * Content Renderer Module
 *
 * Format-specific preview rendering and download for all content types.
 * Each format gets:
 *   - renderPreview(content, container)  → visual HTML preview in the review panel
 *   - download(content, filename)        → native file download (pptx, docx, html, etc.)
 *
 * Supported formats:
 *   - presentation  → slide deck preview + .pptx download via PptxGenJS
 *   - article_long  → styled article preview + .html download (future: .docx)
 *   - blog_post     → styled blog preview + .html download
 *   - linkedin_post → card-style preview + .txt download (copy-friendly)
 *   - infographic   → section layout preview + .html download
 *
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0.0
 */
(function($) {
    'use strict';

    // =========================================================================
    // SLIDE THEME (default fallback — used when Color Scheme Selector absent)
    // =========================================================================
    var SLIDE_THEME_DEFAULT = {
        bg: '#0F2B46',           // dark navy
        titleBg: '#1A3A5C',
        accentColor: '#42A5F5',  // bright blue
        accentAlt: '#66BB6A',    // green
        textColor: '#FFFFFF',
        subtextColor: '#B0BEC5',
        bulletColor: '#42A5F5',
        chartColors: ['#42A5F5', '#66BB6A', '#EF5350', '#FF7043', '#AB47BC', '#FFA726', '#26C6DA', '#EC407A'],
        headerGradientFrom: '#1a237e',
        headerGradientTo: '#283593',
        headerSubtitle: '#90caf9',
        footerBg: '#263238',
        sectionColors: {
            'Title Slide':        { accent: '#42A5F5', icon: 'fas fa-play-circle' },
            'Problem Definition': { accent: '#EF5350', icon: 'fas fa-exclamation-triangle' },
            'Problem Amplification': { accent: '#EF5350', icon: 'fas fa-chart-line' },
            'Solution Overview':  { accent: '#66BB6A', icon: 'fas fa-lightbulb' },
            'Solution Details':   { accent: '#66BB6A', icon: 'fas fa-cogs' },
            'Benefits Summary':   { accent: '#42A5F5', icon: 'fas fa-trophy' },
            'Credibility':        { accent: '#AB47BC', icon: 'fas fa-award' },
            'Call to Action':     { accent: '#FF7043', icon: 'fas fa-bullhorn' }
        }
    };

    /**
     * Dynamic theme getter — returns the user-selected color scheme if the
     * Color Scheme Selector module is loaded, otherwise falls back to defaults.
     * Called as _T() throughout this module instead of the old SLIDE_THEME constant.
     */
    function _T() {
        if (window.JCColorSchemeSelector) {
            return window.JCColorSchemeSelector.getActiveTheme();
        }
        return SLIDE_THEME_DEFAULT;
    }

    /** Strip '#' from hex color for PptxGenJS (requires bare hex). */
    function _H(key) {
        var t = _T();
        var val = t[key] || SLIDE_THEME_DEFAULT[key] || '000000';
        return val.replace('#', '');
    }

    // =========================================================================
    // CONTENT RENDERER
    // =========================================================================
    var ContentRenderer = {

        /**
         * Render a format-specific preview into the given container element.
         * @param {string} content    - Raw content from AI (JSON string or HTML)
         * @param {string} format     - API format key: 'presentation', 'article_long', etc.
         * @param {HTMLElement} container - DOM element to render into
         */
        renderPreview: function(content, format, container) {
            if (!container || !content) return;

            switch (format) {
                case 'presentation':
                    this._renderPresentationPreview(content, container);
                    break;
                case 'article_long':
                case 'blog_post':
                    this._renderArticlePreview(content, format, container);
                    break;
                case 'linkedin_post':
                    this._renderLinkedInPreview(content, container);
                    break;
                case 'infographic':
                    this._renderInfographicPreview(content, container);
                    break;
                default:
                    // Fallback: render as raw HTML
                    container.innerHTML = '<div style="padding:20px;font-size:14px;line-height:1.7">' + content + '</div>';
            }
        },

        /**
         * Download content in the appropriate native format.
         * @param {string} content   - Raw content from AI
         * @param {string} format    - API format key
         * @param {string} filename  - Base filename (no extension)
         * @param {object} meta      - Optional metadata (problemTitle, solutionTitle, focus)
         */
        download: function(content, format, filename, meta) {
            switch (format) {
                case 'presentation':
                    this._downloadPptx(content, filename, meta);
                    break;
                case 'linkedin_post':
                    // Parse JSON and download as DOCX (fallback: .txt)
                    var liParsed = this._parseJson(content);
                    if (liParsed && liParsed.hook && this.isDocxReady()) {
                        this._downloadDocx(liParsed, 'linkedin_post', filename, meta);
                    } else if (liParsed && liParsed.hook) {
                        this._downloadText(this._jsonToText(liParsed, 'linkedin_post'), filename);
                    } else {
                        this._downloadText(content, filename);
                    }
                    break;
                case 'infographic':
                    // For now, download as HTML (future: PNG via Nano Banana)
                    this._downloadHtml(content, format, filename, meta);
                    break;
                default:
                    // Article / Blog: parse JSON and download as DOCX (fallback: HTML)
                    var artParsed = this._parseJson(content);
                    if (artParsed && artParsed.sections && this.isDocxReady()) {
                        this._downloadDocx(artParsed, format, filename, meta);
                    } else if (artParsed && artParsed.sections) {
                        var title = meta && meta.problemTitle ? meta.problemTitle : 'Content';
                        var htmlDoc = this._jsonToHtml(artParsed, format, title);
                        this._triggerDownload(htmlDoc, (filename || 'content') + '.html', 'text/html');
                    } else {
                        this._downloadHtml(content, format, filename, meta);
                    }
            }
        },

        /**
         * Check if PptxGenJS is loaded
         */
        isPptxReady: function() {
            return typeof PptxGenJS !== 'undefined';
        },

        /**
         * Check if docx library is loaded (window.docx from UMD build)
         */
        isDocxReady: function() {
            return typeof window.docx !== 'undefined' && typeof window.docx.Document !== 'undefined';
        },

        // =====================================================================
        // PRESENTATION PREVIEW
        // =====================================================================

        _parseSlides: function(content) {
            // Content can be JSON string or already-parsed array
            if (typeof content === 'string') {
                var trimmed = content.trim();
                
                // Try parsing as JSON directly
                try {
                    var parsed = JSON.parse(trimmed);
                    if (Array.isArray(parsed)) return parsed;
                    if (parsed.slides && Array.isArray(parsed.slides)) return parsed.slides;
                } catch(e) {}
                
                // Try extracting JSON from markdown code blocks
                var match = trimmed.match(/```(?:json)?\s*([\s\S]*?)```/);
                if (match) {
                    try {
                        var inner = JSON.parse(match[1].trim());
                        if (Array.isArray(inner)) return inner;
                        if (inner.slides) return inner.slides;
                    } catch(e2) {}
                }
                
                // Try extracting JSON array from mixed content (AI sometimes adds text before/after)
                var arrMatch = trimmed.match(/\[\s*\{[\s\S]*\}\s*\]/);
                if (arrMatch) {
                    try {
                        var arrParsed = JSON.parse(arrMatch[0]);
                        if (Array.isArray(arrParsed)) return arrParsed;
                    } catch(e3) {}
                }

                // Attempt to repair truncated JSON (token limit cut off mid-array).
                // Find the last complete object "}" and close the array.
                if (trimmed.charAt(0) === '[' || trimmed.indexOf('[{') !== -1) {
                    var repaired = this._repairTruncatedJson(trimmed);
                    if (repaired) return repaired;
                }
                
                // Fallback: try to parse HTML slides (legacy format)
                if (trimmed.indexOf('<h2') !== -1 || trimmed.indexOf('<H2') !== -1) {
                    return this._parseHtmlSlides(trimmed);
                }
                
                return null;
            }
            if (Array.isArray(content)) return content;
            if (content && content.slides) return content.slides;
            return null;
        },

        /**
         * Attempt to repair a JSON array that was truncated mid-stream.
         * Finds the last complete slide object and closes the array.
         * @param {string} str - Potentially truncated JSON string
         * @returns {Array|null}
         */
        _repairTruncatedJson: function(str) {
            // Find where the array starts
            var arrStart = str.indexOf('[');
            if (arrStart === -1) return null;
            var sub = str.substring(arrStart);

            // Walk backwards from the end to find the last complete "}" that closes a slide object
            var depth = 0;
            var lastClose = -1;
            for (var i = sub.length - 1; i >= 0; i--) {
                var ch = sub.charAt(i);
                if (ch === '}') {
                    if (depth === 0) lastClose = i;
                    depth++;
                } else if (ch === '{') {
                    depth--;
                }
                // When depth returns to 0, we found a matched top-level object close
                if (depth === 0 && lastClose !== -1) {
                    break;
                }
            }
            if (lastClose === -1) return null;

            // Slice up to and including the last complete object, close the array
            var repaired = sub.substring(0, lastClose + 1) + ']';
            try {
                var parsed = JSON.parse(repaired);
                if (Array.isArray(parsed) && parsed.length > 0) {
                    console.warn('_parseSlides: repaired truncated JSON (' + parsed.length + ' slides recovered)');
                    return parsed;
                }
            } catch(e) {}
            return null;
        },

        /**
         * Parse legacy HTML slide format into slide objects.
         * Expected format: <h2>Title</h2> <ul><li>Point</li></ul> [SPEAKER NOTES: text]
         */
        _parseHtmlSlides: function(html) {
            var slides = [];
            // Split by h2 tags
            var parts = html.split(/<h2[^>]*>/i);
            for (var i = 1; i < parts.length; i++) {
                var part = parts[i];
                var titleEnd = part.indexOf('</h2>');
                var title = titleEnd !== -1 ? part.substring(0, titleEnd).replace(/<[^>]+>/g, '').trim() : 'Slide ' + i;
                var body = titleEnd !== -1 ? part.substring(titleEnd + 5) : part;
                
                // Extract bullet points
                var points = [];
                var liMatches = body.match(/<li[^>]*>([\s\S]*?)<\/li>/gi);
                if (liMatches) {
                    liMatches.forEach(function(li) {
                        var text = li.replace(/<[^>]+>/g, '').trim();
                        if (text) points.push(text);
                    });
                }
                
                // Extract speaker notes
                var notes = '';
                var notesMatch = body.match(/\[SPEAKER NOTES?:\s*([\s\S]*?)(?:\]|$)/i);
                if (notesMatch) notes = notesMatch[1].trim();
                
                // Determine section
                var section = 'Solution Details';
                if (i === 1) section = 'Title Slide';
                else if (title.toLowerCase().indexOf('problem') !== -1 || title.toLowerCase().indexOf('challeng') !== -1) section = 'Problem Definition';
                else if (title.toLowerCase().indexOf('solution') !== -1 || title.toLowerCase().indexOf('approach') !== -1) section = 'Solution Overview';
                else if (title.toLowerCase().indexOf('benefit') !== -1) section = 'Benefits Summary';
                else if (title.toLowerCase().indexOf('next') !== -1 || title.toLowerCase().indexOf('action') !== -1 || title.toLowerCase().indexOf('contact') !== -1) section = 'Call to Action';
                
                slides.push({
                    slide_number: i,
                    slide_title: title,
                    section: section,
                    key_points: points,
                    speaker_notes: notes,
                    data_points: []
                });
            }
            return slides.length > 0 ? slides : null;
        },

        _getSectionTheme: function(section) {
            if (!section) return { accent: _T().accentColor, icon: 'fas fa-file' };
            var keys = Object.keys(_T().sectionColors);
            for (var i = 0; i < keys.length; i++) {
                if (section.indexOf(keys[i]) !== -1 || keys[i].indexOf(section) !== -1) {
                    return _T().sectionColors[keys[i]];
                }
            }
            // Check partial match
            var sectionLower = section.toLowerCase();
            if (sectionLower.indexOf('problem') !== -1) return _T().sectionColors['Problem Definition'];
            if (sectionLower.indexOf('solution') !== -1) return _T().sectionColors['Solution Overview'];
            if (sectionLower.indexOf('benefit') !== -1) return _T().sectionColors['Benefits Summary'];
            if (sectionLower.indexOf('action') !== -1 || sectionLower.indexOf('next') !== -1) return _T().sectionColors['Call to Action'];
            if (sectionLower.indexOf('credib') !== -1 || sectionLower.indexOf('expert') !== -1) return _T().sectionColors['Credibility'];
            return { accent: _T().accentColor, icon: 'fas fa-file-powerpoint' };
        },

        _renderPresentationPreview: function(content, container) {
            var slides = this._parseSlides(content);

            if (!slides) {
                container.innerHTML = '<div style="padding:12px;margin-bottom:12px;background:#fff3e0;border:1px solid #ffe0b2;border-radius:6px;font-size:12px;color:#e65100"><i class="fas fa-info-circle"></i> Content returned as HTML instead of slide JSON. Showing raw output.</div>'
                    + '<div style="padding:20px;font-size:14px;line-height:1.7">' + content + '</div>';
                return;
            }

            var html = '<div class="jc-slide-deck" style="display:flex;flex-direction:column;gap:16px">';

            // Slide navigator
            html += '<div style="display:flex;align-items:center;gap:8px;padding:8px 0;overflow-x:auto">';
            for (var n = 0; n < slides.length; n++) {
                html += '<button type="button" class="jc-slide-nav" data-slide="' + n + '" style="min-width:36px;height:28px;border-radius:4px;border:1.5px solid ' + (n === 0 ? _T().accentColor : '#ddd') + ';background:' + (n === 0 ? _T().accentColor : '#fff') + ';color:' + (n === 0 ? '#fff' : '#666') + ';font-size:11px;font-weight:600;cursor:pointer;transition:all .15s">' + (n + 1) + '</button>';
            }
            html += '</div>';

            // Slides
            for (var s = 0; s < slides.length; s++) {
                var slide = slides[s];
                var theme = this._getSectionTheme(slide.section || '');
                var isTitle = (s === 0 || (slide.section && slide.section.toLowerCase().indexOf('title') !== -1));
                var hasVisual = slide.visual_element && slide.visual_element.type && slide.visual_element.data;

                html += '<div class="jc-slide-panel" data-slide="' + s + '" style="display:' + (s === 0 ? 'block' : 'none') + '">';
                html += '<div class="jc-slide-card" style="position:relative;background:' + _T().bg + ';border-radius:8px;padding:0;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.3);aspect-ratio:16/9;display:flex;flex-direction:column">';
                html += '<div style="height:4px;background:linear-gradient(90deg,' + theme.accent + ',' + theme.accent + '88)"></div>';
                html += '<div style="position:absolute;top:12px;right:14px;font-size:10px;font-weight:600;color:' + _T().subtextColor + ';opacity:.6">' + (slide.slide_number || (s + 1)) + ' / ' + slides.length + '</div>';

                if (hasVisual && !isTitle) {
                    // TWO-COLUMN layout: left text + right visual
                    html += '<div style="flex:1;display:flex;padding:28px 40px;gap:24px;min-height:0">';
                    // Left: text
                    html += '<div style="flex:1;display:flex;flex-direction:column;min-width:0">';
                    if (slide.section) {
                        html += '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:' + theme.accent + ';margin-bottom:10px"><i class="' + theme.icon + '" style="margin-right:5px"></i>' + this._esc(slide.section) + '</div>';
                    }
                    html += '<div style="font-size:17px;font-weight:700;color:#fff;line-height:1.3;margin-bottom:14px">' + this._esc(slide.slide_title || 'Untitled Slide') + '</div>';
                    if (slide.key_points && slide.key_points.length > 0) {
                        html += '<div style="display:flex;flex-direction:column;gap:6px">';
                        for (var k = 0; k < slide.key_points.length; k++) {
                            html += '<div style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:' + _T().subtextColor + ';line-height:1.5"><span style="color:' + theme.accent + ';font-size:6px;margin-top:5px;flex-shrink:0"><i class="fas fa-circle"></i></span><span>' + this._esc(slide.key_points[k]) + '</span></div>';
                        }
                        html += '</div>';
                    }
                    html += '</div>';
                    // Right: visual
                    html += '<div style="flex:1;display:flex;align-items:center;justify-content:center;min-width:0">';
                    html += this._renderVisualPreviewHtml(slide.visual_element, theme.accent);
                    html += '</div>';
                    html += '</div>';
                } else {
                    // SINGLE COLUMN layout (title slides or no visual)
                    html += '<div style="flex:1;display:flex;flex-direction:column;justify-content:' + (isTitle ? 'center' : 'flex-start') + ';padding:' + (isTitle ? '40px 48px' : '28px 40px') + '">';
                    if (slide.section && !isTitle) {
                        html += '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:' + theme.accent + ';margin-bottom:12px"><i class="' + theme.icon + '" style="margin-right:5px"></i>' + this._esc(slide.section) + '</div>';
                    }
                    html += '<div style="font-size:' + (isTitle ? '22px' : '18px') + ';font-weight:700;color:#fff;line-height:1.3;margin-bottom:16px">' + this._esc(slide.slide_title || 'Untitled Slide') + '</div>';
                    if (slide.key_points && slide.key_points.length > 0 && !isTitle) {
                        html += '<div style="flex:1;display:flex;flex-direction:column;gap:8px">';
                        for (var k2 = 0; k2 < slide.key_points.length; k2++) {
                            html += '<div style="display:flex;align-items:flex-start;gap:10px;font-size:13px;color:' + _T().subtextColor + ';line-height:1.5"><span style="color:' + theme.accent + ';font-size:7px;margin-top:6px;flex-shrink:0"><i class="fas fa-circle"></i></span><span>' + this._esc(slide.key_points[k2]) + '</span></div>';
                        }
                        html += '</div>';
                    } else if (isTitle && slide.key_points && slide.key_points.length > 0) {
                        html += '<div style="font-size:14px;color:' + _T().subtextColor + ';line-height:1.6;max-width:80%">' + this._esc(slide.key_points.join(' ')) + '</div>';
                    }
                    if (slide.data_points && slide.data_points.length > 0) {
                        html += '<div style="margin-top:auto;padding-top:12px;border-top:1px solid rgba(255,255,255,.1);display:flex;flex-wrap:wrap;gap:6px">';
                        for (var d = 0; d < slide.data_points.length; d++) {
                            html += '<span style="font-size:10px;padding:3px 8px;border-radius:100px;background:rgba(255,255,255,.08);color:' + _T().subtextColor + ';border:1px solid rgba(255,255,255,.1)"><i class="fas fa-chart-bar" style="margin-right:3px;color:' + theme.accent + '"></i>' + this._esc(slide.data_points[d]) + '</span>';
                        }
                        html += '</div>';
                    }
                    html += '</div>';
                }

                html += '</div>'; // slide card

                // Speaker notes
                if (slide.speaker_notes) {
                    html += '<div class="jc-speaker-notes" style="margin-top:8px;padding:10px 14px;background:#f8f9fa;border:1px solid #eee;border-radius:6px">'
                        + '<div class="jc-notes-toggle" style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:11px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px"><i class="fas fa-sticky-note"></i> Speaker Notes <i class="fas fa-chevron-down jc-notes-chevron" style="margin-left:auto;font-size:9px;transition:transform .2s"></i></div>'
                        + '<div class="jc-notes-body" style="display:none;margin-top:8px;font-size:12px;color:#555;line-height:1.6">' + this._esc(slide.speaker_notes) + '</div>'
                        + '</div>';
                }

                html += '</div>'; // slide panel
            }

            html += '</div>';
            container.innerHTML = html;

            // Render canvas charts after DOM insertion
            var self = this;
            setTimeout(function() { self._renderCanvasCharts(container); }, 50);

            // Bind slide navigation
            container.querySelectorAll('.jc-slide-nav').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var idx = parseInt(btn.dataset.slide);
                    container.querySelectorAll('.jc-slide-nav').forEach(function(b) {
                        var active = parseInt(b.dataset.slide) === idx;
                        b.style.background = active ? _T().accentColor : '#fff';
                        b.style.color = active ? '#fff' : '#666';
                        b.style.borderColor = active ? _T().accentColor : '#ddd';
                    });
                    container.querySelectorAll('.jc-slide-panel').forEach(function(p) {
                        p.style.display = parseInt(p.dataset.slide) === idx ? 'block' : 'none';
                    });
                });
            });

            // Bind speaker notes toggle
            container.querySelectorAll('.jc-notes-toggle').forEach(function(toggle) {
                toggle.addEventListener('click', function() {
                    var body = toggle.nextElementSibling;
                    var chev = toggle.querySelector('.jc-notes-chevron');
                    if (body) {
                        var showing = body.style.display !== 'none';
                        body.style.display = showing ? 'none' : 'block';
                        if (chev) chev.style.transform = showing ? '' : 'rotate(180deg)';
                    }
                });
            });
        },

        // =====================================================================
        // VISUAL ELEMENT — HTML PREVIEW HELPERS
        // =====================================================================

        _renderVisualPreviewHtml: function(ve, accentColor) {
            if (!ve || !ve.type || !ve.data) return '';
            var d = ve.data;
            var html = '';

            switch (ve.type) {
                case 'bar_chart':
                    html += '<canvas class="jc-ve-canvas" data-ve-type="bar_chart" data-ve=\'' + this._safeJsonAttr(ve.data) + '\' data-accent="' + (accentColor || _T().accentColor) + '" width="320" height="200" style="width:100%;max-width:320px;height:auto"></canvas>';
                    if (d.title) html += '<div style="text-align:center;font-size:10px;color:' + _T().subtextColor + ';margin-top:6px;opacity:.7">' + this._esc(d.title) + '</div>';
                    break;

                case 'donut_chart':
                    html += '<canvas class="jc-ve-canvas" data-ve-type="donut_chart" data-ve=\'' + this._safeJsonAttr(ve.data) + '\' data-accent="' + (accentColor || _T().accentColor) + '" width="220" height="220" style="width:100%;max-width:220px;height:auto"></canvas>';
                    break;

                case 'stat_cards':
                    var stats = d.stats || [];
                    html += '<div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center">';
                    for (var i = 0; i < stats.length; i++) {
                        var c = _T().chartColors[i % _T().chartColors.length];
                        html += '<div style="text-align:center;padding:10px 14px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);min-width:70px">'
                            + '<div style="font-size:20px;font-weight:800;color:' + c + ';line-height:1.2">' + this._esc(stats[i].value) + '</div>'
                            + '<div style="font-size:9px;color:' + _T().subtextColor + ';margin-top:3px;text-transform:uppercase;letter-spacing:.5px">' + this._esc(stats[i].label) + '</div></div>';
                    }
                    html += '</div>';
                    break;

                case 'comparison':
                    var bef = d.before || { title: 'Before', points: [] };
                    var aft = d.after || { title: 'After', points: [] };
                    html += '<div style="display:flex;gap:10px;width:100%">';
                    html += '<div style="flex:1;padding:10px;border-radius:8px;background:rgba(239,83,80,.1);border:1px solid rgba(239,83,80,.25)">';
                    html += '<div style="font-size:10px;font-weight:700;color:#EF5350;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">' + this._esc(bef.title) + '</div>';
                    for (var b = 0; b < (bef.points || []).length; b++) {
                        html += '<div style="font-size:10px;color:' + _T().subtextColor + ';margin-bottom:4px;display:flex;gap:5px"><span style="color:#EF5350">&#10007;</span> ' + this._esc(bef.points[b]) + '</div>';
                    }
                    html += '</div>';
                    html += '<div style="flex:1;padding:10px;border-radius:8px;background:rgba(102,187,106,.1);border:1px solid rgba(102,187,106,.25)">';
                    html += '<div style="font-size:10px;font-weight:700;color:#66BB6A;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">' + this._esc(aft.title) + '</div>';
                    for (var a = 0; a < (aft.points || []).length; a++) {
                        html += '<div style="font-size:10px;color:' + _T().subtextColor + ';margin-bottom:4px;display:flex;gap:5px"><span style="color:#66BB6A">&#10003;</span> ' + this._esc(aft.points[a]) + '</div>';
                    }
                    html += '</div></div>';
                    break;

                case 'timeline':
                    var steps = d.steps || [];
                    html += '<div style="display:flex;flex-direction:column;gap:0;width:100%">';
                    for (var t = 0; t < steps.length; t++) {
                        var tc = _T().chartColors[t % _T().chartColors.length];
                        var isLast = (t === steps.length - 1);
                        html += '<div style="display:flex;gap:10px;align-items:flex-start">';
                        html += '<div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:16px">'
                            + '<div style="width:12px;height:12px;border-radius:50%;background:' + tc + '"></div>'
                            + (isLast ? '' : '<div style="width:2px;flex:1;min-height:24px;background:rgba(255,255,255,.15)"></div>') + '</div>';
                        html += '<div style="padding-bottom:' + (isLast ? '0' : '12px') + '">'
                            + '<div style="font-size:8px;font-weight:700;color:' + tc + ';text-transform:uppercase;letter-spacing:1px">' + this._esc(steps[t].phase) + '</div>'
                            + '<div style="font-size:11px;font-weight:600;color:#fff;margin-top:2px">' + this._esc(steps[t].title) + '</div>'
                            + (steps[t].description ? '<div style="font-size:9px;color:' + _T().subtextColor + ';margin-top:2px">' + this._esc(steps[t].description) + '</div>' : '')
                            + '</div></div>';
                    }
                    html += '</div>';
                    break;

                case 'progress_bars':
                    var bars = d.bars || [];
                    var pSuffix = d.value_suffix || '%';
                    html += '<div style="display:flex;flex-direction:column;gap:10px;width:100%">';
                    for (var p = 0; p < bars.length; p++) {
                        var pc = _T().chartColors[p % _T().chartColors.length];
                        var pct = Math.min(100, Math.max(0, bars[p].value || 0));
                        html += '<div><div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:10px;color:' + _T().subtextColor + '">' + this._esc(bars[p].label) + '</span><span style="font-size:10px;font-weight:700;color:' + pc + '">' + pct + pSuffix + '</span></div>'
                            + '<div style="height:8px;border-radius:4px;background:rgba(255,255,255,.1);overflow:hidden"><div style="height:100%;width:' + pct + '%;border-radius:4px;background:linear-gradient(90deg,' + pc + ',' + pc + 'cc)"></div></div></div>';
                    }
                    html += '</div>';
                    break;

                default:
                    html += '<div style="font-size:10px;color:' + _T().subtextColor + ';text-align:center;opacity:.5">Unknown visual: ' + this._esc(ve.type) + '</div>';
            }
            return html;
        },

        _renderCanvasCharts: function(container) {
            var self = this;
            container.querySelectorAll('canvas.jc-ve-canvas').forEach(function(cvs) {
                var type = cvs.getAttribute('data-ve-type');
                var dataStr = cvs.getAttribute('data-ve');
                var accent = cvs.getAttribute('data-accent') || _T().accentColor;
                var data;
                try { data = JSON.parse(dataStr); } catch(e) { return; }

                var dpr = window.devicePixelRatio || 1;
                var rect = cvs.getBoundingClientRect();
                var w = rect.width || parseInt(cvs.getAttribute('width'));
                var h = rect.height || parseInt(cvs.getAttribute('height'));
                cvs.width = w * dpr;
                cvs.height = h * dpr;
                var ctx = cvs.getContext('2d');
                ctx.scale(dpr, dpr);

                if (type === 'bar_chart') self._drawBarChart(ctx, w, h, data, accent);
                else if (type === 'donut_chart') self._drawDonutChart(ctx, w, h, data, accent);
            });
        },

        _drawBarChart: function(ctx, w, h, data) {
            var labels = data.labels || [];
            var values = data.values || [];
            var suffix = data.value_suffix || '';
            var n = Math.min(labels.length, values.length);
            if (n === 0) return;

            var maxVal = Math.max.apply(null, values) || 1;
            var labelW = 80;
            var barAreaW = w - labelW - 50;
            var barH = Math.min(22, (h - 20) / n - 6);
            var gapY = barH + 6;
            var startY = (h - gapY * n) / 2;

            for (var i = 0; i < n; i++) {
                var y = startY + i * gapY;
                var barW = (values[i] / maxVal) * barAreaW;
                var color = _T().chartColors[i % _T().chartColors.length];

                ctx.fillStyle = '#B0BEC5';
                ctx.font = '10px -apple-system, BlinkMacSystemFont, sans-serif';
                ctx.textAlign = 'right';
                ctx.textBaseline = 'middle';
                ctx.fillText(labels[i], labelW - 8, y + barH / 2);

                // Bar with rounded ends (fallback to rect if roundRect unavailable)
                ctx.fillStyle = color;
                ctx.beginPath();
                if (ctx.roundRect) {
                    ctx.roundRect(labelW, y, Math.max(barW, 1), barH, 3);
                } else {
                    ctx.rect(labelW, y, Math.max(barW, 1), barH);
                }
                ctx.fill();

                ctx.fillStyle = '#fff';
                ctx.font = 'bold 10px -apple-system, BlinkMacSystemFont, sans-serif';
                ctx.textAlign = 'left';
                ctx.fillText(values[i] + suffix, labelW + barW + 6, y + barH / 2);
            }
        },

        _drawDonutChart: function(ctx, w, h, data) {
            var segments = data.segments || [];
            if (segments.length === 0) return;

            var cx = w / 2;
            var cy = h / 2;
            var outerR = Math.min(cx, cy) - 8;
            var innerR = outerR * 0.55;
            var total = 0;
            for (var i = 0; i < segments.length; i++) total += (segments[i].value || 0);
            if (total === 0) return;

            var startAngle = -Math.PI / 2;
            for (var j = 0; j < segments.length; j++) {
                var seg = segments[j];
                var sweep = (seg.value / total) * Math.PI * 2;
                var color = _T().chartColors[j % _T().chartColors.length];

                ctx.beginPath();
                ctx.arc(cx, cy, outerR, startAngle, startAngle + sweep);
                ctx.arc(cx, cy, innerR, startAngle + sweep, startAngle, true);
                ctx.closePath();
                ctx.fillStyle = color;
                ctx.fill();

                if (sweep > 0.3) {
                    var midAngle = startAngle + sweep / 2;
                    var labelR = (outerR + innerR) / 2;
                    ctx.fillStyle = '#fff';
                    ctx.font = 'bold 9px -apple-system, BlinkMacSystemFont, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(seg.label, cx + Math.cos(midAngle) * labelR, cy + Math.sin(midAngle) * labelR);
                }
                startAngle += sweep;
            }

            if (data.center_value) {
                ctx.fillStyle = '#fff';
                ctx.font = 'bold 18px -apple-system, BlinkMacSystemFont, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(data.center_value, cx, cy - (data.center_label ? 6 : 0));
            }
            if (data.center_label) {
                ctx.fillStyle = '#78909C';
                ctx.font = '9px -apple-system, BlinkMacSystemFont, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(data.center_label, cx, cy + 12);
            }
        },

        // =====================================================================
        // PRESENTATION DOWNLOAD (PptxGenJS) — with visual element support
        // =====================================================================

        _downloadPptx: function(content, filename, meta) {
            if (!this.isPptxReady()) {
                alert('PowerPoint library is still loading. Please try again in a moment.');
                return;
            }

            var slides = this._parseSlides(content);
            if (!slides) {
                this._downloadHtml(content, 'presentation', filename, meta);
                return;
            }

            var pptx = new PptxGenJS();
            pptx.author = 'DirectReach Campaign Builder';
            pptx.subject = meta && meta.problemTitle ? meta.problemTitle : 'Journey Circle Presentation';
            pptx.title = slides[0] ? (slides[0].slide_title || 'Presentation') : 'Presentation';

            pptx.defineSlideMaster({
                title: 'JC_MASTER',
                background: { color: _T().bg.replace('#', '') },
                objects: [
                    { rect: { x: 0, y: 0, w: '100%', h: 0.04, fill: { color: _T().accentColor.replace('#', '') } } }
                ]
            });

            for (var s = 0; s < slides.length; s++) {
                var sd = slides[s];
                var theme = this._getSectionTheme(sd.section || '');
                var isTitle = (s === 0 || (sd.section && sd.section.toLowerCase().indexOf('title') !== -1));
                var hasVisual = sd.visual_element && sd.visual_element.type && sd.visual_element.data;
                var slide = pptx.addSlide({ masterName: 'JC_MASTER' });

                slide.addText((sd.slide_number || (s + 1)) + ' / ' + slides.length, {
                    x: 8.5, y: 0.15, w: 1.3, h: 0.3,
                    fontSize: 8, color: _H('subtextColor'), align: 'right'
                });

                if (isTitle) {
                    slide.addText(sd.slide_title || 'Untitled', {
                        x: 0.8, y: 1.5, w: 8.4, h: 1.5,
                        fontSize: 28, bold: true, color: _H('textColor'), align: 'left', valign: 'middle'
                    });
                    if (sd.key_points && sd.key_points.length > 0) {
                        slide.addText(sd.key_points.join('\n'), {
                            x: 0.8, y: 3.0, w: 7.0, h: 1.5,
                            fontSize: 14, color: _H('subtextColor'), lineSpacing: 22
                        });
                    }
                } else if (hasVisual) {
                    // SPLIT LAYOUT: left text + right visual
                    var textW = 4.8, vizX = 5.3, vizW = 4.5;

                    if (sd.section) {
                        slide.addText(sd.section.toUpperCase(), {
                            x: 0.8, y: 0.4, w: textW, h: 0.3,
                            fontSize: 9, bold: true, color: theme.accent.replace('#', ''), letterSpacing: 2
                        });
                    }
                    slide.addText(sd.slide_title || '', {
                        x: 0.8, y: 0.8, w: textW, h: 0.7,
                        fontSize: 20, bold: true, color: _H('textColor')
                    });
                    if (sd.key_points && sd.key_points.length > 0) {
                        var visBullets = sd.key_points.map(function(kp) {
                            return { text: kp, options: { fontSize: 12, color: _H('subtextColor'), bullet: { type: 'bullet', color: theme.accent.replace('#', '') }, lineSpacing: 18, paraSpaceAfter: 4 } };
                        });
                        slide.addText(visBullets, { x: 0.8, y: 1.6, w: textW, h: 3.0, valign: 'top' });
                    }
                    this._addPptxVisual(pptx, slide, sd.visual_element, vizX, 0.8, vizW, 4.0, theme);
                    if (sd.data_points && sd.data_points.length > 0) {
                        slide.addText(sd.data_points.map(function(dp) { return '• ' + dp; }).join('   '), {
                            x: 0.8, y: 4.8, w: 8.4, h: 0.4, fontSize: 9, color: _H('subtextColor'), italic: true
                        });
                    }
                } else {
                    // STANDARD LAYOUT
                    if (sd.section) {
                        slide.addText(sd.section.toUpperCase(), {
                            x: 0.8, y: 0.4, w: 5, h: 0.3,
                            fontSize: 9, bold: true, color: theme.accent.replace('#', ''), letterSpacing: 2
                        });
                    }
                    slide.addText(sd.slide_title || '', {
                        x: 0.8, y: 0.8, w: 8.4, h: 0.8,
                        fontSize: 22, bold: true, color: _H('textColor')
                    });
                    if (sd.key_points && sd.key_points.length > 0) {
                        var stdBullets = sd.key_points.map(function(kp) {
                            return { text: kp, options: { fontSize: 13, color: _H('subtextColor'), bullet: { type: 'bullet', color: theme.accent.replace('#', '') }, lineSpacing: 20, paraSpaceAfter: 6 } };
                        });
                        slide.addText(stdBullets, { x: 0.8, y: 1.8, w: 8.4, h: 2.8, valign: 'top' });
                    }
                    if (sd.data_points && sd.data_points.length > 0) {
                        slide.addText(sd.data_points.map(function(dp) { return '• ' + dp; }).join('   '), {
                            x: 0.8, y: 4.8, w: 8.4, h: 0.4, fontSize: 9, color: _H('subtextColor'), italic: true
                        });
                    }
                }

                if (sd.speaker_notes) slide.addNotes(sd.speaker_notes);
            }

            pptx.writeFile({ fileName: (filename || 'presentation') + '.pptx' });
        },

        // =====================================================================
        // PPTX VISUAL ELEMENT RENDERING
        // =====================================================================

        _addPptxVisual: function(pptx, slide, ve, x, y, w, h, theme) {
            if (!ve || !ve.type || !ve.data) return;
            var d = ve.data;
            var colors = _T().chartColors;

            switch (ve.type) {
                case 'bar_chart':
                    try {
                        var chartData = [{ name: d.title || 'Value', labels: d.labels || [], values: d.values || [] }];
                        var barColors = (d.labels || []).map(function(_, i) { return colors[i % colors.length].replace('#', ''); });
                        slide.addChart(pptx.charts.BAR, chartData, {
                            x: x, y: y + 0.4, w: w, h: h - 0.6, barDir: 'bar', barGrouping: 'clustered',
                            showValue: true, valueFontSize: 9, valueFontColor: _H('textColor'),
                            catAxisLabelColor: _H('subtextColor'), catAxisLabelFontSize: 9, valAxisHidden: true,
                            catGridLine: { style: 'none' }, valGridLine: { color: _H('titleBg'), style: 'dash', size: 1 },
                            plotArea: { fill: { color: _H('bg') } }, chartColors: barColors
                        });
                        if (d.title) slide.addText(d.title, { x: x, y: y, w: w, h: 0.35, fontSize: 10, bold: true, color: _H('subtextColor'), align: 'center' });
                    } catch(e) { this._addPptxVisualFallback(slide, d, x, y, w, h); }
                    break;

                case 'donut_chart':
                    try {
                        var segs = d.segments || [];
                        var pieData = [{ name: 'Data', labels: segs.map(function(s) { return s.label; }), values: segs.map(function(s) { return s.value; }) }];
                        var pieColors = segs.map(function(_, i) { return colors[i % colors.length].replace('#', ''); });
                        slide.addChart(pptx.charts.DOUGHNUT, pieData, {
                            x: x + 0.3, y: y + 0.4, w: w - 0.6, h: h - 0.8, holeSize: 55,
                            showLegend: true, legendPos: 'b', legendFontSize: 8, legendColor: _H('subtextColor'),
                            showTitle: false, chartColors: pieColors
                        });
                        if (d.center_value) {
                            slide.addText(d.center_value + (d.center_label ? '\n' + d.center_label : ''), {
                                x: x + 0.3, y: y + 0.4, w: w - 0.6, h: h - 1.2,
                                fontSize: 14, bold: true, color: _H('textColor'), align: 'center', valign: 'middle'
                            });
                        }
                    } catch(e) { this._addPptxVisualFallback(slide, d, x, y, w, h); }
                    break;

                case 'stat_cards':
                    var stats = d.stats || [];
                    var cardW = (w - 0.2 * (stats.length - 1)) / stats.length;
                    var cardH = 1.4;
                    var cardY = y + (h - cardH) / 2;
                    for (var i = 0; i < stats.length; i++) {
                        var cx = x + i * (cardW + 0.2);
                        var sc = colors[i % colors.length].replace('#', '');
                        slide.addShape(pptx.shapes.ROUNDED_RECTANGLE, { x: cx, y: cardY, w: cardW, h: cardH, fill: { color: _H('titleBg') }, line: { color: _H('titleBg'), width: 1 }, rectRadius: 0.1 });
                        slide.addText(stats[i].value || '', { x: cx, y: cardY + 0.15, w: cardW, h: 0.7, fontSize: 22, bold: true, color: sc, align: 'center', valign: 'middle' });
                        slide.addText((stats[i].label || '').toUpperCase(), { x: cx, y: cardY + 0.85, w: cardW, h: 0.4, fontSize: 8, color: _H('subtextColor'), align: 'center', valign: 'top', letterSpacing: 1 });
                    }
                    break;

                case 'comparison':
                    var bef = d.before || { title: 'Before', points: [] };
                    var aft = d.after || { title: 'After', points: [] };
                    var colW = (w - 0.3) / 2;
                    var colH = h - 0.4;
                    // Before
                    slide.addShape(pptx.shapes.ROUNDED_RECTANGLE, { x: x, y: y + 0.2, w: colW, h: colH, fill: { color: _H('titleBg') }, line: { color: 'EF5350', width: 1 }, rectRadius: 0.08 });
                    slide.addText(bef.title.toUpperCase(), { x: x + 0.15, y: y + 0.3, w: colW - 0.3, h: 0.3, fontSize: 9, bold: true, color: 'EF5350', letterSpacing: 2 });
                    if ((bef.points || []).length > 0) {
                        var bBullets = bef.points.map(function(p) { return { text: p, options: { fontSize: 10, color: _H('subtextColor'), bullet: { code: '2717', color: 'EF5350' }, lineSpacing: 16, paraSpaceAfter: 3 } }; });
                        slide.addText(bBullets, { x: x + 0.15, y: y + 0.7, w: colW - 0.3, h: colH - 0.7, valign: 'top' });
                    }
                    // After
                    var afterX = x + colW + 0.3;
                    slide.addShape(pptx.shapes.ROUNDED_RECTANGLE, { x: afterX, y: y + 0.2, w: colW, h: colH, fill: { color: _H('titleBg') }, line: { color: '66BB6A', width: 1 }, rectRadius: 0.08 });
                    slide.addText(aft.title.toUpperCase(), { x: afterX + 0.15, y: y + 0.3, w: colW - 0.3, h: 0.3, fontSize: 9, bold: true, color: '66BB6A', letterSpacing: 2 });
                    if ((aft.points || []).length > 0) {
                        var aBullets = aft.points.map(function(p) { return { text: p, options: { fontSize: 10, color: _H('subtextColor'), bullet: { code: '2713', color: '66BB6A' }, lineSpacing: 16, paraSpaceAfter: 3 } }; });
                        slide.addText(aBullets, { x: afterX + 0.15, y: y + 0.7, w: colW - 0.3, h: colH - 0.7, valign: 'top' });
                    }
                    break;

                case 'timeline':
                    var steps = d.steps || [];
                    var stepH = Math.min(0.9, (h - 0.4) / steps.length);
                    var lineX = x + 0.3;
                    for (var t = 0; t < steps.length; t++) {
                        var sy = y + 0.3 + t * stepH;
                        var sc2 = colors[t % colors.length].replace('#', '');
                        if (t < steps.length - 1) {
                            slide.addShape(pptx.shapes.LINE, { x: lineX, y: sy + 0.24, w: 0, h: stepH - 0.24, line: { color: _H('titleBg'), width: 2 } });
                        }
                        slide.addShape(pptx.shapes.OVAL, { x: lineX - 0.12, y: sy, w: 0.24, h: 0.24, fill: { color: sc2 } });
                        slide.addText(steps[t].phase || '', { x: lineX + 0.35, y: sy - 0.08, w: w - 0.8, h: 0.2, fontSize: 7, bold: true, color: sc2, letterSpacing: 1 });
                        slide.addText(steps[t].title || '', { x: lineX + 0.35, y: sy + 0.12, w: w - 0.8, h: 0.22, fontSize: 11, bold: true, color: _H('textColor') });
                        if (steps[t].description) {
                            slide.addText(steps[t].description, { x: lineX + 0.35, y: sy + 0.34, w: w - 0.8, h: 0.2, fontSize: 8, color: _H('subtextColor') });
                        }
                    }
                    break;

                case 'progress_bars':
                    var bars = d.bars || [];
                    var pSfx = d.value_suffix || '%';
                    var bH = 0.25;
                    var bGap = Math.min(0.7, (h - 0.4) / bars.length);
                    var bStartY = y + 0.3;
                    var bW = w - 0.4;
                    for (var p = 0; p < bars.length; p++) {
                        var by = bStartY + p * bGap;
                        var pct = Math.min(100, Math.max(0, bars[p].value || 0));
                        var bc = colors[p % colors.length].replace('#', '');
                        slide.addText(bars[p].label || '', { x: x + 0.1, y: by, w: bW * 0.7, h: 0.22, fontSize: 9, color: _H('subtextColor'), align: 'left' });
                        slide.addText(pct + pSfx, { x: x + 0.1 + bW * 0.7, y: by, w: bW * 0.3, h: 0.22, fontSize: 10, bold: true, color: bc, align: 'right' });
                        slide.addShape(pptx.shapes.ROUNDED_RECTANGLE, { x: x + 0.1, y: by + 0.24, w: bW, h: bH, fill: { color: _H('titleBg') }, rectRadius: 0.04 });
                        if (pct > 0) {
                            slide.addShape(pptx.shapes.ROUNDED_RECTANGLE, { x: x + 0.1, y: by + 0.24, w: bW * (pct / 100), h: bH, fill: { color: bc }, rectRadius: 0.04 });
                        }
                    }
                    break;

                default:
                    slide.addText('Visual: ' + ve.type, { x: x, y: y, w: w, h: h, fontSize: 12, color: _H('subtextColor'), align: 'center', valign: 'middle' });
            }
        },

        _addPptxVisualFallback: function(slide, data, x, y, w, h) {
            var text = '';
            if (data.labels && data.values) {
                for (var i = 0; i < data.labels.length; i++) text += data.labels[i] + ': ' + (data.values[i] || 0) + (data.value_suffix || '') + '\n';
            } else if (data.segments) {
                for (var j = 0; j < data.segments.length; j++) text += data.segments[j].label + ': ' + data.segments[j].value + '\n';
            }
            if (text) slide.addText(text.trim(), { x: x, y: y, w: w, h: h, fontSize: 11, color: _H('subtextColor'), align: 'center', valign: 'middle', lineSpacing: 18 });
        },

        // =====================================================================
        // ARTICLE / BLOG PREVIEW (future: will get richer formatting)
        // =====================================================================

        _renderArticlePreview: function(content, format, container) {
            var parsed = this._parseJson(content);
            if (!parsed || !parsed.sections) {
                // Fallback: treat as HTML (legacy content)
                var isLong = (format === 'article_long');
                container.innerHTML = '<div style="padding:24px 28px;background:#fff;border:1px solid #eee;border-radius:8px;font-size:' + (isLong ? '15' : '14') + 'px;line-height:1.8;max-height:500px;overflow-y:auto;font-family:Georgia,serif;color:#333">' + content + '</div>';
                return;
            }
            var html = '<div style="padding:24px 28px;background:#fff;border:1px solid #eee;border-radius:8px;max-height:500px;overflow-y:auto;font-family:Georgia,serif;color:#333">';
            if (parsed.title) html += '<h1 style="font-size:24px;font-weight:700;margin:0 0 8px;color:#1a1d26;line-height:1.3">' + this._esc(parsed.title) + '</h1>';
            if (parsed.meta_description) html += '<p style="font-size:13px;color:#888;margin:0 0 20px;font-style:italic;font-family:-apple-system,sans-serif">' + this._esc(parsed.meta_description) + '</p>';
            var sections = parsed.sections || [];
            for (var i = 0; i < sections.length; i++) {
                var sec = sections[i];
                if (sec.heading) html += '<h2 style="font-size:18px;font-weight:700;margin:24px 0 10px;color:#1a1d26;border-bottom:2px solid #eee;padding-bottom:6px">' + this._esc(sec.heading) + '</h2>';
                var paras = sec.paragraphs || [];
                for (var p = 0; p < paras.length; p++) {
                    html += '<p style="font-size:15px;line-height:1.8;margin:0 0 12px;color:#333">' + this._esc(paras[p]) + '</p>';
                }
                if (sec.key_takeaway) html += '<div style="margin:12px 0 16px;padding:10px 14px;background:#f0f7ff;border-left:3px solid #42A5F5;border-radius:0 6px 6px 0;font-size:13px;color:#1565c0;font-family:-apple-system,sans-serif"><strong>Key takeaway:</strong> ' + this._esc(sec.key_takeaway) + '</div>';
            }
            if (parsed.call_to_action) html += '<div style="margin-top:24px;padding:16px;background:#f8fdf8;border:1px solid #c8e6c9;border-radius:8px;font-size:15px;line-height:1.7;color:#2e7d32">' + this._esc(parsed.call_to_action) + '</div>';
            html += '</div>';
            container.innerHTML = html;
        },

        // =====================================================================
        // LINKEDIN PREVIEW
        // =====================================================================

        _renderLinkedInPreview: function(content, container) {
            var parsed = this._parseJson(content);
            var text;
            if (parsed && parsed.hook) {
                // Build display text from JSON structure
                var parts = [];
                parts.push(parsed.hook);
                if (parsed.body && Array.isArray(parsed.body)) parts = parts.concat(parsed.body);
                if (parsed.call_to_action) parts.push(parsed.call_to_action);
                if (parsed.hashtags && Array.isArray(parsed.hashtags)) parts.push('\n' + parsed.hashtags.join(' '));
                text = parts.join('\n\n');
            } else {
                // Fallback: strip HTML tags for legacy content
                text = String(content).replace(/<[^>]+>/g, '').trim();
            }
            container.innerHTML = ''
                + '<div style="max-width:540px;margin:0 auto">'
                + '<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden">'
                  + '<div style="padding:14px 16px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #f0f0f0">'
                    + '<div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#0077b5,#00a0dc);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px"><i class="fab fa-linkedin-in"></i></div>'
                    + '<div><div style="font-size:13px;font-weight:600;color:#333">Your Name</div><div style="font-size:11px;color:#888">Just now</div></div>'
                  + '</div>'
                  + '<div style="padding:16px;font-size:14px;line-height:1.7;color:#333;white-space:pre-wrap">' + this._esc(text) + '</div>'
                  + '<div style="padding:8px 16px;border-top:1px solid #f0f0f0;display:flex;gap:20px;font-size:12px;color:#888">'
                    + '<span><i class="fas fa-thumbs-up" style="color:#0077b5"></i> Like</span>'
                    + '<span><i class="fas fa-comment"></i> Comment</span>'
                    + '<span><i class="fas fa-share"></i> Share</span>'
                  + '</div>'
                + '</div>'
                + '<div style="margin-top:8px;text-align:center;font-size:11px;color:#999">' + text.length + ' characters</div>'
                + '</div>';
        },

        // =====================================================================
        // INFOGRAPHIC PREVIEW (section-based layout)
        // =====================================================================

        _renderInfographicPreview: function(content, container) {
            var parsed = this._parseJson(content);
            if (!parsed || !parsed.sections) {
                // Fallback: legacy HTML
                container.innerHTML = '<div style="padding:12px;margin-bottom:12px;background:#fff3e0;border:1px solid #ffe0b2;border-radius:6px;font-size:12px;color:#e65100"><i class="fas fa-info-circle"></i> Infographic content shown below. Full visual rendering coming soon.</div>'
                    + '<div style="padding:20px;background:#fff;border:1px solid #eee;border-radius:8px;font-size:14px;line-height:1.7;max-height:500px;overflow-y:auto">' + content + '</div>';
                return;
            }
            var colors = _T().chartColors;
            var html = '<div style="max-width:600px;margin:0 auto;max-height:500px;overflow-y:auto">';
            // Header
            html += '<div style="background:linear-gradient(135deg,' + _T().headerGradientFrom + ',' + _T().headerGradientTo + ');border-radius:12px 12px 0 0;padding:28px 24px;text-align:center">';
            if (parsed.title) html += '<div style="font-size:22px;font-weight:800;color:#fff;margin-bottom:6px">' + this._esc(parsed.title) + '</div>';
            if (parsed.subtitle) html += '<div style="font-size:14px;color:' + _T().headerSubtitle + '">' + this._esc(parsed.subtitle) + '</div>';
            html += '</div>';
            // Sections
            var sections = parsed.sections || [];
            for (var i = 0; i < sections.length; i++) {
                var sec = sections[i];
                var sColor = colors[i % colors.length];
                var isEven = (i % 2 === 0);
                html += '<div style="background:' + (isEven ? '#fff' : '#f8f9fa') + ';padding:20px 24px;border-left:4px solid ' + sColor + '">';
                if (sec.heading) html += '<div style="font-size:16px;font-weight:700;color:#333;margin-bottom:8px">' + this._esc(sec.heading) + '</div>';
                if (sec.description) html += '<div style="font-size:13px;color:#666;margin-bottom:12px;line-height:1.5">' + this._esc(sec.description) + '</div>';
                // Data points as chips
                if (sec.data_points && sec.data_points.length) {
                    html += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">';
                    for (var d = 0; d < sec.data_points.length; d++) {
                        var dp = sec.data_points[d];
                        html += '<div style="padding:6px 12px;border-radius:100px;background:' + sColor + '18;border:1px solid ' + sColor + '40;font-size:12px"><span style="font-weight:700;color:' + sColor + '">' + this._esc(dp.value) + '</span> <span style="color:#555">' + this._esc(dp.label) + '</span></div>';
                    }
                    html += '</div>';
                }
                // Visual element (reuse existing helpers)
                if (sec.visual_element && sec.visual_element.type && sec.visual_element.data) {
                    html += '<div style="padding:12px;background:' + _T().bg + ';border-radius:8px;margin-top:8px">';
                    html += this._renderVisualPreviewHtml(sec.visual_element, sColor);
                    html += '</div>';
                }
                html += '</div>';
            }
            // Footer
            html += '<div style="background:' + _T().footerBg + ';border-radius:0 0 12px 12px;padding:16px 24px;text-align:center">';
            if (parsed.call_to_action) html += '<div style="font-size:14px;font-weight:600;color:#fff;margin-bottom:6px">' + this._esc(parsed.call_to_action) + '</div>';
            if (parsed.footer) html += '<div style="font-size:11px;color:' + _T().subtextColor + '">' + this._esc(parsed.footer) + '</div>';
            html += '</div></div>';
            container.innerHTML = html;
            // Render canvas charts
            var self = this;
            setTimeout(function() { self._renderCanvasCharts(container); }, 50);
        },

        // =====================================================================
        // DOCX DOWNLOAD (Article, Blog, LinkedIn)
        // =====================================================================

        /**
         * Generate and download a well-formatted DOCX from parsed JSON content.
         *
         * Uses the docx npm package (loaded via CDN as window.docx).
         * Follows docx-js best practices: US Letter, Arial font,
         * proper headings, bullet numbering config, no unicode bullets.
         *
         * @param {Object} parsed   - Parsed JSON content
         * @param {string} format   - Content format (article_long, article_short, blog_post, linkedin_post)
         * @param {string} filename - Base filename (no extension)
         * @param {Object} meta     - Optional metadata
         */
        _downloadDocx: function(parsed, format, filename, meta) {
            if (!this.isDocxReady()) {
                console.warn('[JC] docx library not loaded, falling back to HTML');
                if (format === 'linkedin_post') {
                    this._downloadText(this._jsonToText(parsed, format), filename);
                } else {
                    var title = meta && meta.problemTitle ? meta.problemTitle : 'Content';
                    this._downloadHtml(this._jsonToHtml(parsed, format, title), format, filename, meta);
                }
                return;
            }

            var D = window.docx;

            if (format === 'linkedin_post') {
                this._buildLinkedInDocx(parsed, filename, meta, D);
            } else {
                this._buildArticleDocx(parsed, format, filename, meta, D);
            }
        },

        /**
         * Build DOCX for Article / Blog format.
         *
         * Structure: Title → meta description → sections (heading + paragraphs + takeaway) → CTA
         */
        _buildArticleDocx: function(parsed, format, filename, meta, D) {
            var self = this;
            var children = [];
            var isArticle = format.indexOf('article') !== -1;
            var formatLabel = isArticle ? 'Article' : 'Blog Post';

            // Title
            if (parsed.title) {
                children.push(new D.Paragraph({
                    heading: D.HeadingLevel.HEADING_1,
                    children: [new D.TextRun({ text: parsed.title })]
                }));
            }

            // Meta description (italic subtitle)
            if (parsed.meta_description) {
                children.push(new D.Paragraph({
                    spacing: { after: 200 },
                    children: [new D.TextRun({
                        text: parsed.meta_description,
                        italics: true,
                        color: '666666',
                        size: 22 // 11pt
                    })]
                }));
            }

            // Horizontal rule after intro
            children.push(new D.Paragraph({
                spacing: { before: 100, after: 200 },
                border: {
                    bottom: { style: D.BorderStyle.SINGLE, size: 6, color: 'CCCCCC', space: 1 }
                },
                children: []
            }));

            // Sections
            var sections = parsed.sections || [];
            for (var i = 0; i < sections.length; i++) {
                var sec = sections[i];

                // Section heading
                if (sec.heading) {
                    children.push(new D.Paragraph({
                        heading: D.HeadingLevel.HEADING_2,
                        children: [new D.TextRun({ text: sec.heading })]
                    }));
                }

                // Section paragraphs
                var paras = sec.paragraphs || [];
                for (var p = 0; p < paras.length; p++) {
                    children.push(new D.Paragraph({
                        spacing: { after: 120 },
                        children: [new D.TextRun({ text: paras[p] })]
                    }));
                }

                // Key takeaway callout (indented, with blue accent)
                if (sec.key_takeaway) {
                    children.push(new D.Paragraph({
                        spacing: { before: 80, after: 160 },
                        indent: { left: 720 },
                        border: {
                            left: { style: D.BorderStyle.SINGLE, size: 12, color: '42A5F5', space: 8 }
                        },
                        shading: { fill: 'F0F7FF', type: D.ShadingType.CLEAR },
                        children: [
                            new D.TextRun({ text: 'Key Takeaway: ', bold: true, color: '1565C0', size: 22 }),
                            new D.TextRun({ text: sec.key_takeaway, color: '1565C0', size: 22 })
                        ]
                    }));
                }
            }

            // Call to Action
            if (parsed.call_to_action) {
                children.push(new D.Paragraph({
                    spacing: { before: 200, after: 100 },
                    border: {
                        bottom: { style: D.BorderStyle.SINGLE, size: 6, color: 'CCCCCC', space: 1 }
                    },
                    children: []
                }));
                children.push(new D.Paragraph({
                    spacing: { before: 100 },
                    shading: { fill: 'F8FDF8', type: D.ShadingType.CLEAR },
                    children: [
                        new D.TextRun({ text: parsed.call_to_action, bold: true, color: '2E7D32' })
                    ]
                }));
            }

            // Footer: generated by line
            children.push(new D.Paragraph({ spacing: { before: 400 }, children: [] }));
            children.push(new D.Paragraph({
                children: [new D.TextRun({
                    text: 'Generated by DirectReach Campaign Builder',
                    size: 18, color: '999999', italics: true
                })]
            }));

            // Create document
            var doc = new D.Document({
                creator: 'DirectReach Campaign Builder',
                title: parsed.title || formatLabel,
                description: parsed.meta_description || '',
                styles: {
                    default: {
                        document: {
                            run: { font: 'Arial', size: 24 } // 12pt default
                        }
                    },
                    paragraphStyles: [
                        {
                            id: 'Heading1', name: 'Heading 1',
                            basedOn: 'Normal', next: 'Normal', quickFormat: true,
                            run: { size: 36, bold: true, font: 'Arial', color: '1A1D26' },
                            paragraph: { spacing: { before: 240, after: 120 }, outlineLevel: 0 }
                        },
                        {
                            id: 'Heading2', name: 'Heading 2',
                            basedOn: 'Normal', next: 'Normal', quickFormat: true,
                            run: { size: 28, bold: true, font: 'Arial', color: '1A1D26' },
                            paragraph: { spacing: { before: 240, after: 120 }, outlineLevel: 1 }
                        }
                    ]
                },
                sections: [{
                    properties: {
                        page: {
                            size: { width: 12240, height: 15840 },
                            margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 }
                        }
                    },
                    children: children
                }]
            });

            // Pack and download
            D.Packer.toBlob(doc).then(function(blob) {
                self._triggerBlobDownload(blob, (filename || formatLabel.toLowerCase().replace(/\s+/g, '-')) + '.docx');
            }).catch(function(err) {
                console.error('[JC] DOCX generation failed:', err);
                // Fallback to HTML
                var title = meta && meta.problemTitle ? meta.problemTitle : 'Content';
                var htmlDoc = self._jsonToHtml(parsed, format, title);
                self._triggerDownload(htmlDoc, (filename || 'content') + '.html', 'text/html');
            });
        },

        /**
         * Build DOCX for LinkedIn Post format.
         *
         * Structure: Hook (bold) → body paragraphs → CTA → hashtags
         */
        _buildLinkedInDocx: function(parsed, filename, meta, D) {
            var self = this;
            var children = [];

            // Hook (opening line, bold and larger)
            if (parsed.hook) {
                children.push(new D.Paragraph({
                    spacing: { after: 200 },
                    children: [new D.TextRun({
                        text: parsed.hook,
                        bold: true,
                        size: 28 // 14pt
                    })]
                }));
            }

            // Body paragraphs
            if (parsed.body && Array.isArray(parsed.body)) {
                for (var b = 0; b < parsed.body.length; b++) {
                    children.push(new D.Paragraph({
                        spacing: { after: 160 },
                        children: [new D.TextRun({ text: parsed.body[b] })]
                    }));
                }
            }

            // Call to action
            if (parsed.call_to_action) {
                children.push(new D.Paragraph({
                    spacing: { before: 100, after: 160 },
                    children: [new D.TextRun({
                        text: parsed.call_to_action,
                        bold: true,
                        color: '2E7D32'
                    })]
                }));
            }

            // Hashtags
            if (parsed.hashtags && Array.isArray(parsed.hashtags)) {
                children.push(new D.Paragraph({
                    spacing: { before: 100 },
                    children: [new D.TextRun({
                        text: parsed.hashtags.join('  '),
                        color: '1976D2',
                        size: 22 // 11pt
                    })]
                }));
            }

            // Footer
            children.push(new D.Paragraph({ spacing: { before: 400 }, children: [] }));
            children.push(new D.Paragraph({
                children: [new D.TextRun({
                    text: 'Generated by DirectReach Campaign Builder',
                    size: 18, color: '999999', italics: true
                })]
            }));

            var doc = new D.Document({
                creator: 'DirectReach Campaign Builder',
                title: 'LinkedIn Post',
                styles: {
                    default: {
                        document: {
                            run: { font: 'Arial', size: 24 }
                        }
                    }
                },
                sections: [{
                    properties: {
                        page: {
                            size: { width: 12240, height: 15840 },
                            margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 }
                        }
                    },
                    children: children
                }]
            });

            D.Packer.toBlob(doc).then(function(blob) {
                self._triggerBlobDownload(blob, (filename || 'linkedin-post') + '.docx');
            }).catch(function(err) {
                console.error('[JC] LinkedIn DOCX generation failed:', err);
                self._downloadText(self._jsonToText(parsed, 'linkedin_post'), filename);
            });
        },

        /**
         * Download a Blob with a given filename.
         * Used by DOCX and future binary downloads.
         */
        _triggerBlobDownload: function(blob, filename) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function() { URL.revokeObjectURL(url); }, 5000);
        },

        // =====================================================================
        // LEGACY DOWNLOAD HELPERS
        // =====================================================================

        _downloadHtml: function(content, format, filename, meta) {
            var title = meta && meta.problemTitle ? meta.problemTitle : 'Content';
            var htmlDoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + this._esc(title) + '</title>'
                + '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:800px;margin:40px auto;padding:0 20px;color:#333;line-height:1.7}h1,h2,h3{color:#1a1d26}h1{font-size:28px}h2{font-size:22px;border-bottom:2px solid #eee;padding-bottom:8px}ul{padding-left:24px}li{margin-bottom:6px}</style>'
                + '</head><body>' + content + '</body></html>';
            this._triggerDownload(htmlDoc, (filename || 'content') + '.html', 'text/html');
        },

        _downloadText: function(content, filename) {
            var text = content.replace(/<[^>]+>/g, '').trim();
            this._triggerDownload(text, (filename || 'content') + '.txt', 'text/plain');
        },

        _triggerDownload: function(data, filename, mimeType) {
            var blob = new Blob([data], { type: mimeType || 'text/plain' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = filename;
            document.body.appendChild(a); a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        // =====================================================================
        // UTILITIES
        // =====================================================================

        _esc: function(text) {
            if (!text) return '';
            var d = document.createElement('div');
            d.textContent = String(text);
            return d.innerHTML;
        },

        /**
         * Parse content as JSON, handling various edge cases.
         * Returns parsed object/array or null if not valid JSON.
         */
        _parseJson: function(content) {
            if (!content) return null;
            if (typeof content === 'object') return content;
            var str = String(content).trim();
            // Strip markdown code fences
            str = str.replace(/^```\w*\s*/i, '').replace(/\s*```$/, '');
            try {
                return JSON.parse(str);
            } catch(e) {
                // Try extracting JSON object or array from mixed content
                var objMatch = str.match(/\{[\s\S]*\}/);
                if (objMatch) { try { return JSON.parse(objMatch[0]); } catch(e2) {} }
                var arrMatch = str.match(/\[[\s\S]*\]/);
                if (arrMatch) { try { return JSON.parse(arrMatch[0]); } catch(e3) {} }
                return null;
            }
        },

        /**
         * Convert parsed JSON content to plain text for download.
         * Used for article, blog, linkedin formats.
         */
        _jsonToText: function(parsed, format) {
            if (!parsed) return '';
            var lines = [];
            if (format === 'linkedin_post') {
                if (parsed.hook) lines.push(parsed.hook, '');
                if (parsed.body && Array.isArray(parsed.body)) {
                    parsed.body.forEach(function(p) { lines.push(p, ''); });
                }
                if (parsed.call_to_action) lines.push(parsed.call_to_action, '');
                if (parsed.hashtags && Array.isArray(parsed.hashtags)) lines.push(parsed.hashtags.join(' '));
                return lines.join('\n');
            }
            // Article / Blog
            if (parsed.title) lines.push(parsed.title, '');
            if (parsed.meta_description) lines.push(parsed.meta_description, '');
            var sections = parsed.sections || [];
            for (var i = 0; i < sections.length; i++) {
                var sec = sections[i];
                if (sec.heading) lines.push('## ' + sec.heading, '');
                var paras = sec.paragraphs || [];
                for (var p = 0; p < paras.length; p++) { lines.push(paras[p], ''); }
                if (sec.key_takeaway) lines.push('Key takeaway: ' + sec.key_takeaway, '');
            }
            if (parsed.call_to_action) lines.push('---', '', parsed.call_to_action);
            return lines.join('\n');
        },

        /**
         * Convert parsed JSON content to styled HTML for download (interim until DOCX).
         */
        _jsonToHtml: function(parsed, format, title) {
            var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + this._esc(title || 'Content') + '</title>'
                + '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:800px;margin:40px auto;padding:0 20px;color:#333;line-height:1.7}h1{font-size:28px;color:#1a1d26}h2{font-size:22px;border-bottom:2px solid #eee;padding-bottom:8px;color:#1a1d26}p{margin:0 0 12px}.meta{font-size:13px;color:#888;font-style:italic;margin-bottom:20px}.takeaway{margin:12px 0 16px;padding:10px 14px;background:#f0f7ff;border-left:3px solid #42A5F5;border-radius:0 6px 6px 0;font-size:13px;color:#1565c0}.cta{margin-top:24px;padding:16px;background:#f8fdf8;border:1px solid #c8e6c9;border-radius:8px;color:#2e7d32}</style></head><body>';
            if (parsed.title) html += '<h1>' + this._esc(parsed.title) + '</h1>';
            if (parsed.meta_description) html += '<p class="meta">' + this._esc(parsed.meta_description) + '</p>';
            var sections = parsed.sections || [];
            for (var i = 0; i < sections.length; i++) {
                var sec = sections[i];
                if (sec.heading) html += '<h2>' + this._esc(sec.heading) + '</h2>';
                var paras = sec.paragraphs || [];
                for (var p = 0; p < paras.length; p++) { html += '<p>' + this._esc(paras[p]) + '</p>'; }
                if (sec.key_takeaway) html += '<div class="takeaway"><strong>Key takeaway:</strong> ' + this._esc(sec.key_takeaway) + '</div>';
            }
            if (parsed.call_to_action) html += '<div class="cta">' + this._esc(parsed.call_to_action) + '</div>';
            html += '</body></html>';
            return html;
        },

        /**
         * Safely encode an object as a JSON string for use inside an HTML attribute.
         */
        _safeJsonAttr: function(obj) {
            try {
                return JSON.stringify(obj).replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            } catch(e) {
                return '{}';
            }
        }
    };

    // Expose globally
    window.JCContentRenderer = ContentRenderer;

})(jQuery);