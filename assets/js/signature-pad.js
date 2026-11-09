/*
 * assets/js/signature-pad.js
 *
 * A small signature pad on a <canvas> (Step 9). Works with mouse, touch and
 * stylus (Pointer Events). No libraries.
 *
 * HTML it expects (see common/sign.php):
 *
 *   <form>
 *     <input type="radio" name="signature_source" value="draw"  checked>   (optional)
 *     <input type="radio" name="signature_source" value="saved">           (optional)
 *     <div data-signature-pad>
 *       <canvas data-pad-canvas class="touch-none"></canvas>
 *       <button type="button" data-pad-undo>Undo</button>
 *       <button type="button" data-pad-clear>Clear</button>
 *       <p data-pad-error hidden></p>
 *       <input type="hidden" name="signature_png" data-pad-output>
 *     </div>
 *   </form>
 *
 * What it does:
 *   - draws smooth strokes; Undo removes the last stroke; Clear removes all
 *   - keeps strokes as fractions of the box (0..1), so a resize or phone
 *     rotation does not lose or distort the drawing
 *   - on submit it REFUSES an empty or tiny scribble and shows a message
 *   - otherwise it writes a transparent PNG data URL (600 x 200) into the hidden field
 *   - if the form has the radio "signature_source = saved" selected, the pad is
 *     hidden and nothing is required
 * The server checks the image again; this script is only for convenience.
 */
