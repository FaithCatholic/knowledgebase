/**
 * @file
 * Keeps the username and password fields off the login form until asked for.
 *
 * The page leads with the single sign-on buttons. "Log in with Password"
 * adds the fields the first time it is clicked, then signs in. While folded
 * away the fields are taken out of the page entirely, not just hidden:
 * browsers and password managers offer to autofill any password field in the
 * page, visible or not, and their prompt covered the other buttons.
 *
 * Without JavaScript the fields simply stay. They're shown straight away after
 * a failed attempt, so the error is seen.
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

        // Wrap the two fields so they open together.
        const fields = document.createElement('div');
        fields.className = 'kb-password-fields';
        fields.id = 'kb-password-fields';
        const inner = document.createElement('div');
        inner.className = 'kb-password-fields__inner';
        name.parentNode.insertBefore(fields, name);
        fields.append(inner);
        inner.append(name, pass);
        button.setAttribute('aria-controls', fields.id);

        const hasError = form.querySelector('.form-item--error, .error, [aria-invalid="true"]')
          || document.querySelector('.messages--error, [data-drupal-messages] .messages--error');
        if (hasError) {
          button.setAttribute('aria-expanded', 'true');
          return;
        }

        // Fold away: take the fields out of the page until they're asked for.
        inner.remove();
        form.classList.add('is-collapsed');
        button.setAttribute('aria-expanded', 'false');

        button.addEventListener('click', (event) => {
          if (!form.classList.contains('is-collapsed')) {
            return;
          }
          event.preventDefault();
          fields.append(inner);
          // Let the browser lay out the closed state first, so opening animates.
          void fields.offsetHeight;
          form.classList.remove('is-collapsed');
          button.setAttribute('aria-expanded', 'true');
          form.querySelector('[name="name"]')?.focus();
        });
      });
    },
  };
})(Drupal, once);
