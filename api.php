<?php
// api.php – Flat‑file Xiangqi backend
// (optional during debugging)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
header('Content-Type: application/json');

$ACTION = $_GET['action'] ?? '';
$BODY = json_decode(file_get_contents('php://input'), true) ?: [];
$DATA_DIR = __DIR__ . '/data/games';
if (!is_dir($DATA_DIR)) mkdir($DATA_DIR, 0775, true);

// Fallback for older PHP where random_bytes may be missing
if (!function_exists('random_bytes')) {
  function random_bytes($length) {
    if (function_exists('openssl_random_pseudo_bytes')) {
      return openssl_random_pseudo_bytes($length);
    }
    $bytes = '';
    for ($i = 0; $i < $length; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return $bytes;
  }
}

function jrand($len=32){ return rtrim(strtr(base64_encode(random_bytes($len)), '+/', '-_'), '='); }
function gid(){ return substr(jrand(18),0,18); }
function gpath($id){ global $DATA_DIR; return "$DATA_DIR/$id.json"; }
function ok($arr){ echo json_encode($arr); exit; }
function bad($msg){ echo json_encode(['error'=>$msg]); exit; }

function initial_board(){
  $B = array_fill(0,10, array_fill(0,9, null));
  $B[9][0] = 'r_R'; $B[9][8] = 'r_R';
  $B[9][1] = 'r_H'; $B[9][7] = 'r_H';
  $B[9][2] = 'r_E'; $B[9][6] = 'r_E';
  $B[9][3] = 'r_A'; $B[9][5] = 'r_A';
  $B[9][4] = 'r_G';
  $B[7][1] = 'r_C'; $B[7][7] = 'r_C';
  for($c=0;$c<9;$c+=2) $B[6][$c] = 'r_S';
  $B[0][0] = 'b_R'; $B[0][8] = 'b_R';
  $B[0][1] = 'b_H'; $B[0][7] = 'b_H';
  $B[0][2] = 'b_E'; $B[0][6] = 'b_E';
  $B[0][3] = 'b_A'; $B[0][5] = 'b_A';
  $B[0][4] = 'b_G';
  $B[2][1] = 'b_C'; $B[2][7] = 'b_C';
  for($c=0;$c<9;$c+=2) $B[3][$c] = 'b_S';
  return $B;
}

function new_game(){
  $id = gid();
  $red = 'R_'.jrand(18); $black = 'B_'.jrand(18);
  $game = [
    'gameId'=>$id, 'variant'=>'xiangqi', 'createdAt'=>time(), 'turn'=>'red', 'version'=>1,
    'players'=>['red'=>['token'=>$red], 'black'=>['token'=>$black]],
    'board'=>initial_board(), 'moves'=>[], 'result'=>null
  ];
  write_game($id, $game, true);
  $base = (isset($_SERVER['HTTPS'])?'https':'http')."://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
  $base = preg_replace('/api\.php.*/','', $base);
  $redUrl = $base.'index.html?game='.$id.'&token='.$red;
  $blackUrl = $base.'index.html?game='.$id.'&token='.$black;
  ok(['gameId'=>$id,'redUrl'=>$redUrl,'blackUrl'=>$blackUrl]);
}

function read_game($id){
  $p = gpath($id); if(!is_file($p)) return null;
  $fh = fopen($p, 'r'); flock($fh, LOCK_SH); $json = stream_get_contents($fh); flock($fh, LOCK_UN); fclose($fh);
  return json_decode($json, true);
}

function write_game($id, $data, $create=false){
  $p = gpath($id); $tmp = $p.'.tmp';
  $fh = fopen($tmp, 'w'); if(!$fh) bad('Unable to write file');
  flock($fh, LOCK_EX); fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE)); fflush($fh); flock($fh, LOCK_UN); fclose($fh);
  rename($tmp, $p); if($create) @chmod($p, 0664);
}

function seat_of($game, $token){
  if($game['players']['red']['token']===$token) return 'red';
  if($game['players']['black']['token']===$token) return 'black';
  return null;
}

