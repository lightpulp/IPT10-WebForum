/*
 * Optional challenge: collapse and expand reply branches.
 * Buttons are created here, after the page loads, so without JavaScript the page
 * shows every reply and no dead controls. Comment text is never read or inserted;
 * only fixed labels are set with textContent.
 */
(function () {
  'use strict';

  var lists = document.querySelectorAll('ul.replies[id^="replies-"]');

  Array.prototype.forEach.call(lists, function (list) {
    var comment = list.parentElement;
    if (!comment) { return; }
    var actions = comment.querySelector(':scope > .comment-body > .comment-actions');
    if (!actions) { return; }

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'toggle-replies';
    button.setAttribute('aria-controls', list.id);

    function render(expanded) {
      button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      button.textContent = expanded ? 'Hide replies' : 'Show replies';
      list.hidden = !expanded;
    }

    button.addEventListener('click', function () {
      render(button.getAttribute('aria-expanded') !== 'true');
    });

    render(true); // everything starts expanded
    actions.insertBefore(button, actions.firstChild);
  });
})();
