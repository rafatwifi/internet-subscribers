(function () {
  var card = document.getElementById('loginCard');
  var glow = document.getElementById('loginGlow');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (card && glow && !reduce) {
    card.addEventListener('pointermove', function (ev) {
      var r = card.getBoundingClientRect();
      var nx = (ev.clientX - r.left) / r.width;
      var ny = (ev.clientY - r.top) / r.height;
      glow.style.left = (nx * 100) + '%';
      glow.style.top = (ny * 100) + '%';
      if (window.innerWidth < 760) {
        card.style.transform = '';
        return;
      }
      var ry = (nx - 0.5) * 9;
      var rx = (0.5 - ny) * 7;
      card.style.transform = 'rotateX(' + rx + 'deg) rotateY(' + ry + 'deg)';
    });
    card.addEventListener('pointerleave', function () {
      card.style.transform = '';
    });
  }

  var canvas = document.getElementById('loginField');
  if (!canvas || !canvas.getContext || reduce) return;
  var ctx = canvas.getContext('2d');
  var lines = [];
  var px = 0.5;
  var py = 0.42;
  var tx = px;
  var ty = py;
  var w = 0;
  var h = 0;
  var running = true;
  var count = 22;

  function size() {
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    w = window.innerWidth;
    h = window.innerHeight;
    canvas.width = Math.max(1, Math.floor(w * dpr));
    canvas.height = Math.max(1, Math.floor(h * dpr));
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    count = w < 700 ? 24 : 46;
    if (lines.length > count) lines.length = count;
    while (lines.length < count) {
      lines.push({
        x: Math.random() * w,
        y: Math.random() * h,
        a: Math.random() * Math.PI * 2,
        v: 0.35 + Math.random() * 0.55,
        len: 28 + Math.random() * 54,
        hue: Math.random() > 0.55 ? '196, 181, 253' : '232, 196, 122'
      });
    }
  }

  function point(ev) {
    var x = ev.clientX;
    var y = ev.clientY;
    if (ev.touches && ev.touches[0]) {
      x = ev.touches[0].clientX;
      y = ev.touches[0].clientY;
    }
    if (!w || !h) return;
    tx = x / w;
    ty = y / h;
  }

  function frame(t) {
    if (!running) return;
    px += (tx - px) * 0.04;
    py += (ty - py) * 0.04;
    var drift = t * 0.00008;
    var cx = (0.5 + Math.cos(drift) * 0.18);
    var cy = (0.46 + Math.sin(drift * 0.7) * 0.16);
    var ax = (px * 0.65) + (cx * 0.35);
    var ay = (py * 0.65) + (cy * 0.35);
    ctx.clearRect(0, 0, w, h);
    var gx = ax * w;
    var gy = ay * h;
    var wash = ctx.createRadialGradient(gx, gy, 0, gx, gy, Math.max(w, h) * 0.45);
    wash.addColorStop(0, 'rgba(232, 196, 122, 0.18)');
    wash.addColorStop(1, 'rgba(232, 196, 122, 0)');
    ctx.fillStyle = wash;
    ctx.fillRect(0, 0, w, h);
    var i;
    for (i = 0; i < lines.length; i++) {
      var f = lines[i];
      var dx = (ax * w) - f.x;
      var dy = (ay * h) - f.y;
      var dist = Math.sqrt(dx * dx + dy * dy) || 1;
      f.a += 0.008 + Math.sin(t * 0.001 + i) * 0.004;
      f.x += Math.cos(f.a) * f.v + (dx / dist) * 1.35;
      f.y += Math.sin(f.a) * f.v + (dy / dist) * 1.35;
      if (f.x < -40) f.x = w + 20;
      if (f.x > w + 40) f.x = -20;
      if (f.y < -40) f.y = h + 20;
      if (f.y > h + 40) f.y = -20;
      ctx.strokeStyle = 'rgba(' + f.hue + ',0.62)';
      ctx.lineWidth = 1.25;
      ctx.beginPath();
      ctx.moveTo(f.x, f.y);
      ctx.lineTo(f.x - Math.cos(f.a) * f.len, f.y - Math.sin(f.a) * f.len);
      ctx.stroke();
    }
    requestAnimationFrame(frame);
  }

  window.addEventListener('pointermove', point, { passive: true });
  window.addEventListener('touchmove', point, { passive: true });
  window.addEventListener('resize', size);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      running = false;
    } else if (!running) {
      running = true;
      requestAnimationFrame(frame);
    }
  });
  size();
  requestAnimationFrame(frame);
})();
