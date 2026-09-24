<?php
// Un avertissement PHP ne doit jamais se glisser devant le JSON : le CRM croirait la réponse perdue.
// Les erreurs restent lisibles dans les logs du conteneur (Coolify).
ini_set('display_errors','0'); ini_set('log_errors','1');
/****************************************************************
 * CRM LouisMagie — Backend PHP (stockage fichiers, SANS base de données)
 * API JSON pour synchroniser le CRM + archiver les PDF.
 * Marche sur n'importe quel hébergement PHP (shared, VPS, Coolify).
 * Installation : voir DEPLOIEMENT.md
 ****************************************************************/

/* ===== Config (via variables d'environnement Coolify, ou valeurs par défaut) ===== */
$TOKEN    = getenv('CRM_TOKEN') ?: 'CHANGE_MOI_secret_long'; // = Réglages → Token secret du CRM
$DATA_DIR = getenv('CRM_DATA') ?: __DIR__.'/data'; // dossier données (créé tout seul)
$PDF_DIR  = __DIR__.'/pdf';                        // dossier PDF (créé tout seul)
$PDF_URL  = 'pdf';

/* ===== Rien à toucher en dessous ===== */
// CORS restreint : autorise seulement les requêtes de même origine (front + API sur le même domaine)
$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$__host   = $_SERVER['HTTP_HOST'] ?? '';
// Origines du site autorisées à poster le formulaire public (préflight inclus)
$__formOrigins = array_filter(array_map('trim', explode(',', getenv('FORM_ORIGINS') ?: 'https://louismagie.fr,https://www.louismagie.fr')));
if ($__origin === '' || parse_url($__origin, PHP_URL_HOST) === $__host || in_array($__origin, $__formOrigins, true)) {
  header('Access-Control-Allow-Origin: '.($__origin ?: '*'));
  if ($__origin !== '') header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');   // rien de ce CRM ne doit être indexé
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$ENTITIES = ['demandes','devis','prestations','factures','clients','relances',
             'activite','mails','catalogue','recettes','declarations','planifs','templates',
             'projets','magiciens','effets','segments','campagnes'];

function out($o){ echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; }

/* Envoi email via SMTP Gmail (mot de passe d'application). 0 dépendance. */
function smtpSend($to,$subject,$bodyText,$attachName='',$attachB64='',$trackUrl='',$htmlIn='',$opts=[]){
  // SMTP générique : Infomaniak, Gmail, etc. (compat anciennes variables GMAIL_*)
  $host=getenv('SMTP_HOST') ?: 'smtp.gmail.com';
  $port=getenv('SMTP_PORT') ?: '587';
  $user=getenv('SMTP_USER') ?: getenv('GMAIL_USER');
  $pass=getenv('SMTP_PASS') ?: getenv('GMAIL_APP_PASSWORD');
  $from=getenv('SMTP_FROM') ?: (getenv('GMAIL_FROM') ?: $user);
  if(!$user||!$pass) return [false,'SMTP non configuré (SMTP_USER / SMTP_PASS)','config'];
  if(!$to) return [false,'destinataire vide','adresse'];
  // Anti-injection d'en-têtes SMTP : rejette tout CRLF dans les adresses / nom de pièce jointe, valide le destinataire
  $to=trim($to); $from=trim($from);
  if(preg_match('/[\r\n]/', $to.$from.$attachName)) return [false,'adresse ou pièce jointe invalide','refus'];
  if(!filter_var($to, FILTER_VALIDATE_EMAIL)) return [false,'destinataire invalide','adresse'];
  // Infomaniak : force SSL implicite sur 465 (leur 587 STARTTLS rejette nos requêtes anti-pipelining)
  if(strpos($host,'infomaniak')!==false){ $port='465'; }
  $secure = ($port=='465') || (getenv('SMTP_SECURE')==='ssl');   // SSL implicite (évite l'anti-pipelining STARTTLS)
  $ctx=stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
  $proto=$secure?'ssl':'tcp';
  $fp=@stream_socket_client("$proto://$host:$port",$en,$es,15,STREAM_CLIENT_CONNECT,$ctx);
  if(!$fp) return [false,"connexion SMTP impossible ($host:$port): $es",'connexion'];
  stream_set_timeout($fp,20); stream_set_blocking($fp,true);
  $helo = (getenv('SMTP_FROM') && strpos(getenv('SMTP_FROM'),'@')) ? substr(strrchr(getenv('SMTP_FROM'),'@'),1) : 'louismagie.fr';
  // lit une réponse SMTP complète : lignes entières (jusqu'au \n), s'arrête sur la dernière ligne « code<espace> »
  $read=function() use($fp){ $d=''; while(($l=@fgets($fp,8192))!==false){ $d.=$l; if(substr($l,-1)==="\n" && strlen($l)>=4 && $l[3]===' ') break; } return $d; };
  // Chaque réponse est lue et son code vérifié : un refus arrête l'échange AVANT l'envoi du corps.
  // Retour : [ok, info, type] ; type = ok | config | connexion | auth | expediteur | adresse | refus | temp | incertain
  $cmd=function($c) use($fp,$read){ if(@fwrite($fp,$c."\r\n")===false) return ''; return $read(); };
  $code=function($r){ return preg_match('/^\s*(\d{3})/',(string)$r,$m) ? (int)$m[1] : 0; };
  $lu=function($r){ $r=trim(preg_replace('/\s+/',' ',(string)$r)); return $r!=='' ? substr($r,0,300) : 'pas de réponse'; };
  $fin=function($info,$type,$quit=true) use($fp,$cmd){ if($quit) $cmd('QUIT'); @fclose($fp); return [false,$info,$type]; };
  $r=$read();            if($code($r)!==220) return $fin('serveur SMTP indisponible : '.$lu($r),'connexion');
  $r=$cmd("EHLO $helo"); if($code($r)!==250) return $fin('EHLO refusé : '.$lu($r),'connexion');
  if(!$secure){
    $r=$cmd("STARTTLS"); if($code($r)!==220) return $fin('STARTTLS refusé : '.$lu($r),'connexion');
    if(!@stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) return $fin('TLS échec','connexion',false);
    $r=$cmd("EHLO $helo"); if($code($r)!==250) return $fin('EHLO refusé : '.$lu($r),'connexion');
  }
  $r=$cmd("AUTH LOGIN");
  if($code($r)===334) $r=$cmd(base64_encode($user));
  if($code($r)===334) $r=$cmd(base64_encode($pass));
  if($code($r)!==235) return $fin('authentification SMTP refusée : '.$lu($r),'auth');
  $r=$cmd("MAIL FROM:<$from>"); if($code($r)!==250) return $fin('expéditeur refusé : '.$lu($r),'expediteur');
  $r=$cmd("RCPT TO:<$to>"); $c=$code($r);
  if($c!==250 && $c!==251){
    $t = $c>=500 ? (preg_match('/\b5\.1\.\d{1,3}\b/',$r) ? 'adresse' : 'refus') : 'temp';
    return $fin(($t==='adresse'?'adresse refusée : ':'destinataire refusé : ').$lu($r), $t);
  }
  $r=$cmd("DATA"); $c=$code($r);
  if($c!==354) return $fin('DATA refusé : '.$lu($r), $c>=500?'refus':'temp');
  // En-têtes : Date et Message-ID (RFC 5322), nom d'expéditeur, objet plié (RFC 2047),
  // List-Unsubscribe One-Click (RFC 8058) pour les seuls emails d'actualité.
  // Ce bloc reste AVANT $html et $bP : ils lisent $bodyText.
  $dom = strtolower((string)substr((string)strrchr($from,'@'),1)); if (!preg_match('/^[a-z0-9.-]+$/',$dom)) $dom = $helo;
  $cleMid = (string)($opts['mid'] ?? '');
  // Message-ID stable par (trackId, destinataire) : si une planif repart après un plantage, la boîte écarte le doublon
  $mid = preg_match('/^[A-Za-z0-9_-]{1,64}$/',$cleMid) ? $cleMid.'.'.substr(hash('sha256',strtolower($to)),0,10) : bin2hex(random_bytes(12));
  $nomExp = enteteMime(getenv('SMTP_FROM_NAME') ?: 'Louis · LouisMagie');
  if ($nomExp !== '' && strpos($nomExp,'=?') !== 0) $nomExp = '"'.addcslashes($nomExp,'"\\').'"';   // nom ASCII : entre guillemets
  $h = "Date: ".date('r')."\r\nMessage-ID: <$mid@$dom>\r\n"
     ."From: ".($nomExp !== '' ? "$nomExp <$from>" : $from)."\r\nReply-To: $from\r\nTo: $to\r\n"
     ."Subject: ".enteteMime($subject)."\r\n";
  $lienDesabo = (string)($opts['listUnsub'] ?? '');
  if ($lienDesabo !== '' && preg_match('#^https://[^\s<>"]+$#', $lienDesabo)) {
    $h .= "List-Unsubscribe: <$lienDesabo>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    if (strpos($bodyText, 'action=desabo') === false)            // pas de doublon si le CRM a déjà mis le lien dans le texte
      $bodyText = rtrim($bodyText)."\n\n-- \nNe plus recevoir mes actualités : $lienDesabo\n";
  }
  $h .= "MIME-Version: 1.0\r\n";
  // HTML : template fourni par le CRM si présent, sinon repli simple ; pixel de suivi ajouté si tracking
  $html = $htmlIn ?: ($trackUrl ? '<div style="white-space:pre-wrap;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222">'.htmlspecialchars($bodyText).'</div>' : '');
  if ($html && $trackUrl) $html .= "<img src=\"$trackUrl\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none\">";
  $bP=function() use($bodyText,$html){ // partie corps (texte seul, ou alternative texte+html si tracking)
    if(!$html) return "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($bodyText));
    $a='alt'.md5(uniqid());
    return "Content-Type: multipart/alternative; boundary=\"$a\"\r\n\r\n"
      ."--$a\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($bodyText))."\r\n"
      ."--$a\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html))."\r\n--$a--\r\n";
  };
  if($attachB64){
    $b='mix'.md5(uniqid());
    $m=$h."Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n"
      ."--$b\r\n".$bP()."\r\n"
      ."--$b\r\nContent-Type: application/pdf; name=\"$attachName\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$attachName\"\r\n\r\n".chunk_split($attachB64)."\r\n--$b--\r\n";
  } else if($html){
    $m=$h.$bP();
  } else {
    $m=$h."Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($bodyText));
  }
  // Le corps part : à partir d'ici, le serveur a pu accepter le message.
  stream_set_timeout($fp,60);   // la RFC 5321 prévoit une attente longue après le point final
  if(@fwrite($fp,$m."\r\n.\r\n")===false) return $fin("coupure pendant l'envoi : l'email est peut-être parti",'incertain',false);
  $r=$read(); $c=$code($r);
  if($c===250){ $cmd('QUIT'); @fclose($fp); return [true,'envoyé','ok']; }
  if($c>=400 && $c<500) return $fin('refus temporaire : '.$lu($r),'temp');
  if($c>=500 && $c<600) return $fin('refusé : '.$lu($r),'refus');
  @fclose($fp);
  return [false,"pas de confirmation du serveur après l'envoi : l'email est peut-être parti",'incertain'];
}
/* Diagnostic SMTP : renvoie la transcription complète du dialogue (pour debug) */
function smtpDiag($to){
  $host=getenv('SMTP_HOST')?:'smtp.gmail.com'; $port=getenv('SMTP_PORT')?:'587';
  $user=getenv('SMTP_USER')?:getenv('GMAIL_USER'); $pass=getenv('SMTP_PASS')?:getenv('GMAIL_APP_PASSWORD');
  $from=getenv('SMTP_FROM')?:(getenv('GMAIL_FROM')?:$user);
  if(strpos($host,'infomaniak')!==false){ $port='465'; }
  $secure=($port=='465')||(getenv('SMTP_SECURE')==='ssl');
  $T=[]; $T[]="CONFIG host=$host port=$port secure=".($secure?'oui':'non')." user=$user from=$from pass=".($pass?'(défini)':'(VIDE)');
  if(!$user||!$pass) return ['ok'=>false,'steps'=>$T,'info'=>'creds manquants'];
  $ctx=stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
  $fp=@stream_socket_client(($secure?'ssl':'tcp')."://$host:$port",$en,$es,15,STREAM_CLIENT_CONNECT,$ctx);
  if(!$fp){ $T[]="CONNECT ÉCHEC: $es"; return ['ok'=>false,'steps'=>$T,'info'=>'connexion']; }
  stream_set_timeout($fp,20); stream_set_blocking($fp,true);
  $helo=(strpos($from,'@'))?substr(strrchr($from,'@'),1):'louismagie.fr';
  $read=function() use($fp){ $d=''; while(($l=fgets($fp,8192))!==false){ $d.=$l; if(substr($l,-1)==="\n"&&strlen($l)>=4&&$l[3]===' ') break; } return rtrim($d); };
  $cmd=function($c,$show=null) use($fp,$read,&$T){ fwrite($fp,$c."\r\n"); $T[]='C: '.($show?:$c); $r=$read(); $T[]='S: '.$r; return $r; };
  $T[]='S: '.$read();
  $cmd("EHLO $helo");
  if(!$secure){ $cmd("STARTTLS"); stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT); $cmd("EHLO $helo"); }
  $cmd("AUTH LOGIN"); $cmd(base64_encode($user),'<base64 user>');
  $r=$cmd(base64_encode($pass),'<base64 pass>');
  if(strpos($r,'235')===false){ fclose($fp); return ['ok'=>false,'steps'=>$T,'info'=>'auth refusée']; }
  $cmd("MAIL FROM:<$from>"); $cmd("RCPT TO:<$to>"); $cmd("DATA");
  fwrite($fp,"Date: ".date('r')."\r\nMessage-ID: <".bin2hex(random_bytes(12))."@$helo>\r\nFrom: $from\r\nTo: $to\r\nSubject: Test SMTP CRM\r\n\r\nTest diagnostic.\r\n.\r\n"); $T[]='C: <corps>'; $T[]='S: '.$read();
  $cmd("QUIT"); fclose($fp);
  return ['ok'=>true,'steps'=>$T,'info'=>'ok'];
}
function readJson($path){ if(!is_file($path)) return null; $c=file_get_contents($path); $v=json_decode($c,true); return $v; }
/* ═══ Fusion multi-appareils ═══════════════════════════════════════════
   Un appareil en retard ne doit jamais effacer le travail d'un autre :
   on fusionne enregistrement par enregistrement (updatedAt arbitre) et on
   applique un journal de suppressions partagé. */
function nowTs(){ return gmdate('Y-m-d\\TH:i:s.000\\Z'); }   // même format que le new Date().toISOString() du navigateur
function rowTs($r){ return (string)($r['updatedAt'] ?? ''); }   // `date` est une date métier, pas une date de modification
/* Instant d'envoi d'une planif (UTC, format nowTs). Ancienne ligne sans envoiLe : 07:00 UTC le jour dit
   (9 h l'été, 8 h l'hiver à Paris). Ligne sans date lisible : jamais (au lieu de partir tout de suite). */
function planifInstant($p){
  $e = (string)($p['envoiLe'] ?? '');
  if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $e)) return $e;
  $d = (string)($p['date'] ?? '');
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d.'T07:00:00.000Z' : '9999-12-31T00:00:00.000Z';
}
/* Planifs : la trace d'un envoi réel (ou tenté) ne s'efface pas, et une ligne traitée
   ne redevient jamais « prévu » par la synchro (seul le serveur change ce statut). */
function planifTraitee($r){ return in_array((string)($r['statut'] ?? ''), ['envoi','envoyé','échec','incertain'], true); }
function mergeRows($existantes, $entrantes, $dels, $entity){
  $map = [];
  foreach ((array)$existantes as $r) { if (is_array($r) && isset($r['id'])) $map[(string)$r['id']] = $r; }
  foreach ((array)$entrantes as $r) {
    if (!is_array($r) || !isset($r['id'])) continue;
    $k = (string)$r['id'];
    if (!isset($map[$k])) { $map[$k] = $r; continue; }
    if ($entity === 'planifs' && ($r['statut'] ?? '') === 'prévu' && ($map[$k]['statut'] ?? '') !== 'prévu') continue;
    $te = rowTs($r); $tm = rowTs($map[$k]);
    if ($te !== '' && ($tm === '' || strcmp($te, $tm) >= 0)) $map[$k] = $r;   // l'entrant gagne s'il est au moins aussi récent
  }
  $out = [];
  foreach ($map as $k => $r) {
    $t = $dels[$k] ?? null;
    if ($t !== null && !(strcmp(rowTs($r), (string)$t) > 0) && !($entity === 'planifs' && planifTraitee($r))) continue;   // supprimé, sauf réécriture postérieure ou trace d'envoi
    $out[] = $r;
  }
  if ($entity === 'activite') {   // journal, pas référentiel : on garde les plus récents
    usort($out, function($a,$b){ return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')); });
    $out = array_slice($out, 0, 300);
  }
  return array_values($out);
}
/* Journal de suppressions partagé par tous les appareils */
function delsRead($DATA_DIR){ $v = readJson("$DATA_DIR/_dels.json"); return is_array($v) ? $v : []; }
function delsMerge($a, $b){
  foreach ((array)$b as $e => $ids) {
    if (!is_array($ids)) continue;
    if (!isset($a[$e]) || !is_array($a[$e])) $a[$e] = [];
    foreach ($ids as $id => $t) { $id=(string)$id; $t=(string)$t;
      if (!isset($a[$e][$id]) || strcmp($t, (string)$a[$e][$id]) > 0) $a[$e][$id] = $t; }
  }
  $lim = gmdate('Y-m-d\\TH:i:s.000\\Z', time() - 180*86400);   // même format que les horodatages du navigateur
  foreach ($a as $e => $ids) foreach ((array)$ids as $id => $t) if (strcmp((string)$t, $lim) < 0) unset($a[$e][$id]);
  return $a;
}
/* ── Logo : détourage, recadrage et inversion selon le fond ──────────────
   Même traitement que le CRM, mais côté serveur : les pages publiques
   restent correctes même si l'application n'est pas passée publier sa
   version. Résultat mis en cache, le calcul ne se fait qu'une fois. */
function logoVariante($dataUrl, $fond, $DATA_DIR){
  if (!function_exists('imagecreatefromstring')) return null;      // GD absent → repli sur le mot-symbole
  if (strpos($dataUrl, 'base64,') === false) return null;
  $cache = "$DATA_DIR/_logo-".substr(md5($dataUrl), 0, 10)."-$fond.png";
  if (is_file($cache)) return 'data:image/png;base64,'.base64_encode((string)file_get_contents($cache));

  $src = @imagecreatefromstring(base64_decode(explode('base64,', $dataUrl, 2)[1]));
  if (!$src) return null;
  $w = imagesx($src); $h = imagesy($src);
  if ($w < 2 || $h < 2 || $w * $h > 4000000) { imagedestroy($src); return null; }
  // Sur un PNG à palette, imagecolorat rend un index et non une couleur : on convertit
  if (!imageistruecolor($src) && function_exists('imagepalettetotruecolor')) imagepalettetotruecolor($src);

  // Couleur du coin = fond présumé ; on mesure aussi l'opacité générale
  $coin = imagecolorat($src, 0, 0);
  $br = ($coin >> 16) & 0xFF; $bg = ($coin >> 8) & 0xFF; $bb = $coin & 0xFF;
  $opaques = 0;
  for ($y = 0; $y < $h; $y += 2) for ($x = 0; $x < $w; $x += 2) {
    if (((imagecolorat($src, $x, $y) >> 24) & 0x7F) < 60) $opaques++;
  }
  $proches = 0;
  for ($y = 0; $y < $h; $y += 2) for ($x = 0; $x < $w; $x += 2) {
    $c = imagecolorat($src, $x, $y);
    if (abs((($c >> 16) & 0xFF) - $br) + abs((($c >> 8) & 0xFF) - $bg) + abs(($c & 0xFF) - $bb) < 60) $proches++;
  }
  $echant = (int)(ceil($w/2) * ceil($h/2));
  // image pleine ET assez de pixels de la couleur du coin → c'est bien un fond uni
  $fondUni = $echant > 0 && $opaques > $echant * 0.92 && $proches > $echant * 0.25;

  // Passe unique : transparence du fond, boîte du dessin, luminance du dessin
  $alphaDe = function($c) use ($fondUni, $br, $bg, $bb) {
    $a = ($c >> 24) & 0x7F;
    if (!$fondUni) return $a;
    $d = abs((($c >> 16) & 0xFF) - $br) + abs((($c >> 8) & 0xFF) - $bg) + abs(($c & 0xFF) - $bb);
    if ($d < 40) return 127;                                       // fond → transparent
    if ($d < 80) return (int)round(127 - (127 - $a) * ($d - 40) / 40);
    return $a;
  };
  $x0 = $w; $y0 = $h; $x1 = -1; $y1 = -1; $somme = 0.0; $poids = 0.0;
  for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
      $c = imagecolorat($src, $x, $y);
      $a = $alphaDe($c); $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
      if ($a < 110) {                                              // pixel visible → dessin
        if ($x < $x0) $x0 = $x; if ($x > $x1) $x1 = $x;
        if ($y < $y0) $y0 = $y; if ($y > $y1) $y1 = $y;
        $op = (127 - $a) / 127;
        $somme += ((0.2126*$r + 0.7152*$g + 0.0722*$b) / 255) * $op; $poids += $op;
      }
    }
  }
  if ($x1 < $x0 || $y1 < $y0) { imagedestroy($src); return null; }  // rien de visible
  $lum = $poids > 0 ? $somme / $poids : 0.5;
  $inverser = ($fond === 'dark') ? ($lum < 0.45) : ($lum > 0.60);

  // Recadrage sur le dessin, avec 2% de respiration
  $m = (int)round(max($x1 - $x0 + 1, $y1 - $y0 + 1) * 0.02);
  $x0 = max(0, $x0 - $m); $y0 = max(0, $y0 - $m);
  $x1 = min($w - 1, $x1 + $m); $y1 = min($h - 1, $y1 + $m);
  $nw = $x1 - $x0 + 1; $nh = $y1 - $y0 + 1;

  $out = imagecreatetruecolor($nw, $nh);
  imagealphablending($out, false); imagesavealpha($out, true);
  for ($y = 0; $y < $nh; $y++) {
    for ($x = 0; $x < $nw; $x++) {
      $c = imagecolorat($src, $x0 + $x, $y0 + $y);
      $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
      $a = $alphaDe($c);
      if ($inverser) { $r = 255 - $r; $g = 255 - $g; $b = 255 - $b; }
      imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, $a));
    }
  }
  ob_start(); imagepng($out); $png = (string)ob_get_clean();
  imagedestroy($src); imagedestroy($out);
  if ($png === '') return null;
  @file_put_contents($cache, $png);
  foreach ((array)@glob("$DATA_DIR/_logo-*.png") as $vieux) {       // un logo remplacé laisse un cache orphelin
    if (@filemtime($vieux) < time() - 30*86400) @unlink($vieux);
  }
  return 'data:image/png;base64,'.base64_encode($png);
}

