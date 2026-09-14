<?php

// TZ приходит из .env через docker-compose. Фоллбэк нужен на случай, когда
// переменной нет: date_default_timezone_set(false) в PHP 8 даёт warning
// "Timezone ID '' is invalid" на КАЖДОМ запуске любого скрипта, а часовой пояс
// всё равно остаётся UTC — то есть шум без пользы.
date_default_timezone_set(getenv('TZ') ?: 'UTC');
