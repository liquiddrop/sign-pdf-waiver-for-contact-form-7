/**
 * CF7 PDF Form – Admin JS
 * Visual Placement only.
 */
(function ($) {
    'use strict';

    var mediaFrame = null;
    var cf7Fields  = window.CF7W_CF7Fields || [];

    // ── Prevent CF7 "unsaved changes" dialog ──────────────────────────────────
    var CF7W_OUR_SELECTORS = ['.cf7w-admin-box', '#cf7w-vp-inputs', '#cf7w-step3-section', '.cf7w-admin-section'];

    function isOurElement(el) {
        if ( ! el || ! el.closest ) return false;
        for (var i = 0; i < CF7W_OUR_SELECTORS.length; i++) {
            if ( el.closest(CF7W_OUR_SELECTORS[i]) ) return true;
        }
        return false;
    }

    function clearCF7Dirty() {
        var $form = $('#wpcf7-admin-form-element');
        if ( $form.length ) {
            if ( window.wpcf7 && typeof wpcf7.setFormModified === 'function' ) {
                try { wpcf7.setFormModified(false); } catch(e) {}
            }
            $form.removeData('modified');
            $form.data('modified', false);
            window.onbeforeunload = null;
        }
    }

    ['change', 'input', 'keyup'].forEach(function (evt) {
        document.addEventListener(evt, function (e) {
            if ( isOurElement(e.target) ) {
                e.stopPropagation();
                setTimeout(clearCF7Dirty, 0);
            }
        }, true);
    });

    var cf7wDirtyTimer = null;
    function scheduleClearDirty() {
        clearTimeout(cf7wDirtyTimer);
        cf7wDirtyTimer = setTimeout(clearCF7Dirty, 50);
    }

    var cf7FormBodyAtLoad = '';
    var cf7BodySnapshotTaken = false;

    function getCF7EditorBody() {
        var $ta = $('#wpcf7-form');
        if ( $ta.length && $ta.val() ) return $ta.val();
        if ( typeof CodeMirror !== 'undefined' ) {
            var val = null;
            $('.wpcf7-form-box .CodeMirror').each(function () {
                if ( this.CodeMirror ) { val = this.CodeMirror.getValue(); return false; }
            });
            if ( val ) return val;
        }
        return '';
    }

    function snapshotCF7Body() {
        if ( cf7BodySnapshotTaken ) return;
        var body = getCF7EditorBody();
        if ( body ) { cf7FormBodyAtLoad = body; cf7BodySnapshotTaken = true; }
        clearCF7Dirty();
    }

    window.addEventListener('beforeunload', function (e) {
        var currentBody = getCF7EditorBody();
        if ( cf7FormBodyAtLoad && currentBody === cf7FormBodyAtLoad ) {
            e.stopImmediatePropagation();
            delete e.returnValue;
            return undefined;
        }
    }, true);

    // ── Init ───────────────────────────────────────────────────────────────────
    $(document).ready(function () {
        setTimeout(snapshotCF7Body, 600);
        if ( !initVisualPlacement() ) {
            watchForCanvas();
        }
    });

    $(document).on('click', 'input[name="wpcf7-save"], #publishing-action input[type="submit"]', function () {
        setTimeout(snapshotCF7Body, 1000);
    });

    function watchForCanvas() {
        var done = false;
        function tryInit() {
            if (done) return;
            if (document.getElementById('cf7w-vp-frame-container')) {
                done = true;
                if (observer) observer.disconnect();
                clearInterval(timer);
                initVisualPlacement();
            }
        }
        var polls = 0;
        var timer = setInterval(function() {
            polls++;
            tryInit();
            if (done || polls > 150) clearInterval(timer);
        }, 200);
        var observer = null;
        if (window.MutationObserver) {
            observer = new MutationObserver(tryInit);
            observer.observe(document.body, { childList: true, subtree: true });
        }
        $(document).on('click.cf7w-tab', '.wpcf7-editor-tabs a, #wpcf7-admin-form-element .nav-tab', function() {
            setTimeout(tryInit, 50);
        });
    }

    // ── Upload PDF ─────────────────────────────────────────────────────────────
    $(document).on('click', '#cf7w-upload-btn', function (e) {
        e.preventDefault();
        if ( typeof wp === 'undefined' || ! wp.media ) { alert( CF7W_Admin.media_unavailable ); return; }
        if ( mediaFrame ) {
            mediaFrame.uploader && mediaFrame.uploader.reset && mediaFrame.uploader.reset();
            mediaFrame.setState('library');
            mediaFrame.open();
            return;
        }
        mediaFrame = wp.media({
            title:    CF7W_Admin.upload_title,
            button:   { text: CF7W_Admin.upload_button },
            library:  { type: 'application/pdf' },
            multiple: false,
        });
        mediaFrame.on('select', function () {
            var att = mediaFrame.state().get('selection').first().toJSON();
            $('#cf7w_pdf_attach_id').val(att.id);
            $('#cf7w_pdf_url').val(att.url);
            $('#cf7w-upload-btn').text( CF7W_Admin.replace_pdf );
            var filename = att.url.split('/').pop();
            $('#cf7w-current-pdf-link').attr('href', att.url).text(filename);
            $('#cf7w-current-pdf').show();
            $(document).trigger('cf7w:pdf_changed', [att.url]);
        });
        mediaFrame.open();
    });

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // VISUAL PLACEMENT
    // ══════════════════════════════════════════════════════════════════════════

    var vpLogLines = [];
    function vpLog(msg, colour) {
        colour = colour || '#d4d4d4';
        var ts = new Date().toISOString().substr(11, 8);
        vpLogLines.push('<div style="color:' + colour + '">[' + ts + '] ' + msg + '</div>');
        var el = document.getElementById('cf7w-vp-debug');
        if (el) { el.innerHTML = vpLogLines.join(''); el.scrollTop = el.scrollHeight; }
    }
    function vpOk(m)   { vpLog('\u2713 ' + m, '#4ec9b0'); }
    function vpErr(m)  { vpLog('\u2717 ' + m, '#f48771'); }
    function vpInfo(m) { vpLog('\u2139 ' + m, '#9cdcfe'); }
    function vpWarn(m) { vpLog('\u26a0 ' + m, '#dcdcaa'); }

    var vpPlacements        = [];
    var vpInitDone          = false;
    var vpPdfDoc            = null;
    var vpCurrentPage       = 1;
    var vpTotalPages        = 1;
    var vpCanvas            = null;
    var vpCanvasW           = 800;
    var vpCanvasH           = 1000;
    var vpCurrentRenderTask = null;

    function initVisualPlacement() {
        vpInfo('initVisualPlacement() called');

        var container = document.getElementById('cf7w-vp-frame-container');
        var overlay   = document.getElementById('cf7w-vp-overlay');
        var wrap      = document.getElementById('cf7w-vp-wrap');

        if (!container) { vpErr('#cf7w-vp-frame-container not found'); return false; }
        if (!overlay)   { vpErr('#cf7w-vp-overlay not found');         return false; }
        vpOk('Elements found');

        if (vpInitDone) {
            vpInfo('Re-draw only');
            redrawOverlay(overlay);
            return true;
        }
        vpInitDone = true;

        // Canvas + drag shield
        vpCanvas = document.createElement('canvas');
        vpCanvas.id = 'cf7w-vp-canvas';
        vpCanvas.style.cssText = 'display:block;border:none;';
        container.appendChild(vpCanvas);

        var shield = document.createElement('div');
        shield.id = 'cf7w-vp-shield';
        shield.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;z-index:9998;display:none;cursor:move;';
        wrap.appendChild(shield);
        overlay.style.zIndex = '9999';

        // Page controls
        var pageControls = document.getElementById('cf7w-vp-page-controls');
        if (!pageControls) {
            pageControls = document.createElement('div');
            pageControls.id = 'cf7w-vp-page-controls';
            pageControls.style.cssText = 'display:flex;align-items:center;gap:8px;margin-top:8px;font-size:13px;';
            pageControls.innerHTML =
                '<button type="button" class="button" id="cf7w-vp-prev">&#8592; Prev</button>'
                + '<span id="cf7w-vp-page-label" style="min-width:90px;text-align:center;">Page 1 / 1</span>'
                + '<button type="button" class="button" id="cf7w-vp-next">Next &#8594;</button>'
                + '<span class="description" style="margin-left:8px;">Showing placements for this page only.</span>';
            wrap.parentNode.insertBefore(pageControls, wrap.nextSibling);
        }

        var currentPdfUrl = window.CF7W_PdfUrl || '';
        vpInfo('CF7W_PdfUrl = "' + currentPdfUrl + '"');

        function syncSize() {
            var wi = document.getElementById('cf7w-vp-iframe-w-input');
            var hi = document.getElementById('cf7w-vp-iframe-h-input');
            if (wi) wi.value = vpCanvasW;
            if (hi) hi.value = vpCanvasH;
        }

        function renderPage(pageNum) {
            if (!vpPdfDoc) { vpWarn('renderPage: no PDF loaded'); return; }
            pageNum = Math.max(1, Math.min(pageNum, vpTotalPages));
            vpCurrentPage = pageNum;

            var lbl = document.getElementById('cf7w-vp-page-label');
            if (lbl) lbl.textContent = 'Page ' + vpCurrentPage + ' / ' + vpTotalPages;
            var prevBtn = document.getElementById('cf7w-vp-prev');
            var nextBtn = document.getElementById('cf7w-vp-next');
            if (prevBtn) prevBtn.disabled = vpCurrentPage <= 1;
            if (nextBtn) nextBtn.disabled = vpCurrentPage >= vpTotalPages;

            vpPdfDoc.getPage(pageNum).then(function(page) {
                var viewport = page.getViewport({ scale: 1 });
                var scale    = vpCanvasW / viewport.width;
                var scaledVp = page.getViewport({ scale: scale });

                vpCanvasW = Math.round(scaledVp.width);
                vpCanvasH = Math.round(scaledVp.height);
                vpCanvas.width  = vpCanvasW;
                vpCanvas.height = vpCanvasH;
                vpCanvas.style.width  = vpCanvasW + 'px';
                vpCanvas.style.height = vpCanvasH + 'px';
                wrap.style.width  = vpCanvasW + 'px';
                wrap.style.height = vpCanvasH + 'px';
                overlay.style.width  = vpCanvasW + 'px';
                overlay.style.height = vpCanvasH + 'px';
                shield.style.width   = vpCanvasW + 'px';
                shield.style.height  = vpCanvasH + 'px';
                syncSize();

                // Cancel any in-flight render — concurrent renders corrupt canvas transforms
                if (vpCurrentRenderTask) {
                    try { vpCurrentRenderTask.cancel(); } catch(e) {}
                    vpCurrentRenderTask = null;
                }

                var ctx = vpCanvas.getContext('2d');
                ctx.setTransform(1, 0, 0, 1, 0, 0);
                ctx.clearRect(0, 0, vpCanvasW, vpCanvasH);
                vpCurrentRenderTask = page.render({ canvasContext: ctx, viewport: scaledVp });
                var thisTask = vpCurrentRenderTask;
                vpCurrentRenderTask.promise.then(function() {
                    if (thisTask !== vpCurrentRenderTask && vpCurrentRenderTask !== null) return;
                    vpCurrentRenderTask = null;
                    vpOk('Page ' + pageNum + ' rendered (' + vpCanvasW + '\u00d7' + vpCanvasH + ')');
                    redrawOverlay(overlay);
                }).catch(function(err) {
                    if (err && err.name === 'RenderingCancelledException') return;
                    vpErr('Render error: ' + err);
                    vpCurrentRenderTask = null;
                });
            }).catch(function(err) {
                vpErr('getPage error: ' + err);
            });
        }

        function loadPdf(url) {
            if (!url) { vpWarn('loadPdf: empty URL'); return; }
            vpInfo('Loading PDF: ' + url);
            if (vpCurrentRenderTask) {
                try { vpCurrentRenderTask.cancel(); } catch(e) {}
                vpCurrentRenderTask = null;
            }
            if (typeof pdfjsLib === 'undefined') { vpErr('PDF.js not loaded'); return; }
            // Worker script is bundled locally — assets/vendor/pdfjs/pdf.worker.min.js
            // CF7W_PdfjsWorkerUrl is localised via wp_localize_script in class-admin.php
            pdfjsLib.GlobalWorkerOptions.workerSrc = CF7W_Admin.pdfjs_worker_url;
            pdfjsLib.getDocument(url).promise.then(function(doc) {
                vpPdfDoc     = doc;
                vpTotalPages = doc.numPages;
                vpOk('PDF loaded: ' + vpTotalPages + ' page(s)');
                vpCurrentPage = Math.min(vpCurrentPage, vpTotalPages);
                renderPage(vpCurrentPage);
            }).catch(function(err) {
                vpErr('PDF.js load error: ' + err);
            });
        }

        var COLOURS = ['#2271b1','#d63638','#00a32a','#dba617','#8b5cf6','#ec4899','#0891b2','#f97316','#16a34a','#dc2626'];
        function colourFor(field) {
            var idx = 0;
            for (var i = 0; i < field.length; i++) idx = (idx + field.charCodeAt(i)) % COLOURS.length;
            return COLOURS[idx];
        }

        function createBox(pl) {
            var color = colourFor(pl.cf7_field);
            var box = document.createElement('div');
            box.className = 'cf7w-vp-box';
            box.style.cssText = 'position:absolute;left:'+pl.x+'px;top:'+pl.y+'px;width:'+pl.w+'px;height:'+pl.h+'px;'
                + 'border:2px solid '+color+';background:'+color+'22;cursor:move;box-sizing:border-box;'
                + 'border-radius:2px;pointer-events:all;z-index:10000;';

            var lbl = document.createElement('div');
            lbl.style.cssText = 'position:absolute;inset:0;background:'+color+';color:#fff;font-size:10px;font-weight:600;'
                + 'line-height:1;padding:1px 3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;'
                + 'pointer-events:none;user-select:none;display:flex;align-items:center;border-radius:2px;';
            lbl.textContent = pl.cf7_field;
            box.appendChild(lbl);

            var del = document.createElement('div');
            del.style.cssText = 'position:absolute;top:0;right:0;width:12px;height:12px;background:rgba(0,0,0,0.6);'
                + 'color:#fff;font-size:9px;font-weight:bold;display:flex;align-items:center;justify-content:center;'
                + 'cursor:pointer;border-radius:0 2px 0 3px;z-index:2;line-height:1;';
            del.textContent = '\u00d7';
            del.title = 'Remove';
            del.addEventListener('click', function(e) {
                e.stopPropagation();
                var idx = vpPlacements.indexOf(pl);
                if (idx > -1) vpPlacements.splice(idx, 1);
                if (box.parentNode) box.parentNode.removeChild(box);
                syncInputs();
                vpInfo('Removed "' + pl.cf7_field + '"');
            });
            box.appendChild(del);

            var rh = document.createElement('div');
            rh.className = 'cf7w-vp-resize';
            rh.style.cssText = 'position:absolute;right:0;bottom:0;width:10px;height:10px;background:rgba(0,0,0,0.6);'
                + 'cursor:se-resize;border-radius:0 0 2px 0;z-index:2;overflow:hidden;';
            rh.innerHTML = '<svg width="10" height="10" viewBox="0 0 10 10" style="display:block;pointer-events:none">'
                + '<line x1="2" y1="9" x2="9" y2="2" stroke="white" stroke-width="1.5" stroke-linecap="round" opacity="0.4"/>'
                + '<line x1="5" y1="9" x2="9" y2="5" stroke="white" stroke-width="1.5" stroke-linecap="round"/>'
                + '<line x1="8" y1="9" x2="9" y2="8" stroke="white" stroke-width="1.5" stroke-linecap="round"/>'
                + '</svg>';
            box.appendChild(rh);

            makeDraggable(box, pl, shield);
            makeResizable(rh, box, pl, shield);
            return box;
        }

        function makeDraggable(box, pl, shield) {
            box.addEventListener('mousedown', function(e) {
                if (e.target.classList.contains('cf7w-vp-resize')) return;
                e.preventDefault();
                var startX = e.clientX, startY = e.clientY;
                var startLeft = parseInt(box.style.left, 10), startTop = parseInt(box.style.top, 10);
                shield.style.display = 'block'; shield.style.cursor = 'move';
                function mv(em) {
                    pl.x = Math.max(0, startLeft + em.clientX - startX);
                    pl.y = Math.max(0, startTop  + em.clientY - startY);
                    box.style.left = pl.x + 'px'; box.style.top = pl.y + 'px';
                }
                function up() {
                    shield.style.display = 'none';
                    document.removeEventListener('mousemove', mv);
                    document.removeEventListener('mouseup', up);
                    syncInputs();
                    vpInfo('Moved "' + pl.cf7_field + '" to (' + pl.x + ', ' + pl.y + ')');
                }
                document.addEventListener('mousemove', mv);
                document.addEventListener('mouseup', up);
            });
        }

        function makeResizable(handle, box, pl, shield) {
            handle.addEventListener('mousedown', function(e) {
                e.preventDefault(); e.stopPropagation();
                var startX = e.clientX, startY = e.clientY;
                var sw = parseInt(box.style.width, 10), sh = parseInt(box.style.height, 10);
                shield.style.display = 'block'; shield.style.cursor = 'se-resize';
                function mv(em) {
                    pl.w = Math.max(30, sw + em.clientX - startX);
                    pl.h = Math.max(16, sh + em.clientY - startY);
                    box.style.width = pl.w + 'px'; box.style.height = pl.h + 'px';
                }
                function up() {
                    shield.style.display = 'none';
                    document.removeEventListener('mousemove', mv);
                    document.removeEventListener('mouseup', up);
                    syncInputs();
                }
                document.addEventListener('mousemove', mv);
                document.addEventListener('mouseup', up);
            });
        }

        function syncInputs() {
            scheduleClearDirty();
            var c = document.getElementById('cf7w-vp-inputs');
            if (!c) return;
            var toRemove = [];
            for (var i = 0; i < c.children.length; i++) {
                if (c.children[i].name && c.children[i].name.indexOf('cf7w_vp[') === 0) toRemove.push(c.children[i]);
            }
            toRemove.forEach(function(el) { c.removeChild(el); });
            vpPlacements.forEach(function(pl, i) {
                ['cf7_field','page','x','y','w','h','canvas_w','canvas_h'].forEach(function(k) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'cf7w_vp[' + i + '][' + k + ']';
                    inp.value = pl[k] !== undefined ? pl[k] : 0;
                    c.appendChild(inp);
                });
            });
        }

        function redrawOverlay(ov) {
            ov.innerHTML = '';
            vpPlacements.forEach(function(pl) {
                if ((parseInt(pl.page, 10) || 1) === vpCurrentPage) {
                    pl.el = createBox(pl);
                    ov.appendChild(pl.el);
                } else {
                    pl.el = null;
                }
            });
            vpInfo('Overlay: page ' + vpCurrentPage + ', '
                + vpPlacements.filter(function(p){ return (parseInt(p.page,10)||1) === vpCurrentPage; }).length + ' box(es)');
        }

        // Width selector
        $(document).off('change.cf7wvp-size').on('change.cf7wvp-size', '#cf7w-vp-width', function() {
            vpCanvasW = parseInt(this.value, 10) || 800;
            syncSize();
            if (vpPdfDoc) renderPage(vpCurrentPage);
        });

        // Page nav
        $(document).off('click.cf7wvp-nav').on('click.cf7wvp-nav', '#cf7w-vp-prev', function(e) {
            e.preventDefault(); if (vpCurrentPage > 1) renderPage(vpCurrentPage - 1);
        });
        $(document).off('click.cf7wvp-nav2').on('click.cf7wvp-nav2', '#cf7w-vp-next', function(e) {
            e.preventDefault(); if (vpCurrentPage < vpTotalPages) renderPage(vpCurrentPage + 1);
        });

        // Add field buttons
        $(document).off('click.cf7wvp').on('click.cf7wvp', '.cf7w-vp-add-field', function(e) {
            e.preventDefault();
            var field = this.dataset.field;
            // Offset each new box slightly so stacked boxes are visible
            var existingOnPage = vpPlacements.filter(function(p) {
                return p.cf7_field === field && (parseInt(p.page,10)||1) === vpCurrentPage;
            });
            var offset = existingOnPage.length * 20;
            var pl = { cf7_field: field, page: vpCurrentPage, x: 40 + offset, y: 40 + offset, w: 240, h: 40, canvas_h: vpCanvasH, canvas_w: vpCanvasW, el: null };
            vpPlacements.push(pl);
            pl.el = createBox(pl);
            overlay.appendChild(pl.el);
            syncInputs();
            vpInfo('Added "' + field + '" on page ' + vpCurrentPage);
        });

        // Reload PDF button
        $(document).off('click.cf7wvp-refresh').on('click.cf7wvp-refresh', '#cf7w-vp-refresh', function(e) {
            e.preventDefault();
            var url = (document.getElementById('cf7w_pdf_url') || {}).value || currentPdfUrl;
            if (!url) { vpWarn('No PDF URL'); return; }
            currentPdfUrl = url;
            vpPdfDoc = null;
            loadPdf(url);
        });

        // New PDF uploaded
        $(document).on('cf7w:pdf_changed', function(e, newUrl) {
            currentPdfUrl = newUrl;
            vpPdfDoc = null;
            vpPlacements = [];
            var ov = document.getElementById('cf7w-vp-overlay');
            if (ov) ov.innerHTML = '';
            var inp = document.getElementById('cf7w-vp-inputs');
            if (inp) inp.querySelectorAll('input[name^="cf7w_vp["]').forEach(function(el){ el.parentNode.removeChild(el); });
            loadPdf(newUrl);
        });

        // Restore saved placements
        if (window.CF7W_VpPlacements && window.CF7W_VpPlacements.length && !vpPlacements.length) {
            vpInfo('Restoring ' + window.CF7W_VpPlacements.length + ' placement(s)');
            window.CF7W_VpPlacements.forEach(function(p) {
                vpPlacements.push({
                    cf7_field: p.cf7_field,
                    page:      parseInt(p.page, 10) || 1,
                    x:         parseFloat(p.x) || 40,
                    y:         parseFloat(p.y) || 40,
                    w:         parseFloat(p.w) || 180,
                    h:         parseFloat(p.h) || 40,
                    canvas_w:  parseFloat(p.canvas_w) || 0,
                    canvas_h:  parseFloat(p.canvas_h) || 0,
                    el:        null
                });
            });
            syncInputs();
        }

        // Auto-load PDF
        if (currentPdfUrl) {
            loadPdf(currentPdfUrl);
        } else {
            vpWarn('No PDF URL — upload a PDF in Step 1 and save the form');
        }

        vpOk('initVisualPlacement() complete');
        window.cf7wLoadPdfIntoViewer = loadPdf;
        return true;
    }

}(jQuery));

// ── CF7W PDF embed tag-generator: width radio toggle ─────────────────────────
// Uses event delegation on document so it fires correctly after CF7 injects
// the panel into the DOM via its own AJAX / tab-switching mechanism.
jQuery( document ).on( 'change', '.cf7w-pdf-width-radio', function () {
    var fieldset = jQuery( this ).closest( 'fieldset' );
    var widthPx  = fieldset.find( '.cf7w-pdf-width-px' );
    var isCustom = ( jQuery( this ).val() === 'custom' );
    widthPx.prop( 'disabled', ! isCustom );
    if ( isCustom ) {
        if ( ! widthPx.val() ) widthPx.val( '700' );
        // Fire a native input event so CF7's tag-generator framework
        // picks up the new value and updates the tag preview immediately.
        widthPx[0].dispatchEvent( new Event( 'input', { bubbles: true } ) );
    } else {
        // Clear the value so CF7 omits the width: option from the tag.
        widthPx.val( '' );
        widthPx[0].dispatchEvent( new Event( 'input', { bubbles: true } ) );
    }
} );