/* Verrou exclusif par fichier : deux appareils qui envoient la même table au
   même instant liraient chacun l'ancien état et le second effacerait la
   fusion du premier. Lecture-fusion-écriture se fait donc sous verrou. */
function sousVerrou($DATA_DIR, $nom, $fn){
  $h = @fopen("$DATA_DIR/.verrou-".preg_replace('/[^a-z_]/i','',$nom), 'c');
  if ($h) @flock($h, LOCK_EX);
  try { return $fn(); }
  finally { if ($h) { @flock($h, LOCK_UN); @fclose($h); } }
}

/* Modifie une table côté serveur SOUS VERROU et en relisant le fichier : une
   écriture du serveur (signature, accusé de lecture…) ne doit pas écraser un
   envoi d'appareil arrivé entre sa lecture et son écriture. $fn reçoit le
   tableau par référence ; renvoyer false = rien à écrire. */
function majTable($DATA_DIR, $e, $fn){
  return sousVerrou($DATA_DIR, $e, function() use ($DATA_DIR, $e, $fn) {
    $f = "$DATA_DIR/$e.json"; $arr = readJson($f); if (!is_array($arr)) $arr = [];
    $avant = $arr;
    $res = $fn($arr);
    if ($res === false) return false;
    historiser($DATA_DIR, $e, $avant, $arr);
    return writeJson($f, $arr) ? $res : false;
  });
}

/* Registre des envois (data/_envois.json) : hors ENTITIES, donc hors synchro, empreintes et fusion.
   Clé = sha256(trackId|destinataire en minuscules). Un même trackId ne part qu'une fois vers une
   même adresse : un nouvel essai après une réponse perdue répond « déjà envoyé » sans SMTP.
   Le verrou 'envois' couvre lecture, envoi SMTP et écriture : un second essai simultané attend,
   puis lit « envoyé ». Ordre des contrôles : envoyé, incertain, même document < 24 h, plafond. */
function envoisLire($DATA_DIR){ $v = readJson("$DATA_DIR/_envois.json"); return is_array($v) ? $v : []; }
function plafondJour(){ $v = getenv('SMTP_MAX_JOUR');                                   // absente ou vide : 500 ; "0" : sans plafond
  return ($v === false || trim($v) === '') ? 500 : max(0, (int)$v); }
function envoiUnique($DATA_DIR, $m, $envoyer){
  $to  = strtolower(trim((string)($m['to'] ?? '')));
  $tid = substr((string)($m['trackId'] ?? ''), 0, 200);
  $cle = $tid !== '' ? hash('sha256', $tid.'|'.$to) : 'x'.bin2hex(random_bytes(8));   // sans trackId : journalisé, jamais dédoublonné
  $ref = substr((string)($m['ref'] ?? ''), 0, 120);
  $max = plafondJour();                                                                  // 0 = sans plafond
  $iso = function($t){ return gmdate('Y-m-d\\TH:i:s\\Z', (int)$t); };
  return sousVerrou($DATA_DIR, 'envois', function() use ($DATA_DIR, $m, $envoyer, $to, $tid, $cle, $ref, $max, $iso) {
    $reg = envoisLire($DATA_DIR); $now = time(); $e = $reg[$cle] ?? null;
    if ($e && ($e['s'] ?? '') === 'envoyé')
      return ['ok'=>true, 'deja'=>true, 'type'=>'ok', 'at'=>$iso($e['t'] ?? 0), 'info'=>'déjà envoyé, rien renvoyé'];
    if ($e && ($e['s'] ?? '') === 'incertain' && empty($m['forcer']))
      return ['ok'=>false, 'code'=>'incertain', 'type'=>'incertain', 'at'=>$iso($e['t'] ?? 0), 'info'=>"le serveur mail n'a pas confirmé le premier envoi"];
    if ($ref !== '' && empty($m['confirme'])) foreach ($reg as $k => $x) {      // même document, même destinataire, autre essai, < 24 h
      if ($k !== $cle && ($x['ref'] ?? '') === $ref && ($x['to'] ?? '') === $to
          && in_array($x['s'] ?? '', ['envoyé','incertain'], true) && ($x['t'] ?? 0) > $now - 86400)
        return ['ok'=>false, 'code'=>'recent', 'type'=>'recent', 'at'=>$iso($x['t']), 's'=>$x['s'], 'sujet'=>$x['sujet'] ?? '',
                'info'=>'ce document est déjà parti vers cette adresse il y a moins de 24 h'];
    }
    $n = 0; foreach ($reg as $x) if (($x['s'] ?? '') !== 'échec' && ($x['t'] ?? 0) > $now - 86400) $n++;
    if ($max > 0 && $n >= $max) { alertePlafond($DATA_DIR, $max);
      return ['ok'=>false, 'code'=>'plafond', 'type'=>'plafond', 'info'=>"plafond de sécurité atteint : $max emails sur 24 h"]; }
    $r = $envoyer(); $ok = !empty($r[0]); $info = (string)($r[1] ?? ''); $type = (string)($r[2] ?? ($ok ? 'ok' : 'refus'));
    $incertain = !$ok && $type === 'incertain';
    $reg[$cle] = ['s'=>$ok ? 'envoyé' : ($incertain ? 'incertain' : 'échec'), 't'=>$now, 'to'=>$to, 'tid'=>$tid, 'ref'=>$ref,
      'sujet'=>mb_substr((string)($m['subject'] ?? ''), 0, 80), 'pj'=>(int)($m['pj'] ?? 0), 'o'=>(string)($m['origine'] ?? ''), 'info'=>mb_substr($info, 0, 160)];
    foreach ($reg as $k => $x) if (($x['t'] ?? 0) < $now - 90*86400) unset($reg[$k]);   // 90 jours
    writeJson("$DATA_DIR/_envois.json", $reg);
    return ['ok'=>$ok, 'info'=>$info, 'type'=>$type] + ($incertain ? ['code'=>'incertain'] : []);
  });
}
function alertePlafond($DATA_DIR, $max){   // une alerte par jour, adressée à Louis lui-même
  $f = "$DATA_DIR/.alerte-plafond"; if (@file_get_contents($f) === gmdate('Y-m-d')) return;
  @file_put_contents($f, gmdate('Y-m-d'));
  $moi = getenv('SMTP_FROM') ?: getenv('SMTP_USER');
  if ($moi) @smtpSend($moi, "CRM : plafond d'envoi atteint", "Le serveur a bloqué un envoi : $max emails partis sur les dernières 24 h.\nSi ce n'est pas toi, change le mot de passe du CRM.\nDétail : Réglages, bouton « Emails partis du serveur ».");
}

/* Historique : toute version remplacée ou supprimée est conservée (journal
   mensuel en ajout seul). Une modification n'est photographiée qu'une fois
   par quart d'heure et par fiche — sinon l'enregistrement automatique d'un
   brief en cours de frappe remplirait le disque. Une suppression l'est toujours. */
function historiser($DATA_DIR, $e, $existantes, $fusion){
  if ($e === 'activite') return;
  $apres = [];
  foreach ((array)$fusion as $r) if (is_array($r) && isset($r['id'])) $apres[(string)$r['id']] = $r;
  $dir = "$DATA_DIR/_historique"; if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $idxF = "$dir/.derniers.json"; $idx = readJson($idxF); if (!is_array($idx)) $idx = [];
  $at = nowTs(); $lim = gmdate('Y-m-d\\TH:i:s.000\\Z', time() - 15*60);
  $lignes = ''; $idxModif = false;
  foreach ((array)$existantes as $r) {
    if (!is_array($r) || !isset($r['id'])) continue;
    $k = (string)$r['id'];
    if (!isset($apres[$k])) $raison = 'suppression';
    elseif (json_encode($apres[$k]) !== json_encode($r)) {
      $cle = "$e|$k";
      if (isset($idx[$cle]) && strcmp((string)$idx[$cle], $lim) > 0) continue;   // photo récente : inutile
      $raison = 'modification';
    }
    else continue;
    $lignes .= json_encode(['at'=>$at,'entity'=>$e,'id'=>$k,'raison'=>$raison,'avant'=>$r],
                           JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    $idx["$e|$k"] = $at; $idxModif = true;
  }
  if ($lignes === '') return;
  @file_put_contents("$dir/".gmdate('Y-m').".jsonl", $lignes, FILE_APPEND | LOCK_EX);
  if ($idxModif) {
    foreach ($idx as $c => $t) if (strcmp((string)$t, gmdate('Y-m-d', time() - 2*86400)) < 0) unset($idx[$c]);
    writeJson($idxF, $idx);
  }
  foreach ((array)@glob("$dir/*.jsonl") as $vieux) {                // 24 mois d'historique
    if (@filemtime($vieux) < time() - 730*86400) @unlink($vieux);
  }
}
/* Libellé lisible d'une fiche, pour la liste de l'historique */
function resumeFiche($r){
  foreach (['nom','nomClient','titre','label','client','objet','subject','txt'] as $c) {
    if (!empty($r[$c]) && is_string($r[$c])) return trim(((string)($r['prenom'] ?? '')).' '.$r[$c]);
  }
  return (string)($r['id'] ?? '');
}
/* Empreinte d'une table : id + date de modification de chaque fiche, triés.
   Deux appareils qui affichent la même empreinte ont exactement les mêmes versions. */
function empreinte($rows){
  $l = [];
  foreach ((array)$rows as $r) if (is_array($r) && isset($r['id'])) $l[] = [(string)$r['id'], (string)($r['updatedAt'] ?? '')];
  usort($l, function($a, $b){ return strcmp($a[0], $b[0]); });
  $s = ''; foreach ($l as $x) $s .= $x[0]."\t".$x[1]."\n";
  return ['n'=>count($l), 'h'=>substr(hash('sha256', $s), 0, 12)];
}

/* Copie de sécurité quotidienne avant la première écriture du jour */
function backupJour($DATA_DIR, $e){
  $src = "$DATA_DIR/$e.json"; if (!is_file($src)) return;
  $dir = "$DATA_DIR/_bak"; if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $dst = "$dir/$e-".gmdate('Y-m-d').".json";
  if (!is_file($dst)) @copy($src, $dst);
  foreach ((array)@glob("$dir/$e-*.json") as $f) {              // on garde 14 jours
    if (@filemtime($f) < time() - 14*86400) @unlink($f);
  }
}
function writeJson($path,$val){ // écriture atomique (tmp + rename), jamais de fichier vide ni tronqué
  $json = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false || $json === '') return false;          // encodage impossible → on ne touche à rien
  $tmp = $path.'.tmp'.getmypid();
  $n = @file_put_contents($tmp, $json, LOCK_EX);
  if ($n !== strlen($json)) { @unlink($tmp); return false; }   // écriture partielle (disque plein) → abandon
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  return true; }

/* En-tête MIME (RFC 2047) : ASCII imprimable tel quel, sinon mots encodés de 42 octets UTF-8 au plus,
   pliés par CRLF+espace. Tout caractère de contrôle devient un espace : aucune injection d'en-tête. */
function enteteMime($s){
  $s = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$s));   // pas de /u : jamais null sur de l'UTF-8 invalide
  if ($s === '') return '';
  if (!preg_match('/[\x80-\xFF]/', $s) && strpos($s, '=?') === false && strlen($s) <= 900) return $s;
  $car = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
  if ($car === false) $car = [$s];                                     // UTF-8 invalide : un seul mot, comme avant
  $mots = []; $cur = '';
  foreach ($car as $c) { if ($cur !== '' && strlen($cur) + strlen($c) > 42) { $mots[] = $cur; $cur = ''; } $cur .= $c; }
  $mots[] = $cur;
  return implode("\r\n ", array_map(function($m){ return '=?UTF-8?B?'.base64_encode($m).'?='; }, $mots));
}

/* ═══ Clé des liens de désabonnement ═══
   Propre au serveur, jamais la formKey publique du formulaire du site. Créée une fois, jamais remplacée.
   desaboInit ne prend aucun verrou (appelée dans putConfig, sous le verrou 'config').
   Ne JAMAIS appeler desaboCles sous le verrou 'config' : flock n'est pas réentrant. */
function desaboInit(array &$cfg){
  $modif = false;
  if (!is_string($cfg['desaboKey'] ?? null) || $cfg['desaboKey'] === '') {
    $cfg['desaboKey'] = bin2hex(random_bytes(24)); $modif = true;
  }
  if (!array_key_exists('desaboLegacy', $cfg)) {        // clé qui signait les liens envoyés avant cette version
    $cfg['desaboLegacy'] = (string)($cfg['formKey'] ?? '');
    $cfg['desaboLegacyJusqua'] = date('Y-m-d', strtotime('+1 year'));
    $modif = true;
  }
  return $modif;
}
function desaboCles($DATA_DIR){
  return sousVerrou($DATA_DIR, 'config', function() use ($DATA_DIR) {
    $f = "$DATA_DIR/config.json"; $cfg = readJson($f);
    if (!is_array($cfg)) { if (is_file($f)) return ''; $cfg = []; }   // config illisible : on n'écrase rien
    $avant = (string)($cfg['desaboKey'] ?? '');
    if (desaboInit($cfg) && !writeJson($f, $cfg)) return $avant;      // écriture ratée : seule une clé déjà enregistrée sert
    return (string)$cfg['desaboKey'];
  });
}
/* Même calcul que hmac16 du CRM : HMAC-SHA256 de l'adresse en minuscules, 16 caractères hexadécimaux */
function desaboSig($em, $cle){ return substr(hash_hmac('sha256', strtolower(trim((string)$em)), (string)$cle), 0, 16); }
/* 'lien' (clé serveur), 'lien-ancien' (formKey d'avant, vrai destinataire, moins de 12 mois) ou '' */
function desaboVerifier($DATA_DIR, $cfg, $em, $sig){
  if ($em === '' || $sig === '') return '';
  $cle = (string)($cfg['desaboKey'] ?? '');
  if ($cle !== '' && hash_equals(desaboSig($em, $cle), $sig)) return 'lien';
  $anc = array_key_exists('desaboLegacy', $cfg) ? (string)$cfg['desaboLegacy'] : (string)($cfg['formKey'] ?? '');
  $jusqua = (string)($cfg['desaboLegacyJusqua'] ?? '');
  if ($anc === '' || ($jusqua !== '' && date('Y-m-d') > $jusqua)) return '';
  if (!hash_equals(desaboSig($em, $anc), $sig)) return '';
  foreach (['mails', 'planifs'] as $t) {             // la formKey est publique : seuls les vrais destinataires passent
    $rows = readJson("$DATA_DIR/$t.json"); if (!is_array($rows)) continue;
    foreach ($rows as $r) {
      if (is_array($r) && strtolower(trim((string)($r['to'] ?? ''))) === $em
          && ($t === 'planifs' || ($r['kind'] ?? '') === 'campagne')) return 'lien-ancien';
    }
  }
  return '';
}
/* URL One-Click (RFC 8058) calculée par le serveur pour le destinataire réel. https forcé :
   derrière Traefik, $_SERVER['HTTPS'] est vide. CRM_PUBLIC_URL (facultatif) prime. */
