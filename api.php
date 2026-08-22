<?php
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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$ENTITIES = ['demandes','devis','prestations','factures','clients','relances',
             'activite','mails','catalogue','recettes','declarations','planifs','templates',
             'projets','magiciens','effets'];

function out($o){ echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; }

/* Envoi email via SMTP Gmail (mot de passe d'application). 0 dépendance. */
function smtpSend($to,$subject,$bodyText,$attachName='',$attachB64='',$trackUrl='',$htmlIn=''){
  // SMTP générique : Infomaniak, Gmail, etc. (compat anciennes variables GMAIL_*)
  $host=getenv('SMTP_HOST') ?: 'smtp.gmail.com';
  $port=getenv('SMTP_PORT') ?: '587';
  $user=getenv('SMTP_USER') ?: getenv('GMAIL_USER');
  $pass=getenv('SMTP_PASS') ?: getenv('GMAIL_APP_PASSWORD');
  $from=getenv('SMTP_FROM') ?: (getenv('GMAIL_FROM') ?: $user);
  if(!$user||!$pass) return [false,'SMTP non configuré (SMTP_USER / SMTP_PASS)'];
  if(!$to) return [false,'destinataire vide'];
  // Anti-injection d'en-têtes SMTP : rejette tout CRLF dans les adresses / nom de pièce jointe, valide le destinataire
  $to=trim($to); $from=trim($from);
  if(preg_match('/[\r\n]/', $to.$from.$attachName)) return [false,'adresse ou pièce jointe invalide'];
  if(!filter_var($to, FILTER_VALIDATE_EMAIL)) return [false,'destinataire invalide'];
  // Infomaniak : force SSL implicite sur 465 (leur 587 STARTTLS rejette nos requêtes anti-pipelining)
  if(strpos($host,'infomaniak')!==false){ $port='465'; }
  $secure = ($port=='465') || (getenv('SMTP_SECURE')==='ssl');   // SSL implicite (évite l'anti-pipelining STARTTLS)
  $ctx=stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
  $proto=$secure?'ssl':'tcp';
  $fp=@stream_socket_client("$proto://$host:$port",$en,$es,15,STREAM_CLIENT_CONNECT,$ctx);
  if(!$fp) return [false,"connexion SMTP impossible ($host:$port): $es"];
  stream_set_timeout($fp,20); stream_set_blocking($fp,true);
  $helo = (getenv('SMTP_FROM') && strpos(getenv('SMTP_FROM'),'@')) ? substr(strrchr(getenv('SMTP_FROM'),'@'),1) : 'louismagie.fr';
  // lit une réponse SMTP complète : lignes entières (jusqu'au \n), s'arrête sur la dernière ligne « code<espace> »
  $read=function() use($fp){ $d=''; while(($l=fgets($fp,8192))!==false){ $d.=$l; if(substr($l,-1)==="\n" && strlen($l)>=4 && $l[3]===' ') break; } return $d; };
  $cmd=function($c) use($fp,$read){ fwrite($fp,$c."\r\n"); return $read(); };
  $read();
  $cmd("EHLO $helo");
  if(!$secure){
    $cmd("STARTTLS");
    if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) return [false,'TLS échec'];
    $cmd("EHLO $helo");
  }
  $cmd("AUTH LOGIN"); $cmd(base64_encode($user));
  $r=$cmd(base64_encode($pass));
  if(strpos($r,'235')===false){ fclose($fp); return [false,'authentification SMTP refusée : '.trim($r)]; }
  $cmd("MAIL FROM:<$from>"); $cmd("RCPT TO:<$to>"); $cmd("DATA");
  $h="From: $from\r\nReply-To: $from\r\nTo: $to\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMIME-Version: 1.0\r\n";
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
  fputs($fp,$m."\r\n.\r\n"); $r=$read(); $cmd("QUIT"); fclose($fp);
  return [strpos($r,'250')!==false, strpos($r,'250')!==false?'envoyé':('refus: '.trim($r))];
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
  fwrite($fp,"From: $from\r\nTo: $to\r\nSubject: Test SMTP CRM\r\n\r\nTest diagnostic.\r\n.\r\n"); $T[]='C: <corps>'; $T[]='S: '.$read();
  $cmd("QUIT"); fclose($fp);
  return ['ok'=>true,'steps'=>$T,'info'=>'ok'];
}
function readJson($path){ if(!is_file($path)) return null; $c=file_get_contents($path); $v=json_decode($c,true); return $v; }
function writeJson($path,$val){ // écriture atomique (tmp + rename) → jamais de fichier JSON tronqué en cas d'écritures concurrentes
  $tmp=$path.'.tmp'.getmypid();
  if(file_put_contents($tmp, json_encode($val, JSON_UNESCAPED_UNICODE), LOCK_EX)!==false) @rename($tmp,$path);
  else @file_put_contents($path, json_encode($val, JSON_UNESCAPED_UNICODE)); }

