/**
 * @file
 * Keeps the in-page chat open: no collapsing from the header.
 */
((Drupal, once) => {
  Drupal.behaviors.kbChatInline = {
    attach(context) {
      once('kb-chat-inline', '.kb-chat-inline .chat-container', context).forEach((container) => {
        const header = container.querySelector('.ai-deepchat--header');
        const open = () => {
          container.classList.add('chat-open');
          container.classList.remove('chat-collapsed', 'chat-collapsed-minimal');
          if (header) {
            header.classList.add('active');
            header.setAttribute('aria-expanded', 'true');
          }
        };
        open();
        // The chatbot's own toggle listens on the header; stop it closing the
        // chat, while still letting the "Clear history" menu work.
        if (header) {
          header.addEventListener('click', (event) => {
            if (!event.target.closest('.chat-dropdown')) {
              event.stopImmediatePropagation();
              open();
            }
          }, true);
          header.removeAttribute('role');
          header.removeAttribute('tabindex');
        }
        // Load the chat library now rather than waiting for a click.
        if (Drupal.aiDeepchat && Drupal.aiDeepchat.load) {
          Drupal.aiDeepchat.load();
        }
      });
    },
  };
})(Drupal, once);