function urlDesabo($DATA_DIR, $to){
  static $cle = null;
  $to = trim((string)$to);
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return '';
  if ($cle === null) {
    $cfg = readJson("$DATA_DIR/config.json");
    $cle = is_array($cfg) ? (string)($cfg['desaboKey'] ?? '') : '';
    if ($cle === '' && is_file("$DATA_DIR/config.json")) $cle = desaboCles($DATA_DIR);   // jamais sur un serveur neuf
  }
  if ($cle === '') return '';
  $base = getenv('CRM_PUBLIC_URL') ?: ('https://'.($_SERVER['HTTP_HOST'] ?? '').($_SERVER['SCRIPT_NAME'] ?? '/api.php'));
  if (!preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?/[A-Za-z0-9._/-]*$#', $base)) return '';
  return $base.'?action=desabo&e='.rawurlencode($to).'&s='.desaboSig($to, $cle);
}

/* ═══ Registre des oppositions (désabonnements), par ADRESSE ═══
   Fichier du serveur, hors $ENTITIES : aucun appareil ne l'écrit. Ni la fusion par updatedAt,
   ni un DB.remove, ni un appareil en retard ne peuvent l'effacer.
   Forme : {"adresse@minuscules": {"le": ISO, "source": "lien|lien-ancien|un-clic|manuel|fiche|sauvegarde|historique",
            "note"?: texte, "leveLe"?: ISO, "leveNote"?: texte}}
   Une entrée reste active tant qu'aucune levée postérieure n'existe.
   Ne jamais appeler desabosAmorcer ni desabosSet depuis un sousVerrou('desabos') : flock n'est pas réentrant.
   Sous ce verrou, utiliser desabosUnion, qui ne verrouille et n'écrit rien. */
function desaboNorm($e){ return strtolower(trim((string)$e)); }
function desaboUtc($v){ $v = (string)$v; $t = ($v === '') ? false : strtotime($v);
  return $t === false ? $v : gmdate('Y-m-d\\TH:i:s.000\\Z', $t); }            // même format que nowTs()
function desabosLire($DATA_DIR){ $v = readJson("$DATA_DIR/_desabos.json"); return is_array($v) ? $v : []; }
function desaboActif($x){ return is_array($x) && (empty($x['leveLe']) || strcmp((string)($x['le'] ?? ''), (string)$x['leveLe']) > 0); }
/* Une seule fois : reprend les drapeaux actuels, les copies du jour (_bak, 14 j) et l'historique
   (_historique, 24 mois) pour retrouver les désabonnements déjà effacés par une synchro. */
function desabosAmorcer($DATA_DIR){
  if (is_file("$DATA_DIR/_desabos.json")) return;
  sousVerrou($DATA_DIR, 'desabos', function() use ($DATA_DIR) {
    if (is_file("$DATA_DIR/_desabos.json")) return;                // un autre appel l'a fait entre-temps
    $reg = [];
    $note = function($c, $src) use (&$reg) {
      if (!is_array($c) || empty($c['desabo']) || empty($c['email'])) return;
      $em = desaboNorm($c['email']); if ($em === '' || isset($reg[$em])) return;
      $le = desaboUtc($c['desaboLe'] ?? '');
      $reg[$em] = ['le' => $le !== '' ? $le : nowTs(), 'source' => $src];
    };
    foreach ((array)readJson("$DATA_DIR/clients.json") as $c) $note($c, 'fiche');
    foreach ((array)@glob("$DATA_DIR/_bak/clients-*.json") as $f) foreach ((array)readJson($f) as $c) $note($c, 'sauvegarde');
    foreach ((array)@glob("$DATA_DIR/_historique/*.jsonl") as $f) {
      $h = @fopen($f, 'r'); if (!$h) continue;
      while (($l = fgets($h)) !== false) {
        if (strpos($l, '"entity":"clients"') === false || strpos($l, '"desabo":true') === false) continue;
        $x = json_decode($l, true); if (is_array($x)) $note($x['avant'] ?? null, 'historique');
      }
      fclose($h);
    }
    writeJson("$DATA_DIR/_desabos.json", $reg ?: new stdClass());
  });
}
/* Ajout idempotent : une opposition active garde sa date d'origine et le fichier n'est pas réécrit */
function desaboAjouter($DATA_DIR, $em, $source){
  $em = desaboNorm($em); if ($em === '' || strpos($em, '@') === false) return false;
  desabosAmorcer($DATA_DIR);
  return sousVerrou($DATA_DIR, 'desabos', function() use ($DATA_DIR, $em, $source) {
    $r = desabosLire($DATA_DIR);
    if (desaboActif($r[$em] ?? null)) return true;
    $r[$em] = array_merge(is_array($r[$em] ?? null) ? $r[$em] : [], ['le' => nowTs(), 'source' => $source]);
    unset($r[$em]['note']);                                        // la note d'une ancienne opposition manuelle ne suit pas
    return writeJson("$DATA_DIR/_desabos.json", $r);
  });
}
/* Adresses opposées, à partir d'un registre déjà lu : registre actif + drapeaux de clients.json
   absents du registre (appli en cache). Aucun verrou, aucune écriture. */
function desabosUnion($DATA_DIR, $reg){
  $out = [];
  foreach ((array)$reg as $em => $x) if (desaboActif($x)) $out[$em] = $x;
  foreach ((array)readJson("$DATA_DIR/clients.json") as $c) {
    if (!is_array($c) || empty($c['desabo']) || empty($c['email'])) continue;
    $em = desaboNorm($c['email']);
    if ($em !== '' && !isset($reg[$em]) && !isset($out[$em])) $out[$em] = ['le' => desaboUtc($c['desaboLe'] ?? ''), 'source' => 'fiche'];
  }
  return $out;
}
/* Idem, en amorçant le registre si besoin. $persister : inscrit au registre les drapeaux qui n'y sont pas,
   pour qu'une synchro ne puisse plus les effacer. Une adresse levée (leveLe) ne revient pas par un vieux drapeau. */
function desabosSet($DATA_DIR, $persister = false){
  desabosAmorcer($DATA_DIR);
  $reg = desabosLire($DATA_DIR);
  $out = desabosUnion($DATA_DIR, $reg);
  $manq = []; foreach ($out as $em => $x) if (!isset($reg[$em])) $manq[$em] = $x;
  if ($persister && $manq) sousVerrou($DATA_DIR, 'desabos', function() use ($DATA_DIR, $manq) {
    $r = desabosLire($DATA_DIR); $n = 0;
    foreach ($manq as $em => $x) if (!isset($r[$em])) { $r[$em] = $x; $n++; }
    if ($n) writeJson("$DATA_DIR/_desabos.json", $r);
  });
  return $out;
}

$raw = file_get_contents('php://input');
// Les pages publiques (code d'accès d'un brief, accusé de lecture, formulaire agence)
// envoient un formulaire HTML classique : PHP l'a déjà décodé dans $_POST et le corps
// n'est pas du JSON. Seules les requêtes du CRM transportent du JSON.
$estFormulaire = !empty($_POST);
$req = (!$estFormulaire && $raw !== '') ? json_decode($raw, true) : [];
if (!is_array($req)) $req = [];
$action = $_GET['action'] ?? ($req['action'] ?? '');
$auth   = $_GET['auth']   ?? ($req['auth']   ?? '');   // sha256(mot de passe) envoyé par le CRM
$token  = $_GET['token']  ?? ($req['token']  ?? '');   // legacy / Apps Script

// Un corps JSON illisible (POST tronqué, JSON invalide) ne doit JAMAIS passer pour un succès
if (!$estFormulaire && $raw !== '' && json_decode($raw, true) === null && json_last_error() !== JSON_ERROR_NONE) {
  out(['ok'=>false, 'error'=>'corps de requête illisible (tronqué ou trop volumineux)']);
}
if ($action === '' && $raw === '' && empty($_GET)) out(['ok'=>true, 'msg'=>'CRM LouisMagie API (PHP) en ligne']);
if ($action === 'ping') out(['ok'=>true, 'msg'=>'CRM LouisMagie API (PHP) en ligne']);
if ($action === '') out(['ok'=>false, 'error'=>'action manquante']);

if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0775, true);
if (!is_dir($DATA_DIR)) out(['ok'=>false, 'error'=>'dossier data non créable']);

/* ===== Pixel de suivi d'ouverture (public, pas d'auth) ===== */
if ($action === 'track') {
  $m = $_GET['m'] ?? '';
  if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)$m)) $m = '';   // identifiant de suivi invalide → ignoré
  if ($m !== '') { $f=$DATA_DIR.'/_opens.json'; $arr=readJson($f); if(!is_array($arr))$arr=[];
    if(!array_filter($arr, function($o) use($m){ return ($o['m']??'')===$m; })) $arr[]=['m'=>$m,'at'=>date('c')];
    writeJson($f,$arr); }
  header('Content-Type: image/gif'); header('Cache-Control: no-store');
  echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); exit;
}

