/*
 * assets/js/form-items.js
 *
 * Adds and removes Section A item cards on the supplier form.
 * It only changes the page; nothing is sent to the server until the person
 * presses Save draft or Submit (a normal form post).
 *
 * After every change the cards are renumbered, so the field names are always
 * items[0][...], items[1][...] and so on, in the order shown.
 */
(function () {
  'use strict';

  var list = document.getElementById('items');
  var tpl  = document.getElementById('item-template');
  var add  = document.getElementById('add-item');
  if (!list || !tpl || !add) { return; }

  var max = parseInt(list.getAttribute('data-max'), 10) || 50;

  function cards() { return list.querySelectorAll('[data-item]'); }

  function renumber() {
    var all = cards();
    all.forEach(function (card, i) {
      card.querySelectorAll('[name]').forEach(function (el) {
        el.name = el.name.replace(/^items\[[^\]]*\]/, 'items[' + i + ']');
      });
      var title = card.querySelector('[data-item-title]');
      if (title) { title.textContent = 'Item ' + (i + 1); }
      var remove = card.querySelector('[data-remove-item]');
      if (remove) { remove.classList.toggle('hidden', all.length < 2); }
    });
    add.disabled = all.length >= max;
  }

  add.addEventListener('click', function () {
    if (cards().length >= max) { return; }
    var node = tpl.content.firstElementChild.cloneNode(true);
    list.appendChild(node);
    renumber();
    // Suggest the next item number and put the cursor in the first box.
    var number = node.querySelector('input[name$="[item_no]"]');
    if (number) { number.value = String(cards().length); }
    var first = node.querySelector('textarea');
    if (first) { first.focus(); }
  });

  list.addEventListener('click', function (event) {
    var button = event.target.closest('[data-remove-item]');
    if (!button || cards().length < 2) { return; }
    button.closest('[data-item]').remove();
    renumber();
  });

  renumber();
})();