(function () {
    'use strict';

    var OUT_W = 600;          // exported image size (3 : 1, same as the box on screen)
    var OUT_H = 200;
    var MIN_POINTS = 8;       // fewer points than this is just a tap
    var MIN_LENGTH = 0.25;    // total path length, as a fraction of the box width
    var MIN_SPAN = 0.08;      // the drawing must be at least this wide OR this tall (fraction)

    function init(root) {
        var canvas = root.querySelector('[data-pad-canvas]');
        var output = root.querySelector('[data-pad-output]');
        var undoBtn = root.querySelector('[data-pad-undo]');
        var clearBtn = root.querySelector('[data-pad-clear]');
        var errorBox = root.querySelector('[data-pad-error]');
        var form = root.closest('form');
        if (!canvas || !output) { return; }

        var ctx = canvas.getContext('2d');
        var strokes = [];      // each stroke = array of {x, y} as fractions of the box
        var current = null;    // the stroke being drawn
        var activeId = null;   // pointer that is drawing

        // ---- Sizing: the box is 3:1 and as wide as its container --------------
        function resize() {
            var rect = canvas.getBoundingClientRect();
            var dpr = window.devicePixelRatio || 1;
            canvas.width = Math.max(1, Math.round(rect.width * dpr));
            canvas.height = Math.max(1, Math.round(rect.height * dpr));
            redraw();
        }

        // ---- Drawing ---------------------------------------------------------
        function drawStroke(c, stroke, w, h, lineWidth) {
            c.lineWidth = lineWidth;
            c.lineCap = 'round';
            c.lineJoin = 'round';
            c.strokeStyle = '#111827';
            c.fillStyle = '#111827';
            if (stroke.length === 1) {                       // a single tap = a dot
                c.beginPath();
                c.arc(stroke[0].x * w, stroke[0].y * h, lineWidth / 2, 0, Math.PI * 2);
                c.fill();
                return;
            }
            c.beginPath();
            c.moveTo(stroke[0].x * w, stroke[0].y * h);
            for (var i = 1; i < stroke.length - 1; i++) {    // smooth through the midpoints
                var mx = (stroke[i].x + stroke[i + 1].x) / 2 * w;
                var my = (stroke[i].y + stroke[i + 1].y) / 2 * h;
                c.quadraticCurveTo(stroke[i].x * w, stroke[i].y * h, mx, my);
            }
            var last = stroke[stroke.length - 1];
            c.lineTo(last.x * w, last.y * h);
            c.stroke();
        }

        function redraw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            var lineWidth = Math.max(2, canvas.width / OUT_W * 3);
            strokes.forEach(function (s) { drawStroke(ctx, s, canvas.width, canvas.height, lineWidth); });
            if (current) { drawStroke(ctx, current, canvas.width, canvas.height, lineWidth); }
        }

        function point(ev) {
            var rect = canvas.getBoundingClientRect();
            return {
                x: Math.min(1, Math.max(0, (ev.clientX - rect.left) / rect.width)),
                y: Math.min(1, Math.max(0, (ev.clientY - rect.top) / rect.height))
            };
        }

        canvas.addEventListener('pointerdown', function (ev) {
            if (activeId !== null) { return; }
            if (ev.pointerType === 'mouse' && ev.button !== 0) { return; }
            activeId = ev.pointerId;
            try { canvas.setPointerCapture(ev.pointerId); } catch (e) { /* not important */ }
            current = [point(ev)];
            hideError();
            redraw();
            ev.preventDefault();
        });

        canvas.addEventListener('pointermove', function (ev) {
            if (ev.pointerId !== activeId || !current) { return; }
            current.push(point(ev));
            redraw();
            ev.preventDefault();
        });

        function finish(ev) {
            if (ev.pointerId !== activeId) { return; }
            if (current) { strokes.push(current); }
            current = null;
            activeId = null;
            redraw();
        }
        canvas.addEventListener('pointerup', finish);
        canvas.addEventListener('pointercancel', finish);

        // ---- Undo and Clear ----------------------------------------------------
        if (undoBtn) {
            undoBtn.addEventListener('click', function () { strokes.pop(); output.value = ''; hideError(); redraw(); });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function () { strokes = []; output.value = ''; hideError(); redraw(); });
        }

        // ---- Is the drawing big enough to count as a signature? ------------------
        function isEnoughInk() {
            var points = 0, length = 0;
            var minX = 1, maxX = 0, minY = 1, maxY = 0;
            var ratio = canvas.width / canvas.height;       // so lengths use the same unit in x and y
            strokes.forEach(function (s) {
                points += s.length;
                for (var i = 0; i < s.length; i++) {
                    minX = Math.min(minX, s[i].x); maxX = Math.max(maxX, s[i].x);
                    minY = Math.min(minY, s[i].y); maxY = Math.max(maxY, s[i].y);
                    if (i > 0) {
                        var dx = s[i].x - s[i - 1].x;
                        var dy = (s[i].y - s[i - 1].y) / ratio;
                        length += Math.sqrt(dx * dx + dy * dy);
                    }
                }
            });
            var span = Math.max(maxX - minX, (maxY - minY) / ratio);
            return points >= MIN_POINTS && length >= MIN_LENGTH && span >= MIN_SPAN;
        }

        // ---- The PNG ---------------------------------------------------------------
        function exportPng() {
            var out = document.createElement('canvas');
            out.width = OUT_W;
            out.height = OUT_H;
            var c = out.getContext('2d');                    // transparent background
            strokes.forEach(function (s) { drawStroke(c, s, OUT_W, OUT_H, 3); });
            return out.toDataURL('image/png');
        }

        function showError(text) {
            if (!errorBox) { return; }
            errorBox.textContent = text;
            errorBox.hidden = false;
        }
        function hideError() {
            if (errorBox) { errorBox.hidden = true; }
        }

        // ---- "Use my saved signature" hides the pad ------------------------------------
        function usingSaved() {
            if (!form) { return false; }
            var picked = form.querySelector('input[name="signature_source"]:checked');
            return !!(picked && picked.value === 'saved');
        }
        function syncSource() {
            root.hidden = usingSaved();
            if (!root.hidden) { resize(); }
        }
        if (form) {
            form.querySelectorAll('input[name="signature_source"]').forEach(function (r) {
                r.addEventListener('change', syncSource);
            });

            form.addEventListener('submit', function (ev) {
                if (usingSaved()) { output.value = ''; return; }
                if (!isEnoughInk()) {
                    ev.preventDefault();
                    output.value = '';
                    showError(strokes.length === 0
                        ? 'Please draw your signature in the box.'
                        : 'That scribble is too small. Please draw your full signature.');
                    canvas.scrollIntoView({ block: 'center' });
                    return;
                }
                output.value = exportPng();
            });
        }

        window.addEventListener('resize', resize);
        syncSource();
        resize();

        // Small hook for tests and for other scripts.
        root.signaturePad = { isEnoughInk: isEnoughInk, exportPng: exportPng, strokeCount: function () { return strokes.length; } };
    }

    document.querySelectorAll('[data-signature-pad]').forEach(init);
})();