/* ===== Brief d'équipe : page privée par projet (lien secret + code d'accès optionnel) ===== */
if ($action === 'brief') {
  $id = $_GET['id'] ?? ''; $k = $_GET['k'] ?? '';
  $projets = readJson("$DATA_DIR/projets.json"); if(!is_array($projets)) $projets=[];
  $p = null; foreach($projets as $x){ if(($x['id']??'')===$id){ $p=$x; break; } }
  $b = $p['brief'] ?? null;
  $ok = $p && is_array($b) && !empty($b['token']) && hash_equals((string)$b['token'], (string)$k) && !empty($b['publie']);
  header('Content-Type: text/html; charset=utf-8');
  $H = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
  // ── Marque : le logo si Louis en a chargé un, sinon le mot-symbole
  $cfgB = readJson("$DATA_DIR/config.json"); if(!is_array($cfgB)) $cfgB = [];
  // Le logo brut peut être noir sur noir : on ne l'affiche que si une version
  // lisible sur fond sombre existe — publiée par le CRM, ou calculable ici.
  $aLogo = !empty($cfgB['logoOnDark'])
        || (!empty($cfgB['logo']) && function_exists('imagecreatefromstring'));
  // Le logo porte déjà le mot « LouisMagie » : on ne le réécrit pas à côté
  // (même règle que les PDF, pilotée par le réglage « écrire le nom à côté du logo »).
  $nomAcote = !$aLogo || ($cfgB['pdfAfficherNom'] ?? false) === true;
  $marque = function($cls='mark') use ($aLogo) {
    return $aLogo ? '<img class="'.$cls.'" src="?action=logo&v=dark" alt="LouisMagie">' : '';
  };
  // ── Coquille commune à la charte
  $page = function($corps, $titre='Brief équipe', $htmlLang='fr') use ($H) {
    return '<!doctype html><html lang="'.$H($htmlLang).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      .'<meta name="color-scheme" content="dark"><meta name="robots" content="noindex,nofollow">'
      .'<title>'.$H($titre).' — LouisMagie</title>'
      .'<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
      .'<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">'
      .'<style>'
      .'*{margin:0;padding:0;box-sizing:border-box}'
      .':root{--or:#FF7700;--or-deep:#E56200;--noir:#0A0A08;--noir2:#141410;--noir3:#1E1E1A;--anthr:#2C2C28;--creme:#F5F2EE;--gc:#C8C3BB;--gm:#8A8580}'
      .'html,body{background:var(--noir)}'
      .'body{font-family:"DM Sans",-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;font-weight:300;color:var(--creme);line-height:1.65;font-size:16px;padding:0 16px 70px;-webkit-text-size-adjust:100%}'
      .'.wrap{max-width:760px;margin:0 auto}'
      .'.eyebrow{font-family:Syne,sans-serif;font-size:10px;letter-spacing:3.5px;text-transform:uppercase;color:var(--or);font-weight:700;text-align:center}'
      .'header{padding:54px 0 30px;text-align:center;border-bottom:1px solid var(--anthr);position:relative}'
      .'header::before{content:"";position:absolute;top:-40px;left:50%;transform:translateX(-50%);width:420px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(255,119,0,.12) 0%,transparent 68%);pointer-events:none}'
      .'header .eyebrow{margin-bottom:16px;position:relative}'
      .'h1{font-family:Syne,sans-serif;font-weight:800;font-size:clamp(32px,7vw,52px);line-height:1;letter-spacing:-1.5px;color:#fff;position:relative}'
      .'h1 .g{color:var(--or)}'
      .'.lede{color:var(--gm);font-size:14px;max-width:38em;margin:16px auto 0;position:relative}'
      .'section{padding:30px 0;border-bottom:1px solid var(--anthr)}'
      .'.cat{font-family:Syne,sans-serif;font-size:10px;font-weight:700;letter-spacing:3.5px;text-transform:uppercase;color:var(--or);text-align:center;margin:38px 0 2px}'
      .'h2{font-family:Syne,sans-serif;font-weight:800;font-size:23px;color:#fff;margin-bottom:12px;letter-spacing:-.3px}'
      .'h2 .n{color:var(--or);font-size:12px;vertical-align:super;margin-right:9px;font-family:"DM Sans",sans-serif;font-weight:700}'
      .'p{margin-bottom:13px;color:var(--gc)}'
      .'strong,b{color:#fff;font-weight:500}'
      .'.quote{font-style:italic;color:var(--or);font-size:18px;line-height:1.55;border-left:2px solid var(--or);padding-left:18px;margin:10px 0 6px}'
      .'.callout{background:linear-gradient(160deg,var(--noir3),var(--noir2));border:1px solid rgba(255,119,0,.4);border-radius:14px;padding:24px;text-align:center;margin:12px 0}'
      .'.callout .big{font-family:Syne,sans-serif;font-weight:800;font-size:34px;color:var(--or);line-height:1}'
      .'.callout .small{color:var(--gc);font-size:14px;margin-top:9px}'
      .'.cols{display:grid;grid-template-columns:1fr;gap:12px;margin-top:8px}'
      .'.col{background:var(--noir2);border:1px solid var(--anthr);border-radius:14px;padding:16px 18px}'
      .'.col .h{font-family:Syne,sans-serif;font-size:10px;letter-spacing:2px;text-transform:uppercase;font-weight:700;margin-bottom:10px}'
      .'.col.do .h{color:#3FB97A}.col.dont .h{color:#E5564B}'
      .'.col ul{list-style:none}.col li{position:relative;padding:5px 0 5px 20px;font-size:14px;color:var(--creme)}'
      .'.col.do li::before{content:"\\2713";position:absolute;left:0;color:#3FB97A;font-size:12px;top:7px}'
      .'.col.dont li::before{content:"\\2715";position:absolute;left:0;color:#E5564B;font-size:12px;top:7px}'
      .'.menu-intro{color:var(--gm);font-size:14px;margin-bottom:10px}'
      .'.role{margin:18px 0}'
      .'.role h3{font-family:Syne,sans-serif;font-size:11px;letter-spacing:2.5px;text-transform:uppercase;color:var(--or);margin-bottom:10px;font-weight:700}'
      .'.role ul{list-style:none}'
      .'.role li{position:relative;padding:6px 0 6px 20px;color:var(--creme);font-size:15px}'
      .'.role li::before{content:"";position:absolute;left:0;top:14px;width:6px;height:6px;border-radius:50%;background:var(--or)}'
      .'.role .ref{color:var(--or);font-size:.84em;font-style:italic;opacity:.9}'
      .'.mag{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}'
      .'.mag span{background:var(--noir2);border:1px solid var(--anthr);border-radius:100px;padding:6px 14px;font-size:13px;color:var(--gc)}'
      .'.prat{display:grid;grid-template-columns:1fr;gap:10px;margin-top:8px}'
      .'.pr{background:var(--noir2);border:1px solid var(--anthr);border-radius:12px;padding:14px 17px}'
      .'.pr .k{font-family:Syne,sans-serif;font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--or);font-weight:700;margin-bottom:4px}'
      .'.pr .v{font-size:14.5px;color:var(--creme)}.pr .v b{color:#fff}'
      .'.pr a{color:var(--or);text-decoration:none}'
      .'.tl{margin-top:10px;position:relative;padding-left:6px}'
      .'.tl-i{display:flex;gap:16px;padding:9px 0;border-bottom:1px solid var(--anthr)}'
      .'.tl-i:last-child{border-bottom:none}'
      .'.tl-h{font-family:Syne,sans-serif;font-weight:700;font-size:15px;color:var(--or);min-width:74px;flex-shrink:0}'
      .'.tl-v{color:var(--creme);font-size:15px}'
      .'ul.chk{list-style:none;margin-top:8px}'
      .'ul.chk li{position:relative;padding:7px 0 7px 30px;color:var(--creme);font-size:15px;border-bottom:1px solid var(--anthr)}'
      .'ul.chk li:last-child{border-bottom:none}'
      .'ul.chk li::before{content:"";position:absolute;left:0;top:11px;width:15px;height:15px;border:1.5px solid var(--or);border-radius:4px}'
      .'.lu-f{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}'
      .'.lu-f select,.lu-f input{flex:1;min-width:190px;padding:13px;border:1.5px solid var(--anthr);border-radius:8px;background:var(--noir2);color:var(--creme);font-size:16px;font-family:inherit;outline:none}'
      .'.lu-f select:focus,.lu-f input:focus{border-color:var(--or)}'
      .'.lu-f button{padding:13px 26px;background:var(--or);color:#fff;border:none;border-radius:4px;font-family:Syne,sans-serif;font-weight:700;font-size:11px;letter-spacing:2px;text-transform:uppercase;cursor:pointer}'
      .'.ok-lu{background:var(--noir2);border:1px solid rgba(63,185,122,.5);border-left:3px solid #3FB97A;border-radius:12px;padding:18px;margin-top:14px;color:var(--creme)}'
      .'.note{background:var(--noir2);border:1px solid rgba(255,119,0,.4);border-radius:12px;padding:15px 17px;margin-top:20px;font-size:14px;color:var(--creme);line-height:1.65}'
      .'.close{font-family:Syne,sans-serif;font-weight:700;font-size:20px;color:var(--creme);text-align:center;padding:34px 0 8px;line-height:1.4}'
      .'.close b{color:var(--or)}'
      .'.contact{text-align:center;color:var(--gm);font-size:13.5px;padding-bottom:12px}.contact b{color:var(--creme)}'
      .'.wa{display:inline-block;margin-top:14px;background:var(--or);color:#fff;text-decoration:none;font-family:Syne,sans-serif;font-weight:700;font-size:11px;letter-spacing:2px;text-transform:uppercase;padding:14px 28px;border-radius:4px;box-shadow:0 4px 20px rgba(255,119,0,.28)}'
      .'footer{text-align:center;color:var(--gm);font-size:11px;margin-top:10px;border-top:1px solid var(--anthr);padding-top:18px;letter-spacing:.5px}'
      .'.gate{max-width:420px;margin:14vh auto;background:var(--noir2);border:1px solid var(--anthr);border-top:3px solid var(--or);border-radius:14px;padding:40px 32px;text-align:center}'
      .'.gate h1{font-size:24px;margin-bottom:10px}.gate p{font-size:14px;margin-bottom:22px}'
      .'.gate input{width:100%;padding:14px;border:1.5px solid var(--anthr);border-radius:8px;background:var(--noir3);color:var(--creme);font-size:16px;font-family:inherit;outline:none;text-align:center;letter-spacing:2px}'
      .'.gate input:focus{border-color:var(--or)}'
      .'.gate button{width:100%;margin-top:14px;padding:15px;background:var(--or);color:#fff;border:none;border-radius:4px;font-family:Syne,sans-serif;font-weight:700;font-size:12px;letter-spacing:2px;text-transform:uppercase;cursor:pointer}'
      .'.err{color:#E5564B;font-size:13px;margin-top:12px}'
      .'.mark{display:block;margin:0 auto 20px;height:64px;width:auto;max-width:72%;object-fit:contain}'
      .'.mark-f{display:block;margin:0 auto 14px;height:34px;width:auto;opacity:.55;object-fit:contain}'
      .'.gate .mark{height:52px;margin-bottom:18px}'
      .'.lang{display:flex;gap:6px;justify-content:center;padding-top:18px}'
      .'.lang a{font-family:Syne,sans-serif;font-size:10px;font-weight:700;letter-spacing:2px;color:var(--gm);text-decoration:none;border:1px solid var(--anthr);border-radius:100px;padding:6px 14px}'
      .'.lang a.on{color:#fff;border-color:var(--or);background:rgba(255,119,0,.12)}'
      .'@media(min-width:620px){.cols{grid-template-columns:1fr 1fr}.prat{grid-template-columns:1fr 1fr}}'
      .'</style></head><body>'.$corps.'</body></html>';
  };

  $wantEN = ($_GET['lang'] ?? '') === 'en';   // le magicien anglophone arrive avec &lang=en
  if(!$ok){
    echo $page('<div class="gate">'.($aLogo ? $marque() : '<div class="eyebrow" style="margin-bottom:18px">Louis<span style="color:#fff">Magie</span></div>')
      .'<h1>'.($wantEN?'Brief unavailable':'Brief indisponible').'</h1><p>'
      .($wantEN?'This link is not valid, or the brief is not published yet.<br>Contact Louis to get the right link.'
              :'Ce lien n\'est pas valide, ou le brief n\'est pas encore publié.<br>Contacte Louis pour recevoir le bon lien.').'</p>'
      .'<a class="wa" href="mailto:contact@louismagie.fr">'.($wantEN?'Email Louis':'Écrire à Louis').'</a></div>',
      $wantEN?'Brief unavailable':'Brief indisponible', $wantEN?'en':'fr');
    exit;
  }

  // ── Code d'accès équipe (optionnel)
  $code = trim((string)($b['code'] ?? ''));
  if($code !== ''){
    $saisi = (string)($_POST['code'] ?? $_GET['c'] ?? '');
    if(!hash_equals(mb_strtoupper($code), mb_strtoupper(trim($saisi)))){
      $err = $saisi!=='' ? '<div class="err">'.($wantEN?'Wrong code.':'Code incorrect.').'</div>' : '';
      $titreG = $wantEN && !empty($b['en']['titre']) ? $b['en']['titre'] : ($b['titre'] ?? 'Imagine the Impossible');
      echo $page('<form class="gate" method="post" action="'.$H('?action=brief&id='.rawurlencode($id).'&k='.rawurlencode($k).($wantEN?'&lang=en':'')).'">'
        .($aLogo ? $marque() : '<div class="eyebrow" style="margin-bottom:18px">Louis<span style="color:#fff">Magie</span></div>')
        .'<h1>'.($wantEN?'Team brief':'Brief équipe').'</h1><p>'.$H($titreG).'<br>'
        .($wantEN?'Enter the code Louis sent you.':'Entre le code transmis par Louis.').'</p>'
        .'<input name="code" placeholder="CODE" autocapitalize="characters" autofocus>'
        .'<button type="submit">'.($wantEN?'Open the brief':'Accéder au brief').'</button>'.$err.'</form>',
        $wantEN?'Brief access':'Accès au brief', $wantEN?'en':'fr');
      exit;
    }
  }

  // ── Langue : le français est la base, l'anglais vit dans brief.en (mêmes champs)
  $aEN  = !empty($b['en']) && is_array($b['en']);
  $lang = ($aEN && ($_GET['lang'] ?? '') === 'en') ? 'en' : 'fr';
  if($lang === 'en'){
    $v = $b['en'];
    foreach(['waLouis','token'] as $kk) if(!isset($v[$kk]) && isset($b[$kk])) $v[$kk] = $b[$kk];
    $v['lus'] = $b['lus'] ?? [];   // les accusés de lecture restent communs aux deux versions
    $b = $v;
  }
  $T = $lang === 'en' ? [
    'concept'=>'The concept','catE'=>'Part one · The mindset','catM'=>'Part two · The menu of illusions',
    'catP'=>'Part three · Practical details','do'=>'Do','dont'=>'Don\'t','deroule'=>'The run of the night',
    'zones'=>'Who covers what','contacts'=>'Contacts on site','contact'=>'Contact','avant'=>'Before you leave',
    'equipe'=>'The team','lu'=>'Read it all?',
    'luTexte'=>'Let me know, so I can see everyone is up to date — saves me chasing you.',
    'luChoix'=>'— Pick your name —','luDeja'=>' ✓ (already confirmed)','luPrenom'=>'Your first name',
    'luBtn'=>'I have read the brief','luOk'=>'Noted, thank you!','luOk2'=>'Louis knows you are up to date. See you very soon.',
    'contactDef'=>'Any question, message me directly on WhatsApp.','wa'=>'Message Louis',
    'foot'=>' — confidential document',
  ] : [
    'concept'=>'Le concept','catE'=>'Première partie · L\'état d\'esprit','catM'=>'Deuxième partie · Le menu d\'illusions',
    'catP'=>'Troisième partie · Le cadre pratique','do'=>'À faire','dont'=>'À éviter','deroule'=>'Le déroulé',
    'zones'=>'Qui couvre quoi','contacts'=>'Contacts sur place','contact'=>'Contact','avant'=>'Avant de partir',
    'equipe'=>'L\'équipe','lu'=>'Tu as tout lu ?',
    'luTexte'=>'Signale-le-moi pour que je sache que tout le monde est à jour — ça m\'évite de relancer.',
    'luChoix'=>'— Choisis ton nom —','luDeja'=>' ✓ (déjà signalé)','luPrenom'=>'Ton prénom',
    'luBtn'=>'J\'ai lu le brief','luOk'=>'C\'est noté, merci !','luOk2'=>'Louis sait que tu es à jour. À très vite.',
    'contactDef'=>'La moindre question, écris-moi directement sur WhatsApp.','wa'=>'Écrire à Louis',
    'foot'=>' — document confidentiel',
  ];
  // lien de bascule : on conserve le code d'accès déjà saisi
  $urlLang = function($l) use ($id, $k, $code, $H) {
    $u = '?action=brief&id='.rawurlencode($id).'&k='.rawurlencode($k);
    if($code !== '') $u .= '&c='.rawurlencode($code);
    if($l === 'en') $u .= '&lang=en';
    return $H($u);
  };

  // ── Rendu du brief
  $md = function($t) use ($H) {   // gras **texte** + retours à la ligne
    $t = $H($t);
    $t = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $t);
    return nl2br($t);
  };
  $o = '<div class="wrap">';
  if($aEN){
    $o .= '<div class="lang">'
      .'<a href="'.$urlLang('fr').'"'.($lang==='fr'?' class="on"':'').'>FR</a>'
      .'<a href="'.$urlLang('en').'"'.($lang==='en'?' class="on"':'').'>EN</a></div>';
  }
  $eyebrow = $b['eyebrow'] ?? ($lang==='en' ? 'LouisMagie · Team brief' : 'LouisMagie · Brief équipe');
  if (!$nomAcote) $eyebrow = trim(preg_replace('/^\s*LouisMagie\s*·\s*/ui', '', $eyebrow));
  $o .= '<header>'.$marque().'<div class="eyebrow">'.$H($eyebrow).'</div>';
  $titre = $b['titre'] ?? 'Imagine the Impossible';
  // met en accent orange les derniers mots du titre
  $mots = preg_split('/\s+/u', $titre);
  if(count($mots) > 1){ $prem = array_shift($mots); $o .= '<h1>'.$H($prem).' <span class="g">'.$H(implode(' ',$mots)).'</span></h1>'; }
  else $o .= '<h1>'.$H($titre).'</h1>';
  if(!empty($b['sousTitre'])) $o .= '<p class="lede">'.$H($b['sousTitre']).'</p>';
  $o .= '</header>';

  if(!empty($b['concept'])) $o .= '<section><h2>'.$H($T['concept']).'</h2><p>'.$md($b['concept']).'</p></section>';

  $secs = is_array($b['sections'] ?? null) ? $b['sections'] : [];
  if($secs){
    $o .= '<div class="cat">'.$H($b['catEsprit'] ?? $T['catE']).'</div>';
    $n=0;
    foreach($secs as $sec){
      $n++;
      $o .= '<section><h2><span class="n">'.$n.'</span>'.$H($sec['titre'] ?? '').'</h2>';
      if(!empty($sec['texte'])) $o .= '<p>'.$md($sec['texte']).'</p>';
      if(!empty($sec['quote'])) $o .= '<div class="quote">'.$H($sec['quote']).'</div>';
      if(!empty($sec['texte2'])) $o .= '<p>'.$md($sec['texte2']).'</p>';
      if(!empty($sec['calloutBig'])) $o .= '<div class="callout"><div class="big">'.$H($sec['calloutBig']).'</div><div class="small">'.$H($sec['calloutSmall'] ?? '').'</div></div>';
      // À faire / À éviter sur la dernière section qui les porte
      if(!empty($sec['do']) || !empty($sec['dont'])){
        $li = function($arr){ $h=''; foreach((array)$arr as $x){ if(trim((string)$x)!=='') $h.='<li>'.htmlspecialchars($x,ENT_QUOTES,'UTF-8').'</li>'; } return $h; };
        $o .= '<div class="cols">'
          .'<div class="col do"><div class="h">'.$H($T['do']).'</div><ul>'.$li($sec['do'] ?? []).'</ul></div>'
          .'<div class="col dont"><div class="h">'.$H($T['dont']).'</div><ul>'.$li($sec['dont'] ?? []).'</ul></div></div>';
      }
      $o .= '</section>';
    }
  }

  $roles = is_array($b['roles'] ?? null) ? $b['roles'] : [];
  if($roles){
    $o .= '<div class="cat">'.$H($b['catMenu'] ?? $T['catM']).'</div><section>';
    if(!empty($b['menuIntro'])) $o .= '<p class="menu-intro">'.$md($b['menuIntro']).'</p>';
    foreach($roles as $r){
      $o .= '<div class="role"><h3>'.$H($r['nom'] ?? '').'</h3><ul>';
      foreach((array)($r['effets'] ?? []) as $ef){
        if(trim((string)$ef)==='') continue;
        // « Effet (référence) » → la référence passe en italique orange
        if(preg_match('/^(.*?)\s*\((.+)\)\s*$/u', $ef, $mm))
          $o .= '<li>'.$H($mm[1]).' <span class="ref">('.$H($mm[2]).')</span></li>';
        else $o .= '<li>'.$H($ef).'</li>';
      }
      $o .= '</ul></div>';
    }
    if(!empty($b['menuNote'])) $o .= '<div class="note">'.$md($b['menuNote']).'</div>';
    $o .= '</section>';
  }

  /* Équipe : résolue à l'affichage depuis le projet et les fiches magiciens.
     Un magicien renommé se met donc à jour sur un brief déjà publié, sans
     avoir à le régénérer. La liste figée du brief ne sert que de repli. */
  $equipeAff = [];
  if (!empty($p['equipe']) && is_array($p['equipe'])) {
    $mags = readJson("$DATA_DIR/magiciens.json"); if(!is_array($mags)) $mags = [];
    $parId = [];
    foreach ($mags as $mg) {
      if (!is_array($mg) || !isset($mg['id'])) continue;
      $parId[(string)$mg['id']] = trim(((string)($mg['prenom'] ?? '')).' '.((string)($mg['nom'] ?? '')));
    }
    $rolesEN = ['Serveur'=>'Waiter','Serveuse'=>'Waitress','Hôte d\'accueil'=>'Host','Hôtesse d\'accueil'=>'Host',
                'Invité'=>'Guest','Barman'=>'Bartender','Vestiaire'=>'Cloakroom','Photographe'=>'Photographer',
                'Technicien'=>'Technician','Sécurité'=>'Security','Autre'=>'Other'];
    foreach ($p['equipe'] as $m) {
      if (!is_array($m)) continue;
      $nom = trim((string)($parId[(string)($m['magicienId'] ?? '')] ?? ''));
      if ($nom === '') continue;
      $role = (string)($m['role'] ?? '');
      if ($lang === 'en' && isset($rolesEN[$role])) $role = $rolesEN[$role];
      $equipeAff[] = $nom.($role !== '' ? ' · '.$role : '');
    }
  }
  if (!$equipeAff && !empty($b['equipe']) && is_array($b['equipe'])) $equipeAff = $b['equipe'];

  $prat = is_array($b['pratique'] ?? null) ? $b['pratique'] : [];
  $deroule = is_array($b['deroule'] ?? null) ? $b['deroule'] : [];
  $zones = is_array($b['zones'] ?? null) ? $b['zones'] : [];
  $check = is_array($b['checklist'] ?? null) ? $b['checklist'] : [];
  $contacts = is_array($b['contacts'] ?? null) ? $b['contacts'] : [];

  if($prat || $deroule || $zones || $check || $contacts)
    $o .= '<div class="cat">'.$H($b['catPratique'] ?? $T['catP']).'</div>';

  // ── Déroulé de la soirée (frise horaire)
  if($deroule){
    $o .= '<section><h2>'.$H($T['deroule']).'</h2><div class="tl">';
    foreach($deroule as $d){
      if(trim((string)($d['quoi'] ?? ''))==='') continue;
      $o .= '<div class="tl-i"><div class="tl-h">'.$H($d['h'] ?? '').'</div><div class="tl-v">'.$md($d['quoi']).'</div></div>';
    }
    $o .= '</div></section>';
  }

  if($prat){
    $o .= '<section><div class="prat">';
    foreach($prat as $it){
      if(trim((string)($it['v'] ?? ''))==='') continue;
      $o .= '<div class="pr"><div class="k">'.$H($it['k'] ?? '').'</div><div class="v">'.$md($it['v']).'</div></div>';
    }
    $o .= '</div></section>';
  }

  // ── Répartition des zones
  if($zones){
    $o .= '<section><h2>'.$H($T['zones']).'</h2>';
    if(!empty($b['zonesIntro'])) $o .= '<p class="menu-intro">'.$md($b['zonesIntro']).'</p>';
    $o .= '<div class="prat">';
    foreach($zones as $z){
      if(trim((string)($z['nom'] ?? ''))==='') continue;
      $o .= '<div class="pr"><div class="k">'.$H($z['nom']).'</div><div class="v">'.$md($z['qui'] ?? '—').'</div></div>';
    }
    $o .= '</div></section>';
  }

  // ── Contacts sur place
  if($contacts){
    $o .= '<section><h2>'.$H($T['contacts']).'</h2><div class="prat">';
    foreach($contacts as $c){
      if(trim((string)($c['nom'] ?? ''))==='') continue;
      $tel = trim((string)($c['tel'] ?? ''));
      $v = '<b>'.$H($c['nom']).'</b>';
      if($tel !== '') $v .= '<br><a href="tel:'.$H(preg_replace('/[^0-9+]/','',$tel)).'">'.$H($tel).'</a>';
      $o .= '<div class="pr"><div class="k">'.$H($c['role'] ?? $T['contact']).'</div><div class="v">'.$v.'</div></div>';
    }
    $o .= '</div></section>';
  }

  // ── Checklist avant de partir
  if($check){
    $o .= '<section><h2>'.$H($T['avant']).'</h2><ul class="chk">';
    foreach($check as $c){ if(trim((string)$c)!=='') $o .= '<li>'.$H($c).'</li>'; }
    $o .= '</ul></section>';
  }

  if($equipeAff){
    $o .= '<section><h2>'.$H($T['equipe']).'</h2><div class="mag">';
    foreach($equipeAff as $m){ $o .= '<span>'.$H($m).'</span>'; }
    $o .= '</div></section>';
  }

  // ── Accusé de lecture
  if(!empty($b['accuse'])){
    $noms = [];
    foreach($equipeAff as $m){ $n = trim(explode('·', (string)$m)[0]); if($n!=='') $noms[] = $n; }
    $lus = array_map(function($x){ return mb_strtolower(trim((string)($x['nom'] ?? ''))); }, (array)($b['lus'] ?? []));
    $o .= '<section id="lu"><h2>'.$H($T['lu']).'</h2>'
      .'<p>'.$H($T['luTexte']).'</p>'
      .'<form class="lu-f" method="post" action="?action=briefLu&id='.$H($id).'&k='.$H($k).($lang==='en'?'&lang=en':'').($code!==''?'&c='.$H(rawurlencode($code)):'').'">';
    if($noms){
      $o .= '<select name="nom" required><option value="">'.$H($T['luChoix']).'</option>';
      foreach($noms as $n){
        $dejaLu = in_array(mb_strtolower($n), $lus, true);
        $o .= '<option value="'.$H($n).'"'.($dejaLu?' disabled':'').'>'.$H($n).($dejaLu?$T['luDeja']:'').'</option>';
      }
      $o .= '</select>';
    } else {
      $o .= '<input name="nom" placeholder="'.$H($T['luPrenom']).'" required>';
    }
    $o .= '<button type="submit">'.$H($T['luBtn']).'</button></form>';
    if(($_GET['lu'] ?? '')==='1') $o .= '<div class="ok-lu"><strong>'.$H($T['luOk']).'</strong><br>'.$H($T['luOk2']).'</div>';
    if(!empty($b['lus'])){
      $o .= '<div class="mag" style="margin-top:14px">';
      foreach((array)$b['lus'] as $l){ $o .= '<span>✓ '.$H($l['nom'] ?? '').'</span>'; }
      $o .= '</div>';
    }
    $o .= '</section>';
  }

  if(!empty($b['cloture'])) $o .= '<div class="close">'.$md($b['cloture']).'</div>';
  $o .= '<div class="contact">'.$md($b['contact'] ?? $T['contactDef']);
  if(!empty($b['waLouis'])) $o .= '<br><a class="wa" href="https://wa.me/'.$H(preg_replace('/[^0-9]/','',$b['waLouis'])).'">'.$H($T['wa']).'</a>';
  $o .= '</div>';
  $o .= '<footer>'.$marque('mark-f').($nomAcote?'LouisMagie · ':'').$H($b['titre'] ?? '').($p['date'] ? ' · '.$H(date('d/m/Y', strtotime($p['date']))) : '').$H($T['foot']).'</footer></div>';
  echo $page($o, ($b['titre'] ?? 'Brief').($lang==='en'?' — Team brief':' — Brief équipe'), $lang);
  exit;
}

