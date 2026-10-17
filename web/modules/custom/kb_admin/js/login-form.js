/**
 * @file
 * Folds the username and password fields away on the login form.
 *
 * The page leads with the single sign-on buttons. "Log in with Password"
 * opens the fields the first time it is clicked, then signs in. Without
 * JavaScript the fields simply stay visible. They open straight away after a
 * failed attempt, so the error is seen, or when already filled in.
 */
((Drupal, once) => {
  Drupal.behaviors.kbPasswordLogin = {
    attach(context) {
      once('kb-password-login', 'form.kb-password-login', context).forEach((form) => {
        const name = form.querySelector('.js-form-item-name');
        const pass = form.querySelector('.js-form-item-pass');
        const button = form.querySelector('.button-login, [data-drupal-selector="edit-submit"]');
        if (!name || !pass || !button) {
          return;
        }

        // Wrap the two fields so they open and close together.
        const fields = document.createElement('div');
        fields.className = 'kb-password-fields';
        fields.id = 'kb-password-fields';
        const inner = document.createElement('div');
        inner.className = 'kb-password-fields__inner';
        name.parentNode.insertBefore(fields, name);
        fields.append(inner);
        inner.append(name, pass);
        button.setAttribute('aria-controls', fields.id);

        const open = (focus) => {
          form.classList.remove('is-collapsed');
          button.setAttribute('aria-expanded', 'true');
          if (focus) {
            form.querySelector('[name="name"]')?.focus();
          }
        };

        const hasError = form.querySelector('.form-item--error, .error, [aria-invalid="true"]')
          || document.querySelector('.messages--error, [data-drupal-messages] .messages--error');
        const filled = form.querySelector('[name="name"]')?.value;
        if (hasError || filled) {
          open(false);
          return;
        }

        form.classList.add('is-collapsed');
        button.setAttribute('aria-expanded', 'false');
        button.addEventListener('click', (event) => {
          if (form.classList.contains('is-collapsed')) {
            event.preventDefault();
            open(true);
          }
        });
      });
    },
  };
})(Drupal, once);