function inside($r,$c){ return $r>=0 && $r<=9 && $c>=0 && $c<=8; }
function emptySq($B,$r,$c){ return inside($r,$c) && !$B[$r][$c]; }
function enemyAt($B,$r,$c,$me){ return inside($r,$c) && $B[$r][$c] && $B[$r][$c][0]!==$me[0]; }
function palace($side,$r,$c){ if($c<3 || $c>5) return false; return $side==='red' ? ($r>=7 && $r<=9) : ($r>=0 && $r<=2); }
function copyB($B){ return json_decode(json_encode($B), true); }
function findGeneral($B,$side){ $target = $side==='red'?'r_G':'b_G'; for($r=0;$r<10;$r++) for($c=0;$c<9;$c++){ if($B[$r][$c]===$target) return [$r,$c]; } return [-1,-1]; }
function pathClear($B,$r1,$c1,$r2,$c2){
  if($r1===$r2){ $step = $c1<$c2?1:-1; for($c=$c1+$step; $c!=$c2; $c+=$step) if($B[$r1][$c]) return false; return true; }
  if($c1===$c2){ $step = $r1<$r2?1:-1; for($r=$r1+$step; $r!=$r2; $r+=$step) if($B[$r][$c1]) return false; return true; }
  return false;
}
function flyingGenerals($B){
  list($rr,$rc)=findGeneral($B,'red'); list($br,$bc)=findGeneral($B,'black');
  if($rc!==$bc) return false;
  $min=min($rr,$br)+1; $max=max($rr,$br)-1;
  for($r=$min;$r<=$max;$r++){ if($B[$r][$rc]) return false; }
  return true;
}
function inCheckAfter($B,$side){
  if(flyingGenerals($B)) return true;
  list($gr,$gc)=findGeneral($B, $side);
  if($gr<0) return true;
  $opp = $side==='red'?'b':'r';
  for($r=$gr-1; $r>=0; $r--){ $p=$B[$r][$gc]; if(!$p) continue; if($p[0]===$opp && ($p[2]==='R' || ($p[2]==='G' && palace($opp==='r'?'red':'black',$r,$gc)))) return true; else break; }
  for($r=$gr+1; $r<=9; $r++){ $p=$B[$r][$gc]; if(!$p) continue; if($p[0]===$opp && ($p[2]==='R' || ($p[2]==='G' && palace($opp==='r'?'red':'black',$r,$gc)))) return true; else break; }
  for($c=$gc-1; $c>=0; $c--){ $p=$B[$gr][$c]; if(!$p) continue; if($p[0]===$opp && $p[2]==='R') return true; else break; }
  for($c=$gc+1; $c<=8; $c++){ $p=$B[$gr][$c]; if(!$p) continue; if($p[0]===$opp && $p[2]==='R') return true; else break; }
  $dirs=[[ -1,0],[1,0],[0,-1],[0,1]];
  foreach($dirs as $d){ $r=$gr+$d[0]; $c=$gc+$d[1]; $screens=0; while(inside($r,$c)){ $p=$B[$r][$c]; if($p){ $screens++; if($screens===2){ if($p[0]===$opp && $p[2]==='C') return true; break; } } $r+=$d[0]; $c+=$d[1]; } }
  $cands=[[-2,-1,-1,0],[-2,1,-1,0],[2,-1,1,0],[2,1,1,0],[-1,-2,0,-1],[1,-2,0,-1],[-1,2,0,1],[1,2,0,1]];
  foreach($cands as $cd){ list($dr,$dc,$lr,$lc)=$cd; $lr+=$gr; $lc+=$gc; if($B[$lr][$lc]) continue; $rr=$gr+$dr; $cc=$gc+$dc; if(inside($rr,$cc) && $B[$rr][$cc] && $B[$rr][$cc][0]===$opp && $B[$rr][$cc][2]==='H') return true; }
  if($opp==='b'){ $t=[[1,0]]; if($gr>=5) $t=array_merge($t, [[0,-1],[0,1]]); }
  else { $t=[[-1,0]]; if($gr<=4) $t=array_merge($t, [[0,-1],[0,1]]); }
  foreach($t as $d){ $rr=$gr+$d[0]; $cc=$gc+$d[1]; if(inside($rr,$cc) && $B[$rr][$cc] && $B[$rr][$cc][0]===$opp && $B[$rr][$cc][2]==='S') return true; }
  return false;
}
function legal_move($game, $from, $to, $seat){
  $B = $game['board']; list($r1,$c1)=$from; list($r2,$c2)=$to;
  if(!inside($r1,$c1)||!inside($r2,$c2)) return 'Out of bounds';
  $me = $B[$r1][$c1]; if(!$me) return 'No piece on source square';
  if(($seat==='red' && $me[0]!=='r') || ($seat==='black' && $me[0]!=='b')) return 'Not your piece';
  if($B[$r2][$c2] && $B[$r2][$c2][0]===$me[0]) return 'Cannot capture your own piece';
  $role = substr($me,2,1); $dr=$r2-$r1; $dc=$c2-$c1; $adr=abs($dr); $adc=abs($dc);
  switch($role){
    case 'R': if(!pathClear($B,$r1,$c1,$r2,$c2)) return 'Path blocked for chariot'; break;
    case 'H': {
      $ok = ($adr===2 && $adc===1) || ($adr===1 && $adc===2);
      if(!$ok) return 'Illegal horse move';
      if($adr===2){ $lr=$r1+($dr>0?1:-1); $lc=$c1; if($B[$lr][$lc]) return 'Horse leg blocked'; }
      if($adc===2){ $lr=$r1; $lc=$c1+($dc>0?1:-1); if($B[$lr][$lc]) return 'Horse leg blocked'; }
      break; }
    case 'E': {
      if(!($adr===2&&$adc===2)) return 'Elephant moves 2 diagonally';
      $mr=$r1+($dr>0?1:-1); $mc=$c1+($dc>0?1:-1); if($B[$mr][$mc]) return 'Elephant eye blocked';
      if($me[0]==='r' && $r2<5) return 'Elephant cannot cross river';
      if($me[0]==='b' && $r2>4) return 'Elephant cannot cross river';
      break; }
    case 'A': {
      if(!($adr===1&&$adc===1)) return 'Advisor moves 1 diagonal';
      $side = $me[0]==='r'?'red':'black'; if(!palace($side,$r2,$c2)) return 'Advisor must stay in palace';
      break; }
    case 'G': {
      if(!(($adr===1&&$adc===0)||($adr===0&&$adc===1))) return 'General moves 1 orthogonal in palace';
      $side = $me[0]==='r'?'red':'black'; if(!palace($side,$r2,$c2)) return 'General must stay in palace';
      break; }
    case 'C': {
      if($r1!==$r2 && $c1!==$c2) return 'Cannon moves straight';
      $screens=0; if($r1===$r2){ $step=$c1<$c2?1:-1; for($c=$c1+$step;$c!=$c2;$c+=$step) if($B[$r1][$c]) $screens++; }
      else { $step=$r1<$r2?1:-1; for($r=$r1+$step;$r!=$r2;$r+=$step) if($B[$r][$c1]) $screens++; }
      if(!$B[$r2][$c2]){ if($screens>0) return 'Cannon cannot jump without capture'; }
      else { if($screens!==1) return 'Cannon must capture over exactly one screen'; }
      break; }
    case 'S': {
      $side = $me[0]==='r'?'red':'black';
      if($side==='red'){
        if($dr!==-1 || $adc>0){
          if($r1<=4 && $adr===0 && $adc===1) { /* ok */ }
          else return 'Illegal soldier move';
        }
      } else {
        if($dr!==1 || $adc>0){
          if($r1>=5 && $adr===0 && $adc===1) { /* ok */ }
          else return 'Illegal soldier move';
        }
      }
      break; }
  }
  $NB = copyB($B); $NB[$r2][$c2] = $me; $NB[$r1][$c1] = null;
  $side = ($me[0]==='r')?'red':'black';
  if (inCheckAfter($NB, $side)) return 'Move leaves you in check (or generals facing)';
  return true;
}