/* ===== Formulaire agence : demande de projet Imagine the Impossible ===== */
if ($action === 'imagine') {
  $cfgI = readJson("$DATA_DIR/config.json"); if(!is_array($cfgI)) $cfgI=[];
  $key  = getenv('IMAGINE_KEY') ?: ($cfgI['imagineKey'] ?? '');
  $k    = $_GET['k'] ?? ($_POST['k'] ?? '');
  $agencePre = trim((string)($_GET['a'] ?? ''));   // pré-remplissage éventuel du nom de l'agence
  header('Content-Type: text/html; charset=utf-8');
  $H = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

  $shell = function($corps,$titre='Imagine the Impossible') use ($H) {
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      .'<meta name="color-scheme" content="dark"><meta name="robots" content="noindex,nofollow">'
      .'<title>'.$H($titre).'</title>'
      .'<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
      .'<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">'
      .'<style>*{margin:0;padding:0;box-sizing:border-box}'
      .':root{--or:#FF7700;--or-deep:#E56200;--noir:#0A0A08;--noir2:#141410;--noir3:#1E1E1A;--anthr:#2C2C28;--creme:#F5F2EE;--gc:#C8C3BB;--gm:#8A8580}'
      .'html,body{background:var(--noir)}'
      .'body{font-family:"DM Sans",-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-weight:300;color:var(--creme);line-height:1.6;padding:0 16px 60px}'
      .'.wrap{max-width:660px;margin:0 auto}'
      .'header{padding:52px 0 28px;text-align:center;position:relative}'
      .'header::before{content:"";position:absolute;top:-30px;left:50%;transform:translateX(-50%);width:400px;height:240px;border-radius:50%;background:radial-gradient(circle,rgba(255,119,0,.12) 0%,transparent 68%);pointer-events:none}'
      .'.eyebrow{font-family:Syne,sans-serif;font-size:10px;letter-spacing:3.5px;text-transform:uppercase;color:var(--or);font-weight:700;position:relative}'
      .'h1{font-family:Syne,sans-serif;font-weight:800;font-size:clamp(30px,6vw,44px);line-height:1.02;letter-spacing:-1.2px;color:#fff;margin:14px 0 12px;position:relative}'
      .'h1 .g{color:var(--or)}'
      .'.lede{color:var(--gm);font-size:15px;max-width:34em;margin:0 auto;position:relative}'
      .'form{margin-top:8px}'
      .'fieldset{border:none;background:var(--noir2);border:1px solid var(--anthr);border-radius:14px;padding:20px 22px 22px;margin-bottom:14px;overflow:hidden}'
      .'legend{display:block;width:100%;font-family:Syne,sans-serif;font-size:10px;letter-spacing:2.5px;text-transform:uppercase;color:var(--or);font-weight:700;margin:0 0 16px;padding:0}'
      .'.row{display:grid;grid-template-columns:1fr;gap:14px}'
      .'label{display:block;font-size:12px;font-weight:400;color:var(--gc);margin-bottom:6px;letter-spacing:.3px}'
      .'label .req{color:var(--or)}'
      .'input,select,textarea{width:100%;padding:13px 14px;border:1.5px solid var(--anthr);border-radius:8px;background:var(--noir3);color:var(--creme);font-size:16px;font-family:inherit;font-weight:300;outline:none;transition:border-color .2s}'
      .'input:focus,select:focus,textarea:focus{border-color:var(--or)}'
      .'textarea{min-height:96px;resize:vertical}'
      .'.hint{font-size:12px;color:var(--gm);margin-top:6px}'
      .'details.plus{background:var(--noir2);border:1px solid var(--anthr);border-radius:14px;margin-bottom:14px;overflow:hidden}'
      .'details.plus summary{list-style:none;cursor:pointer;padding:18px 22px;display:flex;flex-direction:column;gap:3px;position:relative}'
      .'details.plus summary::-webkit-details-marker{display:none}'
      .'details.plus summary::after{content:"+";position:absolute;right:22px;top:16px;font-family:Syne,sans-serif;font-size:22px;color:var(--or);line-height:1}'
      .'details.plus[open] summary::after{content:"–"}'
      .'.sum-t{font-family:Syne,sans-serif;font-size:10px;letter-spacing:2.5px;text-transform:uppercase;color:var(--or);font-weight:700}'
      .'.sum-s{font-size:13px;color:var(--gm)}'
      .'.plus-in{padding:4px 22px 22px}'
      .'.cat-e{font-family:Syne,sans-serif;font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--gm);font-weight:700;margin:14px 0 8px}'
      .'.chips{display:flex;flex-wrap:wrap;gap:8px}'
      .'.chip{display:inline-flex;align-items:center;margin:0}'
      .'.chip input{position:absolute;opacity:0;width:0;height:0}'
      .'.chip span{display:inline-block;padding:9px 15px;border:1.5px solid var(--anthr);border-radius:100px;background:var(--noir3);color:var(--gc);font-size:13.5px;cursor:pointer;transition:all .18s;user-select:none}'
      .'.chip input:checked+span{background:var(--or);border-color:var(--or);color:#fff}'
      .'.chip input:focus-visible+span{border-color:var(--or)}'
      .'.agence-fixe{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--noir3);border:1px solid var(--anthr);border-left:3px solid var(--or);border-radius:8px;padding:12px 14px;margin-bottom:16px}'
      .'.agence-fixe span{font-size:12px;color:var(--gm)}'
      .'.agence-fixe strong{font-family:Syne,sans-serif;font-weight:700;font-size:15px;color:#fff}'
      .'button.go{width:100%;padding:18px;background:var(--or);color:#fff;border:none;border-radius:4px;font-family:Syne,sans-serif;font-weight:700;font-size:12px;letter-spacing:2.5px;text-transform:uppercase;cursor:pointer;box-shadow:0 4px 20px rgba(255,119,0,.28);transition:all .22s}'
      .'button.go:hover{background:var(--or-deep);transform:translateY(-1px)}'
      .'.foot{text-align:center;color:var(--gm);font-size:12px;margin-top:22px;line-height:1.8}'
      .'.foot a{color:var(--or);text-decoration:none}'
      .'.hp{position:absolute;left:-9999px;opacity:0;height:0;width:0}'
      .'.msg{background:var(--noir2);border:1px solid var(--anthr);border-left:3px solid var(--or);border-radius:12px;padding:26px;text-align:center}'
      .'.msg h2{font-family:Syne,sans-serif;font-weight:800;font-size:22px;color:#fff;margin-bottom:10px}'
      .'.msg p{color:var(--gc);font-size:15px}'
      .'.err{color:#E5564B;font-size:13px;margin-top:10px}'
      .'@media(min-width:620px){.row.c2{grid-template-columns:1fr 1fr}}'
      .'</style></head><body><div class="wrap">'.$corps.'</div></body></html>';
  };

  if ($key === '' || !hash_equals($key, (string)$k)) {
    echo $shell('<header><div class="eyebrow">LouisMagie</div><h1>Lien <span class="g">invalide</span></h1></header>'
      .'<div class="msg"><p>Ce formulaire n\'est pas accessible avec ce lien.<br>Contacte Louis pour en recevoir un nouveau.</p></div>','Lien invalide');
    exit;
  }

  // ── Enregistrement
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim((string)($_POST['website'] ?? '')) !== '') { echo $shell('<div class="msg"><h2>Merci !</h2></div>'); exit; }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $rf = $DATA_DIR.'/_ratelimit.json'; $rl = readJson($rf); if(!is_array($rl)) $rl=[];
    $now = time();
    $rl = array_values(array_filter($rl, function($x) use($now){ return ($x['t'] ?? 0) > $now-3600; }));
    if (count(array_filter($rl, function($x) use($ip){ return ($x['ip'] ?? '')===$ip; })) >= 8) {
      echo $shell('<div class="msg"><h2>Trop de demandes</h2><p>Réessaie dans un moment, ou écris directement à Louis.</p></div>'); exit;
    }
    $rl[] = ['ip'=>$ip,'t'=>$now]; writeJson($rf,$rl);

    $c = function($n,$max=300){ $v=trim((string)($_POST[$n] ?? '')); $v=preg_replace('/[\x00-\x1F\x7F]/u',' ',$v); return mb_substr($v,0,$max); };
    // Imagine the Impossible est dédié à une seule agence partenaire : elle est fixée en configuration
    $agence = trim((string)($cfgI['imagineAgence'] ?? 'Inspiration'));
    $contact=$c('contact',120); $mail=$c('email',160);
    if($contact==='' || !filter_var($mail, FILTER_VALIDATE_EMAIL)){
      echo $shell('<div class="msg"><h2>Formulaire incomplet</h2><p>Votre nom et un email valide sont nécessaires.</p><p style="margin-top:14px"><a href="?action=imagine&k='.$H($k).'" style="color:#FF7700">← Revenir au formulaire</a></p></div>'); exit;
    }
    $date=$c('date',10); if($date!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) $date='';
    $nomEvt = $c('evenement',140);
    $lieu   = $c('lieu',180);

    $notes = "— Demande reçue via le formulaire agence le ".date('d/m/Y à H:i')." —\n"
      ."Contact : $contact · $mail".($c('tel',40)?(' · '.$c('tel',40)):'')."\n"
      .($c('horaires',60)?("Horaires souhaités : ".$c('horaires',60)."\n"):'')
      .($c('duree',60)?("Durée : ".$c('duree',60)."\n"):'')
      .($c('magiciens',40)?("Magiciens souhaités : ".$c('magiciens',40)."\n"):'')
      .($c('budget',60)?("Budget indiqué : ".$c('budget',60)."\n"):'')
      .($c('reponse',40)?("Réponse souhaitée avant : ".$c('reponse',40)."\n"):'')
      .($c('langues',80)?("Langues du public : ".$c('langues',80)."\n"):'')
      .($roles?("Rôles souhaités : ".implode(', ',$roles)."\n"):'')
      .($effNoms?("Effets souhaités : ".implode(', ',$effNoms)."\n"):'')
      .($c('zones',200)?("Zones à couvrir : ".$c('zones',200)."\n"):'')
      .($c('contraintes',300)?("Contraintes du lieu : ".$c('contraintes',300)."\n"):'')
      .($c('planning',1200)?("\nDéroulé annoncé :\n".$c('planning',1200)."\n"):'')
      .($c('message',2000)?("\nLeur message :\n".$c('message',2000)."\n"):'');

    // nombre de magiciens : le chiffre exact prime sur la fourchette (« 4 à 6 » → 4)
    $nbMag = 0;
    $exact = (int)preg_replace('/\D/','', $c('magiciensExact',6));
    if($exact>0 && $exact<200) $nbMag = $exact;
    elseif(preg_match('/(\d+)/', $c('magiciens',40), $mm)) $nbMag = (int)$mm[1];
    // souhaits détaillés
    $roles = array_values(array_filter(array_map(function($x){ return mb_substr(trim((string)$x),0,40); }, (array)($_POST['roles'] ?? []))));
    $effIds = array_values(array_filter(array_map(function($x){ return mb_substr(trim((string)$x),0,40); }, (array)($_POST['effets'] ?? []))));
    $effNoms = [];
    if($effIds){
      $bq = readJson("$DATA_DIR/effets.json"); if(!is_array($bq)) $bq=[];
      foreach($effIds as $eid){ foreach($bq as $e){ if(($e['id'] ?? '')===$eid){ $effNoms[]=(string)($e['nom'] ?? ''); break; } } }
    }
    $budget = 0; if(preg_match('/(\d[\d\s]*)/', str_replace([' ',' '],'',$c('budget',60)), $bm)) $budget = (int)preg_replace('/\D/','',$bm[1]);

    $prj = [
      'id'=>'PRJ-'.date('ymd').'-'.substr(bin2hex(random_bytes(3)),0,5),
      'nom'=>($nomEvt !== '' ? $nomEvt : ('Demande '.$agence)),
      'date'=>$date, 'lieu'=>$lieu, 'agence'=>$agence, 'agenceEmail'=>$mail, 'agenceTel'=>$c('tel',40),
      'idAgence'=>(string)($cfgI['imagineIdAgence'] ?? ''), 'budgetTotal'=>$budget, 'theme'=>$c('theme',160), 'typeEvenement'=>$c('type',80),
      'nbInvites'=>$c('invites',40), 'ambiance'=>$c('ambiance',200), 'dureePresta'=>$c('horaires',60),
      'nbMagiciensCible'=>$nbMag, 'tarifMode'=>'TTC', 'whatsappGroupe'=>'', 'idDevis'=>'', 'lignesSupp'=>[],
      'statut'=>'Prospect', 'briefUrl'=>'', 'notesBrief'=>'', 'feedback'=>'', 'notes'=>$notes,
      'maGestionMontant'=>0, 'statutAgence'=>'En attente', 'costume'=>$c('costume',120),
      'equipe'=>[], 'consommables'=>[], 'equipement'=>[],
      'logistique'=>['transport'=>'','hebergement'=>'','repas'=>''], 'checklist'=>[],
      'demandeAgence'=>true, 'recuLe'=>date('c'), 'createdAt'=>date('c'),
      'effetsSouhaites'=>$effIds, 'rolesSouhaites'=>$roles,
      'planningAgence'=>$c('planning',1200), 'zonesAgence'=>$c('zones',200),
      'contraintes'=>$c('contraintes',300), 'langues'=>$c('langues',80),
    ];
    $prj['updatedAt'] = nowTs();
    majTable($DATA_DIR, 'projets', function(&$arr) use ($prj) { $arr[] = $prj; return true; });

    $notif = getenv('SMTP_FROM') ?: getenv('SMTP_USER');
    if ($notif) {
      @smtpSend($notif, '🎭 Nouvelle demande Imagine — '.$agence,
        "Nouvelle demande de projet reçue via le formulaire agence.\n\n"
        ."Agence : $agence\nContact : $contact · $mail\n"
        .($nomEvt?("Événement : $nomEvt\n"):'').($date?("Date : ".date('d/m/Y',strtotime($date))."\n"):'')
        .($lieu?("Lieu : $lieu\n"):'').($c('invites',40)?("Invités : ".$c('invites',40)."\n"):'')
        .($c('magiciens',40)?("Magiciens souhaités : ".$c('magiciens',40)."\n"):'')
        .($c('budget',60)?("Budget : ".$c('budget',60)."\n"):'')
        .($c('message',2000)?("\nMessage :\n".$c('message',2000)."\n"):'')
        ."\nElle est déjà dans ton CRM, section Imagine.");
    }
    echo $shell('<header><div class="eyebrow">LouisMagie · Imagine the Impossible</div><h1>Demande <span class="g">bien reçue</span></h1></header>'
      .'<div class="msg"><h2>Merci '.$H($contact).' !</h2><p>Louis a reçu votre demande pour <strong style="color:#fff">'.$H($prj['nom']).'</strong>'
      .($date?(' du '.$H(date('d/m/Y',strtotime($date)))):'').'.<br><br>Il revient vers vous rapidement avec une proposition adaptée.</p></div>'
      .'<div class="foot">Une précision à ajouter ? <a href="mailto:'.$H($cfgI['emailLouis'] ?? 'contact@louismagie.fr').'">Écrire à Louis</a></div>','Demande envoyée');
    exit;
  }

  // ── Formulaire
  $sel = function($nom,$label,$opts,$req=false) use ($H) {
    $h = '<div><label>'.$H($label).($req?' <span class="req">*</span>':'').'</label><select name="'.$H($nom).'"'.($req?' required':'').'>';
    foreach($opts as $o){ $h .= '<option'.($o===''?' value="" disabled selected':'').'>'.$H($o===''?'Choisir…':$o).'</option>'; }
    return $h.'</select></div>';
  };
  $txt = function($nom,$label,$ph='',$req=false,$type='text',$val='') use ($H) {
    return '<div><label>'.$H($label).($req?' <span class="req">*</span>':'').'</label>'
      .'<input type="'.$H($type).'" name="'.$H($nom).'" placeholder="'.$H($ph).'"'.($req?' required':'').' value="'.$H($val).'"></div>';
  };
  $agenceNom = trim((string)($cfgI['imagineAgence'] ?? 'Inspiration'));
  // Banque d'effets proposée à la sélection (regroupée par catégorie)
  $effetsHtml = '';
  $banque = readJson("$DATA_DIR/effets.json");
  if(is_array($banque) && count($banque)){
    $cats=[];
    foreach($banque as $e){ $c=trim((string)($e['categorie'] ?? '')) ?: 'Autres'; $cats[$c][]=$e; }
    ksort($cats);
    $effetsHtml = '<div style="margin-top:18px"><label>Effets qui vous font envie</label>';
    foreach($cats as $cat=>$liste){
      $effetsHtml .= '<div class="cat-e">'.$H($cat).'</div><div class="chips">';
      foreach($liste as $e){
        $nom = (string)($e['nom'] ?? ''); if($nom==='') continue;
        $effetsHtml .= '<label class="chip"><input type="checkbox" name="effets[]" value="'.$H($e['id'] ?? $nom).'"><span>'.$H($nom).'</span></label>';
      }
      $effetsHtml .= '</div>';
    }
    $effetsHtml .= '<div class="hint">Cochez ce qui vous plaît — nous adaptons ensuite selon les magiciens et le lieu.</div></div>';
  }
  $form = '<header><div class="eyebrow">LouisMagie × '.$H($agenceNom).'</div>'
    .'<h1>Parlez-nous de <span class="g">votre événement</span></h1>'
    .'<p class="lede">Magie immersive en déambulation : des magiciens infiltrés parmi vos invités, des « glitchs dans la réalité » de quelques secondes. Décrivez votre événement, Louis revient vers vous sous 24 h avec une proposition.</p></header>'
    .'<form method="post"><input type="text" name="website" class="hp" tabindex="-1" autocomplete="off">'
    .'<input type="hidden" name="k" value="'.$H($k).'">'
    .'<fieldset><legend>Votre contact</legend>'
    .'<div class="agence-fixe"><span>Demande pour le compte de</span><strong>'.$H($agenceNom).'</strong></div>'
    .'<div class="row c2">'
    .$txt('contact','Votre nom','Prénom Nom',true)
    .$txt('email','Email','vous@'.strtolower(preg_replace('/[^A-Za-z0-9]/','',$agenceNom)).'.com',true,'email')
    .'</div>'
    .'<div style="margin-top:14px">'.$txt('tel','Téléphone','06 12 34 56 78',false,'tel').'</div>'
    .'</fieldset>'
    .'<fieldset><legend>L\'événement</legend><div class="row c2">'
    .$txt('evenement','Nom de l\'événement / client final','Soirée de gala, lancement…')
    .$txt('date','Date',' ',false,'date')
    .$txt('lieu','Lieu','Hôtel, ville, pays')
    .$sel('type','Type d\'événement',['','Cocktail / soirée VIP','Gala','Lancement de produit','Séminaire / convention','Mariage','Inauguration','Autre'])
    .$sel('invites','Nombre d\'invités',['','Moins de 100','100 à 300','300 à 600','600 à 1 000','Plus de 1 000'])
    .$txt('horaires','Horaires souhaités','21h45 - 23h45')
    .'</div></fieldset>'
    .'<fieldset><legend>La prestation</legend><div class="row c2">'
    .$sel('magiciens','Nombre de magiciens',['','À me conseiller','2 à 3','4 à 6','7 à 10','Plus de 10'])
    .$sel('budget','Budget envisagé',['','À discuter','Moins de 3 000 €','3 000 à 6 000 €','6 000 à 12 000 €','Plus de 12 000 €'])
    .$txt('theme','Thème / univers','Nuit orientale, années 20…')
    .$txt('costume','Dress code imposé','Costume blanc, noir, libre…')
    .'</div>'
    .'<div style="margin-top:14px"><label>Contexte, contraintes, attentes</label>'
    .'<textarea name="message" placeholder="Ambiance recherchée, public international, zones à couvrir, contraintes du lieu, ce que vous imaginez…"></textarea>'
    .'<div class="hint">Plus vous en dites, plus la proposition sera juste — mais l\'essentiel suffit.</div></div>'
    .'<div style="margin-top:14px">'.$txt('reponse','Réponse souhaitée avant le',' ',false,'date').'</div>'
    .'</fieldset>'
    // ── Mode détaillé (replié par défaut : le formulaire reste court pour ceux qui sont pressés)
    .'<details class="plus"><summary><span class="sum-t">Aller plus loin</span>'
    .'<span class="sum-s">Rôles, effets souhaités, déroulé de la soirée — facultatif</span></summary>'
    .'<div class="plus-in">'
    .'<div class="row c2">'
    .$txt('magiciensExact','Nombre exact de magiciens','8',false,'number')
    .$txt('langues','Langues du public','Français, anglais…')
    .'</div>'
    .'<div style="margin-top:16px"><label>Rôles souhaités</label><div class="chips">'
    .implode('', array_map(function($r) use ($H){
        return '<label class="chip"><input type="checkbox" name="roles[]" value="'.$H($r).'"><span>'.$H($r).'</span></label>';
      }, ['Serveur','Hôte d\'accueil','Invité','Barman','Vestiaire','Photographe']))
    .'</div><div class="hint">Nos magiciens s\'infiltrent dans ces personnages. Laissez vide si vous nous faites confiance.</div></div>'
    .$effetsHtml
    .'<div style="margin-top:16px"><label>Déroulé de la soirée</label>'
    .'<textarea name="planning" placeholder="19h00 accueil des invités&#10;20h30 dîner assis&#10;21h45 cocktail — c\'est là qu\'on intervient&#10;23h30 fin"></textarea>'
    .'<div class="hint">Même approximatif, ça nous aide à placer les interventions au bon moment.</div></div>'
    .'<div class="row c2" style="margin-top:16px">'
    .$txt('zones','Zones à couvrir','Terrasse, bar, salle, entrée…')
    .$txt('contraintes','Contraintes du lieu','Feu interdit, plafond bas, extérieur…')
    .'</div>'
    .'</div></details>'
    .'<button class="go" type="submit">Envoyer la demande</button>'
    .'<div class="foot">Réponse sous 24 h ouvrées · Aucun engagement<br>Une question ? <a href="mailto:'.$H($cfgI['emailLouis'] ?? 'contact@louismagie.fr').'">'.$H($cfgI['emailLouis'] ?? 'contact@louismagie.fr').'</a></div>'
    .'</form>';
  echo $shell($form);
  exit;
}

