// Landing page popup tutorials (SweetAlert2) and page effects. Exposes window.SetloTour = { open(id), nudgeSeen(), markNudgeSeen() }
// and window.SetloLanding = { init(root) } (scroll reveal and the count-up in the hero).
// Steps are plain data below. Back/Next buttons and the ← / → keys move between steps; Esc or Done closes.
(function (global) {
  'use strict';
  var SEEN_KEY = 'setlo-tour-seen';
  var SRC = (document.currentScript && document.currentScript.src) || '';
  var BASE = SRC.indexOf('assets/js/') >= 0 ? SRC.split('assets/js/')[0] : '';
  var pose = function (p) { return BASE + 'assets/mascot/' + p + '.png'; };

  var TOURS = {
    tour: {
      title: 'Quick tour',
      steps: [
        { pose: 'courier', icon: 'receipt', title: 'Start a bill', text: 'Tap <b>New Bill</b>, name it (like “Samgyup night”) and invite the people you ate with. Share a link or add them from your Groups.' },
        { pose: 'receipt', icon: 'scan', title: 'Scan the receipt', text: 'Take a photo of the receipt. Setlo reads the items and prices for you. You always get a chance to <b>review and fix</b> anything it misread.' },
        { pose: 'tap', icon: 'pointer', title: 'Tap to assign items', text: 'Everyone taps what they ordered. Shared dishes are split equally between the people who tapped them, and tax or service charge is shared fairly.' },
        { pose: 'thumbs', icon: 'shield', title: 'Settle with proof', text: 'The sender marks a payment as paid, and the receiver confirms they got it. Nothing counts as settled until both sides agree, with a timestamped record.' },
      ],
    },
    scan: {
      title: 'Scanning tips',
      steps: [
        { pose: 'search', icon: 'bulb', title: 'Good light', text: 'Lay the receipt flat in even light. Avoid shadows across the text and glare from the paper.' },
        { pose: 'receipt', icon: 'frame', title: 'Fill the frame', text: 'Get the whole receipt in the photo, top to bottom, and keep the camera straight above it so lines aren’t slanted.' },
        { pose: 'read', icon: 'search', title: 'Check the totals', text: 'On the review step, compare the items and the total with your paper receipt. Fix any wrong price or quantity before assigning.' },
      ],
    },
    settle: {
      title: 'Settling up',
      steps: [
        { pose: 'phone', icon: 'wallet', title: 'Pay the person', text: 'Open <b>Settle up</b>, pick who you owe, and pay with GCash, Maya, bank transfer or cash. Their “Pay me” QR is one tap away.' },
        { pose: 'tap', icon: 'check', title: 'Mark it as paid', text: 'After paying, mark it as paid and add the reference number or a screenshot as proof (optional but helpful).' },
        { pose: 'celebrate', icon: 'shield', title: 'Receiver confirms', text: 'The receiver checks their account and confirms. If something looks off they can <b>dispute</b> it and the app manager helps both sides sort it out.' },
      ],
    },
  };

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function storage(fn) { try { return fn(global.localStorage); } catch (e) { return null; } }

  function open(id) {
    var tour = TOURS[id];
    if (!tour || !global.Swal) return;
    var i = 0, listener;

    function step() {
      var s = tour.steps[i], last = i === tour.steps.length - 1;
      var dots = tour.steps.map(function (_, n) { return '<i class="' + (n === i ? 'on' : '') + '"></i>'; }).join('');
      global.Swal.fire({
        customClass: { popup: 'setlo-swal lp-tour' },
        title: esc(s.title),
        html: '<div class="lp-tour-art" aria-hidden="true"><img src="' + pose(s.pose) + '" alt="" /><span class="lp-tour-badge">' + (global.SetloIcons ? global.SetloIcons.svg(s.icon, 20) : '') + '</span></div><div class="lp-tour-text">' + s.text + '</div><div class="lp-dots" aria-label="Step ' + (i + 1) + ' of ' + tour.steps.length + '">' + dots + '</div>',
        showCancelButton: i > 0,
        cancelButtonText: 'Back',
        confirmButtonText: last ? 'Done' : 'Next',
        confirmButtonColor: (getComputedStyle(document.querySelector('.lp') || document.documentElement).getPropertyValue('--lp-brand') || '#0d9488').trim(),
        showCloseButton: true,
        allowOutsideClick: true,
        reverseButtons: true,
        focusConfirm: true,
        showClass: { popup: '' },
        willOpen: function () {
          if (listener) document.removeEventListener('keydown', listener);
          listener = function (e) {
            if (e.key === 'ArrowRight') { e.preventDefault(); global.Swal.clickConfirm(); }
            else if (e.key === 'ArrowLeft' && i > 0) { e.preventDefault(); global.Swal.clickCancel(); }
          };
          document.addEventListener('keydown', listener);
        },
        willClose: function () { if (listener) { document.removeEventListener('keydown', listener); listener = null; } },
      }).then(function (r) {
        if (r.isConfirmed) {
          if (last) { markNudgeSeen(); return; }
          i++; step();
        } else if (r.dismiss === global.Swal.DismissReason.cancel) {
          i--; step();
        } else {
          markNudgeSeen(); // closed with × / Esc / outside click
        }
      });
    }
    step();
  }

  function nudgeSeen() { return storage(function (ls) { return ls.getItem(SEEN_KEY) === '1'; }) === true; }
  function markNudgeSeen() { storage(function (ls) { ls.setItem(SEEN_KEY, '1'); }); }

  // ---------- Page effects ----------
  function reduced() { return global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches; }

  /** Count a ₱ amount up from zero (the hero's "Your share"). */
  function countUp(el) {
    var to = parseFloat(el.getAttribute('data-count')), start = null, dur = 1300;
    if (!isFinite(to)) return;
    function fmt(n) { return '\u20B1' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function tick(t) {
      if (start === null) start = t;
      var k = Math.min(1, (t - start) / dur), eased = 1 - Math.pow(1 - k, 3);
      el.textContent = fmt(to * eased);
      if (k < 1) global.requestAnimationFrame(tick);
    }
    el.textContent = fmt(0);
    global.setTimeout(function () { global.requestAnimationFrame(tick); }, 1900); // after the avatars have popped onto the dishes
  }

  /** Fade-and-rise for .lp-reveal blocks as they scroll into view. Content stays visible without JS or with reduced motion. */
  function init(root) {
    root = root || document;
    var els = root.querySelectorAll('.lp-reveal');
    if (reduced() || !('IntersectionObserver' in global)) {
      els.forEach(function (e) { e.classList.add('in'); });
      return;
    }
    root.classList.add('lp-ready');
    var io = new global.IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
    }, { threshold: 0.12, rootMargin: '0px 0px -5% 0px' });
    els.forEach(function (e) { io.observe(e); });
    root.querySelectorAll('[data-count]').forEach(countUp);
  }

  global.SetloTour = { open: open, nudgeSeen: nudgeSeen, markNudgeSeen: markNudgeSeen };
  global.SetloLanding = { init: init };
})(window);