$raw = file_get_contents('php://input');
$req = $raw ? json_decode($raw, true) : [];
if (!is_array($req)) $req = [];
$action = $_GET['action'] ?? ($req['action'] ?? '');
$auth   = $_GET['auth']   ?? ($req['auth']   ?? '');   // sha256(mot de passe) envoyé par le CRM
$token  = $_GET['token']  ?? ($req['token']  ?? '');   // legacy / Apps Script

if ($action === '' || $action === 'ping') out(['ok'=>true, 'msg'=>'CRM LouisMagie API (PHP) en ligne']);

if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0775, true);
if (!is_dir($DATA_DIR)) out(['ok'=>false, 'error'=>'dossier data non créable']);

/* ===== Pixel de suivi d'ouverture (public, pas d'auth) ===== */
if ($action === 'track') {
  $m = $_GET['m'] ?? '';
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
  // ── Coquille commune à la charte
  $page = function($corps, $titre='Brief équipe') use ($H) {
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
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
      .'@media(min-width:620px){.cols{grid-template-columns:1fr 1fr}.prat{grid-template-columns:1fr 1fr}}'
      .'</style></head><body>'.$corps.'</body></html>';
  };

  if(!$ok){
    echo $page('<div class="gate"><div class="eyebrow" style="margin-bottom:18px">Louis<span style="color:#fff">Magie</span></div>'
      .'<h1>Brief indisponible</h1><p>Ce lien n\'est pas valide, ou le brief n\'est pas encore publié.<br>Contacte Louis pour recevoir le bon lien.</p>'
      .'<a class="wa" href="mailto:contact@louismagie.fr">Écrire à Louis</a></div>','Brief indisponible');
    exit;
  }

  // ── Code d'accès équipe (optionnel)
  $code = trim((string)($b['code'] ?? ''));
  if($code !== ''){
    $saisi = (string)($_POST['code'] ?? $_GET['c'] ?? '');
    if(!hash_equals(mb_strtoupper($code), mb_strtoupper(trim($saisi)))){
      $err = $saisi!=='' ? '<div class="err">Code incorrect.</div>' : '';
      echo $page('<form class="gate" method="post">'
        .'<div class="eyebrow" style="margin-bottom:18px">Louis<span style="color:#fff">Magie</span></div>'
        .'<h1>Brief équipe</h1><p>'.$H($b['titre'] ?? 'Imagine the Impossible').'<br>Entre le code transmis par Louis.</p>'
        .'<input name="code" placeholder="CODE" autocapitalize="characters" autofocus>'
        .'<button type="submit">Accéder au brief</button>'.$err.'</form>','Accès au brief');
      exit;
    }
  }

  // ── Rendu du brief
  $md = function($t) use ($H) {   // gras **texte** + retours à la ligne
    $t = $H($t);
    $t = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $t);
    return nl2br($t);
  };
  $o = '<div class="wrap"><header><div class="eyebrow">'.$H($b['eyebrow'] ?? 'LouisMagie · Brief équipe').'</div>';
  $titre = $b['titre'] ?? 'Imagine the Impossible';
  // met en accent orange les derniers mots du titre
  $mots = preg_split('/\s+/u', $titre);
  if(count($mots) > 1){ $prem = array_shift($mots); $o .= '<h1>'.$H($prem).' <span class="g">'.$H(implode(' ',$mots)).'</span></h1>'; }
  else $o .= '<h1>'.$H($titre).'</h1>';
  if(!empty($b['sousTitre'])) $o .= '<p class="lede">'.$H($b['sousTitre']).'</p>';
  $o .= '</header>';

  if(!empty($b['concept'])) $o .= '<section><h2>Le concept</h2><p>'.$md($b['concept']).'</p></section>';

  $secs = is_array($b['sections'] ?? null) ? $b['sections'] : [];
  if($secs){
    $o .= '<div class="cat">'.$H($b['catEsprit'] ?? 'Première partie · L\'état d\'esprit').'</div>';
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
          .'<div class="col do"><div class="h">À faire</div><ul>'.$li($sec['do'] ?? []).'</ul></div>'
          .'<div class="col dont"><div class="h">À éviter</div><ul>'.$li($sec['dont'] ?? []).'</ul></div></div>';
      }
      $o .= '</section>';
    }
  }

  $roles = is_array($b['roles'] ?? null) ? $b['roles'] : [];
  if($roles){
    $o .= '<div class="cat">'.$H($b['catMenu'] ?? 'Deuxième partie · Le menu d\'illusions').'</div><section>';
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

  $prat = is_array($b['pratique'] ?? null) ? $b['pratique'] : [];
  if($prat){
    $o .= '<div class="cat">'.$H($b['catPratique'] ?? 'Troisième partie · Le cadre pratique').'</div><section><div class="prat">';
    foreach($prat as $it){
      if(trim((string)($it['v'] ?? ''))==='') continue;
      $o .= '<div class="pr"><div class="k">'.$H($it['k'] ?? '').'</div><div class="v">'.$md($it['v']).'</div></div>';
    }
    $o .= '</div></section>';
  }

  if(!empty($b['equipe']) && is_array($b['equipe'])){
    $o .= '<section><h2>L\'équipe</h2><div class="mag">';
    foreach($b['equipe'] as $m){ $o .= '<span>'.$H($m).'</span>'; }
    $o .= '</div></section>';
  }

  if(!empty($b['cloture'])) $o .= '<div class="close">'.$md($b['cloture']).'</div>';
  $o .= '<div class="contact">'.$md($b['contact'] ?? 'La moindre question, écris-moi directement sur WhatsApp.');
  if(!empty($b['waLouis'])) $o .= '<br><a class="wa" href="https://wa.me/'.$H(preg_replace('/[^0-9]/','',$b['waLouis'])).'">Écrire à Louis</a>';
  $o .= '</div>';
  $o .= '<footer>LouisMagie · '.$H($b['titre'] ?? '').($p['date'] ? ' · '.$H(date('d/m/Y', strtotime($p['date']))) : '').' — document confidentiel</footer></div>';
  echo $page($o, ($b['titre'] ?? 'Brief').' — Brief équipe');
  exit;
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
    'recuLe'=>date('c'),
  ];
  $f = "$DATA_DIR/demandes.json"; $arr = readJson($f); if(!is_array($arr)) $arr=[];
  $arr[] = $dem; writeJson($f, $arr);

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
  $config = readJson("$DATA_DIR/config.json"); $l = is_array($config) ? ($config['logo'] ?? '') : '';
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
    // Garde : la signature ne fait AVANCER le statut que depuis Brouillon/Envoyé (jamais rétrograder « Acompte reçu » etc.)
    if (in_array($d['statut'] ?? '', ['Brouillon','Envoyé',''])) {
      $devis[$idx]['statut']='Accepté';
      if (empty($devis[$idx]['dateAcceptation'])) $devis[$idx]['dateAcceptation']=date('Y-m-d');
    }
    $devis[$idx]['signataire']=$signataire;
    $devis[$idx]['signatureImg']=$img;
    $devis[$idx]['signedAt']=$now;
    $devis[$idx]['updatedAt']=$now;
    writeJson("$DATA_DIR/devis.json",$devis);
    // Journal séparé, jamais écrasé par une resynchro → la signature ne se perd jamais
    $sf=$DATA_DIR.'/_signatures.json'; $sigs=readJson($sf); if(!is_array($sigs))$sigs=[];
    $sigs=array_values(array_filter($sigs,function($s)use($id){return ($s['id']??'')!==$id;}));
    $sigs[]=['id'=>$id,'signataire'=>$signataire,'signatureImg'=>$img,'signedAt'=>$now,
             'ip'=>$_SERVER['REMOTE_ADDR']??'','montantTTC'=>$d['montantTTC']??0];
    writeJson($sf,$sigs);
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
  $f=$DATA_DIR.'/planifs.json'; $arr=readJson($f); if(!is_array($arr))$arr=[];
  $today=date('Y-m-d'); $sent=0; $fail=0;
  foreach ($arr as &$p) {
    if (($p['statut']??'')==='prévu' && ($p['date']??'9999') <= $today) {
      $tu='';
      if(!empty($p['trackId'])){ $base=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME']; $tu=$base.'?action=track&m='.rawurlencode($p['trackId']); }
      list($ok,$info)=smtpSend($p['to']??'',$p['subject']??'',$p['body']??'','','',$tu,$p['html']??'');
      $p['statut']=$ok?'envoyé':'échec'; $p['sentAt']=date('c'); $p['info']=$info; $ok?$sent++:$fail++;
    }
  }
  unset($p); writeJson($f,$arr);
  out(['ok'=>true,'sent'=>$sent,'fail'=>$fail,'total'=>count($arr)]);
}