/* ===== Désabonnement des emails marketing (public, obligation légale) =====
   GET = page de confirmation, AUCUNE écriture : les passerelles de sécurité ouvrent les liens des emails reçus.
   Seul un POST (bouton « Confirmer » ou One-Click RFC 8058 envoyé par la messagerie) désabonne.
   L'opposition va d'abord au registre du serveur (_desabos.json), puis sur les fiches pour l'affichage. */
if ($action === 'desabo') {
  $em = desaboNorm($_GET['e'] ?? ($_POST['e'] ?? ''));
  $sig = (string)($_GET['s'] ?? ($_POST['s'] ?? ''));
  $cfgD = readJson("$DATA_DIR/config.json"); if(!is_array($cfgD)) $cfgD=[];
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  $H = function($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); };
  $page = function($titre,$txt,$form='') use ($H) {
    $mail = $H($GLOBALS['__mailLouis'] ?? 'contact@louismagie.fr');
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      .'<meta name="robots" content="noindex,nofollow"><title>'.$H($titre).' — LouisMagie</title>'
      .'<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
      .'<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400&display=swap" rel="stylesheet">'
      .'<style>body{margin:0;background:#0A0A08;font-family:"DM Sans",-apple-system,Arial,sans-serif;font-weight:300;color:#F5F2EE;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}'
      .'.c{max-width:440px;background:#FDFCFB;color:#0A0A08;border-radius:14px;border-top:3px solid #FF7700;padding:40px 34px;text-align:center}'
      .'.l{font-family:Syne,sans-serif;font-weight:800;font-size:22px;letter-spacing:-.4px;margin-bottom:20px}.l span{color:#FF7700}'
      .'h1{font-family:Syne,sans-serif;font-weight:800;font-size:19px;margin-bottom:10px}'
      .'p{font-size:14.5px;color:#5A5650;line-height:1.7}'
      .'a{display:inline-block;margin-top:22px;background:#FF7700;color:#fff;text-decoration:none;font-family:Syne,sans-serif;font-weight:700;font-size:11px;letter-spacing:2px;text-transform:uppercase;padding:14px 28px;border-radius:4px}'
      .'button{display:inline-block;margin-top:22px;background:#FF7700;color:#fff;border:0;cursor:pointer;font-family:Syne,sans-serif;font-weight:700;font-size:11px;letter-spacing:2px;text-transform:uppercase;padding:14px 28px;border-radius:4px}'
      .'a.d{display:block;background:none;color:#5A5650;padding:0;margin-top:18px;font-family:"DM Sans",Arial,sans-serif;font-weight:400;font-size:13px;letter-spacing:0;text-transform:none;text-decoration:underline}</style></head>'
      .'<body><div class="c"><div class="l">Louis<span>Magie</span></div><h1>'.$H($titre).'</h1><p>'.$txt.'</p>'
      .$form
      .($form !== '' ? '<a class="d" href="mailto:'.$mail.'">Une question ? Écrire à Louis</a>' : '<a href="mailto:'.$mail.'">Écrire à Louis</a>')
      .'</div></body></html>';
  };
  $GLOBALS['__mailLouis'] = $cfgD['emailLouis'] ?? 'contact@louismagie.fr';
  $via = desaboVerifier($DATA_DIR, $cfgD, $em, $sig);   // 'lien', 'lien-ancien' ou ''
  if ($via === '') { echo $page('Lien invalide','Ce lien de désabonnement n\'est pas ou plus valide. Pour ne plus recevoir d\'emails, écrivez à Louis avec le bouton ci-dessous.'); exit; }
  $memeAdresse = function($c) use ($em) { return is_array($c) && desaboNorm($c['email'] ?? '') === $em; };
  $unClic = (string)($_POST['List-Unsubscribe'] ?? '') === 'One-Click';
  $confirme = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ((string)($_POST['confirm'] ?? '') === '1' || $unClic);
  if (!$confirme) {   // GET, HEAD, POST vide ou JSON : on n'écrit RIEN
    $dejaOpp = desabosUnion($DATA_DIR, desabosLire($DATA_DIR));   // lecture seule, sans verrou
    if (isset($dejaOpp[$em])) {
      echo $page('C\'est déjà fait', 'L\'adresse <strong>'.$H($em).'</strong> ne reçoit plus d\'email d\'actualité.'); exit;
    }
    $q = '?action=desabo&e='.rawurlencode($em).'&s='.rawurlencode($sig);
    echo $page('Confirmer le désabonnement',
      'Un dernier clic : l\'adresse <strong>'.$H($em).'</strong> ne recevra plus d\'email d\'actualité de LouisMagie.<br><br>Les échanges liés à vos devis, factures et prestations continueront normalement.',
      '<form method="post" action="'.$H($q).'"><input type="hidden" name="confirm" value="1"><button type="submit">Confirmer le désabonnement</button></form>');
    exit;
  }
  $source = $unClic ? 'un-clic' : $via;
  if (!desaboAjouter($DATA_DIR, $em, $source)) {
    echo $page('Un souci technique', 'Votre demande n\'a pas pu être enregistrée. Écrivez-moi avec le bouton ci-dessous et je vous retire de la liste moi-même.');
    exit;
  }
  // Fiches : drapeau pour l'affichage et pour une appli restée en cache. Idempotent : une fiche déjà
  // désabonnée garde sa date et son updatedAt ; sans fiche à changer, majTable n'écrit rien.
  majTable($DATA_DIR, 'clients', function(&$cls) use ($memeAdresse, $unClic, $via) {
    $besoin = false; $ts = nowTs();
    foreach ($cls as &$c) {
      if (!$memeAdresse($c) || !empty($c['desabo'])) continue;
      $c['desabo'] = true; $c['desaboLe'] = $ts; $c['desaboVia'] = $unClic ? 'un-clic' : 'lien'; $c['desaboPar'] = $via; $c['updatedAt'] = $ts;
      $besoin = true;
    }
    unset($c);
    return $besoin;
  });
  echo $page('C\'est fait', 'L\'adresse <strong>'.$H($em).'</strong> ne recevra plus d\'email d\'actualité.<br><br>Les échanges liés à vos devis, factures et prestations continueront normalement.');
  exit;
}

/* ===== Accusé de lecture d'un brief (public, protégé par le jeton du brief) ===== */
if ($action === 'briefLu') {
  $id = $_GET['id'] ?? ''; $k = $_GET['k'] ?? '';
  $nom = trim((string)($_POST['nom'] ?? ''));
  $nom = mb_substr(preg_replace('/[\x00-\x1F\x7F<>]/u',' ',$nom), 0, 60);
  $f = "$DATA_DIR/projets.json"; $projets = readJson($f); if(!is_array($projets)) $projets=[];
  $idx=-1; foreach($projets as $i=>$x){ if(($x['id']??'')===$id){ $idx=$i; break; } }
  $b = $idx>=0 ? ($projets[$idx]['brief'] ?? null) : null;
  $ok = $b && !empty($b['token']) && hash_equals((string)$b['token'], (string)$k) && !empty($b['publie']) && $nom!=='';
  if($ok){
    $lus = is_array($b['lus'] ?? null) ? $b['lus'] : [];
    $existe = false;
    foreach($lus as $l){ if(mb_strtolower(trim((string)($l['nom'] ?? ''))) === mb_strtolower($nom)) { $existe=true; break; } }
    if(!$existe){
      $lus[] = ['nom'=>$nom, 'at'=>date('c')];
      majTable($DATA_DIR, 'projets', function(&$arr) use ($id, $nom) {
        foreach ($arr as &$x) {
          if (($x['id'] ?? '') !== $id || !is_array($x['brief'] ?? null)) continue;
          $l = is_array($x['brief']['lus'] ?? null) ? $x['brief']['lus'] : [];
          foreach ($l as $y) if (mb_strtolower(trim((string)($y['nom'] ?? ''))) === mb_strtolower($nom)) return false;
          $l[] = ['nom'=>$nom, 'at'=>date('c')];
          $x['brief']['lus'] = $l; $x['updatedAt'] = nowTs();
          return true;
        }
        return false;
      });
      $notif = getenv('SMTP_FROM') ?: getenv('SMTP_USER');
      if($notif) @smtpSend($notif, '✅ Brief lu — '.$nom,
        $nom." vient de confirmer la lecture du brief « ".($b['titre'] ?? '')." ».\n\n"
        .count($lus)." magicien(s) ont confirmé pour l'instant.\n\nLouisMagie CRM");
    }
  }
  // retour sur la page du brief, avec confirmation
  $url = '?action=brief&id='.rawurlencode($id).'&k='.rawurlencode($k).(!empty($b['code'])?('&c='.rawurlencode($b['code'])):'')
       .((($_GET['lang'] ?? '')==='en')?'&lang=en':'').'&lu='.($ok?'1':'0').'#lu';
  header('Location: '.$url); exit;
}

/* ===== Réception publique d'une demande depuis le site louismagie.fr =====
   Le formulaire du site poste ici : la demande atterrit directement dans le CRM.
   Protections : clé de formulaire, origine autorisée, pot de miel, limite par IP, validation. */
if ($action === 'newDemande') {
  $cfgPub = readJson("$DATA_DIR/config.json"); if(!is_array($cfgPub)) $cfgPub=[];
  $key = getenv('FORM_KEY') ?: ($cfgPub['formKey'] ?? '');
  // Origines autorisées (site + preview), configurables
  $allow = array_filter(array_map('trim', explode(',', getenv('FORM_ORIGINS') ?: ($cfgPub['formOrigins'] ?? 'https://louismagie.fr,https://www.louismagie.fr'))));
  $org = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($org && in_array($org, $allow, true)) {
    header('Access-Control-Allow-Origin: '.$org);
    header('Vary: Origin');
  }
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
  if ($key === '') out(['ok'=>false,'error'=>'formulaire non configuré']);
  if (!hash_equals($key, (string)($req['key'] ?? $_GET['key'] ?? ''))) out(['ok'=>false,'error'=>'clé invalide']);
  if (trim((string)($req['website'] ?? '')) !== '') out(['ok'=>true]);   // pot de miel : bot → on fait semblant d'accepter

  // Limite : 5 envois / heure / IP
  $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
  $rf = $DATA_DIR.'/_ratelimit.json'; $rl = readJson($rf); if(!is_array($rl)) $rl=[];
  $now = time();
  $rl = array_filter($rl, function($x) use($now){ return ($x['t'] ?? 0) > $now-3600; });
  $nb = count(array_filter($rl, function($x) use($ip){ return ($x['ip'] ?? '') === $ip; }));
  if ($nb >= 5) out(['ok'=>false,'error'=>'trop de demandes, réessayez plus tard']);
  $rl[] = ['ip'=>$ip,'t'=>$now]; writeJson($rf, array_values($rl));

  $clean = function($v,$max=400){ $v = trim((string)$v); $v = preg_replace('/[\x00-\x1F\x7F]/u',' ',$v); return mb_substr($v,0,$max); };
  $nom = $clean($req['nom'] ?? $req['name'] ?? '', 120);
  $mail = $clean($req['email'] ?? '', 160);
  if ($nom === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) out(['ok'=>false,'error'=>'nom ou email invalide']);
  $dateEvt = $clean($req['dateEvenement'] ?? $req['date'] ?? '', 10);
  if ($dateEvt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEvt)) $dateEvt = '';

  $dem = [
    'id'=>'DEM-'.date('ymd').'-'.substr(bin2hex(random_bytes(3)),0,5),
    'date'=>date('Y-m-d'),
    'nom'=>$nom, 'email'=>$mail,
    'tel'=>$clean($req['tel'] ?? $req['phone'] ?? '', 40),
    'typeEvenement'=>$clean($req['typeEvenement'] ?? $req['type'] ?? '', 60),
    'dateEvenement'=>$dateEvt, 'creneau'=>'',
    'lieu'=>$clean($req['lieu'] ?? '', 160), 'distanceKm'=>0,
    'nbInvites'=>$clean($req['nbInvites'] ?? $req['guests'] ?? '', 40),
    'prestationSouhaitee'=>$clean($req['message'] ?? '', 1500),
    'duree'=>'', 'budgetEstime'=>$clean($req['budget'] ?? '', 60),
    'source'=>$clean($req['source'] ?? 'Site web', 60),
    'statut'=>'Nouveau', 'notes'=>'',
    'recuLe'=>date('c'), 'updatedAt'=>nowTs(),
  ];
  majTable($DATA_DIR, 'demandes', function(&$arr) use ($dem) { $arr[] = $dem; return true; });

  // Notification immédiate (best effort)
  $notif = getenv('SMTP_FROM') ?: getenv('SMTP_USER');
  if ($notif) {
    $corps = "Nouvelle demande depuis le site !\n\n"
      ."Nom : $nom\nEmail : $mail\n".($dem['tel']?("Téléphone : ".$dem['tel']."\n"):'')
      .($dem['typeEvenement']?("Type : ".$dem['typeEvenement']."\n"):'')
      .($dem['dateEvenement']?("Date : ".$dem['dateEvenement']."\n"):'')
      .($dem['lieu']?("Lieu : ".$dem['lieu']."\n"):'')
      .($dem['nbInvites']?("Invités : ".$dem['nbInvites']."\n"):'')
      .($dem['prestationSouhaitee']?("\nMessage :\n".$dem['prestationSouhaitee']."\n"):'')
      ."\nElle est déjà dans ton CRM, onglet Demandes.";
    @smtpSend($notif, '📥 Nouvelle demande — '.$nom, $corps);
  }
  out(['ok'=>true,'id'=>$dem['id']]);
}

/* ===== Logo public (sert le logo configuré pour l'en-tête des emails) ===== */
if ($action === 'logo') {
  $config = readJson("$DATA_DIR/config.json"); if(!is_array($config)) $config = [];
  $v = $_GET['v'] ?? '';
  $brut = (string)($config['logo'] ?? '');
  // 1) Variante déjà calculée par le CRM (identique à ce qu'affichent l'app et les PDF)
  $l = $brut;
  if ($v === 'dark'  && !empty($config['logoOnDark']))  $l = $config['logoOnDark'];
  if ($v === 'light' && !empty($config['logoOnLight'])) $l = $config['logoOnLight'];
  // 2) Sinon on la calcule ici : les pages publiques ne doivent pas dépendre
  //    du passage du CRM, et un logo noir serait invisible sur fond noir.
  if ($l === $brut && ($v === 'dark' || $v === 'light') && $brut !== '') {
    $calc = logoVariante($brut, $v, $DATA_DIR);
    if ($calc !== null) $l = $calc;
  }
  if ($l && strpos($l, 'base64,') !== false) {
    $mime = preg_match('/^data:([^;]+);/', $l, $mm) ? $mm[1] : 'image/png';
    header('Content-Type: '.$mime); header('Cache-Control: max-age=3600');
    echo base64_decode(explode('base64,', $l, 2)[1]); exit;
  }
  header('Content-Type: image/gif'); header('Cache-Control: no-store');
  echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); exit;
}

