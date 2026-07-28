<?php require dirname(__DIR__).'/lib.php'; panel_session();
header('Content-Type: application/json; charset=utf-8');
if(empty($_SESSION['glpi_token'])){ http_response_code(401); echo json_encode(['auth'=>false]); exit; }
$tok=$_SESSION['glpi_token'];

// Los perfiles de Técnico nunca se ofrecen ni se pueden activar desde el panel.
function es_tecnico($n){ return (bool)preg_match('/t[eé]cn|technic/iu',(string)$n); }

// Relee el perfil activo desde GLPI y actualiza los flags de rol de la sesión PHP.
function refresh_session_role($tok){
  list($c,$full)=glpi_fetch('/getFullSession',[],$tok);
  $s=$full['session']??$full??[];
  $prof=strtolower($s['glpiactiveprofile']['name']??'');
  $_SESSION['profile']=$s['glpiactiveprofile']['name']??'';
  $_SESSION['isAdmin']=in_array($prof,['super-admin','admin']);
  $_SESSION['isSuper']=(strpos($prof,'superv')!==false);
  return [(int)($s['glpiactiveprofile']['id']??0), $_SESSION['profile']];
}

function mis_perfiles($tok){
  list($c,$d)=glpi_fetch('/getMyProfiles',[],$tok);
  if($c===401||$c===403) return [null,[]];
  $raw=$d['myprofiles']??(is_array($d)?$d:[]);
  $out=[];
  foreach($raw as $p){
    $id=(int)($p['id']??0); $n=trim((string)($p['name']??''));
    if($id&&$n!=='') $out[]=['id'=>$id,'name'=>$n];
  }
  return [$c,$out];
}

$m=$_SERVER['REQUEST_METHOD'];

if($m==='GET'){
  // Sincronizar: relee rol activo + lista fresca de perfiles desde GLPI
  list($aid,$aname)=refresh_session_role($tok);
  list($code,$todos)=mis_perfiles($tok);
  if($code===null){ $_SESSION=[]; session_destroy(); http_response_code(401); echo json_encode(['auth'=>false]); exit; }
  $eleg=array_values(array_filter($todos,function($p){return !es_tecnico($p['name']);}));
  if(count($todos)>0 && count($eleg)===0){
    // Le quitaron todo salvo Técnico → este panel no es para él
    $_SESSION=[]; session_destroy();
    echo json_encode(['ok'=>true,'only_tecnico'=>true]); exit;
  }
  echo json_encode(['ok'=>true,'profiles'=>$eleg,'active_id'=>$aid,'active_name'=>$aname,
    'isAdmin'=>$_SESSION['isAdmin'],'isSuper'=>$_SESSION['isSuper']],JSON_UNESCAPED_UNICODE);
  exit;
}

if($m!=='POST'){ http_response_code(405); echo '{}'; exit; }

$in=json_decode(file_get_contents('php://input'),true)?:[];
$pid=(int)($in['profiles_id']??0);
if($pid<=0){ http_response_code(400); echo json_encode(['ok'=>false]); exit; }

// Validación server-side: el perfil debe pertenecer al usuario y NO ser Técnico
list($code,$todos)=mis_perfiles($tok);
if($code===null){ $_SESSION=[]; session_destroy(); http_response_code(401); echo json_encode(['auth'=>false]); exit; }
$valido=false;
foreach($todos as $p){ if($p['id']===$pid && !es_tecnico($p['name'])){ $valido=true; break; } }
if(!$valido){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Perfil no permitido']); exit; }

list($c2,$r)=glpi_write('/changeActiveProfile','POST',['profiles_id'=>$pid],$tok);
if($c2>=400){ http_response_code(502); echo json_encode(['ok'=>false,'error'=>'GLPI rechazó el cambio de perfil']); exit; }

list($aid,$aname)=refresh_session_role($tok);
echo json_encode(['ok'=>true,'active_id'=>$aid,'active_name'=>$aname,
  'isAdmin'=>$_SESSION['isAdmin'],'isSuper'=>$_SESSION['isSuper']],JSON_UNESCAPED_UNICODE);