/* ===== Diagnostic SMTP (clé requise) ===== */
if ($action === 'smtptest') {
  $__ck=getenv('CRON_KEY'); if (!$__ck || !hash_equals($__ck, (string)($_GET['key'] ?? ''))) out(['ok'=>false,'error'=>'clé invalide (mets CRON_KEY dans Coolify)']);
  $to = $_GET['to'] ?? (getenv('SMTP_USER') ?: 'test@example.com');
  if (isset($_GET['full'])) {  // teste le VRAI chemin : multipart + PDF joint + HTML/tracking
    $tu = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'].'?action=track&m=diagfull';
    $att = base64_encode(str_repeat("Faux PDF de test pour diagnostic SMTP. ", 600)); // ~23 Ko
    list($ok,$info)=smtpSend($to,'Test SMTP CRM (PDF+HTML)',"Bonjour,\n\nCeci est un test d'envoi complet avec pièce jointe et HTML.\n\nLouisMagie",'test.pdf',$att,$tu);
    out(['ok'=>$ok,'info'=>$info,'mode'=>'complet (multipart + pièce jointe + HTML)']);
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
    $config = readJson("$DATA_DIR/config.json"); if(!is_array($config)) $config = [];
    $opens = readJson($DATA_DIR.'/_opens.json'); if(!is_array($opens)) $opens = [];
    $sigs  = readJson($DATA_DIR.'/_signatures.json'); if(!is_array($sigs)) $sigs = [];
    // firstRun : serveur RÉELLEMENT neuf (aucun fichier de données ni config) → autorise l'initialisation depuis un appareil
    $firstRun = !is_file("$DATA_DIR/config.json");
    if ($firstRun) foreach ($ENTITIES as $e) { if (is_file("$DATA_DIR/$e.json")) { $firstRun = false; break; } }
    out(['ok'=>true, 'data'=>$data, 'config'=>$config, 'opens'=>$opens, 'signatures'=>$sigs, 'firstRun'=>$firstRun]);
  }

  case 'putEntity': {
    $e = $req['entity'] ?? '';
    if (!in_array($e, $ENTITIES)) out(['ok'=>false,'error'=>'entité inconnue']);
    writeJson("$DATA_DIR/$e.json", $req['rows'] ?? []);
    out(['ok'=>true]);
  }

  case 'putConfig': {
    writeJson("$DATA_DIR/config.json", $req['config'] ?? []);
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
    $tu='';
    if(!empty($req['trackId'])){ $base=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME']; $tu=$base.'?action=track&m='.rawurlencode($req['trackId']); }
    list($ok,$info)=smtpSend($req['to']??'', $req['subject']??'', $req['body']??'', $req['attachName']??'', $req['attachB64']??'', $tu, $req['html']??'');
    out(['ok'=>$ok, 'info'=>$info]);
  }

  default: out(['ok'=>false, 'error'=>'action inconnue: '.$action]);
}
