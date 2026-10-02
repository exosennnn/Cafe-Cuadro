<?php
/**
 * E-SIGNATURE CAPTURE PAD
 *
 * A small drawable canvas plus a hidden `signature_data` input, meant to sit
 * inside the same <form> as an approval decision (forward/approve/reject) or
 * a request filing. On submit, the hidden input carries the drawn signature
 * as JSON: an array of strokes, each stroke an array of [x, y] points
 * normalized to 0..1 (relative to the pad's own width/height), so the same
 * data can be redrawn at any size later - on screen (SVG) or in the
 * generated PDF (SimplePDF::signatureBlock()).
 *
 * $suffix must be unique per <form>/modal instance on the page (e.g. the
 * request_id), since a single page can render more than one modal/form that
 * each need their own pad and hidden input.
 */
if (!function_exists('signaturePadHtml')) {
    function signaturePadHtml(string $suffix = ''): void {
        $canvasId = 'sigPad' . $suffix;
        $inputId  = 'sigData' . $suffix;
        $clearId  = 'sigClear' . $suffix;
        ?>
        <div class="mb-3">
          <label class="form-label mb-1">
            Your E-Signature <span class="text-muted small">(draw with mouse, pen, or finger)</span>
          </label>
          <div class="border rounded bg-white" style="touch-action:none;">
            <canvas id="<?= e($canvasId) ?>" width="600" height="160"
                    style="width:100%; height:100px; display:block; cursor:crosshair;"></canvas>
          </div>
          <div class="d-flex justify-content-between align-items-center mt-1">
            <small class="text-muted">This is recorded as your e-signature on this step of the document.</small>
            <button type="button" id="<?= e($clearId) ?>" class="btn btn-sm btn-link p-0">Clear</button>
          </div>
          <input type="hidden" name="signature_data" id="<?= e($inputId) ?>">
        </div>
        <script>
        (function () {
          var canvas = document.getElementById('<?= $canvasId ?>');
          var input = document.getElementById('<?= $inputId ?>');
          var clearBtn = document.getElementById('<?= $clearId ?>');
          if (!canvas || !input) return;
          var ctx = canvas.getContext('2d');
          var drawing = false, strokes = [], current = null;

          function pos(e) {
            var rect = canvas.getBoundingClientRect();
            var t = e.touches && e.touches.length ? e.touches[0] : e;
            var x = (t.clientX - rect.left) / rect.width;
            var y = (t.clientY - rect.top) / rect.height;
            return [Math.min(1, Math.max(0, x)), Math.min(1, Math.max(0, y))];
          }
          function redraw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.strokeStyle = '#1a1a1a';
            ctx.lineWidth = 2.2;
            ctx.lineJoin = 'round';
            ctx.lineCap = 'round';
            strokes.forEach(function (s) {
              if (s.length < 2) return;
              ctx.beginPath();
              s.forEach(function (p, i) {
                var x = p[0] * canvas.width, y = p[1] * canvas.height;
                if (i === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); }
              });
              ctx.stroke();
            });
          }
          function sync() { input.value = strokes.length ? JSON.stringify(strokes) : ''; }
          function start(e) { drawing = true; current = [pos(e)]; strokes.push(current); e.preventDefault(); }
          function move(e) { if (!drawing) return; current.push(pos(e)); redraw(); e.preventDefault(); }
          function end() { if (!drawing) return; drawing = false; sync(); }

          canvas.addEventListener('mousedown', start);
          canvas.addEventListener('mousemove', move);
          window.addEventListener('mouseup', end);
          canvas.addEventListener('touchstart', start, { passive: false });
          canvas.addEventListener('touchmove', move, { passive: false });
          canvas.addEventListener('touchend', end);
          if (clearBtn) {
            clearBtn.addEventListener('click', function () { strokes = []; redraw(); sync(); });
          }
        })();
        </script>
        <?php
    }
}

if (!function_exists('renderSignatureSvg')) {
    /** Redraw a stored signature (JSON strokes) as a small inline SVG. */
    function renderSignatureSvg(?string $signatureData, int $w = 220, int $h = 66): string {
        if (!$signatureData) return '';
        $strokes = json_decode($signatureData, true);
        if (!is_array($strokes) || empty($strokes)) return '';

        $polylines = '';
        foreach ($strokes as $stroke) {
            if (!is_array($stroke) || count($stroke) < 2) continue;
            $points = [];
            foreach ($stroke as $pt) {
                if (!is_array($pt) || count($pt) < 2) continue;
                $points[] = round(((float)$pt[0]) * 300, 1) . ',' . round(((float)$pt[1]) * 90, 1);
            }
            if (count($points) < 2) continue;
            $polylines .= '<polyline points="' . e(implode(' ', $points))
                . '" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />';
        }
        if ($polylines === '') return '';

        return '<svg viewBox="0 0 300 90" preserveAspectRatio="xMinYMid meet" style="width:' . (int)$w . 'px;height:' . (int)$h . 'px;">'
            . $polylines . '</svg>';
    }
}
