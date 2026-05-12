# Il Custode dei Miracoli

Sito vetrina del libro con pagine **Home**, **Il Libro**, **Contatti** e base newsletter self-hosted.

## Stack
- HTML/CSS/JS statico per front-end.
- PHP 8.3 consigliato (compatibile Aruba) per iscrizione/disiscrizione newsletter.
- MySQL 8 (Percona) per gestione iscritti.

## Setup newsletter (Aruba)
1. Attiva **PHP 8.3** (è stabile e pienamente supportato dal codice attuale).
2. Importa `newsletter_schema.sql` nel database MySQL.
3. Copia `config.sample.php` in `config.php` e inserisci password DB/SMTP reali.
4. Verifica che `newsletter.php` sia raggiungibile sul dominio.
5. Testa iscrizione dal form in `contatti.html`.

## Sicurezza minima inclusa
- Prepared statements PDO.
- Token di conferma hashed (SHA-256) su DB.
- Double opt-in con token a scadenza (72h).
- Stato iscritti: `pending`, `active`, `unsubscribed`.

## Note operative
- I pulsanti Amazon restano visibili ma disattivati fino all'apertura preordini.
- Uscita libro indicata a **fine 2026** nelle pagine pubbliche.
