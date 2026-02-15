/**
 * Step 9 Asset Creator - v3 (Outline-First Review Flow)
 *
 * NEW FLOW (per content type in batch):
 *   1. Generate outline via AI → show to user
 *   2. User reviews outline, provides feedback or approves
 *   3. On approve → generate full content → show for review
 *   4. User can revise, download, or approve content
 *   5. Move to next content type in batch
 *
 * State: contentAssets[pid] = { problem: { types: {} }, solution: { types: {} } }
 *
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.1.0
 */
(function($) {
    'use strict';

    var CONTENT_TYPES = {
        article:      { id: 'article',      label: 'Article',       icon: 'fas fa-file-alt',  color: '#1565c0', bgColor: '#e3f2fd', apiFormat: 'article_long' },
        blog:         { id: 'blog',         label: 'Blog Post',     icon: 'fas fa-blog',      color: '#2e7d32', bgColor: '#e8f5e9', apiFormat: 'blog_post' },
        linkedin:     { id: 'linkedin',     label: 'LinkedIn Post', icon: 'fab fa-linkedin',  color: '#0077b5', bgColor: '#e1f5fe', apiFormat: 'linkedin_post' },
        infographic:  { id: 'infographic',  label: 'Infographic',   icon: 'fas fa-chart-pie', color: '#e65100', bgColor: '#fff3e0', apiFormat: 'infographic' },
        presentation: { id: 'presentation', label: 'Presentation',  icon: 'fas fa-desktop',   color: '#6a1b9a', bgColor: '#f3e5f5', apiFormat: 'presentation' }
    };
    var CT_LIST = Object.values(CONTENT_TYPES);
    var FP = 'problem', FS = 'solution';

    function esc(t) { if (!t) return ''; var d = document.createElement('div'); d.textContent = String(t); return d.innerHTML; }
    function slugify(s) { return (s||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').substring(0,40); }

    // =================================================================
    class Step9Manager {
        constructor(workflow) {
            this.workflow = workflow;
            this.apiBase = workflow.config.restUrl;
            this.nonce = workflow.config.restNonce;
            this.selectedProblems = [];
            this.selectedSolutions = {};
            this.assets = {};
            this.currentItem = null;
            this.currentTypes = [];
            this.batchIndex = 0;
            this.openCards = {};
            this.init();
        }

        init() {
            var self = this;
            var state = this.workflow.getState();
            if (state) {
                this.selectedProblems = state.selectedProblems || [];
                this.selectedSolutions = state.selectedSolutions || {};
                this.assets = this._migrate(state.contentAssets || {});
            }
            $(document).on('jc:stepChanged', function(e, step) { if (step === 9) self.initStep9(); });
            $(document).on('jc:colorSchemeChanged', function() { var g=document.getElementById('jc-asset-grid'); if(g && g.style.display!=='none') self._renderGrid(g); });
            if (state && state.currentStep === 9) this.initStep9();
            console.log('Step9Manager v3 (outline-first) ready');
        }

        _migrate(raw) {
            if (!raw || typeof raw !== 'object') return {};
            var m = {};
            Object.keys(raw).forEach(function(pid) {
                var e = raw[pid];
                if (e && (e.problem || e.solution)) {
                    m[pid] = { problem: e.problem || {types:{}}, solution: e.solution || {types:{}} };
                } else if (e && e.types) {
                    m[pid] = { problem: {types: e.types}, solution: {types:{}} };
                } else {
                    m[pid] = { problem: {types:{}}, solution: {types:{}} };
                }
            });
            return m;
        }

        initStep9() {
            var grid = document.getElementById('jc-asset-grid');
            if (!grid) return;
            var state = this.workflow.getState();
            this.selectedProblems = state.selectedProblems || [];
            this.selectedSolutions = state.selectedSolutions || {};
            this.assets = this._migrate(state.contentAssets || {});
            if (this.selectedProblems.length === 0) {
                grid.innerHTML = '<div style="padding:32px;text-align:center;color:#666"><i class="fas fa-exclamation-circle" style="font-size:2.5em;margin-bottom:12px;display:block;color:#ffc107"></i><p style="font-size:15px;margin:0">Please complete Steps 5-7 first.</p></div>';
                return;
            }
            this.currentItem = null;
            this._renderGrid(grid);
        }

        // -- Counts --
        _cntFocus(focus) { var n=0,s=this; this.selectedProblems.forEach(function(p){ var fd=s.assets[String(p.id)]&&s.assets[String(p.id)][focus]; if(fd&&fd.types) Object.values(fd.types).forEach(function(t){if(t.status==='approved'||t.status==='downloaded')n++;}); }); return n; }
        _cntCard(pid) { var pc=0,sc=0,a=this.assets[pid]; if(a){ if(a.problem&&a.problem.types) Object.values(a.problem.types).forEach(function(t){if(t.status==='approved'||t.status==='downloaded')pc++;}); if(a.solution&&a.solution.types) Object.values(a.solution.types).forEach(function(t){if(t.status==='approved'||t.status==='downloaded')sc++;}); } return {pc:pc,sc:sc,total:pc+sc}; }
        _cntRow(pid,focus) { var fd=this.assets[pid]&&this.assets[pid][focus]; if(!fd||!fd.types)return 0; return Object.values(fd.types).filter(function(t){return t.status==='approved'||t.status==='downloaded';}).length; }

        // =================================================================
        // GRID
        // =================================================================
        _renderGrid(grid) {
            var panel = document.getElementById('jc-asset-creation-panel');
            if (this.currentItem && panel) { grid.style.display='none'; panel.style.display='block'; return; }
            grid.style.display=''; if(panel) panel.style.display='none';
            var pT=this._cntFocus(FP),sT=this._cntFocus(FS),gT=pT+sT,mx=this.selectedProblems.length*CT_LIST.length*2,self=this;
            var csBtn = (window.JCColorSchemeSelector) ? window.JCColorSchemeSelector.buildTriggerHtml() : '';
            var h='<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 18px;margin-bottom:16px;background:#fff;border-radius:10px;border:1px solid #e2e5eb;box-shadow:0 1px 3px rgba(0,0,0,.06)"><div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700;color:#1a1d26">Content Assets</span><span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:4px 10px;border-radius:100px;background:#fef2f2;color:#dc4545"><i class="fas fa-exclamation-circle"></i> '+pT+' Problem</span><span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:4px 10px;border-radius:100px;background:#eff6ff;color:#3b82f6"><i class="fas fa-lightbulb"></i> '+sT+' Solution</span><span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:4px 10px;border-radius:100px;background:'+(gT>0?'#f0fdf4':'#f3f4f8')+';color:'+(gT>0?'#16a34a':'#8c91a0')+'"><i class="fas fa-'+(gT>0?'check-circle':'tasks')+'"></i> '+gT+' of '+mx+' created</span></div>'+csBtn+'</div>';
            this.selectedProblems.forEach(function(p,i){ h+=self._cardHtml(p,i); });
            grid.innerHTML=h;
            this._bindGrid(grid);
        }

        _cardHtml(problem, index) {
            var pid=String(problem.id),sol=this.selectedSolutions[pid]||this.selectedSolutions[problem.id]||'',c=this._cntCard(pid),hc=c.total>0,isOpen=this.openCards[pid]||(index===0&&!hc);
            return '<div class="jc-pair-card'+(hc?' has-content':'')+'" data-problem-id="'+pid+'" style="background:#fff;border-radius:12px;border:1px solid '+(hc?'#bbf7d0':'#e2e5eb')+';margin-bottom:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06)">'
              +'<div class="jc-pair-header" data-problem-id="'+pid+'" style="display:flex;align-items:center;gap:14px;padding:16px 20px;background:'+(hc?'linear-gradient(135deg,#f0fdf4,#f7fdf9)':'#fafbfd')+';border-bottom:1px solid '+(isOpen?(hc?'#bbf7d0':'#eef0f4'):'transparent')+';cursor:pointer;user-select:none">'
              +'<span style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0;background:'+(hc?'linear-gradient(135deg,#16a34a,#15803d)':'linear-gradient(135deg,#dc4545,#b91c1c)')+'">'+(hc?'<i class="fas fa-check" style="font-size:15px"></i>':String(index+1))+'</span>'
              +'<div style="flex:1;min-width:0"><div style="display:flex;align-items:baseline;gap:8px;margin-bottom:2px;font-size:13px;line-height:1.4"><span style="display:inline-block;font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px;text-transform:uppercase;letter-spacing:.6px;background:#dc4545;color:#fff;flex-shrink:0">Problem</span><span style="font-weight:500">'+esc(problem.title)+'</span></div><div style="display:flex;align-items:baseline;gap:8px;font-size:13px;line-height:1.4"><span style="display:inline-block;font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px;text-transform:uppercase;letter-spacing:.6px;background:#3b82f6;color:#fff;flex-shrink:0">Solution</span><span style="font-weight:400;color:#5a6070">'+esc(sol)+'</span></div></div>'
              +'<span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;white-space:nowrap;background:'+(hc?'#dcfce7':'#f3f4f8')+';color:'+(hc?'#16a34a':'#8c91a0')+'">'+(c.total>0?c.total+' created':'0 created')+'</span>'
              +'<i class="fas fa-chevron-down jc-pair-chevron" style="color:#8c91a0;font-size:14px;transition:transform .25s ease;flex-shrink:0;'+(isOpen?'transform:rotate(180deg)':'')+'"></i></div>'
              +'<div class="jc-pair-body" style="'+(isOpen?'max-height:1200px':'max-height:0')+';overflow:hidden;transition:max-height .35s cubic-bezier(.4,0,.2,1)"><div style="padding:0 20px 20px">'
              +this._rowHtml(pid,FP)+this._rowHtml(pid,FS)
              +'<div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:16px;border-top:1px solid #eef0f4">'
              +(c.total>0?'<button type="button" class="button button-small jc-view-created-btn" data-problem-id="'+pid+'" style="font-size:12px"><i class="fas fa-eye"></i> View Created</button>':'')
              +'<button type="button" class="button button-small button-primary jc-generate-btn" data-problem-id="'+pid+'" style="font-size:12px" disabled><i class="fas fa-magic"></i> Generate Selected</button>'
              +'</div></div></div></div>';
        }

        _rowHtml(pid, focus) {
            var fd=(this.assets[pid]&&this.assets[pid][focus])||{types:{}},types=fd.types||{},cr=this._cntRow(pid,focus),mx=CT_LIST.length,isP=focus===FP;
            var bg=isP?'#fef2f2':'#eff6ff',bd=isP?'#fecaca':'#bfdbfe',lc=isP?'#dc4545':'#3b82f6',li=isP?'fas fa-exclamation-circle':'fas fa-lightbulb',lt=isP?'Problem Content':'Solution Content';
            var sBg=isP?'#fff1f2':'#eff6ff',sBd=isP?'#dc4545':'#3b82f6',sCl=isP?'#dc4545':'#3b82f6';
            var chips='';
            CT_LIST.forEach(function(ct){ var td=types[ct.id],done=td&&(td.status==='approved'||td.status==='downloaded');
                if(done){ chips+='<label class="jc-ct-chip created" data-type="'+ct.id+'" data-focus="'+focus+'" data-problem-id="'+pid+'" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:100px;font-size:12px;font-weight:600;cursor:pointer;background:#dcfce7;border:1.5px solid #bbf7d0;color:#16a34a;user-select:none"><i class="fas fa-check-circle" style="font-size:11px"></i><i class="'+ct.icon+'" style="font-size:12px"></i><span>'+ct.label+'</span></label>'; }
                else { chips+='<label class="jc-ct-chip selectable" data-type="'+ct.id+'" data-focus="'+focus+'" data-problem-id="'+pid+'" data-sbg="'+sBg+'" data-sbd="'+sBd+'" data-scl="'+sCl+'" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:100px;font-size:12px;font-weight:500;cursor:pointer;background:#fff;border:1.5px solid #e2e5eb;color:#5a6070;user-select:none"><input type="checkbox" class="jc-ct-check" data-type="'+ct.id+'" data-focus="'+focus+'" data-problem-id="'+pid+'" style="display:none"><i class="'+ct.icon+'" style="font-size:12px;color:'+ct.color+'"></i><span>'+ct.label+'</span></label>'; }
            });
            return '<div class="jc-content-row" data-focus="'+focus+'" data-problem-id="'+pid+'" style="padding:16px;border-radius:8px;margin-top:16px;background:'+bg+';border:1px solid '+bd+'"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px"><span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:'+lc+'"><i class="'+li+'" style="font-size:13px"></i> '+lt+'</span><span style="font-size:11px;font-weight:600;color:'+(cr>0?'#16a34a':'#8c91a0')+'">'+cr+' of '+mx+' created</span></div><div style="display:flex;flex-wrap:wrap;gap:8px">'+chips+'</div></div>';
        }

        _bindGrid(grid) {
            var self=this;
            grid.querySelectorAll('.jc-pair-header').forEach(function(h){ h.addEventListener('click',function(){ var card=h.closest('.jc-pair-card'),pid=h.dataset.problemId,open=card.classList.toggle('open'); self.openCards[pid]=open; var body=card.querySelector('.jc-pair-body'),chev=card.querySelector('.jc-pair-chevron'); if(body)body.style.maxHeight=open?'1200px':'0'; if(chev)chev.style.transform=open?'rotate(180deg)':''; h.style.borderBottomColor=open?'':'transparent'; }); });
            grid.querySelectorAll('.jc-ct-chip.selectable').forEach(function(chip){ chip.addEventListener('click',function(){ var cb=chip.querySelector('.jc-ct-check'); if(!cb)return; cb.checked=!cb.checked; if(cb.checked){chip.style.background=chip.dataset.sbg;chip.style.borderColor=chip.dataset.sbd;chip.style.color=chip.dataset.scl;chip.style.fontWeight='600';}else{chip.style.background='#fff';chip.style.borderColor='#e2e5eb';chip.style.color='#5a6070';chip.style.fontWeight='500';} self._updateGenBtn(grid,cb.dataset.problemId); }); });
            grid.querySelectorAll('.jc-ct-chip.created').forEach(function(chip){ chip.addEventListener('click',function(){ self._openReview(chip.dataset.problemId,chip.dataset.focus,chip.dataset.type); }); });
            grid.querySelectorAll('.jc-generate-btn').forEach(function(btn){ btn.addEventListener('click',function(){ self._startBatch(grid,btn.dataset.problemId); }); });
            grid.querySelectorAll('.jc-view-created-btn').forEach(function(btn){ btn.addEventListener('click',function(){ self._openCreated(btn.dataset.problemId); }); });
        }

        _updateGenBtn(grid,pid) { var btn=grid.querySelector('.jc-generate-btn[data-problem-id="'+pid+'"]'); if(!btn)return; var n=grid.querySelectorAll('.jc-ct-check[data-problem-id="'+pid+'"]:checked').length; btn.disabled=n===0; btn.innerHTML=n>0?'<i class="fas fa-magic"></i> Generate Selected ('+n+')':'<i class="fas fa-magic"></i> Generate Selected'; }

        // =================================================================
        // BATCH START
        // =================================================================
        _startBatch(grid, pid) {
            var problem=this.selectedProblems.find(function(p){return String(p.id)===pid;});
            if(!problem) return;
            var checks=grid.querySelectorAll('.jc-ct-check[data-problem-id="'+pid+'"]:checked');
            var items=Array.from(checks).map(function(c){return {typeId:c.dataset.type,focus:c.dataset.focus};});
            if(items.length===0){this.workflow.showNotification('Please select at least one content type.','error');return;}
            this.currentItem={problemId:pid,problemTitle:problem.title,solutionTitle:this.selectedSolutions[pid]||this.selectedSolutions[problem.id]||'',genItems:items};
            this.currentTypes=items.map(function(i){return i.focus+':'+i.typeId;});
            this.batchIndex=0;
            if(!this.assets[pid]) this.assets[pid]={problem:{types:{}},solution:{types:{}}};
            if(!this.assets[pid].problem) this.assets[pid].problem={types:{}};
            if(!this.assets[pid].solution) this.assets[pid].solution={types:{}};
            this._showPanel();
            this._processNext();
        }

        // =================================================================
        // PANEL
        // =================================================================
        _showPanel() {
            var grid=document.getElementById('jc-asset-grid'),panel=document.getElementById('jc-asset-creation-panel');
            if(grid) grid.style.display='none';
            if(!panel) return;
            panel.style.display='block';
            panel.innerHTML=this._panelHtml();
            this._bindPanel(panel);
        }

        _panelHtml() {
            var item=this.currentItem,items=item.genItems||[],self=this,pid=item.problemId;
            var pI=items.filter(function(i){return i.focus===FP;}),sI=items.filter(function(i){return i.focus===FS;});
            var sum='';
            if(pI.length) sum+='<span style="color:#dc4545;font-weight:600">Problem:</span> '+pI.map(function(i){return CONTENT_TYPES[i.typeId]?CONTENT_TYPES[i.typeId].label:i.typeId;}).join(', ');
            if(sI.length){if(sum)sum+=' &nbsp;|&nbsp; ';sum+='<span style="color:#3b82f6;font-weight:600">Solution:</span> '+sI.map(function(i){return CONTENT_TYPES[i.typeId]?CONTENT_TYPES[i.typeId].label:i.typeId;}).join(', ');}

            var queueHtml='';
            items.forEach(function(gi,idx){
                var ct=CONTENT_TYPES[gi.typeId],fl=gi.focus===FP?'P':'S',fc=gi.focus===FP?'#dc4545':'#3b82f6';
                var td=self.assets[pid]&&self.assets[pid][gi.focus]&&self.assets[pid][gi.focus].types&&self.assets[pid][gi.focus].types[gi.typeId];
                var icon='<i class="fas fa-circle" style="font-size:6px;color:#ccc"></i>',stxt='Pending',sc='#aaa';
                if(td&&(td.status==='approved'||td.status==='downloaded')){icon='<i class="fas fa-check-circle" style="font-size:11px;color:#43a047"></i>';stxt='Done';sc='#43a047';}
                else if(td&&td.status==='draft'){icon='<i class="fas fa-file-alt" style="font-size:11px;color:#1565c0"></i>';stxt='Content ready';sc='#1565c0';}
                else if(td&&td.status==='outline'){icon='<i class="fas fa-list" style="font-size:11px;color:#f57c00"></i>';stxt='Outline ready';sc='#f57c00';}
                else if(idx===self.batchIndex){icon='<i class="fas fa-spinner fa-spin" style="font-size:11px;color:#f57c00"></i>';stxt='Active';sc='#f57c00';}
                var cur=idx===self.batchIndex;
                queueHtml+='<div class="jc-q-item" data-index="'+idx+'" style="display:flex;align-items:center;gap:8px;padding:6px 14px;font-size:12px;border-radius:6px;background:'+(cur?'#f0f4ff':'transparent')+';border:1px solid '+(cur?'#d0d9ec':'transparent')+';white-space:nowrap">'
                  +icon+'<span style="font-size:10px;font-weight:700;color:'+fc+'">'+fl+'</span><i class="'+ct.icon+'" style="color:'+ct.color+';width:14px;text-align:center;font-size:12px"></i><span>'+ct.label+'</span><span class="jc-q-status" style="font-size:11px;color:'+sc+'">'+stxt+'</span></div>';
            });

            return '<div style="padding:14px 18px;margin-bottom:18px;background:linear-gradient(135deg,#f8f9fa,#eef2f7);border-radius:10px;border:1px solid #e0e4ea"><div style="display:flex;align-items:center;justify-content:space-between"><div>'
              +'<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px"><span style="background:#dc4545;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:3px;text-transform:uppercase">Problem</span><span style="font-weight:600;font-size:14px;color:#333">'+esc(item.problemTitle)+'</span></div>'
              +'<div style="display:flex;align-items:center;gap:6px"><span style="background:#3b82f6;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:3px;text-transform:uppercase">Solution</span><span style="font-size:13px;color:#555">'+esc(item.solutionTitle)+'</span></div>'
              +'</div><button type="button" class="button button-small" id="jc-back-btn"><i class="fas fa-arrow-left"></i> Back</button></div>'
              +'<div style="margin-top:10px;font-size:12px;color:#666">Generating: '+sum+'</div></div>'
              +'<div style="background:#fff;border:1px solid #eee;border-radius:8px;padding:8px 10px;margin-bottom:14px"><div style="display:flex;align-items:center;gap:6px;margin-bottom:6px"><span style="font-size:11px;font-weight:700;color:#8c91a0;text-transform:uppercase;letter-spacing:1px">Queue</span></div><div id="jc-queue" style="display:flex;flex-wrap:wrap;gap:6px">'+queueHtml+'</div></div>'
              +'<div style="width:100%"><div id="jc-work" style="background:#fff;border:1px solid #eee;border-radius:8px;min-height:300px"><div style="padding:40px;text-align:center;color:#aaa"><i class="fas fa-hourglass-start" style="font-size:2em;margin-bottom:12px;display:block"></i><p>Starting...</p></div></div>'
              +'<div id="jc-fb" style="padding:14px 0;margin-top:14px;display:none"><textarea id="jc-fb-input" rows="3" placeholder="Provide feedback..." style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;margin-bottom:10px"></textarea><div id="jc-actions" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px"></div></div>'
              +'</div>';
        }

        _bindPanel(panel) {
            var self=this;
            var b=panel.querySelector('#jc-back-btn');
            if(b) b.addEventListener('click',function(){ self.currentItem=null;self.currentTypes=[];self.batchIndex=0; var g=document.getElementById('jc-asset-grid'),p=document.getElementById('jc-asset-creation-panel'); if(p)p.style.display='none'; if(g){g.style.display='';self._renderGrid(g);} });
        }

        // =================================================================
        // BATCH ORCHESTRATOR
        // =================================================================
        async _processNext() {
            var items=this.currentItem?this.currentItem.genItems:[];
            if(this.batchIndex>=items.length){this._showComplete();return;}
            var gi=items[this.batchIndex],pid=this.currentItem.problemId;
            var td=this.assets[pid]&&this.assets[pid][gi.focus]&&this.assets[pid][gi.focus].types&&this.assets[pid][gi.focus].types[gi.typeId];
            this._updateQueue();
            // Skip already approved
            if(td&&(td.status==='approved'||td.status==='downloaded')){this.batchIndex++;this._processNext();return;}
            // Resume at outline review if outline exists
            if(td&&td.outline&&td.status==='outline'){this._showOutline(gi,td.outline);return;}
            // Resume at content review if content exists
            if(td&&td.content&&td.status==='draft'){this._showContent(gi);return;}
            // Generate outline
            var ct=CONTENT_TYPES[gi.typeId];
            this._showLoading('Generating outline',ct);
            try {
                var state=this.workflow.getState();
                var outline=await this._apiOutline(state,gi.focus,ct.apiFormat);
                if(!this.assets[pid][gi.focus].types[gi.typeId]) this.assets[pid][gi.focus].types[gi.typeId]={};
                Object.assign(this.assets[pid][gi.focus].types[gi.typeId],{outline:outline,status:'outline',format:ct.apiFormat,focus:gi.focus,updatedAt:new Date().toISOString()});
                this.workflow.updateState('contentAssets',this.assets);
                this._updateQueue();
                this._showOutline(gi,outline);
            } catch(err) { this._showError('Outline generation failed: '+err.message,gi); }
        }

        _skip() { this.batchIndex++; this._updateQueue(); this._processNext(); }

        // =================================================================
        // LOADING
        // =================================================================
        _showLoading(label,ct) {
            var w=document.getElementById('jc-work'),fb=document.getElementById('jc-fb');
            if(fb) fb.style.display='none';
            if(!w) return;
            w.innerHTML='<div style="padding:50px;text-align:center"><div class="jc-loading-spinner" style="width:36px;height:36px;margin:0 auto 16px"></div><div style="font-size:16px;font-weight:600;color:#333;margin-bottom:6px">'+esc(label)+'</div><div style="font-size:13px;color:#888"><i class="'+ct.icon+'" style="color:'+ct.color+'"></i> '+ct.label+'</div></div>';
        }

        // =================================================================
        // OUTLINE REVIEW
        // =================================================================
        _showOutline(gi, outline) {
            var w=document.getElementById('jc-work'),fb=document.getElementById('jc-fb');
            if(!w) return;
            var ct=CONTENT_TYPES[gi.typeId],isP=gi.focus===FP;
            var fc=isP?'#dc4545':'#3b82f6',fbg=isP?'#fef2f2':'#eff6ff',fbd=isP?'#fecaca':'#bfdbfe',fl=isP?'Problem-focused':'Solution-focused';
            w.innerHTML='<div style="padding:20px">'
              +'<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px"><i class="fas fa-list-ul" style="font-size:18px;color:#f57c00"></i><span style="font-size:16px;font-weight:700;color:#333">Outline Review</span><span style="font-size:12px;font-weight:600;padding:3px 10px;border-radius:100px;background:'+fbg+';color:'+fc+';border:1px solid '+fbd+'">'+fl+'</span><span style="font-size:12px;font-weight:500;color:#666"><i class="'+ct.icon+'" style="color:'+ct.color+'"></i> '+ct.label+'</span></div>'
              +'<div style="background:#fffbf0;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#92400e"><i class="fas fa-info-circle" style="margin-right:6px"></i>Review this outline. Provide feedback to revise, or approve to generate full content.</div>'
              +'<div style="background:#fff;border:1px solid #eee;border-radius:8px;padding:20px;max-height:400px;overflow-y:auto;font-size:14px;line-height:1.7">'+this._fmtOutline(outline)+'</div></div>';
            if(fb){
                fb.style.display='';
                var inp=fb.querySelector('#jc-fb-input'); if(inp){inp.value='';inp.placeholder='Provide feedback on this outline...';}
                var btns=fb.querySelector('#jc-actions');
                if(btns) btns.innerHTML='<div style="display:flex;gap:8px"><button type="button" class="button button-secondary" id="jc-act-revise-outline"><i class="fas fa-edit"></i> Revise Outline</button><button type="button" class="button button-secondary" id="jc-act-regen" title="Discard this outline and generate a new one from scratch"><i class="fas fa-redo"></i> Regenerate</button></div><div style="display:flex;gap:8px"><button type="button" class="button" id="jc-act-approve-outline" style="background:#43a047;color:#fff;border-color:#388e3c"><i class="fas fa-check"></i> Approve & Generate Content</button><button type="button" class="button" id="jc-act-skip" style="color:#888;border-color:#ddd"><i class="fas fa-forward"></i> Skip</button><button type="button" class="button" id="jc-act-delete" style="color:#dc4545;border-color:#fecaca" title="Delete this content type entirely"><i class="fas fa-trash-alt"></i> Delete</button></div>';
                var self=this;
                var r=document.getElementById('jc-act-revise-outline'); if(r) r.addEventListener('click',function(){self._revOutline(gi);});
                var a=document.getElementById('jc-act-approve-outline'); if(a) a.addEventListener('click',function(){self._approveOutline(gi);});
                var s=document.getElementById('jc-act-skip'); if(s) s.addEventListener('click',function(){self._skip();});
                var rg=document.getElementById('jc-act-regen'); if(rg) rg.addEventListener('click',function(){self._regenerate(gi);});
                var del=document.getElementById('jc-act-delete'); if(del) del.addEventListener('click',function(){self._deleteContent(gi);});
            }
        }

        _fmtOutline(outline) {
            if(!outline) return '<p style="color:#aaa">No outline generated.</p>';

            // If already an object/array (not a string), render directly
            if(typeof outline==='object' && outline!==null) return this._renderOutlineObj(outline);

            var raw=String(outline).trim();

            // Strip markdown code fences
            raw=raw.replace(/^```(?:json)?\s*/i,'').replace(/\s*```\s*$/,'').trim();

            // Attempt to parse — try multiple strategies
            var parsed=this._tryParseJson(raw);

            // If it's a JSON object/array, render structured
            if(parsed && typeof parsed==='object') return this._renderOutlineObj(parsed);

            // Plain text fallback — parse markdown-style headings and bullets
            var lines=raw.split('\n'),html='';
            lines.forEach(function(line){
                var t=line.trim();
                if(!t){html+='<br>';return;}
                // Strip markdown bold/italic for display but keep the text
                var display = t.replace(/\*\*\*(.*?)\*\*\*/g,'$1').replace(/\*\*(.*?)\*\*/g,'$1').replace(/\*(.*?)\*/g,'$1');
                // Headings: # or ## or ###
                if(/^#{1,3}\s/.test(t)){
                    var lv=t.match(/^(#+)/)[1].length;
                    var text=display.replace(/^#+\s*/,'');
                    html+='<div style="font-weight:700;font-size:'+(18-lv*2)+'px;margin:12px 0 6px;color:#333">'+esc(text)+'</div>';
                }
                // Bullets: - or * or • (but NOT ** which is bold markdown)
                else if(/^[-\u2022]\s/.test(t) || /^\*\s/.test(t)){
                    var text=display.replace(/^[-\u2022]\s*/,'').replace(/^\*\s*/,'');
                    html+='<div style="padding-left:20px;margin:4px 0"><span style="color:#42A5F5;margin-right:8px">\u2022</span>'+esc(text)+'</div>';
                }
                // Sub-bullets: indented with spaces/tabs + - or * or •
                else if(/^\s{2,}[-*\u2022]\s/.test(line)){
                    var text=display.replace(/^\s+[-*\u2022]\s*/,'');
                    html+='<div style="padding-left:40px;margin:3px 0"><span style="color:#90CAF9;margin-right:8px">\u25E6</span>'+esc(text)+'</div>';
                }
                // Numbered items: 1. or 1)
                else if(/^\d+[.)]\s/.test(t)){
                    var n=t.match(/^(\d+)/)[1];
                    var text=display.replace(/^\d+[.)]\s*/,'');
                    html+='<div style="padding-left:20px;margin:4px 0"><span style="color:#42A5F5;font-weight:600;margin-right:8px">'+n+'.</span>'+esc(text)+'</div>';
                }
                // Bold lines (likely section headers): **text** or **text:**
                else if(/^\*\*/.test(t)){
                    var text=display;
                    html+='<div style="font-weight:700;font-size:14px;margin:12px 0 4px;color:#333">'+esc(text)+'</div>';
                }
                // Regular text
                else {html+='<div style="margin:4px 0">'+esc(display)+'</div>';}
            });
            return html;
        }

        /**
         * Robust JSON parser — handles double-encoding, trailing commas,
         * and truncated Gemini responses (missing closing brackets).
         */
        _tryParseJson(str) {
            if(!str) return null;

            // Strategy 1: direct parse (handles well-formed JSON)
            var cleaned=str.replace(/,\s*([\]\}])/g,'$1');
            try {
                var p=JSON.parse(cleaned);
                // Handle double-encoded: first parse returned a string
                if(typeof p==='string') try { p=JSON.parse(p); } catch(e){}
                if(typeof p==='object'&&p!==null) return p;
            } catch(e){}

            // Strategy 2: repair truncated JSON (Gemini cut off mid-response)
            // Only attempt if it looks like JSON (starts with [ or {)
            if(/^\[/.test(cleaned)||/^\{/.test(cleaned)) {
                var repaired=this._repairJson(cleaned);
                if(repaired) {
                    try {
                        var p2=JSON.parse(repaired);
                        if(typeof p2==='string') try { p2=JSON.parse(p2); } catch(e){}
                        if(typeof p2==='object'&&p2!==null) return p2;
                    } catch(e){}
                }
            }

            // Strategy 3: if it's a double-encoded string that itself failed,
            // try unescaping manually
            if(str.charAt(0)==='"') {
                try {
                    var unquoted=JSON.parse(str); // removes outer quotes + unescapes
                    if(typeof unquoted==='string') return this._tryParseJson(unquoted);
                } catch(e){}
            }

            return null;
        }

        /**
         * Attempt to repair truncated JSON by closing open brackets/braces/strings.
         * Handles the common Gemini issue of cutting off mid-array or mid-object.
         */
        _repairJson(str) {
            // Remove any trailing incomplete key-value (e.g. ,"speaker_notes": "some text that got cut)
            // Find the last complete value: look for last }, ] or complete "string"
            var s=str;
            // If inside an unclosed string, close it
            var inString=false, lastGoodPos=0, depth={'{':0,'[':0};
            for(var i=0;i<s.length;i++){
                var c=s[i];
                if(inString){
                    if(c==='\\'){ i++; continue; } // skip escaped char
                    if(c==='"') inString=false;
                    continue;
                }
                if(c==='"') { inString=true; continue; }
                if(c==='{') depth['{']++;
                else if(c==='}') { depth['{']--; lastGoodPos=i; }
                else if(c==='[') depth['[']++;
                else if(c===']') { depth['[']--; lastGoodPos=i; }
                else if(c===','||c===':') lastGoodPos=i;
            }

            // If we're inside an unclosed string, truncate to before that string value started
            // Find the last complete object in the array
            if(inString || depth['{']>0 || depth['{']<0 || depth['[']!==0) {
                // Approach: find last '}' that isn't inside a string, trim after it, then close brackets
                var result='';
                var inStr2=false, braces=0, brackets=0, lastCloseBrace=-1, lastCloseBracket=-1;
                for(var j=0;j<s.length;j++){
                    var ch=s[j];
                    if(inStr2){
                        if(ch==='\\'){ j++; continue; }
                        if(ch==='"') inStr2=false;
                        continue;
                    }
                    if(ch==='"') { inStr2=true; continue; }
                    if(ch==='{') braces++;
                    else if(ch==='}') { braces--; if(braces===0) lastCloseBrace=j; }
                    else if(ch==='[') brackets++;
                    else if(ch===']') { brackets--; lastCloseBracket=j; }
                }

                // Trim to last complete top-level object (for arrays of slide objects)
                if(lastCloseBrace>0 && s.charAt(0)==='[') {
                    result=s.substring(0,lastCloseBrace+1);
                    // Remove any trailing comma
                    result=result.replace(/,\s*$/,'');
                    // Close the outer array
                    result+=']';
                    return result;
                }
                // For top-level objects
                if(lastCloseBracket>0 && s.charAt(0)==='{') {
                    result=s.substring(0,lastCloseBracket+1);
                    result=result.replace(/,\s*$/,'');
                    result+='}';
                    return result;
                }
            }

            // Simple bracket closing if counts are just off
            var result2=s;
            // Count open/close
            var ob=0,cb=0,os=0,cs=0;
            inString=false;
            for(var k=0;k<result2.length;k++){
                var cc=result2[k];
                if(inString){if(cc==='\\'){ k++; continue; } if(cc==='"') inString=false; continue;}
                if(cc==='"') { inString=true; continue; }
                if(cc==='{') ob++; else if(cc==='}') cb++;
                if(cc==='[') os++; else if(cc===']') cs++;
            }
            if(inString) result2+='"';
            while(cb<ob) { result2+='}'; cb++; }
            while(cs<os) { result2+=']'; cs++; }
            return result2;
        }

        /**
         * Render a JSON outline object as a structured bulleted list.
         * Handles all known formats: article/blog (sections), presentation (slides), linkedin, infographic.
         */
        _renderOutlineObj(obj) {
            var h='';

            // ---- Unwrap {slides:[...]} wrapper (common Gemini presentation response) ----
            if(!Array.isArray(obj) && obj.slides && Array.isArray(obj.slides)) {
                // Presentation wrapped in {slides: [...], title?: "...", ...}
                if(obj.title) h+='<div style="font-weight:700;font-size:16px;color:#1e293b;margin-bottom:12px">'+esc(obj.title)+'</div>';
                return h + this._renderSlides(obj.slides);
            }

            // ---- Array: could be presentation slides OR an unwrapped sections array ----
            if(Array.isArray(obj)) {
                // Detect if this is truly a presentation (slides have slide_title or slide_number)
                var looksLikeSlides=obj.length>0 && obj.some(function(item){ return item.slide_title || item.slide_number || item.speaker_notes; });

                if(looksLikeSlides) {
                    return this._renderSlides(obj);
                }

                // Otherwise treat as unwrapped sections array (article/blog/infographic returned without wrapper)
                return this._renderOutlineObj({ sections: obj });
            }

            // ---- Title / headline ----
            if(obj.title) h+='<div style="font-weight:700;font-size:16px;color:#1e293b;margin-bottom:4px">'+esc(obj.title)+'</div>';
            if(obj.subtitle) h+='<div style="font-size:13px;color:#666;margin-bottom:8px">'+esc(obj.subtitle)+'</div>';
            if(obj.meta_description) h+='<div style="font-size:13px;color:#666;font-style:italic;margin-bottom:12px">'+esc(obj.meta_description)+'</div>';

            // ---- LinkedIn post ----
            if(obj.hook) {
                h+='<div style="font-weight:600;font-size:14px;color:#333;margin-bottom:8px">'+esc(obj.hook)+'</div>';
                var body=obj.body||[];
                if(Array.isArray(body)) body.forEach(function(p){ h+='<div style="padding-left:20px;margin:4px 0"><span style="color:#42A5F5;margin-right:8px">\u2022</span>'+esc(p)+'</div>'; });
                if(obj.call_to_action) h+='<div style="margin-top:8px;font-weight:600;color:#43a047"><i class="fas fa-bullhorn" style="margin-right:6px"></i>'+esc(obj.call_to_action)+'</div>';
                if(obj.hashtags&&obj.hashtags.length) h+='<div style="margin-top:6px;font-size:12px;color:#1976d2">'+obj.hashtags.map(function(t){return esc(t);}).join(' ')+'</div>';
                return h;
            }

            // ---- Sections-based (article, blog, infographic) ----
            var sections=obj.sections||[];
            if(sections.length) {
                sections.forEach(function(sec,i){
                    var heading=sec.heading||sec.title||('Section '+(i+1));
                    h+='<div style="margin-bottom:14px">';
                    h+='<div style="font-weight:700;font-size:14px;color:#333;margin-bottom:4px">'+(i+1)+'. '+esc(heading)+'</div>';
                    if(sec.description) h+='<div style="padding-left:20px;font-size:13px;color:#555;margin-bottom:4px">'+esc(sec.description)+'</div>';
                    // Paragraphs
                    var paras=sec.paragraphs||sec.points||sec.key_points||sec.bullet_points||[];
                    if(Array.isArray(paras)) paras.forEach(function(p){ h+='<div style="padding-left:20px;margin:3px 0"><span style="color:#42A5F5;margin-right:8px">\u2022</span>'+esc(typeof p==='string'?p:(p.text||p.point||JSON.stringify(p)))+'</div>'; });
                    // Key takeaway
                    if(sec.key_takeaway) h+='<div style="padding-left:20px;margin-top:4px;font-size:12px;color:#f57c00"><i class="fas fa-lightbulb" style="margin-right:4px"></i><strong>Key takeaway:</strong> '+esc(sec.key_takeaway)+'</div>';
                    // Infographic data points
                    var dp=sec.data_points||[];
                    if(dp.length) dp.forEach(function(d){ h+='<div style="padding-left:20px;margin:3px 0"><span style="color:#66bb6a;margin-right:8px">\u25B8</span><strong>'+esc(d.label||'')+'</strong>: '+esc(d.value||'')+'</div>'; });
                    h+='</div>';
                });
            }

            // CTA
            if(obj.call_to_action) h+='<div style="margin-top:8px;font-weight:600;color:#43a047"><i class="fas fa-bullhorn" style="margin-right:6px"></i>'+esc(obj.call_to_action)+'</div>';
            if(obj.footer) h+='<div style="margin-top:6px;font-size:12px;color:#666">'+esc(obj.footer)+'</div>';

            // Fallback: if we got an object but didn't match any known shape, render key-value pairs
            if(!h) {
                Object.keys(obj).forEach(function(key){
                    var val=obj[key];
                    h+='<div style="margin-bottom:8px"><strong style="color:#333">'+esc(key.replace(/_/g,' '))+':</strong> ';
                    if(Array.isArray(val)) { h+='<ul style="margin:4px 0 4px 20px;padding:0;list-style:disc">'; val.forEach(function(v){ h+='<li style="margin:2px 0">'+esc(typeof v==='string'?v:JSON.stringify(v))+'</li>'; }); h+='</ul>'; }
                    else { h+=esc(typeof val==='string'?val:JSON.stringify(val)); }
                    h+='</div>';
                });
            }

            return h;
        }

        /**
         * Render an array of presentation slide objects as visual card-style outline.
         * Each slide shows its number, section badge, title, bullet points, and speaker notes.
         */
        _renderSlides(slides) {
            if(!slides||!slides.length) return '<p style="color:#aaa">No slides in outline.</p>';
            var sectionColors={
                'title slide':'#42A5F5','problem definition':'#EF5350','problem amplification':'#EF5350',
                'solution overview':'#66BB6A','solution details':'#66BB6A','benefits summary':'#42A5F5',
                'credibility':'#AB47BC','call to action':'#FF7043'
            };
            var h='<div style="display:flex;flex-direction:column;gap:12px">';
            for(var i=0;i<slides.length;i++){
                var slide=slides[i];
                var title=slide.slide_title||slide.title||('Slide '+(i+1));
                var num=slide.slide_number||(i+1);
                var section=slide.section||'';
                var secLower=section.toLowerCase();
                var secColor=sectionColors[secLower]||'#42A5F5';
                // Find partial match if exact fails
                if(!sectionColors[secLower]){
                    if(secLower.indexOf('problem')!==-1) secColor='#EF5350';
                    else if(secLower.indexOf('solution')!==-1) secColor='#66BB6A';
                    else if(secLower.indexOf('benefit')!==-1) secColor='#42A5F5';
                    else if(secLower.indexOf('action')!==-1||secLower.indexOf('cta')!==-1) secColor='#FF7043';
                    else if(secLower.indexOf('credib')!==-1) secColor='#AB47BC';
                }
                h+='<div style="background:#fff;border:1px solid #eee;border-radius:8px;border-left:4px solid '+secColor+';padding:12px 16px">';
                h+='<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">';
                h+='<span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:'+secColor+';color:#fff;font-size:11px;font-weight:700;flex-shrink:0">'+num+'</span>';
                if(section) h+='<span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:100px;background:'+secColor+'15;color:'+secColor+';border:1px solid '+secColor+'30;text-transform:uppercase;letter-spacing:.5px">'+esc(section)+'</span>';
                h+='</div>';
                h+='<div style="font-weight:600;font-size:14px;color:#1e293b;margin-bottom:6px">'+esc(title)+'</div>';
                var pts=slide.key_points||slide.bullet_points||slide.points||[];
                if(pts.length){
                    for(var p=0;p<pts.length;p++){
                        var pt=typeof pts[p]==='string'?pts[p]:(pts[p].text||pts[p].point||JSON.stringify(pts[p]));
                        h+='<div style="display:flex;align-items:flex-start;gap:8px;padding-left:4px;margin:3px 0;font-size:13px;color:#555;line-height:1.4"><span style="color:'+secColor+';margin-top:2px;flex-shrink:0;font-size:7px"><i class="fas fa-circle"></i></span><span>'+esc(pt)+'</span></div>';
                    }
                }
                if(slide.data_points&&slide.data_points.length){
                    h+='<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">';
                    for(var d=0;d<slide.data_points.length;d++){
                        h+='<span style="font-size:10px;padding:2px 8px;border-radius:100px;background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0"><i class="fas fa-chart-bar" style="margin-right:3px;color:'+secColor+'"></i>'+esc(slide.data_points[d])+'</span>';
                    }
                    h+='</div>';
                }
                if(slide.speaker_notes){
                    h+='<div style="margin-top:8px;padding:8px 10px;background:#f8f9fa;border-radius:5px;border:1px solid #eef0f4;font-size:11px;color:#888;line-height:1.4"><i class="fas fa-comment-dots" style="margin-right:4px;color:#bbb"></i>'+esc(slide.speaker_notes)+'</div>';
                }
                h+='</div>';
            }
            h+='</div>';
            return h;
        }

        async _revOutline(gi) {
            var inp=document.getElementById('jc-fb-input'),fb=inp?inp.value.trim():'';
            if(!fb){if(inp)inp.style.borderColor='#e53935';return;} if(inp)inp.style.borderColor='#ddd';
            var ct=CONTENT_TYPES[gi.typeId],pid=this.currentItem.problemId;
            this._showLoading('Revising outline',ct);
            try {
                var state=this.workflow.getState(),cur=this.assets[pid][gi.focus].types[gi.typeId].outline||'';
                var outline=await this._apiOutline(state,gi.focus,ct.apiFormat,cur,fb);
                this.assets[pid][gi.focus].types[gi.typeId].outline=outline;
                this.assets[pid][gi.focus].types[gi.typeId].updatedAt=new Date().toISOString();
                this.workflow.updateState('contentAssets',this.assets);
                this._showOutline(gi,outline);
            } catch(err){this._showError('Outline revision failed: '+err.message,gi);}
        }

        async _approveOutline(gi) {
            var ct=CONTENT_TYPES[gi.typeId],pid=this.currentItem.problemId,td=this.assets[pid][gi.focus].types[gi.typeId];
            this._showLoading('Generating full content',ct);
            try {
                var state=this.workflow.getState();
                var content=await this._apiContent(state,gi.focus,ct.apiFormat,td.outline);
                this.assets[pid][gi.focus].types[gi.typeId].content=content;
                this.assets[pid][gi.focus].types[gi.typeId].status='draft';
                this.assets[pid][gi.focus].types[gi.typeId].updatedAt=new Date().toISOString();
                this.workflow.updateState('contentAssets',this.assets);
                this._updateQueue();
                this._showContent(gi);
            } catch(err){this._showError('Content generation failed: '+err.message,gi);}
        }

        // =================================================================
        // CONTENT REVIEW
        // =================================================================
        _showContent(gi) {
            var w=document.getElementById('jc-work'),fb=document.getElementById('jc-fb');
            if(!w) return;
            var pid=this.currentItem.problemId,ct=CONTENT_TYPES[gi.typeId];
            var td=this.assets[pid]&&this.assets[pid][gi.focus]&&this.assets[pid][gi.focus].types&&this.assets[pid][gi.focus].types[gi.typeId];
            var isP=gi.focus===FP,fc=isP?'#dc4545':'#3b82f6',fbg=isP?'#fef2f2':'#eff6ff',fbd=isP?'#fecaca':'#bfdbfe',fl=isP?'Problem-focused':'Solution-focused';
            var isApp=td&&(td.status==='approved'||td.status==='downloaded');
            w.innerHTML='<div style="padding:20px"><div style="display:flex;align-items:center;gap:10px;margin-bottom:16px"><i class="fas fa-file-alt" style="font-size:18px;color:#1565c0"></i><span style="font-size:16px;font-weight:700;color:#333">Content Review</span><span style="font-size:12px;font-weight:600;padding:3px 10px;border-radius:100px;background:'+fbg+';color:'+fc+';border:1px solid '+fbd+'">'+fl+'</span><span style="font-size:12px;font-weight:500;color:#666"><i class="'+ct.icon+'" style="color:'+ct.color+'"></i> '+ct.label+'</span>'+(isApp?'<span style="font-size:12px;font-weight:600;padding:3px 10px;border-radius:100px;background:#dcfce7;color:#16a34a"><i class="fas fa-check-circle"></i> Approved</span>':'')+'</div><div id="jc-preview" style="max-height:500px;overflow-y:auto"></div></div>';
            var prev=w.querySelector('#jc-preview');
            if(td&&td.content&&window.JCContentRenderer&&ct&&ct.apiFormat){window.JCContentRenderer.renderPreview(td.content,ct.apiFormat,prev);}
            else if(td&&td.content){prev.innerHTML='<div style="padding:20px;background:#fff;border:1px solid #eee;border-radius:8px;font-size:14px;line-height:1.7">'+td.content+'</div>';}
            else {prev.innerHTML='<div style="padding:20px;text-align:center;color:#aaa">No content generated.</div>';}
            if(fb){
                fb.style.display='';
                var inp=fb.querySelector('#jc-fb-input');if(inp){inp.value='';inp.placeholder='Provide feedback to revise this content...';}
                var hasNext=this.batchIndex<this.currentItem.genItems.length-1;
                var btns=fb.querySelector('#jc-actions');
                if(btns) btns.innerHTML='<div style="display:flex;gap:8px"><button type="button" class="button button-secondary" id="jc-act-revise"><i class="fas fa-edit"></i> Revise</button><button type="button" class="button button-secondary" id="jc-act-dl"><i class="fas fa-download"></i> Download</button><button type="button" class="button button-secondary" id="jc-act-regen" title="Discard and regenerate from scratch"><i class="fas fa-redo"></i> Regenerate</button></div><div style="display:flex;gap:8px"><button type="button" class="button" id="jc-act-approve" style="background:#43a047;color:#fff;border-color:#388e3c"><i class="fas fa-check"></i> Approve'+(hasNext?' & Next':'')+'</button><button type="button" class="button" id="jc-act-skip" style="color:#888;border-color:#ddd"><i class="fas fa-forward"></i> Skip</button><button type="button" class="button" id="jc-act-delete" style="color:#dc4545;border-color:#fecaca" title="Delete this content type entirely"><i class="fas fa-trash-alt"></i> Delete</button></div>';
                var self=this;
                var rv=document.getElementById('jc-act-revise');if(rv)rv.addEventListener('click',function(){self._revContent(gi);});
                var dl=document.getElementById('jc-act-dl');if(dl)dl.addEventListener('click',function(){self._dlContent(gi);});
                var ap=document.getElementById('jc-act-approve');if(ap)ap.addEventListener('click',function(){self._approveContent(gi);});
                var sk=document.getElementById('jc-act-skip');if(sk)sk.addEventListener('click',function(){self._skip();});
                var rg=document.getElementById('jc-act-regen');if(rg)rg.addEventListener('click',function(){self._regenerate(gi);});
                var del=document.getElementById('jc-act-delete');if(del)del.addEventListener('click',function(){self._deleteContent(gi);});
            }
        }

        async _revContent(gi) {
            var inp=document.getElementById('jc-fb-input'),fb=inp?inp.value.trim():'';
            if(!fb){if(inp)inp.style.borderColor='#e53935';return;} if(inp)inp.style.borderColor='#ddd';
            var ct=CONTENT_TYPES[gi.typeId],pid=this.currentItem.problemId,td=this.assets[pid][gi.focus].types[gi.typeId];
            this._showLoading('Revising content',ct);
            try {
                var state=this.workflow.getState();
                var instr=gi.focus===FP?'Maintain PROBLEM focus: pain points, challenges, consequences.':'Maintain SOLUTION focus: approach, benefits, implementation.';
                var resp=await fetch(this.apiBase+'/ai/generate-content',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':this.nonce},body:JSON.stringify({problem_title:this.currentItem.problemTitle,solution_title:this.currentItem.solutionTitle,format:ct.apiFormat,outline:td.outline||'',existing_content:td.content||'',feedback:fb,focus:gi.focus,focus_instruction:instr,service_area_id:state.serviceAreaId||0,industries:state.industries||[],brain_content:state.brainContent||[]})});
                var data=await resp.json();
                if(data.success&&data.content){td.content=data.content;td.status='draft';td.updatedAt=new Date().toISOString();this.workflow.updateState('contentAssets',this.assets);}
            } catch(e){console.error('Revision failed:',e);}
            this._showContent(gi);
        }

        _dlContent(gi) {
            var pid=this.currentItem.problemId,ct=CONTENT_TYPES[gi.typeId],td=this.assets[pid]&&this.assets[pid][gi.focus]&&this.assets[pid][gi.focus].types&&this.assets[pid][gi.focus].types[gi.typeId];
            if(!td||!td.content)return;
            var fn=slugify(this.currentItem.problemTitle)+'-'+(gi.focus===FP?'problem':'solution')+'-'+gi.typeId;
            var meta={problemTitle:this.currentItem.problemTitle,solutionTitle:this.currentItem.solutionTitle,focus:gi.focus};
            if(window.JCContentRenderer&&ct&&ct.apiFormat){window.JCContentRenderer.download(td.content,ct.apiFormat,fn,meta);}
            else{var blob=new Blob([td.content],{type:'text/html'});var url=URL.createObjectURL(blob);var a=document.createElement('a');a.href=url;a.download=fn+'.html';document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(url);}
            td.status='downloaded';this.workflow.updateState('contentAssets',this.assets);
        }

        _approveContent(gi) {
            var pid=this.currentItem.problemId;
            this.assets[pid][gi.focus].types[gi.typeId].status='approved';
            this.assets[pid][gi.focus].types[gi.typeId].updatedAt=new Date().toISOString();
            this.workflow.updateState('contentAssets',this.assets);
            this.workflow.showNotification((CONTENT_TYPES[gi.typeId]?CONTENT_TYPES[gi.typeId].label:gi.typeId)+' approved!','info');
            this.batchIndex++;
            this._updateQueue();
            this._processNext();
        }

        // =================================================================
        // DELETE & REGENERATE
        // =================================================================

        /**
         * Delete generated content for a specific type. Removes all data
         * (outline, content, status) and returns to the grid.
         */
        _deleteContent(gi) {
            var pid=this.currentItem.problemId;
            var ct=CONTENT_TYPES[gi.typeId];
            var label=ct?ct.label:gi.typeId;
            if(!confirm('Delete the generated '+label+' ('+(gi.focus===FP?'Problem':'Solution')+')?\n\nThis will remove the outline and all generated content for this type.')) return;
            // Remove the type data entirely
            if(this.assets[pid]&&this.assets[pid][gi.focus]&&this.assets[pid][gi.focus].types){
                delete this.assets[pid][gi.focus].types[gi.typeId];
            }
            this.workflow.updateState('contentAssets',this.assets);
            this.workflow.showNotification(label+' deleted.','info');
            // Return to grid
            this.currentItem=null;this.currentTypes=[];this.batchIndex=0;
            var g=document.getElementById('jc-asset-grid'),p=document.getElementById('jc-asset-creation-panel');
            if(p) p.style.display='none';
            if(g){g.style.display='';this._renderGrid(g);}
        }

        /**
         * Regenerate content for a specific type from scratch.
         * Clears existing data and runs through the generation pipeline again.
         */
        async _regenerate(gi) {
            var pid=this.currentItem.problemId;
            var ct=CONTENT_TYPES[gi.typeId];
            var label=ct?ct.label:gi.typeId;
            if(!confirm('Regenerate '+label+' ('+(gi.focus===FP?'Problem':'Solution')+') from scratch?\n\nThis will discard the current outline and content.')) return;
            // Clear existing data for this type
            if(this.assets[pid]&&this.assets[pid][gi.focus]&&this.assets[pid][gi.focus].types){
                delete this.assets[pid][gi.focus].types[gi.typeId];
            }
            this.workflow.updateState('contentAssets',this.assets);
            // Generate fresh outline
            this._showLoading('Generating outline',ct);
            try {
                var state=this.workflow.getState();
                var outline=await this._apiOutline(state,gi.focus,ct.apiFormat);
                if(!this.assets[pid][gi.focus].types[gi.typeId]) this.assets[pid][gi.focus].types[gi.typeId]={};
                Object.assign(this.assets[pid][gi.focus].types[gi.typeId],{outline:outline,status:'outline',format:ct.apiFormat,focus:gi.focus,updatedAt:new Date().toISOString()});
                this.workflow.updateState('contentAssets',this.assets);
                this._updateQueue();
                this._showOutline(gi,outline);
            } catch(err) { this._showError('Regeneration failed: '+err.message,gi); }
        }

        // =================================================================
        // ERROR
        // =================================================================
        _showError(msg, gi) {
            var w=document.getElementById('jc-work'),fb=document.getElementById('jc-fb');
            if(!w)return;
            w.innerHTML='<div style="padding:30px;text-align:center"><i class="fas fa-exclamation-triangle" style="font-size:2.5em;color:#e53935;margin-bottom:12px;display:block"></i><div style="font-size:15px;font-weight:600;color:#333;margin-bottom:8px">Generation Failed</div><div style="font-size:13px;color:#888;max-width:400px;margin:0 auto">'+esc(msg)+'</div></div>';
            if(fb){
                fb.style.display='';
                var btns=fb.querySelector('#jc-actions');
                if(btns) btns.innerHTML='<button type="button" class="button button-secondary" id="jc-act-retry"><i class="fas fa-redo"></i> Retry</button><button type="button" class="button" id="jc-act-skip" style="color:#888;border-color:#ddd"><i class="fas fa-forward"></i> Skip</button>';
                var self=this,pid=this.currentItem.problemId;
                var r=document.getElementById('jc-act-retry');if(r)r.addEventListener('click',function(){if(self.assets[pid]&&self.assets[pid][gi.focus]&&self.assets[pid][gi.focus].types)delete self.assets[pid][gi.focus].types[gi.typeId];self._processNext();});
                var s=document.getElementById('jc-act-skip');if(s)s.addEventListener('click',function(){self._skip();});
            }
        }

        // =================================================================
        // BATCH COMPLETE
        // =================================================================
        _showComplete() {
            var w=document.getElementById('jc-work'),fb=document.getElementById('jc-fb');
            if(fb) fb.style.display='none';
            if(!w) return;
            var pid=this.currentItem.problemId,items=this.currentItem.genItems,self=this,approved=0;
            items.forEach(function(gi){var td=self.assets[pid]&&self.assets[pid][gi.focus]&&self.assets[pid][gi.focus].types&&self.assets[pid][gi.focus].types[gi.typeId];if(td&&(td.status==='approved'||td.status==='downloaded'))approved++;});
            w.innerHTML='<div style="padding:40px;text-align:center"><i class="fas fa-check-circle" style="font-size:3em;color:#43a047;margin-bottom:16px;display:block"></i><div style="font-size:18px;font-weight:700;color:#333;margin-bottom:8px">Batch Complete</div><div style="font-size:14px;color:#666;margin-bottom:20px">'+approved+' of '+items.length+' content types approved.</div><button type="button" class="button button-primary" id="jc-done" style="font-size:14px;padding:8px 24px"><i class="fas fa-arrow-left"></i> Return to Grid</button></div>';
            var d=document.getElementById('jc-done');if(d)d.addEventListener('click',function(){self.currentItem=null;self.currentTypes=[];self.batchIndex=0;var g=document.getElementById('jc-asset-grid'),p=document.getElementById('jc-asset-creation-panel');if(p)p.style.display='none';if(g){g.style.display='';self._renderGrid(g);}});
            this._updateQueue();
        }

        // =================================================================
        // QUEUE SIDEBAR UPDATE
        // =================================================================
        _updateQueue() {
            var q=document.getElementById('jc-queue');if(!q)return;
            var items=this.currentItem?this.currentItem.genItems:[],pid=this.currentItem?this.currentItem.problemId:null,self=this;
            q.querySelectorAll('.jc-q-item').forEach(function(el,idx){
                var gi=items[idx];if(!gi)return;
                var td=self.assets[pid]&&self.assets[pid][gi.focus]&&self.assets[pid][gi.focus].types&&self.assets[pid][gi.focus].types[gi.typeId];
                var st=el.querySelector('.jc-q-status'),cur=idx===self.batchIndex;
                el.style.background=cur?'#f0f4ff':'transparent';el.style.borderColor=cur?'#d0d9ec':'transparent';
                if(td&&(td.status==='approved'||td.status==='downloaded')){if(st){st.innerHTML='<i class="fas fa-check-circle" style="color:#43a047"></i>';st.style.color='#43a047';}}
                else if(td&&td.status==='draft'){if(st){st.textContent='Content ready';st.style.color='#1565c0';}}
                else if(td&&td.status==='outline'){if(st){st.textContent='Outline ready';st.style.color='#f57c00';}}
                else if(cur){if(st){st.innerHTML='<i class="fas fa-spinner fa-spin"></i> Active';st.style.color='#f57c00';}}
                else if(idx<self.batchIndex){if(st){st.textContent='Skipped';st.style.color='#aaa';}}
                else{if(st){st.textContent='Pending';st.style.color='#aaa';}}
            });
        }

        // =================================================================
        // API CALLS
        // =================================================================
        async _apiOutline(state, focus, apiFormat, existing, feedback) {
            var item=this.currentItem,instr=focus===FP?'Focus on PROBLEM: pain points, challenges, consequences.':'Focus on SOLUTION: approach, benefits, implementation.';
            var body={problem_title:item.problemTitle,solution_title:item.solutionTitle,format:apiFormat,focus:focus,focus_instruction:instr,service_area_id:state.serviceAreaId||0,industries:state.industries||[],brain_content:state.brainContent||[]};
            if(existing) body.existing_outline=existing;
            if(feedback) body.feedback=feedback;
            var resp=await fetch(this.apiBase+'/ai/generate-outline',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':this.nonce},body:JSON.stringify(body)});
            var data=await resp.json();
            if(data.success&&data.outline) return data.outline;
            throw new Error(data.error||data.message||'Outline generation failed');
        }

        async _apiContent(state, focus, apiFormat, outline) {
            var item=this.currentItem,instr=focus===FP?'PROBLEM angle: pain points, challenges, risks, costs.':'SOLUTION angle: approach, methodology, benefits, ROI, implementation.';
            var resp=await fetch(this.apiBase+'/ai/generate-content',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':this.nonce},body:JSON.stringify({problem_title:item.problemTitle,solution_title:item.solutionTitle,format:apiFormat,outline:outline,focus:focus,focus_instruction:instr,service_area_id:state.serviceAreaId||0,industries:state.industries||[],brain_content:state.brainContent||[]})});
            var data=await resp.json();
            if(data.success&&data.content) return data.content;
            throw new Error(data.error||data.message||'Content generation failed');
        }

        // =================================================================
        // OPEN EXISTING REVIEW (from grid chip click)
        // =================================================================
        _openReview(pid, focus, typeId) {
            var problem=this.selectedProblems.find(function(p){return String(p.id)===pid;});
            if(!problem) return;
            var td=this.assets[pid]&&this.assets[pid][focus]&&this.assets[pid][focus].types&&this.assets[pid][focus].types[typeId];
            if(!td) return;
            this.currentItem={problemId:pid,problemTitle:problem.title,solutionTitle:this.selectedSolutions[pid]||'',genItems:[{typeId:typeId,focus:focus}]};
            this.currentTypes=[focus+':'+typeId];
            this.batchIndex=0;
            if(!this.assets[pid])this.assets[pid]={problem:{types:{}},solution:{types:{}}};
            if(!this.assets[pid].problem)this.assets[pid].problem={types:{}};
            if(!this.assets[pid].solution)this.assets[pid].solution={types:{}};
            this._showPanel();
            var gi={typeId:typeId,focus:focus};
            if(td.content&&(td.status==='draft'||td.status==='approved'||td.status==='downloaded')){this._showContent(gi);}
            else if(td.outline){this._showOutline(gi,td.outline);}
        }

        _openCreated(pid) {
            var self=this;
            for(var fi=0;fi<2;fi++){var f=fi===0?FP:FS;var types=self.assets[pid]&&self.assets[pid][f]&&self.assets[pid][f].types?self.assets[pid][f].types:{};var first=Object.keys(types).find(function(t){return types[t]&&(types[t].status==='approved'||types[t].status==='downloaded');});if(first){self._openReview(pid,f,first);return;}}
        }
    }

    // =================================================================
    $(document).ready(function() {
        if (window.drJourneyCircle) {
            window.drStep9Manager = new Step9Manager(window.drJourneyCircle);
        }
    });

})(jQuery);