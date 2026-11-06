/*
 * assets/js/ui.js
 *
 * Opens and closes the side menu on phones (below 1024 px wide).
 * Nothing else. No library needed.
 *
 * The menu is "closed" when it has the classes -translate-x-full and
 * max-lg:invisible (the CSS in header.php); opening removes them.
 */
(function () {
  'use strict';

  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebar-overlay');
  var toggle  = document.querySelector('[data-menu-toggle]');
  var close   = document.querySelector('[data-menu-close]');
  if (!sidebar || !overlay || !toggle) { return; }

  var desktop = window.matchMedia('(min-width: 1024px)');

  function setOpen(open) {
    sidebar.classList.toggle('-translate-x-full', !open);
    sidebar.classList.toggle('max-lg:invisible', !open);
    overlay.classList.toggle('hidden', !open);
    document.body.classList.toggle('overflow-hidden', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (open) {
      // Move the keyboard focus into the menu.
      var first = sidebar.querySelector('a, button');
      if (first) { first.focus(); }
    }
  }

  toggle.addEventListener('click', function () { setOpen(true); });
  overlay.addEventListener('click', function () { setOpen(false); });
  if (close) { close.addEventListener('click', function () { setOpen(false); toggle.focus(); }); }

  // Escape closes the menu.
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
      setOpen(false);
      toggle.focus();
    }
  });

  // Turning the phone sideways (or widening the window) must not leave a dark overlay behind.
  desktop.addEventListener('change', function (event) {
    if (event.matches) { setOpen(false); }
  });
})();
