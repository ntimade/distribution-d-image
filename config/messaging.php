<?php

return [
    // Envoi des messages WhatsApp et SMS. Désactivé par défaut : les boutons restent affichés mais grisés.
    // Pour le réactiver : MESSAGES_ENABLED=true dans le .env, puis php artisan config:cache.
    'enabled' => (bool) env('MESSAGES_ENABLED', false),
];
