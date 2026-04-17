/**
 * CF7 Waiver – Signature pad (front-end)
 *
 * Works with the [cf7w_signature] form tag.
 * Each canvas draws strokes; on mouseup/touchend the PNG data URI
 * is written into the hidden <input> that CF7 will POST.
 */
(function ($) {
    'use strict';

    function initPad($canvas) {
        var canvas  = $canvas[0];
        var ctx     = canvas.getContext('2d');
        var fieldId = $canvas.data('field');
        var $input  = $('#cf7w-input-' + fieldId);
        var inkColor= $input.data('ink') || '#000000';

        ctx.strokeStyle = inkColor;
        ctx.lineWidth   = 2;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';

        var drawing  = false;
        var lastX    = 0;
        var lastY    = 0;
        var hasDrawn = false;

        function getPos(e) {
            var rect = canvas.getBoundingClientRect();
            var src  = e.touches ? e.touches[0] : e;
            return { x: src.clientX - rect.left, y: src.clientY - rect.top };
        }

        function startDraw(e) {
            e.preventDefault();
            drawing = true;
            var p = getPos(e);
            lastX = p.x; lastY = p.y;
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            // Track on document so strokes continue outside the canvas boundary
            document.addEventListener('mousemove', onDocMove);
            document.addEventListener('mouseup',   onDocUp);
        }

        function onDocMove(e) {
            if ( ! drawing ) return;
            var p = getPos(e);
            // If cursor has left the canvas, clamp to canvas edges so the stroke
            // doesn't jump when it re-enters
            p.x = Math.max(0, Math.min(canvas.width,  p.x));
            p.y = Math.max(0, Math.min(canvas.height, p.y));
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            lastX = p.x; lastY = p.y;
            hasDrawn = true;
        }

        function onDocUp(e) {
            if ( ! drawing ) return;
            drawing = false;
            document.removeEventListener('mousemove', onDocMove);
            document.removeEventListener('mouseup',   onDocUp);
            if ( hasDrawn ) {
                $input.val(canvas.toDataURL('image/png'));
            }
        }

        function startTouch(e) {
            e.preventDefault();
            if ( e.touches.length !== 1 ) return;
            drawing = true;
            var p = getPos(e);
            lastX = p.x; lastY = p.y;
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
        }

        function moveTouch(e) {
            if ( ! drawing ) return;
            e.preventDefault();
            var p = getPos(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            lastX = p.x; lastY = p.y;
            hasDrawn = true;
        }

        function stopTouch(e) {
            if ( ! drawing ) return;
            drawing = false;
            if ( hasDrawn ) {
                $input.val(canvas.toDataURL('image/png'));
            }
        }

        canvas.addEventListener('mousedown',  startDraw,  { passive: false });
        canvas.addEventListener('touchstart', startTouch, { passive: false });
        canvas.addEventListener('touchmove',  moveTouch,  { passive: false });
        canvas.addEventListener('touchend',   stopTouch);
    }

    function initClear($btn) {
        $btn.on('click', function () {
            var fieldId = $(this).data('field');
            var $canvas = $('#cf7w-canvas-' + fieldId);
            var canvas  = $canvas[0];
            var ctx     = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            $('#cf7w-input-' + fieldId).val('');
        });
    }

    function init() {
        $('.cf7w-canvas').each(function () {
            initPad($(this));
        });
        $('.cf7w-clear').each(function () {
            initClear($(this));
        });
    }

    $(document).ready(init);

    // Re-init when CF7 resets the form after successful submission
    $(document).on('wpcf7mailsent', function () {
        setTimeout(function () {
            $('.cf7w-canvas').each(function () {
                var canvas = this;
                var ctx    = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                $('#cf7w-input-' + $(canvas).data('field')).val('');
            });
        }, 100);
    });

}(jQuery));
