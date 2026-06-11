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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$ENTITIES = ['demandes','devis','prestations','factures','clients','relances',
             'activite','mails','catalogue','recettes','declarations','planifs','templates'];

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
  if(strpos($r,'235')===false){ fclose($fp); return [false,'auth refusée ('.$host.', user '.$user.') : '.trim($r)]; }
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
function writeJson($path,$val){ file_put_contents($path, json_encode($val, JSON_UNESCAPED_UNICODE)); }

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
    if($signataire===''&&$img==='') out(['ok'=>false,'error'=>'signature vide']);
    $now = date('c');
    $devis[$idx]['statut']='Accepté';
    $devis[$idx]['dateAcceptation']=date('Y-m-d');
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
  if(!$valid){ echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;max-width:480px;margin:80px auto;text-align:center;color:#333"><h2>Lien invalide ou expiré</h2><p>Ce lien de signature n\'est plus valide. Contactez LouisMagie.</p></div>'; exit; }
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
    .'*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#15120e;color:#1c1814;padding:18px}'
    .'.wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4)}'
    .'.hd{background:linear-gradient(135deg,#FF7700,#ff9d3d);color:#fff;padding:24px 22px}'
    .'.hd h1{margin:0;font-size:20px;letter-spacing:.5px}.hd p{margin:4px 0 0;opacity:.95;font-size:13px}'
    .'.bd{padding:22px}.bd h2{font-size:14px;color:#FF7700;margin:0 0 8px;text-transform:uppercase;letter-spacing:.5px}'
    .'table{width:100%;border-collapse:collapse;font-size:14px;margin-bottom:14px}td{padding:7px 0;border-bottom:1px solid #eee}'
    .'.r{text-align:right;white-space:nowrap}.dim{color:#888;font-size:13px}.tot{font-weight:700;font-size:16px}.tot td{color:#FF7700;border-bottom:none;padding-top:12px}'
    .'.meta{font-size:13px;color:#555;margin-bottom:16px;line-height:1.6}'
    .'label{display:block;font-size:13px;font-weight:600;margin:14px 0 6px;color:#333}'
    .'input[type=text]{width:100%;padding:12px;border:1px solid #ccc;border-radius:10px;font-size:16px}'
    .'#pad{width:100%;height:160px;border:2px dashed #FF7700;border-radius:12px;background:#fffdfa;touch-action:none;display:block}'
    .'.padhint{font-size:12px;color:#999;text-align:center;margin-top:4px}'
    .'.clr{background:none;border:none;color:#FF7700;font-size:13px;cursor:pointer;float:right}'
    .'.chk{display:flex;align-items:flex-start;gap:10px;margin:16px 0;font-size:13px;color:#444}.chk input{margin-top:3px;width:18px;height:18px}'
    .'button.go{width:100%;padding:15px;background:#FF7700;color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer}button.go:disabled{opacity:.45}'
    .'.done{background:#e9f9ef;color:#1d7a45;padding:16px;border-radius:12px;text-align:center;font-size:15px;line-height:1.5}'
    .'.foot{text-align:center;font-size:11px;color:#aaa;padding:14px}'
    .'</style></head><body><div class="wrap">'
    .'<div class="hd"><h1>LouisMagie</h1><p>Devis '.$jid.' · à valider</p></div><div class="bd">'
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
  echo '</div><div class="foot">LouisMagie — Louis Slosse · contact@louismagie.fr</div></body></html>';
  exit;
}

/* ===== Envoi planifié (déclenché par cron Coolify, protégé par CRON_KEY) ===== */
if ($action === 'runScheduled') {
  if (($_GET['key'] ?? '') === '' || ($_GET['key'] ?? '') !== getenv('CRON_KEY')) out(['ok'=>false,'error'=>'cron key invalide']);
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
  if (($_GET['key'] ?? '') === '' || ($_GET['key'] ?? '') !== getenv('CRON_KEY')) out(['ok'=>false,'error'=>'clé invalide (mets CRON_KEY dans Coolify)']);
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
    out(['ok'=>true, 'data'=>$data, 'config'=>$config, 'opens'=>$opens, 'signatures'=>$sigs]);
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