if($ACTION==='create') new_game();

if($ACTION==='state'){
  $id = $BODY['gameId'] ?? ''; $token=$BODY['token'] ?? ''; $since=intval($BODY['since'] ?? 0);
  if(!$id||!$token) bad('Missing game/token');
  $g = read_game($id); if(!$g) bad('Game not found');
  $seat = seat_of($g,$token); if(!$seat) bad('Invalid token for this game');
  $changed = $g['version']>$since;
  $view = ['version'=>$g['version'],'turn'=>$g['turn'],'you'=>$seat,'result'=>$g['result'],'board'=>$g['board'],'moves'=>array_slice($g['moves'], -10)];
  ok(['changed'=>$changed,'view'=>$view,'redLinkToken'=>$g['players']['red']['token'],'blackLinkToken'=>$g['players']['black']['token']]);
}

if($ACTION==='move'){
  $id=$BODY['gameId']??''; $token=$BODY['token']??''; $from=$BODY['from']??null; $to=$BODY['to']??null; $cv=intval($BODY['clientVersion']??0);
  if(!$id||!$token||!$from||!$to) bad('Missing params');
  $g=read_game($id); if(!$g) bad('Game not found');
  if($g['result']) bad('Game finished');
  $seat=seat_of($g,$token); if(!$seat) bad('Invalid token');
  if($g['turn']!==$seat) bad('Not your turn');
  if($cv!=$g['version']) bad('Out of date, refresh');
  $lm = legal_move($g, $from, $to, $seat); if($lm!==true) bad($lm);
  list($r1,$c1)=$from; list($r2,$c2)=$to; $capt = $g['board'][$r2][$c2];
  $g['board'][$r2][$c2] = $g['board'][$r1][$c1]; $g['board'][$r1][$c1] = null;
  $g['moves'][] = ['from'=>$from,'to'=>$to,'by'=>$seat,'cap'=>$capt,'ts'=>time()];
  $g['version']++;
  if($capt && substr($capt,2,1)==='G'){ $g['result'] = ['winner'=>$seat,'reason'=>'general captured']; }
  else { $g['turn'] = ($g['turn']==='red')?'black':'red'; }
  write_game($id,$g);
  $view = [ 'version'=>$g['version'],'turn'=>$g['turn'],'you'=>$seat,'result'=>$g['result'],'board'=>$g['board'],'moves'=>array_slice($g['moves'],-10) ];
  ok(['view'=>$view]);
}

if($ACTION==='resign'){
  $id=$BODY['gameId']??''; $token=$BODY['token']??''; $g=read_game($id); if(!$g) bad('Game not found');
  $seat=seat_of($g,$token); if(!$seat) bad('Invalid token');
  if(!$g['result']){ $g['result']=['winner'=>($seat==='red'?'black':'red'),'reason'=>'resignation']; $g['version']++; write_game($id,$g); }
  $view = [ 'version'=>$g['version'],'turn'=>$g['turn'],'you'=>$seat,'result'=>$g['result'],'board'=>$g['board'],'moves'=>array_slice($g['moves'],-10) ];
  ok(['view'=>$view]);
}

bad('Unknown action');