/* ===== Signature électronique du devis (public, protégé par le token du devis) ===== */
if ($action === 'sign' || $action === 'signSubmit') {
  $id = $_GET['id'] ?? ($req['id'] ?? '');
  $k  = $_GET['k']  ?? ($req['k']  ?? '');
  $devis = readJson("$DATA_DIR/devis.json"); if(!is_array($devis)) $devis=[];
  $idx=-1; foreach($devis as $i=>$d){ if(($d['id']??'')===$id){ $idx=$i; break; } }
  $d = $idx>=0 ? $devis[$idx] : null;
  $valid = $d && !empty($d['shareToken']) && hash_equals((string)$d['shareToken'], (string)$k);

  if ($action === 'signSubmit') {
    if(!$valid) out(['ok'=>false,'error'=>'lien invalide']);
    if(!empty($d['signataire'])) out(['ok'=>true,'already'=>true]);
    $signataire = trim($req['signataire'] ?? '');
    $img = $req['signatureImg'] ?? '';
    // Anti-XSS stocké : n'accepte que des vraies images PNG/JPEG en base64, taille bornée ; sinon on ignore le tracé
    if ($img !== '' && (!preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $img) || strlen($img) > 500000)) $img = '';
    if($signataire===''&&$img==='') out(['ok'=>false,'error'=>'signature vide']);
    $now = date('c');
    $deja = false;
    majTable($DATA_DIR, 'devis', function(&$devis) use ($id, $signataire, $img, $now, &$deja) {
      foreach ($devis as &$x) {
        if (($x['id'] ?? '') !== $id) continue;
        if (!empty($x['signataire'])) { $deja = true; return false; }
        // Garde : la signature ne fait AVANCER le statut que depuis Brouillon/Envoyé (jamais rétrograder « Acompte reçu » etc.)
        if (in_array($x['statut'] ?? '', ['Brouillon','Envoyé',''])) {
          $x['statut'] = 'Accepté';
          if (empty($x['dateAcceptation'])) $x['dateAcceptation'] = date('Y-m-d');
        }
        $x['signataire'] = $signataire; $x['signatureImg'] = $img; $x['signedAt'] = $now; $x['updatedAt'] = nowTs();
        return true;
      }
      return false;
    });
    if ($deja) out(['ok'=>true,'already'=>true]);
    // Journal séparé, jamais écrasé par une resynchro → la signature ne se perd jamais
    sousVerrou($DATA_DIR, 'signatures', function() use ($DATA_DIR, $id, $signataire, $img, $now, $d) {
      $sf=$DATA_DIR.'/_signatures.json'; $sigs=readJson($sf); if(!is_array($sigs))$sigs=[];
      $sigs=array_values(array_filter($sigs,function($s)use($id){return ($s['id']??'')!==$id;}));
      $sigs[]=['id'=>$id,'signataire'=>$signataire,'signatureImg'=>$img,'signedAt'=>$now,
               'ip'=>$_SERVER['REMOTE_ADDR']??'','montantTTC'=>$d['montantTTC']??0];
      writeJson($sf,$sigs);
    });
    // Notifie LouisMagie (best effort, ignore les erreurs)
    $notif=getenv('SMTP_FROM')?:getenv('SMTP_USER');
    if($notif){ @smtpSend($notif,'✍️ Devis '.$id.' signé en ligne',
      "Bonne nouvelle !\n\n".($signataire?:'Un client')." vient de signer le devis ".$id." (".number_format((float)($d['montantTTC']??0),2,',',' ')." € TTC).\nStatut passé à « Accepté ».\n\nLouisMagie CRM"); }
    out(['ok'=>true]);
  }

  // action 'sign' : page HTML publique de signature
  header('Content-Type: text/html; charset=utf-8');
  $H=function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
  if(!$valid){ echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    .'<title>Lien invalide — LouisMagie</title>'
    .'<body style="margin:0;background:#0A0A08;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px">'
    .'<div style="max-width:420px;background:#FDFCFB;border-radius:14px;border-top:3px solid #FF7700;padding:40px 32px;text-align:center">'
    .'<div style="font-size:24px;font-weight:800;letter-spacing:-.4px;color:#0A0A08;margin-bottom:22px">Louis<span style="color:#FF7700">Magie</span></div>'
    .'<h2 style="font-size:18px;font-weight:700;color:#0A0A08;margin:0 0 10px">Lien invalide ou expiré</h2>'
    .'<p style="font-size:14px;font-weight:300;color:#5A5650;line-height:1.7;margin:0">Ce lien de signature n\'est plus valide.<br>Contactez LouisMagie pour en recevoir un nouveau.</p>'
    .'<a href="mailto:contact@louismagie.fr" style="display:inline-block;margin-top:24px;background:#FF7700;color:#fff;text-decoration:none;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;padding:15px 30px;border-radius:4px">Nous contacter</a>'
    .'</div></body></html>'; exit; }
  $already = !empty($d['signataire']);
  $ttc=number_format((float)($d['montantTTC']??0),2,',',' ');
  $acPct=(float)($d['acomptePct']??0);
  $ac=number_format((float)($d['montantTTC']??0)*$acPct/100,2,',',' ');
  $rows='';
  foreach(($d['prestations']??[]) as $p){
    $rows.='<tr><td>'.$H($p['label']??'').($p['duree']?' <span class="dim">('.$H($p['duree']).')</span>':'').'</td><td class="r">'.number_format((float)($p['prix']??0),2,',',' ').' €</td></tr>';
  }
  if(!empty($d['fraisDeplacement'])) $rows.='<tr><td class="dim">Frais de déplacement</td><td class="r">'.number_format((float)$d['fraisDeplacement'],2,',',' ').' €</td></tr>';
  $dEvt = $d['dateEvenement']??''; $creneau=$d['creneau']??''; $lieu=$d['lieu']??'';
  $jid=$H($id); $jk=$H($k);
  $okBlock = $already
    ? '<div class="done">✅ Devis déjà signé par <b>'.$H($d['signataire']).'</b>'.(!empty($d['signedAt'])?' le '.$H(date('d/m/Y',strtotime($d['signedAt']))):'').'.<br>Merci !</div>'
    : '';
  echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    .'<title>Signature du devis '.$jid.' — LouisMagie</title><style>'
    // Charte graphique officielle LouisMagie : noir 70 % · crème 25 % · orange 5 %
    .'<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
    .'<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">'
    .'<style>'
    .'*{box-sizing:border-box}'
    .':root{--or:#FF7700;--or-deep:#E56200;--noir:#0A0A08;--anthr:#2C2C28;--creme:#F5F2EE;--creme2:#EDEAE5;--blanc:#FDFCFB;--gm:#8A8580;--gf:#5A5650}'
    .'body{margin:0;font-family:"DM Sans",-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-weight:300;background:var(--noir);color:var(--noir);padding:22px 14px;line-height:1.6}'
    .'body::before{content:"";position:fixed;top:-140px;right:-140px;width:460px;height:460px;border-radius:50%;background:radial-gradient(circle,rgba(255,119,0,.10) 0%,transparent 65%);pointer-events:none}'
    .'.wrap{max-width:560px;margin:0 auto;background:var(--blanc);border-radius:14px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.45);position:relative}'
    .'.hd{background:var(--noir);color:#fff;padding:30px 24px 26px;text-align:center;position:relative;border-top:3px solid var(--or)}'
    .'.hd::after{content:"";position:absolute;top:-60px;right:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(255,119,0,.14) 0%,transparent 68%)}'
    .'.hd h1{margin:0;font-family:Syne,sans-serif;font-size:24px;font-weight:800;letter-spacing:-.4px;position:relative}'
    .'.hd h1 span{color:var(--or)}'
    .'.hd p{margin:8px 0 0;font-size:10px;font-weight:400;letter-spacing:3px;text-transform:uppercase;color:rgba(255,255,255,.38);position:relative}'
    .'.bd{padding:26px 24px}'
    .'.bd h2{font-family:Syne,sans-serif;font-size:10px;font-weight:700;color:var(--or);margin:0 0 12px;text-transform:uppercase;letter-spacing:3px}'
    .'table{width:100%;border-collapse:collapse;font-size:14px;margin-bottom:18px}'
    .'td{padding:9px 0;border-bottom:1px solid var(--creme2);color:var(--gf)}'
    .'.r{text-align:right;white-space:nowrap}.dim{color:var(--gm);font-size:13px}'
    .'.tot{font-family:Syne,sans-serif;font-weight:800;font-size:17px}.tot td{color:var(--noir);border-bottom:none;padding-top:14px}.tot .r{color:var(--or)}'
    .'.meta{font-size:14px;color:var(--gf);margin-bottom:22px;line-height:1.75;padding-bottom:18px;border-bottom:1px solid var(--creme2)}'
    .'.meta b{font-family:Syne,sans-serif;font-size:17px;font-weight:800;color:var(--noir);display:block;margin-bottom:4px}'
    .'label{display:block;font-size:11px;font-weight:500;margin:18px 0 7px;color:var(--noir);letter-spacing:.4px;text-transform:uppercase}'
    .'input[type=text]{width:100%;padding:14px;border:1.5px solid var(--creme2);border-radius:8px;font-size:16px;font-family:inherit;background:var(--creme);color:var(--noir);outline:none;transition:border-color .2s}'
    .'input[type=text]:focus{border-color:var(--or)}'
    .'#pad{width:100%;height:170px;border:1.5px dashed rgba(255,119,0,.5);border-radius:8px;background:var(--creme);touch-action:none;display:block}'
    .'.padhint{font-size:11px;color:var(--gm);text-align:center;margin-top:7px;letter-spacing:.3px}'
    .'.clr{background:none;border:none;color:var(--or);font-size:11px;cursor:pointer;float:right;text-transform:none;letter-spacing:0;font-family:inherit}'
    .'.chk{display:flex;align-items:flex-start;gap:11px;margin:20px 0;font-size:13px;color:var(--gf);text-transform:none;letter-spacing:0;font-weight:300}'
    .'.chk input{margin-top:3px;width:20px;height:20px;accent-color:var(--or);flex-shrink:0}'
    .'.chk b{color:var(--noir);font-weight:500}'
    .'button.go{width:100%;padding:18px;background:var(--or);color:#fff;border:none;border-radius:4px;font-family:Syne,sans-serif;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;cursor:pointer;box-shadow:0 4px 20px rgba(255,119,0,.28);transition:all .22s ease}'
    .'button.go:hover:not(:disabled){background:var(--or-deep);box-shadow:0 8px 32px rgba(255,119,0,.4);transform:translateY(-1px)}'
    .'button.go:disabled{opacity:.35;box-shadow:none;cursor:not-allowed}'
    .'.done{background:var(--creme);border-left:3px solid #2E9E63;color:#1d7a45;padding:20px;border-radius:8px;text-align:center;font-size:15px;line-height:1.6}'
    .'.foot{text-align:center;font-size:10px;color:var(--gm);padding:18px 14px;letter-spacing:1.5px;text-transform:uppercase;background:var(--creme);border-top:1px solid var(--creme2)}'
    .'</style></head><body><div class="wrap">'
    .'<div class="hd"><h1>Louis<span>Magie</span></h1><p>Devis '.$jid.' · à valider</p></div><div class="bd">'
    .'<div class="meta"><b>'.$H($d['nomClient']??'').'</b><br>'
    .($dEvt?'Événement : '.$H(date('d/m/Y',strtotime($dEvt))).($creneau?' · '.$H($creneau):'').'<br>':'')
    .($lieu?'Lieu : '.$H($lieu):'').'</div>'
    .'<h2>Prestation</h2><table>'.$rows
    .'<tr class="tot"><td>Total TTC</td><td class="r">'.$ttc.' €</td></tr>'
    .($acPct>0?'<tr><td class="dim">Acompte de réservation ('.rtrim(rtrim(number_format($acPct,1,',',''),'0'),',').' %)</td><td class="r dim">'.$ac.' €</td></tr>':'')
    .'</table>';
  if($already){ echo $okBlock; }
  else {
    echo '<form id="f"><h2>Signature</h2>'
      .'<label>Votre nom et prénom <button type="button" class="clr" onclick="clr()">effacer le tracé</button></label>'
      .'<input type="text" id="name" placeholder="Nom Prénom" autocomplete="name">'
      .'<canvas id="pad"></canvas><div class="padhint">Signez avec le doigt ou la souris (facultatif)</div>'
      .'<label class="chk"><input type="checkbox" id="agree"><span>J\'accepte ce devis et porte la mention « <b>Bon pour accord</b> » pour un montant de '.$ttc.' € TTC.</span></label>'
      .'<button type="submit" class="go" id="go" disabled>✍️ Signer et accepter</button>'
      .'<div id="msg"></div></form>';
    echo '<script>'
      .'var c=document.getElementById("pad"),x=c.getContext("2d"),drawn=false,dr=false;'
      .'function rz(){var r=c.getBoundingClientRect();c.width=r.width*2;c.height=r.height*2;x.scale(2,2);x.lineWidth=2.2;x.lineCap="round";x.strokeStyle="#1c1814";}rz();'
      .'function pos(e){var r=c.getBoundingClientRect(),t=e.touches?e.touches[0]:e;return[t.clientX-r.left,t.clientY-r.top];}'
      .'function st(e){dr=true;var p=pos(e);x.beginPath();x.moveTo(p[0],p[1]);e.preventDefault();}'
      .'function mv(e){if(!dr)return;var p=pos(e);x.lineTo(p[0],p[1]);x.stroke();drawn=true;e.preventDefault();}'
      .'function en(){dr=false;}'
      .'c.addEventListener("mousedown",st);c.addEventListener("mousemove",mv);window.addEventListener("mouseup",en);'
      .'c.addEventListener("touchstart",st,{passive:false});c.addEventListener("touchmove",mv,{passive:false});c.addEventListener("touchend",en);'
      .'function clr(){x.clearRect(0,0,c.width,c.height);drawn=false;}'
      .'var nm=document.getElementById("name"),ag=document.getElementById("agree"),go=document.getElementById("go");'
      .'function upd(){go.disabled=!(ag.checked&&(nm.value.trim().length>1||drawn));}'
      .'nm.addEventListener("input",upd);ag.addEventListener("change",upd);c.addEventListener("mouseup",upd);c.addEventListener("touchend",upd);'
      .'document.getElementById("f").addEventListener("submit",function(e){e.preventDefault();go.disabled=true;go.textContent="Envoi…";'
      .'var img=drawn?c.toDataURL("image/png"):"";'
      .'fetch(location.pathname+"?action=signSubmit",{method:"POST",headers:{"Content-Type":"text/plain"},body:JSON.stringify({id:"'.$jid.'",k:"'.$jk.'",signataire:nm.value.trim(),signatureImg:img})})'
      .'.then(function(r){return r.json();}).then(function(j){'
      .'if(j&&j.ok){document.getElementById("f").innerHTML=\'<div class="done">✅ Merci ! Votre devis est accepté.<br>Vous recevrez la confirmation par email.</div>\';}'
      .'else{go.disabled=false;go.textContent="✍️ Signer et accepter";document.getElementById("msg").innerHTML=\'<p style="color:#c00;font-size:13px">Erreur : \'+((j&&j.error)||"réessayez")+\'</p>\';}'
      .'}).catch(function(){go.disabled=false;go.textContent="✍️ Signer et accepter";});});'
      .'</script>';
  }
  echo '</div><div class="foot">LouisMagie · Louis Slosse · contact@louismagie.fr</div></body></html>';
  exit;
}

