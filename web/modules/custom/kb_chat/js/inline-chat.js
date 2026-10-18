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
        // Answers are rendered inside the chat's shadow DOM, out of reach of
        // the theme: give tables borders and room, and let wide ones scroll.
        const chat = container.querySelector('deep-chat');
        if (chat) {
          chat.auxiliaryStyle = `
            table { border-collapse: collapse; margin: 0.5em 0; display: block; overflow-x: auto; max-width: 100%; }
            th, td { border: 1px solid #d0d0d0; padding: 4px 8px; text-align: left; vertical-align: top; }
            th { background: rgba(0, 0, 0, 0.05); }
          `;
        }
        // Load the chat library now rather than waiting for a click.
        if (Drupal.aiDeepchat && Drupal.aiDeepchat.load) {
          Drupal.aiDeepchat.load();
        }
      });
    },
  };
})(Drupal, once);
