// Landing page popup tutorials (SweetAlert2). Exposes window.SetloTour = { open(id), nudgeSeen(), markNudgeSeen() }.
// Steps are plain data below. Back/Next buttons and the ← / → keys move between steps; Esc or Done closes.
(function (global) {
  'use strict';
  var SEEN_KEY = 'setlo-tour-seen';

  var TOURS = {
    tour: {
      title: 'Quick tour',
      steps: [
        { icon: '🧾', title: 'Start a bill', text: 'Tap <b>New Bill</b>, name it (like “Samgyup night”) and invite the people you ate with. Share a link or add them from your Groups.' },
        { icon: '📸', title: 'Scan the receipt', text: 'Take a photo of the receipt. Setlo reads the items and prices for you. You always get a chance to <b>review and fix</b> anything it misread.' },
        { icon: '👆', title: 'Tap to assign items', text: 'Everyone taps what they ordered. Shared dishes are split equally between the people who tapped them, and tax or service charge is shared fairly.' },
        { icon: '✅', title: 'Settle with proof', text: 'The sender marks a payment as paid, and the receiver confirms they got it. Nothing counts as settled until both sides agree, with a timestamped record.' },
      ],
    },
    scan: {
      title: 'Scanning tips',
      steps: [
        { icon: '💡', title: 'Good light', text: 'Lay the receipt flat in even light. Avoid shadows across the text and glare from the paper.' },
        { icon: '📐', title: 'Fill the frame', text: 'Get the whole receipt in the photo, top to bottom, and keep the camera straight above it so lines aren’t slanted.' },
        { icon: '🔍', title: 'Check the totals', text: 'On the review step, compare the items and the total with your paper receipt. Fix any wrong price or quantity before assigning.' },
      ],
    },
    settle: {
      title: 'Settling up',
      steps: [
        { icon: '💸', title: 'Pay the person', text: 'Open <b>Settle up</b>, pick who you owe, and pay with GCash, Maya, bank transfer or cash. Their “Pay me” QR is one tap away.' },
        { icon: '🙋', title: 'Mark it as paid', text: 'After paying, mark it as paid and add the reference number or a screenshot as proof (optional but helpful).' },
        { icon: '🤝', title: 'Receiver confirms', text: 'The receiver checks their account and confirms. If something looks off they can <b>dispute</b> it and the app manager helps both sides sort it out.' },
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
        html: '<div class="lp-tour-art" aria-hidden="true">' + s.icon + '</div><div class="lp-tour-text">' + s.text + '</div><div class="lp-dots" aria-label="Step ' + (i + 1) + ' of ' + tour.steps.length + '">' + dots + '</div>',
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

  global.SetloTour = { open: open, nudgeSeen: nudgeSeen, markNudgeSeen: markNudgeSeen };
})(window);
