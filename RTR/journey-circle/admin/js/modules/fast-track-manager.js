/**
 * Fast Track Manager
 *
 * Orchestrates the "Fast Track" feature for JourneyCircle. When invoked from
 * Step 5 (after a primary problem is selected), it automatically runs
 * Steps 6, 7, and 9 without human approval:
 *
 *   1. Generate problem titles via API, auto-select top 5
 *   2. For each problem, generate solution titles, auto-select the first
 *   3. For each of the 10 items (5 problems + 5 solutions), generate full
 *      article content directly (skipping outlines)
 *
 * State keys set via workflow.updateState():
 *   - selectedProblems, selectedSolutions, problemSuggestions,
 *     solutionSuggestions, contentAssets, fastTrackCompleted
 *
 * Events:
 *   - Listens:  jc:stepChanged
 *   - Triggers: jc:fastTrackComplete
 *
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0.0
 */

(function($) {
    'use strict';

    // =====================================================================
    // HELPERS
    // =====================================================================

    function esc(text) {
        if (!text) return '';
        var d = document.createElement('div');
        d.textContent = String(text);
        return d.innerHTML;
    }

    // =====================================================================
    // CLASS
    // =====================================================================

    class FastTrackManager {
        constructor(workflow) {
            this.workflow = workflow;
            this.apiBase = workflow.config.restUrl;
            this.nonce = workflow.config.restNonce;

            // Cancellation
            this.abortController = null;
            this.cancelled = false;

            // Progress tracking
            this.totalArticles = 10;
            this.completedArticles = 0;
            this.failedArticles = [];

            this._modalInjected = false;

            this.init();
        }

        // -----------------------------------------------------------------
        // INITIALISATION
        // -----------------------------------------------------------------

        init() {
            this._injectModal();

            // Button click
            $(document).on('click', '.jc-fast-track-btn', (e) => {
                e.preventDefault();
                this.start();
            });

            // Step visibility
            $(document).on('jc:stepChanged', (e, step) => {
                this._toggleButton(step);
            });

            // Also check on primary problem selection changes
            $(document).on('jc:primaryProblemSelected', () => {
                var step = this.workflow.getState('currentStep');
                if (step === 5) this._toggleButton(5);
            });

            // Initial check
            var currentStep = this.workflow.getState('currentStep');
            if (currentStep === 5) this._toggleButton(5);

            console.log('[FastTrack] Manager initialized');
        }

        // -----------------------------------------------------------------
        // BUTTON VISIBILITY
        // -----------------------------------------------------------------

        _toggleButton(step) {
            var $btn = $('.jc-fast-track-btn');
            if (step !== 5) {
                $btn.hide();
                return;
            }
            // Show only if a primary problem is selected
            var state = this.workflow.getState();
            var hasPrimary = !!(state.primaryProblemId || state.primaryProblemStatement);
            $btn.toggle(hasPrimary);
        }

        // -----------------------------------------------------------------
        // PROGRESS MODAL
        // -----------------------------------------------------------------

        _injectModal() {
            if (this._modalInjected) return;
            this._modalInjected = true;

            var html = [
                '<div id="jc-fast-track-modal" style="display:none;position:fixed;inset:0;z-index:100000;',
                'background:rgba(0,0,0,.55);align-items:center;justify-content:center">',
                '<div style="background:#fff;border-radius:12px;padding:36px 40px;max-width:520px;width:90%;',
                'box-shadow:0 20px 60px rgba(0,0,0,.25);position:relative;font-family:-apple-system,BlinkMacSystemFont,',
                '\'Segoe UI\',Roboto,sans-serif">',
                '  <h3 id="jc-ft-title" style="margin:0 0 20px;font-size:20px;font-weight:700;color:#1a1a2e">',
                '    &#9889; Fast Track in Progress</h3>',
                '  <div id="jc-ft-progress-bar" style="background:#e2e8f0;border-radius:6px;height:8px;',
                '    margin-bottom:20px;overflow:hidden">',
                '    <div id="jc-ft-progress-fill" style="width:0%;height:100%;background:linear-gradient(90deg,#4a90d9,#6c5ce7);',
                '      border-radius:6px;transition:width .4s ease"></div>',
                '  </div>',
                '  <div id="jc-ft-steps" style="font-size:14px;line-height:2;color:#475569"></div>',
                '  <div id="jc-ft-error" style="display:none;margin-top:16px;padding:12px 16px;',
                '    background:#fff5f5;border:1px solid #fed7d7;border-radius:8px;color:#c53030;font-size:13px"></div>',
                '  <div id="jc-ft-actions" style="margin-top:24px;text-align:right">',
                '    <button type="button" id="jc-ft-cancel" class="btn" style="padding:8px 20px;',
                '      border:1px solid #cbd5e0;border-radius:6px;background:#fff;color:#4a5568;cursor:pointer;',
                '      font-size:14px">Cancel</button>',
                '    <button type="button" id="jc-ft-retry" class="btn" style="display:none;padding:8px 20px;',
                '      border:none;border-radius:6px;background:#4a90d9;color:#fff;cursor:pointer;',
                '      font-size:14px;margin-left:8px">Retry Failed</button>',
                '  </div>',
                '</div></div>'
            ].join('');

            $('body').append(html);

            // Cancel handler
            $(document).on('click', '#jc-ft-cancel', () => this.cancel());

            // Retry handler
            $(document).on('click', '#jc-ft-retry', () => this.retryFailed());
        }

        _showModal() {
            $('#jc-ft-error').hide();
            $('#jc-ft-retry').hide();
            $('#jc-ft-cancel').show().text('Cancel');
            $('#jc-fast-track-modal').css('display', 'flex');
        }

        _hideModal() {
            $('#jc-fast-track-modal').hide();
        }

        _updateProgress(percent, steps) {
            $('#jc-ft-progress-fill').css('width', Math.min(percent, 100) + '%');
            var html = steps.map(function(s) {
                return '<div>' + s + '</div>';
            }).join('');
            $('#jc-ft-steps').html(html);
        }

        _showError(message) {
            $('#jc-ft-error').html(esc(message)).show();
        }

        _buildStepLines(phase, detail) {
            var lines = [];

            // Step 1: Problem titles
            if (phase === 'problems') {
                lines.push('&#128260; Generating problem titles...');
                lines.push('&#9203; Generate solution titles');
                lines.push('&#9203; Generate articles (0/' + this.totalArticles + ')');
            } else if (phase === 'problems-retry') {
                lines.push('&#128260; Generating problem titles... (retrying)');
                lines.push('&#9203; Generate solution titles');
                lines.push('&#9203; Generate articles (0/' + this.totalArticles + ')');
            } else if (phase === 'problems-done') {
                lines.push('&#9989; Generated problem titles (5 selected)');
                lines.push('&#128260; Generating solution titles... ' + (detail || ''));
                lines.push('&#9203; Generate articles (0/' + this.totalArticles + ')');
            } else if (phase === 'solutions') {
                lines.push('&#9989; Generated problem titles (5 selected)');
                lines.push('&#128260; Generating solution titles... ' + (detail || ''));
                lines.push('&#9203; Generate articles (0/' + this.totalArticles + ')');
            } else if (phase === 'solutions-done') {
                lines.push('&#9989; Generated problem titles (5 selected)');
                lines.push('&#9989; Generated solution titles (5 selected)');
                lines.push('&#128260; Generating articles... (' + this.completedArticles + '/' + this.totalArticles + ')');
            } else if (phase === 'articles') {
                lines.push('&#9989; Generated problem titles (5 selected)');
                lines.push('&#9989; Generated solution titles (5 selected)');
                lines.push('&#128260; Generating articles... (' + this.completedArticles + '/' + this.totalArticles + ') ' + (detail || ''));
            } else if (phase === 'done') {
                lines.push('&#9989; Generated problem titles (5 selected)');
                lines.push('&#9989; Generated solution titles (5 selected)');
                lines.push('&#9989; Generated articles (' + this.completedArticles + '/' + this.totalArticles + ')');
            }

            return lines;
        }

        // -----------------------------------------------------------------
        // MAIN FLOW
        // -----------------------------------------------------------------

        async start() {
            this.cancelled = false;
            this.abortController = new AbortController();
            this.completedArticles = 0;
            this.failedArticles = [];

            this._showModal();

            try {
                // Phase 1: Generate problem titles
                this._updateProgress(5, this._buildStepLines('problems'));
                var problems = await this._generateProblemTitles();
                if (this.cancelled) return;

                // Phase 2: Generate solution titles
                this._updateProgress(20, this._buildStepLines('problems-done', '(0/5)'));
                var solutions = await this._generateSolutionTitles(problems);
                if (this.cancelled) return;

                // Phase 3: Generate articles
                this._updateProgress(40, this._buildStepLines('solutions-done'));
                await this._generateAllArticles(problems, solutions);
                if (this.cancelled) return;

                // Done
                this._finalize(problems, solutions);

            } catch (err) {
                if (this.cancelled) return;
                console.error('[FastTrack] Error:', err);
                this._showError(err.message || 'An unexpected error occurred.');
                $('#jc-ft-cancel').text('Close');
            }
        }

        cancel() {
            this.cancelled = true;
            if (this.abortController) {
                this.abortController.abort();
            }
            this._hideModal();
        }

        async retryFailed() {
            if (this.failedArticles.length === 0) return;

            this.cancelled = false;
            this.abortController = new AbortController();

            $('#jc-ft-error').hide();
            $('#jc-ft-retry').hide();

            var state = this.workflow.getState();
            var contentAssets = state.contentAssets || {};
            var toRetry = this.failedArticles.slice();
            this.failedArticles = [];

            for (var i = 0; i < toRetry.length; i++) {
                if (this.cancelled) return;

                var item = toRetry[i];
                this._updateProgress(
                    this._calcArticlePercent(),
                    this._buildStepLines('articles', '(retrying...)')
                );

                try {
                    var content = await this._generateSingleArticle(item, state);
                    this._storeArticleContent(contentAssets, item, content);
                    this.completedArticles++;
                    this._updateProgress(
                        this._calcArticlePercent(),
                        this._buildStepLines('articles')
                    );
                } catch (err) {
                    if (this.cancelled) return;
                    console.error('[FastTrack] Retry failed for', item.focus, item.problemTitle, err);
                    this.failedArticles.push(item);
                }
            }

            // Save state
            this.workflow.updateState('contentAssets', contentAssets);
            this.workflow.saveState();

            if (this.failedArticles.length > 0) {
                this._showError(
                    this.failedArticles.length + ' article(s) still failed. You can retry or close and generate them manually in Step 9.'
                );
                $('#jc-ft-retry').show();
            } else {
                this._updateProgress(100, this._buildStepLines('done'));
                this._completeNavigation();
            }
        }

        // -----------------------------------------------------------------
        // PHASE 1: PROBLEM TITLES
        // -----------------------------------------------------------------

        async _generateProblemTitles() {
            var state = this.workflow.getState();
            var maxRetries = 2;
            var data;

            for (var attempt = 0; attempt <= maxRetries; attempt++) {
                var response = await fetch(this.apiBase + '/ai/generate-problem-titles', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.nonce
                    },
                    body: JSON.stringify({
                        service_area_id: state.serviceAreaId || 0,
                        service_area_name: '',
                        primary_problem_statement: state.primaryProblemStatement || '',
                        industries: state.industries || [],
                        brain_content: state.brainContent || [],
                        existing_assets: state.existingAssets || [],
                        force_refresh: false,
                        previous_titles: []
                    }),
                    signal: this.abortController.signal
                });

                data = await response.json();

                // If success or non-timeout error, stop retrying
                if (data.success && data.titles && data.titles.length > 0) {
                    break;
                }

                var errorCode = data.code || '';
                if (errorCode !== 'api_timeout' || attempt === maxRetries) {
                    throw new Error(data.error || data.message || 'Failed to generate problem titles.');
                }

                // Timeout — retry after a short delay
                console.warn('[FastTrack] Problem titles timed out, retrying (attempt ' + (attempt + 2) + ')...');
                this._updateProgress(5, this._buildStepLines('problems-retry'));
                await new Promise(function(r) { setTimeout(r, 2000); });
            }

            // Select top 5
            var allTitles = data.titles;
            var selectedProblems = allTitles.slice(0, 5).map(function(t, i) {
                return {
                    id: t.id || ('p_' + i),
                    title: t.title
                };
            });

            // Persist to workflow state
            this.workflow.updateState('problemSuggestions', allTitles);
            this.workflow.updateState('selectedProblems', selectedProblems);

            // Also update the Steps567Manager in-memory state if available
            if (window.drJourneyCircle && window.drJourneyCircle.steps567) {
                window.drJourneyCircle.steps567.problemSuggestions = allTitles;
                window.drJourneyCircle.steps567.selectedProblems = selectedProblems;
            }

            return selectedProblems;
        }

        // -----------------------------------------------------------------
        // PHASE 2: SOLUTION TITLES
        // -----------------------------------------------------------------

        async _generateSolutionTitles(problems) {
            var state = this.workflow.getState();
            var solutionSuggestions = {};
            var selectedSolutions = {};

            for (var i = 0; i < problems.length; i++) {
                if (this.cancelled) return selectedSolutions;

                var problem = problems[i];
                this._updateProgress(
                    20 + (i / problems.length) * 20,
                    this._buildStepLines('solutions', '(' + (i + 1) + '/' + problems.length + ')')
                );

                var data;
                var maxRetries = 2;
                for (var attempt = 0; attempt <= maxRetries; attempt++) {
                    var response = await fetch(this.apiBase + '/ai/generate-solution-titles', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': this.nonce
                        },
                        body: JSON.stringify({
                            problem_id: problem.id,
                            problem_title: problem.title,
                            service_area_id: state.serviceAreaId || 0,
                            service_area_name: '',
                            industries: state.industries || [],
                            brain_content: state.brainContent || [],
                            existing_assets: state.existingAssets || [],
                            force_refresh: false,
                            exclude_titles: []
                        }),
                        signal: this.abortController.signal
                    });

                    data = await response.json();

                    if (data.success && data.titles && data.titles.length > 0) {
                        break;
                    }

                    var errorCode = data.code || '';
                    if (errorCode !== 'api_timeout' || attempt === maxRetries) {
                        throw new Error(
                            'Failed to generate solution titles for "' + problem.title + '": ' +
                            (data.error || data.message || 'No titles returned.')
                        );
                    }

                    console.warn('[FastTrack] Solution titles timed out for "' + problem.title + '", retrying...');
                    await new Promise(function(r) { setTimeout(r, 2000); });
                }

                solutionSuggestions[problem.id] = data.titles;
                selectedSolutions[problem.id] = data.titles[0].title;
            }

            // Persist
            this.workflow.updateState('solutionSuggestions', solutionSuggestions);
            this.workflow.updateState('selectedSolutions', selectedSolutions);

            // Update Steps567Manager in-memory state
            if (window.drJourneyCircle && window.drJourneyCircle.steps567) {
                window.drJourneyCircle.steps567.solutionSuggestions = solutionSuggestions;
                window.drJourneyCircle.steps567.selectedSolutions = selectedSolutions;
            }

            return selectedSolutions;
        }

        // -----------------------------------------------------------------
        // PHASE 3: ARTICLE GENERATION
        // -----------------------------------------------------------------

        async _generateAllArticles(problems, solutions) {
            var state = this.workflow.getState();
            var contentAssets = state.contentAssets || {};

            // Build work items: 5 problem-focus + 5 solution-focus
            var workItems = [];
            for (var i = 0; i < problems.length; i++) {
                var p = problems[i];
                var solTitle = solutions[p.id] || '';

                workItems.push({
                    problemId: p.id,
                    problemTitle: p.title,
                    solutionTitle: solTitle,
                    focus: 'problem'
                });
                workItems.push({
                    problemId: p.id,
                    problemTitle: p.title,
                    solutionTitle: solTitle,
                    focus: 'solution'
                });
            }

            this.totalArticles = workItems.length;

            for (var j = 0; j < workItems.length; j++) {
                if (this.cancelled) return;

                var item = workItems[j];
                this._updateProgress(
                    this._calcArticlePercent(),
                    this._buildStepLines('articles')
                );

                try {
                    var content = await this._generateSingleArticle(item, state);
                    this._storeArticleContent(contentAssets, item, content);
                    this.completedArticles++;
                } catch (err) {
                    if (this.cancelled) return;
                    console.error('[FastTrack] Article generation failed for', item.focus, item.problemTitle, err);
                    this.failedArticles.push(item);
                }

                this._updateProgress(
                    this._calcArticlePercent(),
                    this._buildStepLines('articles')
                );
            }

            // Persist content assets
            this.workflow.updateState('contentAssets', contentAssets);
            this.workflow.saveState();
        }

        async _generateSingleArticle(item, state) {
            // Build content_set_titles for lane discipline
            var selectedProblems = state.selectedProblems || [];
            var selectedSolutions = state.selectedSolutions || {};
            var contentSetTitles = selectedProblems.map(function(p) {
                return {
                    problem_title: p.title,
                    solution_title: selectedSolutions[p.id] || ''
                };
            });

            var response = await fetch(this.apiBase + '/ai/fast-track-content', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.nonce
                },
                body: JSON.stringify({
                    problem_title: item.problemTitle,
                    solution_title: item.solutionTitle,
                    focus: item.focus,
                    brain_content: state.brainContent || [],
                    existing_assets: state.existingAssets || [],
                    industries: state.industries || [],
                    service_area_id: state.serviceAreaId || 0,
                    content_set_titles: contentSetTitles,
                    evaluative_lens: ''
                }),
                signal: this.abortController.signal
            });

            var data = await response.json();

            if (!data.success || !data.content) {
                throw new Error(data.error || data.message || 'Content generation failed.');
            }

            return data.content;
        }

        _storeArticleContent(contentAssets, item, content) {
            if (!contentAssets[item.problemId]) {
                contentAssets[item.problemId] = {
                    problem: { types: {} },
                    solution: { types: {} }
                };
            }
            if (!contentAssets[item.problemId][item.focus]) {
                contentAssets[item.problemId][item.focus] = { types: {} };
            }
            if (!contentAssets[item.problemId][item.focus].types) {
                contentAssets[item.problemId][item.focus].types = {};
            }

            contentAssets[item.problemId][item.focus].types.article_long = {
                content: content,
                status: 'draft'
            };
        }

        _calcArticlePercent() {
            // Articles span from 40% to 95% of the progress bar
            var articleProgress = this.totalArticles > 0
                ? this.completedArticles / this.totalArticles
                : 0;
            return 40 + (articleProgress * 55);
        }

        // -----------------------------------------------------------------
        // COMPLETION
        // -----------------------------------------------------------------

        _finalize(problems, solutions) {
            if (this.failedArticles.length > 0) {
                this._updateProgress(
                    this._calcArticlePercent(),
                    this._buildStepLines('articles')
                );
                this._showError(
                    this.failedArticles.length + ' article(s) failed to generate. ' +
                    'You can retry or close and generate them manually in Step 9.'
                );
                $('#jc-ft-retry').show();
                $('#jc-ft-cancel').text('Close');
                return;
            }

            this._updateProgress(100, this._buildStepLines('done'));
            this._completeNavigation();
        }

        _completeNavigation() {
            var self = this;

            // Brief pause so the user sees the 100% state
            setTimeout(function() {
                self._hideModal();

                self.workflow.updateState('fastTrackCompleted', true);
                self.workflow.saveState();

                // Navigate to Step 9
                self.workflow.goToStep(9, true);

                // Trigger completion event
                $(document).trigger('jc:fastTrackComplete', {
                    completedArticles: self.completedArticles,
                    totalArticles: self.totalArticles,
                    failedCount: self.failedArticles.length
                });

                console.log('[FastTrack] Complete -', self.completedArticles, 'articles generated');
            }, 800);
        }

        // -----------------------------------------------------------------
        // CLEANUP
        // -----------------------------------------------------------------

        destroy() {
            this.cancel();
            $('#jc-fast-track-modal').remove();
            this._modalInjected = false;
        }
    }

    // =====================================================================
    // EXPORT
    // =====================================================================

    window.FastTrackManager = FastTrackManager;

    // Auto-initialize when document is ready
    $(document).ready(function() {
        if (window.drJourneyCircle) {
            window.drFastTrackManager = new FastTrackManager(window.drJourneyCircle);
        }
    });

})(jQuery);
