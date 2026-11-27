<?php
/* Tâche planifiée du CRM, lancée DANS le conteneur par Coolify (Scheduled Tasks) :
 *     php /var/www/html/cron.php        toutes les 15 minutes
 * Aucune clé à saisir : ce fichier refuse tout appel venant du web.
 * Il tourne sous l'utilisateur d'Apache (www-data), pour que les fichiers de données
 * qu'il crée restent modifiables par le CRM. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (function_exists('posix_getuid') && posix_getuid() === 0) {
  $u = function_exists('posix_getpwnam') ? posix_getpwnam('www-data') : false;
  if (!$u || !posix_setgid($u['gid']) || !posix_setuid($u['uid'])) { fwrite(STDERR, "impossible de passer en www-data\n"); exit(1); }
}
umask(0002);
define('LM_CRON_CLI', true);
$_GET = ['action' => 'runScheduled'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? (parse_url((string)(getenv('CRM_PUBLIC_URL') ?: 'https://louismagie.dunai.fr'), PHP_URL_HOST) ?: 'louismagie.dunai.fr');
$_SERVER['HTTPS'] = 'on';
require __DIR__.'/api.php';
