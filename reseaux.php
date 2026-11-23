<?php
/****************************************************************
 * CRM LouisMagie — Module « Réseaux » (publication Facebook / Instagram)
 *
 * Chargé par api.php dans un try/catch : une erreur ici ne doit jamais
 * empêcher le reste du CRM de répondre.
 *
 * Données :
 *   contenus.json        entité synchronisée (les posts, écrits par les appareils)
 *   _publications.json   registre serveur : ce qui est réellement parti (hors synchro)
 *   _meta.json           jetons Meta (jamais envoyés aux appareils ni aux sauvegardes)
 *   _medias/             images et vidéos, servies par action=media (nom = empreinte)
 ****************************************************************/
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'reseaux.php') { http_response_code(404); exit; }

const RS_RESEAUX_API = ['facebook', 'instagram'];          // publiés par le serveur
const RS_RETARD_MAX  = 172800;                             // 48 h : au-delà, plus de publication automatique
const RS_PAR_PASSAGE = 3;                                  // publications au plus par passage du cron
const RS_ETATS_BLOQUANTS = ['envoi', 'préparation', 'publié', 'incertain', 'manuel'];

function rsGraphBase(){ return rtrim(getenv('META_GRAPH') ?: 'https://graph.facebook.com/v27.0', '/'); }
function rsMediaDir($DATA_DIR){ $d = $DATA_DIR.'/_medias'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
function rsBaseUrl(){
  $u = getenv('CRM_PUBLIC_URL');
  if ($u) return rtrim($u, '/');
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  return ($https ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function rsMediaUrl($nom){ return rsBaseUrl().'/api.php?action=media&f='.rawurlencode($nom); }
function rsNomValide($nom){ return is_string($nom) && preg_match('/^[a-f0-9]{32}\.(jpg|mp4)$/', $nom) === 1; }

/* ───── Registre et jetons ───── */
function rsMetaLire($DATA_DIR){ $m = readJson($DATA_DIR.'/_meta.json'); return is_array($m) ? $m : []; }
function rsMetaPublic($m){
  return ['connecte' => !empty($m['pageToken']) && !empty($m['pageId']),
          'pageId' => (string)($m['pageId'] ?? ''), 'pageNom' => (string)($m['pageNom'] ?? ''),
          'igId' => (string)($m['igId'] ?? ''), 'igNom' => (string)($m['igNom'] ?? ''),
          'connecteAt' => (string)($m['connecteAt'] ?? ''), 'verifieAt' => (string)($m['verifieAt'] ?? ''),
          'erreur' => (string)($m['erreur'] ?? '')];
}
function rsRegistre($DATA_DIR){ $r = readJson($DATA_DIR.'/_publications.json'); return is_array($r) ? $r : []; }
/* Modifie UNE entrée du registre sous verrou. $fn reçoit l'entrée actuelle (ou null) et renvoie la nouvelle,
   ou false pour ne rien écrire. Renvoie [écrit, entrée]. */
function rsRegistreMaj($DATA_DIR, $cle, $fn){
  return sousVerrou($DATA_DIR, 'publications', function() use ($DATA_DIR, $cle, $fn) {
    $r = rsRegistre($DATA_DIR);
    $avant = isset($r[$cle]) && is_array($r[$cle]) ? $r[$cle] : null;
    $apres = $fn($avant);
    if ($apres === false) return [false, $avant];
    if ($apres === null) unset($r[$cle]); else $r[$cle] = array_merge($apres, ['majAt' => nowTs()]);
    if (!writeJson($DATA_DIR.'/_publications.json', $r)) return [false, $avant];
    return [true, $apres === null ? null : $r[$cle]];
  });
}

/* ───── Appel HTTP à l'API Graph (sans dépendance) ───── */
function rsGraph($method, $path, $params = []){
  $url = (strpos($path, 'http') === 0) ? $path : rsGraphBase().'/'.ltrim($path, '/');
  $body = http_build_query($params);
  $opts = ['http' => ['method' => $method, 'timeout' => 60, 'ignore_errors' => true,
                      'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n"]];
  if ($method === 'GET') { if ($body !== '') $url .= (strpos($url, '?') === false ? '?' : '&').$body; }
  else $opts['http']['content'] = $body;
  $raw = @file_get_contents($url, false, stream_context_create($opts));
  $code = 0;
  foreach ((array)($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
  if ($raw === false) return ['ok' => false, 'code' => $code, 'erreur' => 'Meta injoignable', 'reseau' => true, 'json' => null];
  $j = json_decode($raw, true);
  if (!is_array($j)) return ['ok' => false, 'code' => $code, 'erreur' => 'réponse illisible de Meta', 'json' => null];
  if (isset($j['error'])) {
    $e = $j['error'];
    $msg = trim((string)($e['error_user_msg'] ?? '') ?: (string)($e['message'] ?? 'erreur Meta'));
    return ['ok' => false, 'code' => $code, 'erreur' => mb_substr($msg, 0, 300), 'metaCode' => (int)($e['code'] ?? 0), 'json' => $j];
  }
  return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'json' => $j, 'erreur' => ($code >= 200 && $code < 300) ? '' : 'HTTP '.$code];
}

/* ───── Médias ───── */
// Lecture publique (Meta doit pouvoir télécharger) : nom = empreinte, impossible à deviner.
function rsMediaServir($DATA_DIR){
  $nom = (string)($_GET['f'] ?? '');
  $f = rsMediaDir($DATA_DIR).'/'.$nom;
  if (!rsNomValide($nom) || !is_file($f)) { http_response_code(404); header('Content-Type: text/plain'); echo 'introuvable'; exit; }
  $type = substr($nom, -3) === 'mp4' ? 'video/mp4' : 'image/jpeg';
  $taille = filesize($f); $debut = 0; $fin = $taille - 1;
  header('Content-Type: '.$type);
  header('Accept-Ranges: bytes');
  header('Cache-Control: public, max-age=31536000, immutable');
  header('X-Robots-Tag: noindex');
  if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {   // lecture vidéo sur iPhone
    if ($m[1] !== '') $debut = (int)$m[1];
    if ($m[2] !== '') $fin = min((int)$m[2], $taille - 1);
    if ($m[1] === '' && $m[2] !== '') { $debut = max(0, $taille - (int)$m[2]); $fin = $taille - 1; }
    if ($debut > $fin || $debut >= $taille) { http_response_code(416); header("Content-Range: bytes */$taille"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $debut-$fin/$taille");
  }
  header('Content-Length: '.($fin - $debut + 1));
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
  $h = fopen($f, 'rb'); fseek($h, $debut); $reste = $fin - $debut + 1;
  while ($reste > 0 && !feof($h)) { $bloc = fread($h, min(1048576, $reste)); echo $bloc; $reste -= strlen($bloc); flush(); }
  fclose($h); exit;
}

function rsMediaAjouter($DATA_DIR){
  $fic = $_FILES['f'] ?? null;
  if (!$fic || !is_array($fic) || ($fic['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($fic['tmp_name'])) return ['ok' => false, 'error' => 'fichier absent ou trop lourd'];
  $tmp = $fic['tmp_name'];
  $tete = (string)file_get_contents($tmp, false, null, 0, 12);
  if (substr($tete, 0, 3) === "\xFF\xD8\xFF") $ext = 'jpg';
  elseif (substr($tete, 4, 4) === 'ftyp') $ext = 'mp4';
  else return ['ok' => false, 'error' => 'format refusé : JPEG ou MP4 seulement'];
  $nom = substr(hash_file('sha256', $tmp), 0, 32).'.'.$ext;
  $dest = rsMediaDir($DATA_DIR).'/'.$nom;
  $deja = is_file($dest);
  if (!$deja && !move_uploaded_file($tmp, $dest)) return ['ok' => false, 'error' => 'écriture impossible'];
  $info = ['ok' => true, 'nom' => $nom, 'deja' => $deja, 'taille' => filesize($dest), 'url' => rsMediaUrl($nom)];
  if ($ext === 'jpg') { $s = @getimagesize($dest); if ($s) { $info['largeur'] = $s[0]; $info['hauteur'] = $s[1]; } }
  return $info;
}

/* ───── Connexion Meta ───── */
function rsMetaConnecter($DATA_DIR, $req){
  $appId = trim((string)($req['appId'] ?? '')); $secret = trim((string)($req['appSecret'] ?? ''));
  $userToken = trim((string)($req['userToken'] ?? '')); $pageToken = trim((string)($req['pageToken'] ?? ''));
  $pageVoulue = trim((string)($req['pageId'] ?? ''));
  $pages = [];
  if ($pageToken === '') {
    if ($userToken === '') return ['ok' => false, 'error' => 'colle le jeton généré dans l’explorateur Meta'];
    $long = $userToken;
    if ($appId !== '' && $secret !== '') {   // jeton court → jeton long (60 j) : les jetons de page qui en dérivent n’expirent pas
      $x = rsGraph('GET', 'oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $appId,
                                                   'client_secret' => $secret, 'fb_exchange_token' => $userToken]);
      if (!$x['ok']) return ['ok' => false, 'error' => 'échange du jeton refusé : '.$x['erreur']];
      $long = (string)($x['json']['access_token'] ?? '');
    }
    $x = rsGraph('GET', 'me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username}', 'access_token' => $long]);
    if (!$x['ok']) return ['ok' => false, 'error' => 'pages introuvables : '.$x['erreur']];
    $pages = (array)($x['json']['data'] ?? []);
    if (!$pages) return ['ok' => false, 'error' => 'aucune page Facebook sur ce compte : vérifie la permission pages_show_list'];
    $choix = null;
    foreach ($pages as $p) if ($pageVoulue !== '' ? (string)$p['id'] === $pageVoulue : true) { $choix = $p; break; }
    if (count($pages) > 1 && $pageVoulue === '') {
      return ['ok' => false, 'choisir' => array_map(function($p){ return ['id' => (string)$p['id'], 'nom' => (string)$p['name']]; }, $pages),
              'error' => 'plusieurs pages : choisis celle de LouisMagie'];
    }
    if (!$choix) return ['ok' => false, 'error' => 'page introuvable'];
    $pageToken = (string)$choix['access_token'];
  }
  $x = rsGraph('GET', 'me', ['fields' => 'id,name,instagram_business_account{id,username}', 'access_token' => $pageToken]);
  if (!$x['ok']) return ['ok' => false, 'error' => 'jeton de page refusé : '.$x['erreur']];
  $j = $x['json'];
  $ig = $j['instagram_business_account'] ?? null;
  $m = ['appId' => $appId, 'appSecret' => $secret, 'pageId' => (string)$j['id'], 'pageNom' => (string)($j['name'] ?? ''),
        'pageToken' => $pageToken, 'igId' => (string)($ig['id'] ?? ''), 'igNom' => (string)($ig['username'] ?? ''),
        'connecteAt' => nowTs(), 'verifieAt' => nowTs(), 'erreur' => ''];
  if (!writeJson($DATA_DIR.'/_meta.json', $m)) return ['ok' => false, 'error' => 'écriture impossible'];
  $pub = rsMetaPublic($m);
  if ($pub['igId'] === '') $pub['avertissement'] = 'Page connectée, mais aucun compte Instagram professionnel n’y est relié.';
  return ['ok' => true, 'meta' => $pub];
}

function rsMetaVerifier($DATA_DIR, $forcer = false){
  $m = rsMetaLire($DATA_DIR);
  if (empty($m['pageToken'])) return $m;
  $dernier = strtotime((string)($m['verifieAt'] ?? '')) ?: 0;
  if (!$forcer && time() - $dernier < 86400) return $m;
  $x = rsGraph('GET', 'me', ['fields' => 'id', 'access_token' => $m['pageToken']]);
  $avant = (string)($m['erreur'] ?? '');
  if ($x['ok']) { $m['erreur'] = ''; $m['verifieAt'] = nowTs(); }
  elseif (empty($x['reseau'])) {
    $m['erreur'] = 'Connexion Meta refusée : '.$x['erreur'];
    $m['verifieAt'] = nowTs();
    if ($avant === '' && function_exists('pushNotifier')) @pushNotifier($DATA_DIR, 'Réseaux : connexion Meta perdue', 'Reconnecte Meta dans Réseaux → Connexion pour reprendre les publications.', '/#reseaux', 'reseaux-meta');
  }
  writeJson($DATA_DIR.'/_meta.json', $m);
  return $m;
}

/* ───── Publication ───── */
function rsParis($date, $heure){
  try { return new DateTime(trim($date).' '.(trim((string)$heure) ?: '12:00'), new DateTimeZone('Europe/Paris')); }
  catch (Throwable $e) { return null; }
}
function rsMediasExistent($DATA_DIR, $noms){
  foreach ($noms as $n) if (!rsNomValide($n) || !is_file(rsMediaDir($DATA_DIR).'/'.$n)) return false;
  return true;
}

/* Lance la publication sur un réseau. Renvoie l'entrée de registre résultante. */
function rsPublier($DATA_DIR, $row, $reseau, $meta, $forcer = false){
  $id = (string)($row['id'] ?? '');
  $cle = $id.'|'.$reseau;
  $cfg = $row['reseaux'][$reseau] ?? null;
  if ($id === '' || !is_array($cfg) || empty($cfg['actif'])) return ['etat' => 'échec', 'erreur' => 'réseau non actif sur ce post'];
  if (($row['statut'] ?? '') !== 'validé') return ['etat' => 'échec', 'erreur' => 'post pas validé'];
  if (empty($meta['pageToken'])) return ['etat' => 'échec', 'erreur' => 'Meta non connecté'];
  if ($reseau === 'instagram' && empty($meta['igId'])) return ['etat' => 'échec', 'erreur' => 'aucun compte Instagram relié à la page'];
  $medias = array_values(array_filter((array)($cfg['medias'] ?? []), 'is_string'));
  $format = (string)($cfg['format'] ?? '');
  if ($format !== 'lien' && !$medias) return ['etat' => 'échec', 'erreur' => 'aucun visuel'];
  if (!rsMediasExistent($DATA_DIR, $medias)) return ['etat' => 'échec', 'erreur' => 'visuel absent du serveur : renvoie les médias depuis le Mac'];

  // Réservation : une seule publication par post et par réseau, même si deux passages se croisent
  list($pris, $entree) = rsRegistreMaj($DATA_DIR, $cle, function($e) use ($forcer) {
    $etat = (string)($e['etat'] ?? '');
    if (in_array($etat, RS_ETATS_BLOQUANTS, true) && !($forcer && $etat === 'incertain')) return false;
    return ['etat' => 'envoi', 'tentatives' => (int)($e['tentatives'] ?? 0) + 1, 'debutAt' => nowTs()];
  });
  if (!$pris) return $entree ?: ['etat' => 'échec', 'erreur' => 'registre indisponible'];

  $texte = (string)($cfg['texte'] ?? '');
  $res = ($reseau === 'facebook') ? rsPublierFacebook($meta, $format, $medias, $texte, $cfg)
                                  : rsPublierInstagram($meta, $medias, $texte, $cfg);
  $nouv = function($e) use ($res) { return array_merge((array)$e, $res); };
  list(, $fin) = rsRegistreMaj($DATA_DIR, $cle, $nouv);
  if (($res['etat'] ?? '') === 'échec' && !empty($res['metaCode']) && (int)$res['metaCode'] === 190) rsMetaVerifier($DATA_DIR, true);
  return $fin ?: $res;
}

function rsPublierFacebook($meta, $format, $medias, $texte, $cfg){
  $page = $meta['pageId']; $tok = $meta['pageToken'];
  $echec = function($x, $etape) { return ['etat' => 'échec', 'erreur' => $etape.' : '.$x['erreur'], 'metaCode' => (int)($x['metaCode'] ?? 0)]; };
  if ($format === 'lien') {
    $lien = (string)($cfg['lien'] ?? '');
    if ($lien === '') return ['etat' => 'échec', 'erreur' => 'lien manquant'];
    $x = rsGraph('POST', "$page/feed", ['message' => $texte, 'link' => $lien, 'access_token' => $tok]);
    if (!$x['ok']) return $echec($x, 'publication');
    $pid = (string)($x['json']['id'] ?? '');
  } elseif ($format === 'video') {
    $x = rsGraph('POST', "$page/videos", ['file_url' => rsMediaUrl($medias[0]), 'description' => $texte, 'access_token' => $tok]);
    if (!$x['ok']) return $echec($x, 'vidéo');
    $pid = (string)($x['json']['id'] ?? '');
    return ['etat' => 'publié', 'postId' => $pid, 'url' => 'https://www.facebook.com/'.$pid, 'publieAt' => nowTs(), 'erreur' => ''];
  } elseif (count($medias) === 1) {
    $x = rsGraph('POST', "$page/photos", ['url' => rsMediaUrl($medias[0]), 'message' => $texte, 'access_token' => $tok]);
    if (!$x['ok']) return $echec($x, 'photo');
    $pid = (string)($x['json']['post_id'] ?? $x['json']['id'] ?? '');
  } else {                                   // album : photos non publiées, puis un post qui les regroupe
    $ids = [];
    foreach ($medias as $n) {
      $x = rsGraph('POST', "$page/photos", ['url' => rsMediaUrl($n), 'published' => 'false', 'access_token' => $tok]);
      if (!$x['ok']) return $echec($x, 'photo '.(count($ids) + 1));
      $ids[] = ['media_fbid' => (string)$x['json']['id']];
    }
    $p = ['message' => $texte, 'access_token' => $tok];
    foreach ($ids as $i => $m) $p["attached_media[$i]"] = json_encode($m);
    $x = rsGraph('POST', "$page/feed", $p);
    if (!$x['ok']) return $echec($x, 'album');
    $pid = (string)($x['json']['id'] ?? '');
  }
  return ['etat' => 'publié', 'postId' => $pid, 'url' => 'https://www.facebook.com/'.$pid, 'publieAt' => nowTs(), 'erreur' => ''];
}

function rsPublierInstagram($meta, $medias, $texte, $cfg){
  $ig = $meta['igId']; $tok = $meta['pageToken'];
  $echec = function($x, $etape) { return ['etat' => 'échec', 'erreur' => $etape.' : '.$x['erreur'], 'metaCode' => (int)($x['metaCode'] ?? 0)]; };
  if (mb_strlen($texte) > 2200) return ['etat' => 'échec', 'erreur' => 'texte Instagram trop long (2 200 caractères au plus)'];
  $video = substr($medias[0], -3) === 'mp4';
  if ($video) {
    $p = ['media_type' => 'REELS', 'video_url' => rsMediaUrl($medias[0]), 'caption' => $texte, 'share_to_feed' => 'true', 'access_token' => $tok];
    $cov = (string)($cfg['couverture'] ?? '');
    if (rsNomValide($cov)) $p['cover_url'] = rsMediaUrl($cov);
    $x = rsGraph('POST', "$ig/media", $p);
    if (!$x['ok']) return $echec($x, 'Reel');
    $cont = (string)$x['json']['id'];
  } elseif (count($medias) === 1) {
    $x = rsGraph('POST', "$ig/media", ['image_url' => rsMediaUrl($medias[0]), 'caption' => $texte, 'access_token' => $tok]);
    if (!$x['ok']) return $echec($x, 'image');
    $cont = (string)$x['json']['id'];
  } else {
    if (count($medias) > 10) return ['etat' => 'échec', 'erreur' => 'carrousel : 10 images au plus'];
    $enfants = [];
    foreach ($medias as $n) {
      $x = rsGraph('POST', "$ig/media", ['image_url' => rsMediaUrl($n), 'is_carousel_item' => 'true', 'access_token' => $tok]);
      if (!$x['ok']) return $echec($x, 'image '.(count($enfants) + 1));
      $enfants[] = (string)$x['json']['id'];
    }
    $x = rsGraph('POST', "$ig/media", ['media_type' => 'CAROUSEL', 'children' => implode(',', $enfants), 'caption' => $texte, 'access_token' => $tok]);
    if (!$x['ok']) return $echec($x, 'carrousel');
    $cont = (string)$x['json']['id'];
  }
  return rsInstagramFinir($meta, $cont);
}

/* Publie un conteneur prêt ; sinon le laisse « en préparation » pour le passage suivant du cron. */
function rsInstagramFinir($meta, $cont, $creeAt = null){
  $tok = $meta['pageToken']; $ig = $meta['igId'];
  $s = rsGraph('GET', $cont, ['fields' => 'status_code', 'access_token' => $tok]);
  $st = $s['ok'] ? (string)($s['json']['status_code'] ?? '') : '';
  if ($st === 'ERROR' || $st === 'EXPIRED') return ['etat' => 'échec', 'erreur' => 'Instagram a refusé le média ('.$st.')', 'conteneur' => $cont];
  if ($st !== 'FINISHED') {
    $debut = $creeAt ? (strtotime($creeAt) ?: time()) : time();
    if (time() - $debut > 3600) return ['etat' => 'échec', 'erreur' => 'préparation Instagram trop longue (plus d’une heure)', 'conteneur' => $cont];
    return ['etat' => 'préparation', 'conteneur' => $cont, 'conteneurAt' => $creeAt ?: nowTs(), 'erreur' => ''];
  }
  $x = rsGraph('POST', "$ig/media_publish", ['creation_id' => $cont, 'access_token' => $tok]);
  if (!$x['ok']) return ['etat' => 'échec', 'erreur' => 'publication : '.$x['erreur'], 'metaCode' => (int)($x['metaCode'] ?? 0), 'conteneur' => $cont];
  $mid = (string)($x['json']['id'] ?? '');
  $l = rsGraph('GET', $mid, ['fields' => 'permalink', 'access_token' => $tok]);
  $url = $l['ok'] ? (string)($l['json']['permalink'] ?? '') : '';
  return ['etat' => 'publié', 'postId' => $mid, 'url' => $url ?: 'https://www.instagram.com/', 'publieAt' => nowTs(), 'erreur' => '', 'conteneur' => $cont];
}

/* Fait avancer un Reel en préparation (registre déjà à « préparation »). */
function rsAvancer($DATA_DIR, $cle, $e, $meta){
  $res = rsInstagramFinir($meta, (string)$e['conteneur'], (string)($e['conteneurAt'] ?? ''));
  if (($res['etat'] ?? '') === 'préparation') return $e;
  list(, $fin) = rsRegistreMaj($DATA_DIR, $cle, function($x) use ($res) {
    if (($x['etat'] ?? '') !== 'préparation') return false;   // déjà traité par un autre passage
    return array_merge((array)$x, $res);
  });
  return $fin ?: $e;
}

/* ───── Passage du cron (toutes les 15 min, sous le verrou de runScheduled) ───── */
function reseauxCron($DATA_DIR){
  $bilan = ['publies' => 0, 'echecs' => 0, 'enPreparation' => 0, 'incertains' => 0, 'auto' => true];
  $meta = rsMetaVerifier($DATA_DIR);
  $config = readJson($DATA_DIR.'/config.json'); if (!is_array($config)) $config = [];
  $auto = !array_key_exists('reseauxAuto', $config) || $config['reseauxAuto'] !== false;
  $bilan['auto'] = $auto;
  if (empty($meta['pageToken']) || !empty($meta['erreur'])) { $bilan['meta'] = empty($meta['pageToken']) ? 'non connecté' : $meta['erreur']; return $bilan; }
  $rows = readJson($DATA_DIR.'/contenus.json'); if (!is_array($rows)) $rows = [];
  $parId = []; foreach ($rows as $r) if (is_array($r) && isset($r['id'])) $parId[(string)$r['id']] = $r;

  // 1. Ce qui est déjà engagé : Reels en préparation, envois coupés
  foreach (rsRegistre($DATA_DIR) as $cle => $e) {
    $etat = (string)($e['etat'] ?? '');
    if ($etat === 'préparation') {
      $e2 = rsAvancer($DATA_DIR, $cle, $e, $meta);
      $e2etat = (string)($e2['etat'] ?? '');
      if ($e2etat === 'publié') { $bilan['publies']++; rsNotifier($DATA_DIR, $cle, $parId, $e2); }
      elseif ($e2etat === 'échec') { $bilan['echecs']++; rsNotifier($DATA_DIR, $cle, $parId, $e2); }
      else $bilan['enPreparation']++;
    } elseif ($etat === 'envoi' && time() - (strtotime((string)($e['debutAt'] ?? '')) ?: time()) > 900) {
      // appel coupé entre la réservation et la réponse de Meta : on ne republie pas à l'aveugle
      rsRegistreMaj($DATA_DIR, $cle, function($x) { return (($x['etat'] ?? '') === 'envoi')
        ? array_merge((array)$x, ['etat' => 'incertain', 'erreur' => 'réponse de Meta perdue : vérifie la page avant de republier']) : false; });
      $bilan['incertains']++;
      rsNotifier($DATA_DIR, $cle, $parId, ['etat' => 'incertain']);
    }
  }
  if (!$auto) return $bilan;

  // 2. Posts validés arrivés à leur heure
  $reg = rsRegistre($DATA_DIR);
  $faits = 0;
  $maintenant = time();
  usort($rows, function($a, $b) { return strcmp((string)($a['date'] ?? '').(string)($a['heure'] ?? ''), (string)($b['date'] ?? '').(string)($b['heure'] ?? '')); });
  foreach ($rows as $r) {
    if (!is_array($r) || ($r['statut'] ?? '') !== 'validé') continue;
    $dt = rsParis((string)($r['date'] ?? ''), (string)($r['heure'] ?? ''));
    if (!$dt) continue;
    $ecart = $maintenant - $dt->getTimestamp();
    if ($ecart < 0 || $ecart > RS_RETARD_MAX) continue;      // pas encore l'heure, ou trop tard : Louis décide
    foreach (RS_RESEAUX_API as $res) {
      if (empty($r['reseaux'][$res]['actif'])) continue;
      $cle = $r['id'].'|'.$res;
      $etat = (string)($reg[$cle]['etat'] ?? '');
      if ($etat !== '') continue;                             // déjà tenté (réussi, en cours ou en échec) : pas d’automatisme
      if ($faits >= RS_PAR_PASSAGE) break 2;
      $e = rsPublier($DATA_DIR, $r, $res, $meta);
      $faits++;
      $e2etat = (string)($e['etat'] ?? '');
      if ($e2etat === 'publié') $bilan['publies']++;
      elseif ($e2etat === 'préparation') $bilan['enPreparation']++;
      else $bilan['echecs']++;
      if ($e2etat !== 'préparation') rsNotifier($DATA_DIR, $cle, $parId, $e);
      $meta = rsMetaLire($DATA_DIR);
      if (!empty($meta['erreur'])) break 2;                   // jeton tombé : on arrête là
    }
  }
  return $bilan;
}

function rsNotifier($DATA_DIR, $cle, $parId, $e){
  if (!function_exists('pushNotifier')) return;
  list($id, $res) = array_pad(explode('|', $cle, 2), 2, '');
  $titre = (string)($parId[$id]['titre'] ?? $id);
  $nomRes = $res === 'instagram' ? 'Instagram' : 'Facebook';
  $etat = (string)($e['etat'] ?? '');
  if ($etat === 'publié') @pushNotifier($DATA_DIR, 'Publié sur '.$nomRes, $titre, '/#reseaux', 'reseaux-'.$cle);
  elseif ($etat === 'échec') @pushNotifier($DATA_DIR, 'Échec sur '.$nomRes, $titre.' : '.(string)($e['erreur'] ?? ''), '/#reseaux', 'reseaux-'.$cle);
  elseif ($etat === 'incertain') @pushNotifier($DATA_DIR, 'À vérifier sur '.$nomRes, $titre.' : la réponse de Meta s’est perdue', '/#reseaux', 'reseaux-'.$cle);
}

/* ───── Actions authentifiées (appelées depuis le switch d’api.php) ───── */
function reseauxAction($action, $req, $DATA_DIR){
  switch ($action) {
    case 'mediaAjouter':
      out(rsMediaAjouter($DATA_DIR));
    case 'mediaExiste': {
      $noms = array_values(array_filter((array)($req['noms'] ?? []), 'rsNomValide'));
      $ex = [];
      foreach ($noms as $n) $ex[$n] = is_file(rsMediaDir($DATA_DIR).'/'.$n);
      out(['ok' => true, 'existe' => $ex ?: new stdClass()]);
    }
    case 'metaEtat': {
      $m = rsMetaVerifier($DATA_DIR, !empty($req['verifier']));
      $pub = rsMetaPublic($m);
      if (!empty($req['quota']) && $pub['connecte'] && $pub['igId'] !== '') {
        $q = rsGraph('GET', $pub['igId'].'/content_publishing_limit', ['fields' => 'quota_usage,config', 'access_token' => $m['pageToken']]);
        if ($q['ok']) { $d = $q['json']['data'][0] ?? []; $pub['quota'] = ['utilise' => (int)($d['quota_usage'] ?? 0), 'max' => (int)($d['config']['quota_total'] ?? 100)]; }
      }
      out(['ok' => true, 'meta' => $pub]);
    }
    case 'metaConnecter':
      out(rsMetaConnecter($DATA_DIR, $req));
    case 'metaDeconnecter':
      @unlink($DATA_DIR.'/_meta.json');
      out(['ok' => true, 'meta' => rsMetaPublic([])]);
    case 'publications':
      out(['ok' => true, 'publications' => rsRegistre($DATA_DIR) ?: new stdClass()]);
    case 'contenuPublier': {
      // L’appareil envoie la fiche telle que Louis la voit : c’est elle qui part, même si la synchro n’est pas finie.
      $row = is_array($req['row'] ?? null) ? $req['row'] : null;
      $res = (string)($req['reseau'] ?? '');
      if (!$row || !in_array($res, RS_RESEAUX_API, true)) out(['ok' => false, 'error' => 'requête incomplète']);
      @set_time_limit(180); ignore_user_abort(true);
      $meta = rsMetaLire($DATA_DIR);
      $e = rsPublier($DATA_DIR, $row, $res, $meta, !empty($req['forcer']));
      // Reel : on attend un peu que Meta prépare la vidéo, le cron prendra la suite sinon
      for ($i = 0; $i < 4 && ($e['etat'] ?? '') === 'préparation'; $i++) { sleep(10); $e = rsAvancer($DATA_DIR, $row['id'].'|'.$res, $e, $meta); }
      out(['ok' => in_array($e['etat'] ?? '', ['publié', 'préparation'], true), 'entree' => $e, 'error' => (string)($e['erreur'] ?? '')]);
    }
    case 'contenuMarquer': {
      // Publication faite à la main (Google, story…) ou remise à zéro après vérification d’un « à vérifier »
      $id = (string)($req['id'] ?? ''); $res = (string)($req['reseau'] ?? ''); $etat = (string)($req['etat'] ?? '');
      if ($id === '' || !preg_match('/^[a-z]+$/', $res)) out(['ok' => false, 'error' => 'requête incomplète']);
      list($ok, $e) = rsRegistreMaj($DATA_DIR, $id.'|'.$res, function($x) use ($etat, $req) {
        if ($etat === 'effacer') return null;
        if ($etat !== 'manuel') return false;
        return array_merge((array)$x, ['etat' => 'manuel', 'url' => mb_substr((string)($req['url'] ?? ''), 0, 300), 'publieAt' => nowTs(), 'erreur' => '']);
      });
      out(['ok' => $ok, 'entree' => $e]);
    }
  }
  out(['ok' => false, 'error' => 'action inconnue: '.$action]);
}
