document.querySelectorAll('#year').forEach((el) => {
  el.textContent = new Date().getFullYear();
});

const feedbackEl = document.getElementById('newsletter-feedback');
const params = new URLSearchParams(window.location.search);
const newsletterState = params.get('newsletter');
if (feedbackEl && newsletterState) {
  const messages = {
    'check-email': "Controlla la tua email per confermare l'iscrizione. Controlla anche la cartella SPAM.",
    'unsubscribed': 'La disiscrizione è stata registrata con successo.',
    'not-active': 'Email non trovata tra le iscrizioni attive.'
    'config-missing': 'Configurazione newsletter non completata: crea /icdm_config/config.php.',
    'invalid': 'Verifica i campi obbligatori (nome, email, consenso).',
    'mail-failed': 'Invio email non riuscito: verifica configurazione SMTP del dominio.'
  };
  feedbackEl.textContent = messages[newsletterState] || messages.invalid;
  feedbackEl.hidden = false;
  const cleanUrl = `${window.location.pathname}${window.location.hash || ''}`;
  window.history.replaceState({}, document.title, cleanUrl);
}
