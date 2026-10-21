/**
 * @file
 * The Masquerade menu: opens under its button, closes on a click elsewhere
 * or Escape.
 */

((Drupal, once) => {
  Drupal.behaviors.masqueradeToolbarMenu = {
    attach(context) {
      once('masquerade-toolbar-menu', '.masquerade-toolbar-menu', context).forEach((menu) => {
        const toggle = menu.querySelector('.masquerade-toolbar-menu__toggle');
        const setOpen = (open) => {
          menu.classList.toggle('is-open', open);
          toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        toggle.addEventListener('click', () => setOpen(!menu.classList.contains('is-open')));
        document.addEventListener('click', (event) => {
          if (!menu.contains(event.target)) {
            setOpen(false);
          }
        });
        menu.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            setOpen(false);
            toggle.focus();
          }
        });
      });
    },
  };
})(Drupal, once);