/* ===== Envoi planifié (déclenché par cron Coolify, protégé par CRON_KEY) ===== */
if ($action === 'runScheduled') {
  $__ck=getenv('CRON_KEY'); if (!$__ck || !hash_equals($__ck, (string)($_GET['key'] ?? ''))) out(['ok'=>false,'error'=>'cron key invalide']);
  @set_time_limit(0); ignore_user_abort(true);   // un curl coupé ne doit pas arrêter PHP entre l'envoi et son inscription
  // Verrou : deux exécutions simultanées du cron enverraient les emails en double (la réservation
  // ligne par ligne ci-dessous protège aussi le cas où ce verrou n'a pas pu s'ouvrir)
  $lockF = $DATA_DIR.'/_cron.lock'; $lock = @fopen($lockF,'c');
  if ($lock && !@flock($lock, LOCK_EX | LOCK_NB)) out(['ok'=>false,'error'=>'envoi déjà en cours']);
  // Allège les envois déjà traités (anciennes campagnes comprises) : une écriture au plus, aucune si rien à faire.
  // La version complète reste dans _historique (historiser, appelé par majTable).
  $alleges = (int)majTable($DATA_DIR, 'planifs', function(&$t) {
    $n = 0;
    foreach ($t as &$x) {
      if (!is_array($x)) continue;
      $st = (string)($x['statut'] ?? '');
      if (($st === 'envoyé' || $st === 'annulé') && ((string)($x['html'] ?? '') !== '' || (string)($x['body'] ?? '') !== '')) {
        $x['html'] = ''; $x['body'] = ''; $x['updatedAt'] = nowTs(); $n++;
      }
    }
    unset($x);
    return $n > 0 ? $n : false;
  });
  $f=$DATA_DIR.'/planifs.json'; $arr=readJson($f); if(!is_array($arr))$arr=[];
  // Adresses désabonnées : à ne jamais servir, même si la planification est antérieure
  $desab = desabosSet($DATA_DIR);   // registre des oppositions + drapeaux des fiches, clés en minuscules
  $now=nowTs(); $sent=0; $fail=0; $skip=0; $sautes=0; $reportes=0; $incertains=0; $perimes=0; $arret=''; $serie=0; $plafond=false;
  $limRetard=gmdate('Y-m-d\\TH:i:s.000\\Z', time()-3*86400);   // plus de 3 jours de retard : Louis doit confirmer
  // Change le statut d'UNE ligne relue sous le verrou de la table (id, ou trackId en secours).
  // $de = statut exigé (null = n'importe lequel). Renvoie la ligne écrite, ou false si elle a
  // disparu, n'a plus ce statut, ou si l'écriture a échoué. $reinsere : ligne disparue → on la
  // remet depuis la copie, pour garder la trace d'un email réellement parti.
  $transition = function($p, $de, $champs, $reinsere = false) use ($DATA_DIR) {
    $pid = (string)($p['id'] ?? ''); $tid = (string)($p['trackId'] ?? '');
    return majTable($DATA_DIR, 'planifs', function(&$t) use ($p, $pid, $tid, $de, $champs, $reinsere) {
      foreach ($t as &$x) {
        if (($pid !== '' && (string)($x['id'] ?? '') === $pid) || ($pid === '' && $tid !== '' && (string)($x['trackId'] ?? '') === $tid)) {
          if ($de !== null && (string)($x['statut'] ?? '') !== $de) return false;
          foreach ($champs as $k => $v) $x[$k] = $v;
          return $x;
        }
      }
      unset($x);
      if (!$reinsere) return false;
      $y = array_merge($p, $champs); $t[] = $y; return $y;
    });
  };
  foreach ($arr as $p) {                       // la copie ne sert qu'à repérer les candidates
    if (($p['statut'] ?? '') !== 'prévu' || strcmp(planifInstant($p), $now) > 0) continue;
    if (!empty($p['reessaiApres']) && strcmp((string)$p['reessaiApres'], $now) > 0) continue;   // nouvel essai pas encore dû
    if (isset($desab[strtolower(trim((string)($p['to'] ?? '')))])) {
      if ($transition($p, 'prévu', ['statut'=>'annulé', 'info'=>'destinataire désabonné', 'html'=>'', 'body'=>'', 'updatedAt'=>nowTs()]) !== false) $skip++;
      continue;
    }
    if (strcmp(planifInstant($p), $limRetard) < 0) {
      if ($transition($p, 'prévu', ['statut'=>'échec','typeEchec'=>'retard','info'=>'Non envoyé : plus de 3 jours de retard sur la date prévue. « Remettre en file » si le message est encore d’actualité.','updatedAt'=>nowTs()]) !== false) $perimes++;
      continue;
    }
    // Réservation : la ligne ne part que si elle vaut ENCORE « prévu » sous verrou.
    // Annulée, supprimée, prise par un autre passage ou écriture impossible → pas d'envoi.
    $r = $transition($p, 'prévu', ['statut'=>'envoi', 'info'=>'envoi en cours', 'updatedAt'=>nowTs()]);
    if (!is_array($r)) { $sautes++; continue; }
    $tu = '';
    if (!empty($r['trackId'])) { $base=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME']; $tu=$base.'?action=track&m='.rawurlencode($r['trackId']); }
    $lu = (($r['kind'] ?? 'campagne') === 'campagne') ? urlDesabo($DATA_DIR, $r['to'] ?? '') : '';   // les planifs actuelles sont des campagnes
    // Passage par le registre : une planif remise « prévu » après une coupure ne repart pas si son email est déjà parti
    $er = envoiUnique($DATA_DIR, ['trackId'=>$r['trackId'] ?? '', 'to'=>$r['to'] ?? '', 'subject'=>$r['subject'] ?? '', 'origine'=>'planif'],
      function() use ($r, $tu, $lu) { return smtpSend($r['to']??'', $r['subject']??'', $r['body']??'', '', '', $tu, $r['html']??'', ['listUnsub'=>$lu, 'mid'=>(string)($r['trackId']??'')]); });
    if (($er['code'] ?? '') === 'plafond') {     // la ligne revient « prévu » : le passage suivant reprend
      $transition($r, 'envoi', ['statut'=>'prévu','info'=>'Pas parti : '.($er['info'] ?? 'plafond atteint').'. Nouvel essai au prochain passage.','updatedAt'=>nowTs()]);
      $plafond = true; $arret = (string)($er['info'] ?? 'plafond'); break;
    }
    $ok = !empty($er['ok']); $type = (string)($er['type'] ?? ($ok ? 'ok' : 'refus'));
    $info = !empty($er['deja']) ? 'déjà envoyé (reprise après coupure)' : (string)($er['info'] ?? '');
    $tent = (int)($r['tentatives'] ?? 0) + 1;
    if ($ok) {                                   // parti : écrit sans condition, ligne réinsérée si effacée entre-temps
      $transition($r, null, ['statut'=>'envoyé','sentAt'=>date('c'),'info'=>$info,'tentatives'=>$tent,'reessaiApres'=>'','typeEchec'=>'','html'=>'','body'=>'','updatedAt'=>nowTs()], true);
      $sent++; $serie=0;
    } elseif ($type === 'incertain') {           // peut-être parti : jamais relancé automatiquement
      $transition($r, null, ['statut'=>'incertain','sentAt'=>date('c'),'info'=>$info,'tentatives'=>$tent,'updatedAt'=>nowTs()], true);
      $incertains++; $serie=0;
    } elseif (in_array($type, ['config','connexion','auth','expediteur'], true)) {
      // compte ou serveur en panne : aucune tentative comptée, les lignes suivantes ne sont pas touchées
      $transition($r, 'envoi', ['statut'=>'prévu','info'=>'Pas parti ('.gmdate('d/m H:i').' UTC) : '.$info.'. Nouvel essai au prochain passage.','updatedAt'=>nowTs()]);
      $arret = $info; break;
    } elseif ($type === 'temp' && $tent < 3) {
      $transition($r, 'envoi', ['statut'=>'prévu','tentatives'=>$tent,'reessaiApres'=>gmdate('Y-m-d\\TH:i:s.000\\Z', time()+3600*$tent),
        'info'=>'Essai '.$tent.'/3 refusé temporairement : '.$info,'updatedAt'=>nowTs()]);
      $reportes++; $serie++;
    } else {                                     // refus, adresse, ou 3e refus temporaire
      $transition($r, 'envoi', ['statut'=>'échec','typeEchec'=>(string)$type,'tentatives'=>$tent,'sentAt'=>date('c'),'info'=>$info,'updatedAt'=>nowTs()]);
      $fail++; if ($type !== 'adresse') $serie++;
    }
    if ($serie >= 3) { $arret = '3 refus d’affilée, dernier : '.$info; break; }   // quota ou contenu refusé : le reste attend
    if (empty($er['deja'])) usleep(350000);   // cadence douce, pour ne pas se faire limiter par le serveur SMTP
  }
  if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
  out(['ok'=>$arret==='','sent'=>$sent,'fail'=>$fail,'reportes'=>$reportes,'incertains'=>$incertains,'perimes'=>$perimes,
       'desabonnes'=>$skip,'sautes'=>$sautes,'alleges'=>$alleges,'arret'=>$arret,'plafond'=>$plafond,'total'=>count($arr)]);
}

/* ===== Diagnostic SMTP (clé requise) ===== */
if ($action === 'smtptest') {
  $__ck=getenv('CRON_KEY'); if (!$__ck || !hash_equals($__ck, (string)($_GET['key'] ?? ''))) out(['ok'=>false,'error'=>'clé invalide (mets CRON_KEY dans Coolify)']);
  $to = $_GET['to'] ?? (getenv('SMTP_USER') ?: 'test@example.com');
  if (isset($_GET['full'])) {  // teste le VRAI chemin : multipart + PDF joint + HTML/tracking
    $tu = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'].'?action=track&m=diagfull';
    $att = base64_encode(str_repeat("Faux PDF de test pour diagnostic SMTP. ", 600)); // ~23 Ko
    list($ok,$info,$type)=smtpSend($to,'Test SMTP CRM (PDF+HTML)',"Bonjour,\n\nCeci est un test d'envoi complet avec pièce jointe et HTML.\n\nLouisMagie",'test.pdf',$att,$tu);
    out(['ok'=>$ok,'info'=>$info,'type'=>$type,'mode'=>'complet (multipart + pièce jointe + HTML)']);
  }
  out(smtpDiag($to));
}

/* ===== Auth par mot de passe (1 seul secret = le mot de passe du CRM) ===== */
$AUTH_FILE = $DATA_DIR.'/_auth';
$stored = is_file($AUTH_FILE) ? trim(file_get_contents($AUTH_FILE)) : '';
if ($stored === '') { $env = getenv('CRM_PASSWORD_HASH'); if ($env) { file_put_contents($AUTH_FILE, $env); $stored = $env; } }

if ($action === 'login') {
  if ($stored === '') { file_put_contents($AUTH_FILE, $auth); out(['ok'=>true, 'first'=>true]); } // 1er appareil définit le mot de passe
  out(['ok'=> ($auth !== '' && hash_equals($stored, $auth))]);
}
if ($action === 'setAuth') {
  if ($stored !== '' && !hash_equals($stored, $auth)) out(['ok'=>false, 'error'=>'mot de passe actuel invalide']);
  file_put_contents($AUTH_FILE, $req['new'] ?? '');
  out(['ok'=>true]);
}

// Toute action data exige le bon mot de passe (ou, en secours, le token legacy s'il est configuré)
$okAuth = ($stored !== '' && $auth !== '' && hash_equals($stored, $auth)) || ($token !== '' && $token === $TOKEN);
if (!$okAuth) out(['ok'=>false, 'error'=>'non autorisé']);

switch ($action) {

  case 'getAll': {
    $data = []; foreach ($ENTITIES as $e) { $v = readJson("$DATA_DIR/$e.json"); $data[$e] = is_array($v) ? $v : []; }
    // firstRun : serveur RÉELLEMENT neuf (aucun fichier de données ni config) → autorise l'initialisation depuis un appareil.
    // Calculé AVANT toute écriture : créer config.json ici ferait passer un serveur neuf pour un serveur vidé.
    $firstRun = !is_file("$DATA_DIR/config.json");
    if ($firstRun) foreach ($ENTITIES as $e) { if (is_file("$DATA_DIR/$e.json")) { $firstRun = false; break; } }
    $cleDesabo = $firstRun ? '' : desaboCles($DATA_DIR);   // créée une seule fois, sous verrou
    $config = readJson("$DATA_DIR/config.json"); if(!is_array($config)) $config = [];
    unset($config['desaboLegacy'], $config['desaboLegacyJusqua']);   // restent sur le serveur
    if ($cleDesabo === '') unset($config['desaboKey']); else $config['desaboKey'] = $cleDesabo;
    $opens = readJson($DATA_DIR.'/_opens.json'); if(!is_array($opens)) $opens = [];
    $sigs  = readJson($DATA_DIR.'/_signatures.json'); if(!is_array($sigs)) $sigs = [];
    $desabos = $firstRun ? [] : desabosSet($DATA_DIR, true);   // registre des oppositions (les drapeaux de fiche y sont inscrits)
    out(['ok'=>true, 'data'=>$data, 'config'=>$config, 'opens'=>$opens, 'signatures'=>$sigs,
         'desabos'=>($desabos ?: new stdClass()), 'dels'=>delsRead($DATA_DIR), 'firstRun'=>$firstRun]);
  }

  case 'putEntity': {
    $e = $req['entity'] ?? '';
    if (!in_array($e, $ENTITIES)) out(['ok'=>false,'error'=>'entité inconnue']);
    $entrantes = is_array($req['rows'] ?? null) ? $req['rows'] : [];
    $res = sousVerrou($DATA_DIR, $e, function() use ($DATA_DIR, $e, $entrantes, $req) {
      $existantes = readJson("$DATA_DIR/$e.json"); if (!is_array($existantes)) $existantes = [];
      // journal de suppressions : union de ce que le serveur sait et de ce que l'appareil apporte
      $dels = sousVerrou($DATA_DIR, 'dels', function() use ($DATA_DIR, $e, $req) {
        $d = delsRead($DATA_DIR);
        if (is_array($req['dels'] ?? null) && $req['dels']) {
          $d = delsMerge($d, [$e => $req['dels']]);
          writeJson("$DATA_DIR/_dels.json", $d);
        }
        return $d;
      });
      $fusion = mergeRows($existantes, $entrantes, $dels[$e] ?? [], $e);
      backupJour($DATA_DIR, $e);
      historiser($DATA_DIR, $e, $existantes, $fusion);
      if (!writeJson("$DATA_DIR/$e.json", $fusion)) return null;
      return $fusion;
    });
    if ($res === null) out(['ok'=>false,'error'=>'écriture impossible']);
    out(['ok'=>true, 'entity'=>$e, 'n'=>count($res), 'rows'=>$res]);
  }

  case 'annulerPlanifs': {
    // Annule SANS rien supprimer : seules les lignes encore « prévu » passent à « annulé »,
    // relues sous le même verrou que la réservation du cron. Rejouer ne change rien.
    $ids = [];
    foreach ((array)($req['ids'] ?? []) as $v) if (is_scalar($v) && (string)$v !== '') $ids[(string)$v] = true;
    if (!$ids) out(['ok'=>false,'error'=>'aucun envoi indiqué']);
    $c = ['annules'=>0,'dejaAnnules'=>0,'enCours'=>0,'dejaPartis'=>0,'incertains'=>0,'echecs'=>0,'introuvables'=>0];
    $rows = [];
    $res = majTable($DATA_DIR, 'planifs', function(&$t) use ($ids, &$c, &$rows) {
      $ts = nowTs(); $vus = [];
      foreach ($t as &$x) {
        $k = (string)($x['id'] ?? ''); if (!isset($ids[$k])) continue;
        $vus[$k] = true;
        switch ((string)($x['statut'] ?? '')) {
          case 'prévu':     $x['statut']='annulé'; $x['info']='annulé par Louis'; $x['annuleAt']=$ts; $x['updatedAt']=$ts; $c['annules']++; break;
          case 'annulé':    $c['dejaAnnules']++; break;
          case 'envoi':     $c['enCours']++; break;
          case 'envoyé':    $c['dejaPartis']++; break;
          case 'incertain': $c['incertains']++; break;
          default:          $c['echecs']++;
        }
        $rows[] = $x;
      }
      unset($x);
      $c['introuvables'] = count($ids) - count($vus);
      return $c['annules'] > 0 ? true : false;       // rien à changer → aucune écriture
    });
    if ($res === false && $c['annules'] > 0) out(['ok'=>false,'error'=>'écriture impossible : rien n\'a été annulé']);
    out(['ok'=>true, 'compte'=>$c, 'rows'=>$rows]);
  }

  case 'requeuePlanifs': {
    // Remise en file demandée par Louis. Seules les lignes encore « échec » CÔTÉ SERVEUR sont touchées
    // (compare-and-set) : un second clic, ou un clic depuis un autre appareil, ne change plus rien.
    // Jamais les « incertain » (peut-être partis) ni les adresses refusées.
    $ids = [];
    foreach ((array)($req['ids'] ?? []) as $v) if (is_scalar($v) && (string)$v !== '') $ids[(string)$v] = 1;
    if (!$ids) out(['ok'=>false,'error'=>'aucune ligne']);
    $remis = 0; $rows = []; $jour = gmdate('Y-m-d'); $at = nowTs();
    $w = majTable($DATA_DIR, 'planifs', function(&$t) use ($ids, &$remis, &$rows, $jour, $at) {
      foreach ($t as &$x) {
        if (!isset($ids[(string)($x['id'] ?? '')])) continue;
        if (($x['statut'] ?? '') === 'échec' && ($x['typeEchec'] ?? '') !== 'adresse') {
          $x['statut']='prévu'; $x['date']=$jour; $x['envoiLe']=$at; $x['tentatives']=0; $x['reessaiApres']=''; $x['typeEchec']='';
          $x['info']='remis en file le '.gmdate('d/m').' par Louis'; $x['updatedAt']=$at; $remis++;
        }
        $rows[] = $x;          // ligne COMPLÈTE (html compris) : une version allégée effacerait le contenu à envoyer
      }
      unset($x);
      return $remis ? true : false;   // rien à remettre : aucune écriture
    });
    if ($remis && $w === false) out(['ok'=>false,'error'=>'écriture impossible']);
    out(['ok'=>true,'remis'=>$remis,'rows'=>$rows]);
  }

  case 'empreintes': {
    $r = [];
    foreach ($ENTITIES as $e) {
      if ($e === 'activite') continue;                             // journal, pas des données métier
      $v = readJson("$DATA_DIR/$e.json"); $r[$e] = empreinte(is_array($v) ? $v : []);
    }
    out(['ok'=>true, 'empreintes'=>$r]);
  }

  case 'historique': {
    $dir = "$DATA_DIR/_historique";
    $fichiers = @glob("$dir/*.jsonl"); if (!is_array($fichiers)) $fichiers = []; rsort($fichiers);
    $max = min(300, max(1, (int)($req['limit'] ?? 150)));
    $out = [];
    foreach ($fichiers as $f) {
      $lignes = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); if (!is_array($lignes)) continue;
      for ($i = count($lignes) - 1; $i >= 0 && count($out) < $max; $i--) {
        $x = json_decode($lignes[$i], true); if (!is_array($x)) continue;
        // liste légère : le contenu complet n'est renvoyé qu'au moment de restaurer
        $out[] = ['at'=>$x['at'] ?? '', 'entity'=>$x['entity'] ?? '', 'id'=>$x['id'] ?? '',
                  'raison'=>$x['raison'] ?? '', 'libelle'=>resumeFiche(is_array($x['avant'] ?? null) ? $x['avant'] : [])];
      }
      if (count($out) >= $max) break;
    }
    out(['ok'=>true, 'entrees'=>$out]);
  }

  case 'historiqueEntree': {
    $at = (string)($req['at'] ?? ''); $e = (string)($req['entity'] ?? ''); $id = (string)($req['id'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}/', $at)) out(['ok'=>false,'error'=>'date invalide']);
    $f = "$DATA_DIR/_historique/".substr($at, 0, 7).".jsonl";
    $lignes = is_file($f) ? @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    foreach ((array)$lignes as $l) {
      $x = json_decode($l, true);
      if (is_array($x) && ($x['at'] ?? '') === $at && ($x['entity'] ?? '') === $e && (string)($x['id'] ?? '') === $id)
        out(['ok'=>true, 'entree'=>$x]);
    }
    out(['ok'=>false, 'error'=>'version introuvable']);
  }

  case 'putConfig': {
    $entrant = is_array($req['config'] ?? null) ? $req['config'] : [];
    $graine = $entrant['desaboKey'] ?? null;          // copie détenue par un appareil (reprise après perte du serveur)
    unset($entrant['desaboKey'], $entrant['desaboLegacy'], $entrant['desaboLegacyJusqua']);   // seul le serveur les pose
    $ok = sousVerrou($DATA_DIR, 'config', function() use ($DATA_DIR, $entrant, $graine) {
      $actuel = readJson("$DATA_DIR/config.json"); if (!is_array($actuel)) $actuel = [];
      if (empty($actuel['desaboKey']) && is_string($graine) && preg_match('/^[A-Za-z0-9]{16,128}$/', $graine))
        $actuel['desaboKey'] = $graine;               // seulement si le serveur n'a aucune clé
      desaboInit($actuel);                            // AVANT la fusion : fige la formKey qui a signé les liens déjà envoyés
      // fusion par clé : un appareil qui ignore un réglage récent ne l'efface plus
      return writeJson("$DATA_DIR/config.json", array_merge($actuel, $entrant));
    });
    if (!$ok) out(['ok'=>false,'error'=>'écriture impossible']);
    out(['ok'=>true]);
  }

  case 'archivePdf': {
    $kind = preg_replace('/[^A-Za-z]/', '', $req['kind'] ?? 'Documents');
    $year = preg_replace('/[^0-9]/', '', (string)($req['year'] ?? date('Y')));
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $req['filename'] ?? 'doc.pdf');
    $dir  = "$PDF_DIR/$kind/$year";
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    file_put_contents("$dir/$name", base64_decode($req['base64'] ?? ''));
    out(['ok'=>true, 'url'=>"$PDF_URL/$kind/$year/$name"]);
  }

  case 'listPdf': {
    $files = [];
    if (is_dir($PDF_DIR)) {
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PDF_DIR, FilesystemIterator::SKIP_DOTS));
      foreach ($it as $f) if ($f->isFile()) $files[] = str_replace($PDF_DIR.'/', '', $f->getPathname());
    }
    out(['ok'=>true, 'files'=>$files]);
  }

  case 'sendEmail': {
    ignore_user_abort(true);   // si le téléphone coupe, PHP finit l'envoi ET son inscription au registre
    // Toutes les réponses portent idem:true : le CRM sait qu'il peut redemander avec le même trackId sans risque de doublon.
    // Jamais d'email d'actualité vers une adresse du registre, même depuis un appareil qui n'a pas
    // encore récupéré le désabonnement. Sans le champ (appli en cache), un email qui porte
    // un lien de désabonnement compte comme email d'actualité. Refus : aucun appel SMTP, aucune écriture.
    $estMkt = array_key_exists('marketing', $req) ? !empty($req['marketing'])
            : (strpos((string)($req['html'] ?? ''), 'action=desabo') !== false);
    if ($estMkt) { $ds = desabosSet($DATA_DIR);
      if (isset($ds[desaboNorm($req['to'] ?? '')])) out(['ok'=>false, 'desabo'=>true, 'idem'=>true, 'info'=>'destinataire désabonné des actualités : email non envoyé']); }
    $tu='';
    if(!empty($req['trackId'])){ $base=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME']; $tu=$base.'?action=track&m='.rawurlencode($req['trackId']); }
    // List-Unsubscribe : campagnes, tests de campagne et emails d'actualité « 1 clic », jamais devis ni factures
    $lu = ($estMkt || in_array((string)($req['kind'] ?? ''), ['campagne','marketing'], true)) ? urlDesabo($DATA_DIR, $req['to'] ?? '') : '';
    $res = envoiUnique($DATA_DIR, [
        'trackId'=>$req['trackId'] ?? '', 'to'=>$req['to'] ?? '', 'subject'=>$req['subject'] ?? '', 'ref'=>$req['ref'] ?? '',
        'forcer'=>!empty($req['forcer']), 'confirme'=>!empty($req['confirme']),
        'pj'=>(int)(strlen((string)($req['attachB64'] ?? ''))*3/4), 'origine'=>($estMkt ? 'crm-actualité' : 'crm')],
      function() use ($req, $tu, $lu) {
        return smtpSend($req['to']??'', $req['subject']??'', $req['body']??'', $req['attachName']??'', $req['attachB64']??'', $tu, $req['html']??'',
                        ['listUnsub'=>$lu, 'mid'=>(string)($req['trackId']??'')]);
      });
    out($res + ['idem'=>true]);
  }

  case 'journalEnvois': {   // lecture seule : ce qui est réellement parti du serveur (50 dernières lignes)
    $reg = envoisLire($DATA_DIR); $now = time();
    uasort($reg, function($a, $b){ return ($b['t'] ?? 0) <=> ($a['t'] ?? 0); });
    $o = []; foreach ($reg as $x) { $o[] = ['at'=>gmdate('Y-m-d\\TH:i:s\\Z', (int)($x['t'] ?? 0)), 'to'=>$x['to'] ?? '', 'sujet'=>$x['sujet'] ?? '',
      'statut'=>$x['s'] ?? '', 'origine'=>$x['o'] ?? '', 'info'=>$x['info'] ?? '']; if (count($o) >= 50) break; }
    $n = 0; foreach ($reg as $x) if (($x['s'] ?? '') !== 'échec' && ($x['t'] ?? 0) > $now - 86400) $n++;
    out(['ok'=>true, 'entrees'=>$o, 'jour'=>$n, 'plafond'=>plafondJour()]);
  }

  case 'desaboManuel': {
    // Opposition (etat=1) ou réabonnement (etat=0) saisi par Louis. Écrit seulement _desabos.json :
    // les fiches n'ont qu'un écrivain (l'appli, via DB.save). Rejouer la même demande n'écrit rien.
    $em = desaboNorm($req['email'] ?? '');
    if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) out(['ok'=>false,'error'=>'adresse email invalide']);
    $etat = !empty($req['etat']);
    $note = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)($req['note'] ?? ''))), 0, 200);
    if (!$etat && $note === '') out(['ok'=>false,'error'=>'réabonnement : indique quand et comment la personne l’a demandé']);
    desabosAmorcer($DATA_DIR);                                      // hors verrou, idempotent
    $res = sousVerrou($DATA_DIR, 'desabos', function() use ($DATA_DIR, $em, $etat, $note) {
      $r = desabosLire($DATA_DIR);
      $x = (isset($r[$em]) && is_array($r[$em])) ? $r[$em] : null;
      $st = desabosUnion($DATA_DIR, $r)[$em] ?? null;               // registre actif + drapeau de fiche hors registre
      if ($etat) {
        if (desaboActif($x)) return ['changed'=>false, 'entree'=>$x]; // déjà opposé : date et note d'origine gardées
        $flag = ($x === null && $st !== null);                        // connu seulement par un drapeau : on garde sa date
        $r[$em] = array_merge($x ?: [], ['le'=>($flag && !empty($st['le'])) ? desaboUtc($st['le']) : nowTs(),
                                        'source'=>$flag ? 'fiche' : 'manuel', 'note'=>$note]);
      } else {
        if ($st === null) return ['changed'=>false, 'entree'=>$x];   // rien à lever côté serveur
        $r[$em] = array_merge(['le'=>(string)($st['le'] ?? ''), 'source'=>(string)($st['source'] ?? 'fiche')], $x ?: [],
                              ['leveLe'=>nowTs(), 'leveNote'=>$note]);   // entrée gardée : historique, et un vieux drapeau ne revient pas
        $r[$em]['le'] = desaboUtc($r[$em]['le']);                    // anciens date('c') à +02:00 : comparaison le/leveLe fiable
      }
      return writeJson("$DATA_DIR/_desabos.json", $r) ? ['changed'=>true, 'entree'=>$r[$em]] : null;
    });
    if ($res === null) out(['ok'=>false,'error'=>'écriture impossible']);
    $ds = desabosUnion($DATA_DIR, desabosLire($DATA_DIR));
    out(['ok'=>true, 'changed'=>$res['changed'], 'entree'=>$res['entree'], 'desabos'=>($ds ?: new stdClass())]);
  }

  default: out(['ok'=>false, 'error'=>'action inconnue: '.$action]);
}
